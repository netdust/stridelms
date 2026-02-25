# netdust-mail Plugin Design

**Date:** 2026-02-25
**Status:** Approved
**Scope:** Standalone email template management plugin for NTDST projects

## Overview

netdust-mail provides:
- Default mail wrapper/layout for all WordPress emails
- CRUD for email templates (drafts) via CPT
- SmartCode system for dynamic content (`{{category.field}}`)
- Action-based triggers for automatic email dispatch
- Attachment support (media files + generated PDFs)
- Admin UI under Tools → Email Templates

## Architecture

**Approach:** Thin wrapper around ntdst-core's `ntdst_mail()` fluent API. No custom mailer — leverages existing infrastructure for sending, queuing, and logging.

### Plugin Structure

```
netdust-mail/
├── netdust-mail.php              # Bootstrap, registers with ntdst container
├── src/
│   ├── MailService.php           # Main service (NTDST_Service_Meta)
│   ├── MailTemplateCPT.php       # CPT registration
│   ├── MailTemplateRepository.php # Query/CRUD for templates
│   ├── SmartCodeParser.php       # {{category.field}} parser
│   ├── SmartCodeRegistry.php     # Register/retrieve smartcode definitions
│   ├── TriggerRegistry.php       # Maps WP actions → templates
│   ├── AttachmentHandler.php     # PDF generation + media attachments
│   └── Admin/
│       ├── AdminController.php   # Menu registration, page routing
│       └── TemplateEditor.php    # Edit screen enhancements
├── templates/
│   └── emails/
│       └── layout.php            # Default HTML wrapper
└── assets/
    ├── css/admin.css
    └── js/admin.js               # SmartCode inserter UI
```

### Key Decisions

- Implements `NTDST_Service_Meta` for DI container registration
- Uses `ntdst_mail()` fluent API internally — no custom mailer
- All emails pass through `ndmail_before_send` filter for SmartCode parsing
- Layout lookup: theme → plugin fallback

## Data Model

### CPT: `ndmail_template`

```php
ntdst_data()->register('ndmail_template', [
    'meta_prefix' => '_ndmail_',
    'label' => 'Email Templates',
    'labels' => [
        'singular' => 'Email Template',
        'add_new' => 'New Template',
    ],
    'public' => false,
    'show_ui' => true,
    'show_in_menu' => false,
    'supports' => ['title'],
    'fields' => [
        'subject'     => ['type' => 'text', 'label' => 'Subject Line'],
        'body'        => ['type' => 'html', 'label' => 'Email Body'],
        'category'    => ['type' => 'text', 'label' => 'Category'],
        'status'      => ['type' => 'text', 'label' => 'Status', 'default' => 'draft'],
        'trigger'     => ['type' => 'text', 'label' => 'WP Action'],
        'attachments' => ['type' => 'json', 'label' => 'Attachments'],
    ],
]);
```

### Status Values

| Status | Behavior |
|--------|----------|
| `draft` | Visible in admin, won't send |
| `active` | Ready to send (manually or via trigger) |

### Attachments JSON Structure

```json
[
  {"type": "media", "id": 456},
  {"type": "pdf", "generator": "stride_quote", "context_key": "quote_id"}
]
```

## SmartCode System

### Syntax

```
{{category.field}}
{{category.field|default_value}}
```

### Built-in Categories

| Category | Fields |
|----------|--------|
| `site` | `name`, `url`, `admin_email` |
| `user` | `email`, `first_name`, `last_name`, `display_name` |
| `date` | `today`, `year`, `month` |

### Registration (for other plugins)

```php
add_filter('ndmail_smartcodes', function($codes) {
    $codes['enrollment'] = [
        'label' => 'Enrollment',
        'codes' => [
            'course_name' => [
                'label' => 'Course Name',
                'callback' => function($context) {
                    $enrollment = get_enrollment($context['enrollment_id']);
                    return $enrollment->course_name ?? null;
                },
            ],
        ],
    ];
    return $codes;
});
```

### Validation

Emails are **blocked** if any SmartCodes remain unparsed after processing:

```php
$unparsed = $this->findUnparsedCodes($result);

if (!empty($unparsed)) {
    ntdst_log('mail')->error('Email blocked: unparsed smartcodes', [
        'template'  => $templateSlug,
        'trigger'   => $triggerAction,
        'unparsed'  => $unparsed,
        'context'   => array_keys($context),
    ]);

    return new WP_Error(
        'ndmail_unparsed_smartcodes',
        sprintf('Cannot send "%s": missing context for %s', $templateSlug, implode(', ', $unparsed))
    );
}
```

## Trigger System

Templates can be linked to WordPress actions for automatic dispatch.

### Built-in Triggers

```php
$triggers = [
    'user_register'   => ['label' => 'User Registration', 'context' => ['user_id']],
    'password_reset'  => ['label' => 'Password Reset', 'context' => ['user_id', 'reset_key']],
    'wp_login'        => ['label' => 'User Login', 'context' => ['user_id']],
];
```

### Registration (for other plugins)

