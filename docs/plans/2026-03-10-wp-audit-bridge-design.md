# WPAuditBridge Design

**Date:** 2026-03-10
**Plugin:** ntdst-audit
**Purpose:** Make any WordPress site GDPR-auditable by logging all personal data processing events.

## Overview

A single service class `WPAuditBridge` inside the ntdst-audit plugin that hooks into WordPress core events and the WP Privacy API. Always on, no configuration. Follows the same bridge pattern as Stride's `AuditBridge`.

## Location

`web/app/plugins/ntdst-audit/src/Bridges/WPAuditBridge.php`

Registered in `plugin-config.php` alongside existing services.

## Events

### Authentication

| WP Hook | Action | Entity Type | Entity ID | Context |
|---|---|---|---|---|
| `wp_login` | `auth.login` | `user` | user ID | `user_login`, `ip_hash` |
| `wp_logout` | `auth.logout` | `user` | user ID | — |
| `wp_login_failed` | `auth.login_failed` | `user` | 0 | `user_login`, `ip_hash` |

### User Lifecycle

| WP Hook | Action | Entity Type | Entity ID | Context |
|---|---|---|---|---|
| `user_register` | `user.created` | `user` | new user ID | `roles` |
| `delete_user` | `user.deleted` | `user` | deleted user ID | `reassign_to` |
| `profile_update` | `user.profile_updated` | `user` | user ID | `changed_fields` (names only) |
| `set_user_role` | `user.role_changed` | `user` | user ID | `old_role`, `new_role` |

### User Meta (Personal Data Changes)

| WP Hook | Action | Entity Type | Entity ID | Context |
|---|---|---|---|---|
| `updated_user_meta` | `usermeta.updated` | `user` | user ID | `meta_key` |
| `deleted_user_meta` | `usermeta.deleted` | `user` | user ID | `meta_key` |

**Allowlisted meta keys only** (to avoid logging noisy internal meta):

```php
private const GDPR_META_KEYS = [
    'first_name', 'last_name', 'nickname', 'description',
    'billing_first_name', 'billing_last_name', 'billing_company',
    'billing_address_1', 'billing_address_2', 'billing_city',
    'billing_postcode', 'billing_country', 'billing_state',
    'billing_email', 'billing_phone', 'billing_vat',
    'shipping_first_name', 'shipping_last_name', 'shipping_company',
    'shipping_address_1', 'shipping_address_2', 'shipping_city',
    'shipping_postcode', 'shipping_country', 'shipping_state',
];
```

### WP Privacy API

| WP Hook | Action | Entity Type | Entity ID | Context |
|---|---|---|---|---|
| `wp_privacy_personal_data_export_file_created` | `privacy.export_created` | `privacy_request` | request ID | `request_email` |
| `wp_privacy_personal_data_erased` | `privacy.data_erased` | `privacy_request` | request ID | `request_email`, `items_removed`, `items_retained` |
| `user_request_action_confirmed` | `privacy.request_confirmed` | `privacy_request` | request ID | `action_name`, `request_email` |

### Admin Actions

| WP Hook | Action | Entity Type | Entity ID | Context |
|---|---|---|---|---|
| `updated_option` | `option.updated` | `option` | 0 | `option_name` |
| `activated_plugin` | `plugin.activated` | `plugin` | 0 | `plugin_file` |
| `deactivated_plugin` | `plugin.deactivated` | `plugin` | 0 | `plugin_file` |
| `switch_theme` | `theme.switched` | `theme` | 0 | `new_theme`, `old_theme` |

**Allowlisted options only:**

```php
private const SECURITY_OPTIONS = [
    'blogname', 'blogdescription', 'siteurl', 'home',
    'admin_email', 'users_can_register', 'default_role',
    'permalink_structure', 'blog_public',
    'wp_page_for_privacy_policy',
];
```

## Design Decisions

1. **No PII in the log** — log field names, never values. "first_name was changed" not "changed from John to Jane."
2. **IP hashing** — login events store `wp_hash($ip)` for pattern detection without storing raw IPs.
3. **Meta key allowlist** — hardcoded list of GDPR-relevant keys. All other meta changes are ignored.
4. **Option allowlist** — only security/privacy-relevant options. Ignores transients, caches, etc.
5. **entity_id = user ID** for user events, `0` for system-level events (plugins, themes, options).
6. **Always on** — no settings, no toggles. Registered in `plugin-config.php`.

## Class Structure

```
src/Bridges/WPAuditBridge.php  (~120 lines)
├── implements NTDST_Service_Meta
├── __construct() — resolves AuditService from container
├── init() — registers all WP hooks
│
├── Authentication:
│   ├── onLogin(string $userLogin, WP_User $user)
│   ├── onLogout(int $userId)
│   └── onLoginFailed(string $userLogin)
│
├── User lifecycle:
│   ├── onUserCreated(int $userId)
│   ├── onUserDeleted(int $userId, ?int $reassignTo)
│   ├── onProfileUpdated(int $userId, WP_User $oldUser)
│   └── onRoleChanged(int $userId, string $newRole, array $oldRoles)
│
├── User meta:
│   ├── onUserMetaUpdated(int $metaId, int $userId, string $metaKey)
│   └── onUserMetaDeleted(array $metaIds, int $userId, string $metaKey)
│
├── WP Privacy API:
│   ├── onPrivacyExportCreated(int $requestId)
│   ├── onPrivacyDataErased(int $requestId)
│   └── onPrivacyRequestConfirmed(int $requestId)
│
├── Admin actions:
│   ├── onOptionUpdated(string $option, mixed $oldValue, mixed $newValue)
│   ├── onPluginActivated(string $plugin)
│   ├── onPluginDeactivated(string $plugin)
│   └── onThemeSwitched(string $newTheme, WP_Theme $oldTheme)
│
└── Constants:
    ├── GDPR_META_KEYS — allowlist
    └── SECURITY_OPTIONS — allowlist
```

## Registration

Add to `plugin-config.php`:

```php
return [
    'services' => [
        \NTDST\Audit\AuditService::class,
        \NTDST\Audit\Bridges\WPAuditBridge::class,  // ← new
        \NTDST\Audit\Admin\AdminController::class,
        \NTDST\Audit\Admin\APIController::class,
    ],
];
```

## What This Does NOT Cover

- Cookie consent banners (use Complianz or similar)
- Privacy policy generation
- User-initiated data export UI (WordPress core handles this at Tools > Export Personal Data)
- Consent management

The WPAuditBridge covers the **accountability** requirement (Article 5(2)) — proving who accessed/modified/exported/erased personal data, and when.
