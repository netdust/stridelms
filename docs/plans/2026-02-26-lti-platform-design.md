# LTI Platform Feature Design

**Date:** 2026-02-26
**Status:** Approved
**Plugin:** netdust-lti

## Overview

Extend the netdust-lti plugin to support both **Tool Provider** (existing) and **Platform/Consumer** (new) roles. This enables any WordPress site with the plugin to launch courses from external LTI 1.3 tools.

### Primary Use Case

- 80% of usage: External non-WordPress LMS platforms launch stride courses (Tool Provider role)
- Platform feature enables local testing of the Tool Provider without external LMS setup
- Production-ready: works locally via DDEV and externally via HTTPS

## Architecture

### Data Layer: CPTs via Data Manager

Replace custom tables with WordPress CPTs managed by NTDST Data Manager.

```php
// lti_platform - External LMS platforms that launch OUR courses (Tool Provider role)
ntdst_data()->register('lti_platform', [
    'label'       => 'LTI Platforms',
    'public'      => false,
    'show_ui'     => true,
    'meta_prefix' => 'lti_',
    'fields'      => [
        'platform_id'    => ['type' => 'url', 'required' => true],   // Issuer URL
        'client_id'      => ['type' => 'text', 'required' => true],
        'deployment_id'  => 'text',
        'auth_endpoint'  => ['type' => 'url', 'required' => true],
        'token_endpoint' => ['type' => 'url', 'required' => true],
        'jwks_endpoint'  => ['type' => 'url', 'required' => true],
    ],
    'field_groups' => [
        'credentials' => ['title' => 'Platform Credentials', 'fields' => ['platform_id', 'client_id', 'deployment_id']],
        'endpoints'   => ['title' => 'Endpoints', 'fields' => ['auth_endpoint', 'token_endpoint', 'jwks_endpoint']],
    ],
    'use_tabs' => true,
]);

// lti_tool - External tools WE launch (Platform role)
ntdst_data()->register('lti_tool', [
    'label'       => 'LTI Tools',
    'public'      => false,
    'show_ui'     => true,
    'meta_prefix' => 'lti_',
    'fields'      => [
        'launch_url'    => ['type' => 'url', 'required' => true],
        'oidc_url'      => ['type' => 'url', 'required' => true],
        'jwks_url'      => ['type' => 'url', 'required' => true],
        'client_id'     => ['type' => 'text', 'required' => true],
        'deployment_id' => 'text',
    ],
    'field_groups' => [
        'credentials' => ['title' => 'Tool Credentials', 'fields' => ['client_id', 'deployment_id']],
        'endpoints'   => ['title' => 'Endpoints', 'fields' => ['launch_url', 'oidc_url', 'jwks_url']],
    ],
    'use_tabs' => true,
]);
```

### File Structure

```
netdust-lti/
├── src/
│   ├── Platform/                    # NEW: Platform/Consumer role
│   │   ├── PlatformRouter.php       # Registers /lti/platform/* endpoints
│   │   ├── OIDCInitiator.php        # Starts login flow, generates state
│   │   ├── JWTBuilder.php           # Creates signed id_token
│   │   └── AGSReceiver.php          # Receives grade passback
│   │
│   ├── LTI/                         # EXISTING: Tool Provider role
│   │   ├── NetdustLTITool.php
│   │   ├── EndpointRouter.php
│   │   └── DeepLinkHandler.php
│   │
│   ├── Admin/
│   │   ├── AdminPage.php            # Existing settings
│   │   └── LaunchTestPage.php       # NEW: Admin launch testing
│   │
│   ├── Shortcodes/
│   │   └── LtiLaunchShortcode.php   # NEW: Frontend embed
│   │
│   ├── Repositories/
│   │   ├── PlatformRepository.php   # Refactor to use Data Manager
│   │   ├── ToolRepository.php       # NEW: for lti_tool CPT
│   │   ├── NonceRepository.php      # Keep $wpdb (performance)
│   │   └── ContextRepository.php    # Refactor to post meta
│   │
│   └── Database/
│       └── Migrations.php           # Update for CPT migration
│
├── scripts/
│   └── migrate-lti-tables.php       # One-time migration
│
└── templates/
    └── admin/
        └── launch-test.php          # NEW: Test page template
```

## LTI Platform Flow

When this site (as Platform) launches an external tool:

```
┌─────────────────┐         ┌─────────────────┐
│  This Site      │         │  External Tool  │
│  (Platform)     │         │  (e.g. stride)  │
└────────┬────────┘         └────────┬────────┘
         │                           │
         │  1. OIDC Login Request    │
         │  POST /lti/login          │
         │──────────────────────────>│
         │                           │
         │  2. Redirect to Platform  │
         │  auth_endpoint + state    │
         │<──────────────────────────│
         │                           │
         │  3. Platform authenticates│
         │  (validates state,        │
         │   creates JWT)            │
         │                           │
         │  4. POST id_token + state │
         │  to /lti/launch           │
         │──────────────────────────>│
         │                           │
         │  5. Tool validates JWT,   │
         │  provisions user, enrolls │
         │  redirects to course      │
         │<──────────────────────────│
```

### Platform Endpoints

| Endpoint | Purpose |
|----------|---------|
| `/lti/platform/auth` | Receives OIDC redirect from tool, validates state, creates JWT |
| `/lti/platform/deep-link-return` | Receives selected courses from tool's Deep Link picker |
| `/lti/platform/grades` | AGS endpoint - receives scores from tool |

## Admin UI

### Launch Test Page

Location: `/wp-admin/admin.php?page=lti-launch-test`

```
┌─────────────────────────────────────────────────┐
│ LTI Launch Test                                 │
├─────────────────────────────────────────────────┤
│ Select Tool:  [Stride LMS        ▼]            │
│                                                 │
│ [Discover Courses via Deep Link]                │
│                                                 │
│ ─── OR Launch Manually ───                      │
│                                                 │
│ Course URL: [_________________________]         │
│                                                 │
│ Launch As:                                      │
│ ○ Current user (admin@example.com)              │
│ ○ Test learner (generates fake LTI user)        │
│                                                 │
│ [Launch in New Tab]                             │
└─────────────────────────────────────────────────┘
```

## Shortcode

```php
// Basic - launches specific course
[lti_launch tool="stride" course_id="123"]Bekijk cursus[/lti_launch]

// With styling
[lti_launch tool="stride" course_id="123" class="button primary"]Start Learning[/lti_launch]

// Deep Link discovery mode
[lti_launch tool="stride" mode="discover"]Browse Courses[/lti_launch]
```

Rendered as form with POST to `/lti/platform/launch`.

## Grade Passback (AGS)

### Storage

Grades stored as user meta on Platform side:

```php
_lti_grades = [
    'tool_42' => [                    // Tool post ID
        'course_123' => [             // Resource link ID
            'score'      => 0.85,     // 0.0 - 1.0
            'max_score'  => 1.0,
            'comment'    => 'Completed with 85%',
            'timestamp'  => '2026-02-26T15:00:00Z',
            'activity'   => 'Course completion',
        ],
    ],
];
```

### Receiver

```php
// AGSReceiver.php
public function receiveScore(WP_REST_Request $request): WP_REST_Response
{
    // 1. Validate JWT bearer token from tool
    // 2. Extract user ID from LTI claim (sub)
    // 3. Find local WP user by LTI ID mapping
    // 4. Store grade in user meta
    // 5. Fire action hook for integrations

    do_action('lti_grade_received', $userId, $toolId, $score, $activity);

    return new WP_REST_Response(['success' => true], 200);
}
```

## Migration Plan

### Tables to Migrate

| Old Table | New Location |
|-----------|--------------|
| `wp_netdust_lti_platforms` | `lti_platform` CPT |
| `wp_netdust_lti_contexts` | Post meta on `lti_platform` |

### Tables to Keep (Performance)

| Table | Reason |
|-------|--------|
| `wp_netdust_lti_nonces` | High-volume, short-lived, needs fast cleanup |
| `wp_netdust_lti_access_tokens` | Same reason |

### Migration Script

`scripts/migrate-lti-tables.php`:

1. Read existing platforms from `wp_netdust_lti_platforms`
2. Create `lti_platform` CPT posts with same data
3. Map old IDs to new post IDs
4. Migrate contexts to post meta
5. Verify data integrity
6. Drop old tables

## Technical Decisions

| Decision | Rationale |
|----------|-----------|
| celtic/lti library for both roles | Already in use, handles JWT/OIDC complexity |
| Data Manager CPTs | WordPress-native, auto-generated admin UI, familiar patterns |
| Keep nonces/tokens as tables | High-volume, performance-sensitive |
| Shortcode over block | Simpler, works in classic/Gutenberg, sufficient for use case |
| User meta for grades | Simple queries, no complex reporting needed |

## Out of Scope

- Course content preview before launch
- Multiple deployment IDs per tool
- Platform-side user role mapping
- Detailed grade analytics/reporting