```php
add_filter('ndmail_triggers', function($triggers) {
    $triggers['stride/enrollment/confirmed'] = [
        'label' => 'Enrollment Confirmed',
        'context' => ['user_id', 'enrollment_id', 'edition_id'],
    ];
    return $triggers;
});
```

### Behavior

1. Admin selects trigger from dropdown when editing template
2. On save, `MailService` hooks into that action
3. When action fires, service looks up templates linked to it
4. Parses SmartCodes with action context
5. Sends if all SmartCodes resolved, logs error if not

Multiple templates can be linked to the same trigger.

## Attachment System

### Static Media Attachments

Files from WP Media Library, selected in template editor.

```php
['type' => 'media', 'id' => 456]
```

### Dynamic PDF Attachments

Generated at send time via registered generators.

```php
['type' => 'pdf', 'generator' => 'stride_quote', 'context_key' => 'quote_id']
```

### PDF Generator Registration

```php
add_filter('ndmail_pdf_generators', function($generators) {
    $generators['stride_quote'] = [
        'label' => 'Quote PDF',
        'callback' => fn($quoteId) => QuoteService::generatePdf($quoteId),
        'context_key' => 'quote_id',
    ];
    return $generators;
});
```

Same validation rule applies: if PDF generator can't resolve (missing context), email is blocked and logged.

## Admin UI

**Location:** Tools → Email Templates

### Template List

- Standard WP list table
- Columns: Title, Subject, Category, Status, Trigger, Actions
- Quick filters: All | Active | Draft | By Category

### Template Editor

- Template name (slug)
- Subject line (supports SmartCodes)
- Body editor with SmartCode inserter button
- Settings panel: Category, Status, Trigger dropdown
- Attachments panel: Media picker + PDF generator dropdown
- Preview button (renders with dummy data)
- Send Test button (sends to current admin)

### Settings Page

- Default "From" name and email
- Layout file location override
- Log level settings

## Developer API

### Sending Emails

```php
// Simple send
ndmail_send('welcome-enrollment', [
    'user_id' => $userId,
    'enrollment_id' => $enrollmentId,
]);

// With recipient override
ndmail_send('password-reset', [
    'user_id' => $userId,
    'reset_key' => $key,
], ['to' => $customEmail]);

// Returns true|WP_Error
```

### Fluent Builder

```php
ndmail_template('welcome-enrollment')
    ->context(['user_id' => $userId, 'enrollment_id' => $enrollmentId])
    ->to($overrideEmail)
    ->cc($ccEmail)
    ->attach($extraFile)
    ->send();
```

### Extension Hooks

| Hook | Type | Purpose |
|------|------|---------|
| `ndmail_before_send` | filter | Modify email before sending |
| `ndmail_after_send` | action | Log, track, trigger follow-ups |
| `ndmail_smartcodes` | filter | Register smartcode categories |
| `ndmail_triggers` | filter | Register action triggers |
| `ndmail_pdf_generators` | filter | Register PDF generators |
| `ndmail_layout_paths` | filter | Add template lookup paths |

## Layout System

Default layout wrapper at `templates/emails/layout.php`. Lookup order:

1. Theme: `{stylesheet_directory}/views/emails/ndmail-layout.php`
2. Theme: `{template_directory}/views/emails/ndmail-layout.php`
3. Plugin: `netdust-mail/templates/emails/layout.php`

Layout receives `$content` (parsed body) and `$subject` variables.

## Logging

Uses `ntdst_log('mail')` for all logging:

- Successful sends
- Blocked emails (unparsed SmartCodes, missing PDF context)
- Trigger activations
- Errors

## Integration Example (Stride)

```php
// In stride-core plugin-config.php or a dedicated StrideMailIntegration service

// Register SmartCodes
add_filter('ndmail_smartcodes', function($codes) {
    $codes['enrollment'] = [
        'label' => 'Inschrijving',
        'codes' => [
            'course_name'  => ['label' => 'Cursus naam', 'callback' => [StrideSmartCodes::class, 'courseName']],
            'edition_date' => ['label' => 'Editie datum', 'callback' => [StrideSmartCodes::class, 'editionDate']],
            'price'        => ['label' => 'Prijs', 'callback' => [StrideSmartCodes::class, 'price']],
        ],
    ];
    return $codes;
});

// Register Triggers
add_filter('ndmail_triggers', function($triggers) {
    $triggers['stride/enrollment/confirmed'] = [
        'label' => 'Inschrijving bevestigd',
        'context' => ['user_id', 'enrollment_id', 'edition_id'],
    ];
    $triggers['stride/quote/created'] = [
        'label' => 'Offerte aangemaakt',
        'context' => ['user_id', 'quote_id'],
    ];
    return $triggers;
});

// Register PDF Generators
add_filter('ndmail_pdf_generators', function($generators) {
    $generators['stride_quote'] = [
        'label' => 'Offerte PDF',
        'callback' => fn($id) => QuoteService::generatePdf($id),
        'context_key' => 'quote_id',
    ];
    return $generators;
});
```
