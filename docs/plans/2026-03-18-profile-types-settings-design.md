# Profile Types & Settings Page Redesign

**Date:** 2026-03-18
**Status:** Approved
**Scope:** Admin settings page overhaul + profile types CRUD + registration/profile integration

---

## Overview

Transform the existing Stride Settings page (`stride-settings`) from a minimal WordPress Settings API form into an extensible Alpine.js-powered settings hub with tabbed navigation. Add "Profile Types" as the first major settings group — a CRUD manager where admins create/manage profile types that users select during registration and can change in their dashboard.

Profile types are a first-class user attribute used to differentiate content visibility, pricing (e.g., student discounts), and admin reporting.

---

## Data Model

### Profile Types (wp_options)

**Option key:** `stride_profile_types`

```php
[
    [
        'slug'        => 'apotheker',
        'label'       => 'Apotheker',
        'description' => 'Werkzaam in de apotheek',
        'color'       => '#3B82F6',
        'icon'        => 'users',
        'order'       => 0,
    ],
    [
        'slug'        => 'arts',
        'label'       => 'Arts',
        'description' => 'Huisarts of specialist',
        'color'       => '#10B981',
        'icon'        => 'heart',
        'order'       => 1,
    ],
]
```

**Per-type fields:**

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `slug` | string | yes | Auto-generated from label on creation. **Immutable after creation** — shown as read-only in edit mode. |
| `label` | string | yes | Display name (e.g., "Apotheker"). Min 1 char, max 50 chars. |
| `description` | string | no | Short description for admin context. Max 200 chars. |
| `color` | string | yes | Hex color, 6-digit (default: `#6B7280`) |
| `icon` | string | no | Icon name from theme's `icons/` directory (e.g., "users", "heart", "layers") |
| `order` | int | yes | Sort order for display |

### User Profile Type (usermeta)

**Meta key:** `_stride_profile_type`
**Value:** Array of slugs, e.g., `['apotheker']`

Stored as array to support future multi-select. UI enforces single selection for now. `getUserType()` returns only the first element.

---

## Component 1: Settings Page Overhaul

### What changes

`StrideSettingsService` is rewritten from inline WordPress Settings API to an Alpine.js tabbed application. Same menu slug (`stride-settings`), same position in admin menu.

### Architecture

```
stride-core/
├── Admin/
│   └── StrideSettingsService.php      → Slim orchestrator (menu, assets, template)
├── assets/
│   ├── css/admin/settings.css         → Settings page styles
│   └── js/admin/settings.js           → Alpine.js app with tab components
└── templates/
    └── admin/
        ├── settings.php               → Alpine.js shell with tab navigation
        └── settings/
            ├── tab-general.php        → URL slugs (migrated from current)
            └── tab-profile-types.php  → Profile type CRUD
```

### StrideSettingsService responsibilities

- Register admin submenu page (existing)
- Enqueue Alpine.js, CSS, JS assets on settings page hook
- Render template shell (`templates/admin/settings.php`)
- Handle AJAX saves via `ntdst/api_data/stride_save_settings` filter
- Pass current settings data to JS via `wp_localize_script()`

### Localized data shape

```javascript
window.strideSettings = {
    nonce: '...',
    tabs: {
        general: {
            trajectory_slug: 'trajecten',
            edition_slug: 'vormingen',
        },
        profileTypes: {
            types: [
                { slug: 'apotheker', label: 'Apotheker', description: '...', color: '#3B82F6', icon: 'users', order: 0, userCount: 12 },
                // ...
            ],
            availableIcons: ['users', 'heart', 'layers', 'mail', 'bell', 'check', 'info', 'file-text'],
        },
    },
};
```

User counts per type are pre-loaded to avoid additional requests for delete warnings.
Available icons are sourced from the theme's `icons/` directory.

### Tab system

The settings shell renders a left sidebar with tab links and a right content area. Tabs are defined as a PHP array in the template:

```php
$tabs = [
    'general'        => ['label' => 'Algemeen', 'icon' => 'settings'],
    'profile-types'  => ['label' => 'Profieltypes', 'icon' => 'users'],
];
```

Active tab tracked via Alpine.js state (`x-data="settingsApp()"`) and URL hash for bookmarkability (`#profile-types`). **Default tab** (no hash): "Algemeen".

Each tab's content is a PHP partial include — server-rendered with Alpine.js for interactivity.

### Save mechanism

**AJAX save** via `ntdstAPI.call('stride_save_settings', { tab, data })` — no full-page POST. This preserves Alpine state, scroll position, and active tab. Each tab has its own save button. Server-side handler validates and saves per-tab data:

- `tab: 'general'` → `update_option('stride_url_slugs', ...)` + flush rewrite rules
- `tab: 'profile-types'` → `update_option('stride_profile_types', ...)`

Success/error shown as inline admin notice within the tab. No page reload.

### Extensibility

Adding a new settings tab requires:
1. Add entry to `$tabs` array
2. Create `templates/admin/settings/tab-{slug}.php` partial
3. Add save handler case in `StrideSettingsService::handleSaveSettings()`

No architectural changes required.

---

## Component 2: Profile Types CRUD (Admin Tab)

### UI

The "Profieltypes" tab contains:

1. **Type list** — table rows showing: color badge, label, slug (muted), description, edit/delete actions
2. **Add button** — opens inline form row at bottom of table
3. **Inline editing** — click edit → row becomes editable fields (slug remains read-only)
4. **Delete** — trash icon with confirmation dialog (shows user count warning if applicable)
5. **Save button** — saves all types via AJAX

### Per-row fields (edit mode)

| Field | Input | Validation |
|-------|-------|------------|
| Label | text input | Required, min 1 char, max 50 chars |
| Slug | read-only text (auto-generated on creation only) | Unique, lowercase alphanumeric + hyphens |
| Description | text input | Optional, max 200 chars |
| Color | native color picker (`<input type="color">`) | Required, defaults to `#6B7280` |
| Icon | dropdown of icons from theme `icons/` dir | Optional |

### Slug generation

On **new type creation only**: slug auto-generated from label via JS `slugify()` (lowercase, replace spaces with hyphens, strip special chars). Admin can edit the slug **before first save**. Once saved, slug becomes immutable — displayed as read-only text in edit mode.

### Server-side sanitization

```php
private function sanitizeProfileTypes(array $types): array
{
    $seen = [];
    $sanitized = [];

    foreach ($types as $index => $type) {
        $slug = sanitize_title($type['slug'] ?? $type['label'] ?? '');
        $label = sanitize_text_field($type['label'] ?? '');

        // Skip entries with empty slug or label
        if (empty($slug) || empty($label)) {
            continue;
        }

        // Skip duplicate slugs (first one wins)
        if (isset($seen[$slug])) {
            continue;
        }
        $seen[$slug] = true;

        $sanitized[] = [
            'slug'        => $slug,
            'label'       => $label,
            'description' => sanitize_text_field($type['description'] ?? ''),
            'color'       => sanitize_hex_color($type['color'] ?? '') ?: '#6B7280',
            'icon'        => sanitize_text_field($type['icon'] ?? ''),
            'order'       => $index,
        ];
    }

    return $sanitized;
}
```

**Key protections:**
- Empty slugs/labels are silently dropped
- Duplicate slugs: first one wins, duplicates dropped
- Invalid hex colors fall back to `#6B7280`

### Deletion safeguard

When admin clicks delete on a type:
- User count is already available in pre-loaded data (`userCount` per type)
- If count > 0: confirmation dialog shows "{n} gebruikers hebben dit profieltype. Weet je zeker dat je dit wilt verwijderen?"
- If count = 0: simple confirmation "Weet je zeker dat je dit profieltype wilt verwijderen?"
- On confirm: type removed from Alpine state, saved on next "Opslaan" click
- Orphaned usermeta is acceptable — `ProfileTypeService::getUserType()` returns `null` for unknown slugs

---

## Component 3: ProfileTypeService

### Location

`stride-core/Modules/User/ProfileTypeService.php`

Registered in `plugin-config.php` as a service implementing `NTDST_Service_Meta`.

### Public API

```php
namespace Stride\Modules\User;

class ProfileTypeService implements \NTDST_Service_Meta
{
    // All defined profile types (from wp_options), ordered
    public function getTypes(): array;

    // Single type by slug, or null if not found
    public function getType(string $slug): ?array;

    // User's primary profile type (first in array), or null
    public function getUserType(int $userId): ?array;

    // All of user's profile types (for future multi-select)
    public function getUserTypes(int $userId): array;

    // Set user's profile type (replaces current value)
    // Returns false if slug is not a known type
    public function setUserType(int $userId, string $slug): bool;

    // Check if user has a specific type
    public function userHasType(int $userId, string $slug): bool;

    // Count users with a specific type (direct DB query for performance)
    public function countUsersWithType(string $slug): int;
}
```

### Implementation notes

- `getTypes()` caches the option value in a private property for the request lifecycle (avoid repeated `get_option()` calls)
- `setUserType()` validates slug against `getTypes()` before writing — returns `false` for unknown slugs
- `countUsersWithType()` uses `$wpdb->get_var()` directly against `wp_usermeta` for performance (no `get_users()`)
- `getUserType()` reads `_stride_profile_type` meta, returns first slug resolved against known types, or `null`

---

## Component 4: Registration Form Integration

### Changes to ntdst-auth

In `ntdst-auth/templates/pages/register.php`:

- Add a required `<select>` field after last_name, before consent checkboxes
- Label: "Ik ben een..." (with required indicator)
- Options populated server-side from `ProfileTypeService::getTypes()`
- Default placeholder option: "Selecteer je profieltype..." (value="", disabled, selected)
- Field name: `profile_type`
- Alpine.js validation: field must have a non-empty value before submit
- HTML `required` attribute as no-JS fallback

### Integration point

Profile type is stored via a hook on `ntdst_auth_registration_complete`:

1. `AuthHandler` passes `profile_type` through in the `$data` array to `RegistrationService::register()`
2. `RegistrationService` passes `$data` to `do_action('ntdst_auth_registration_complete', $userId, $data)`
3. `ProfileTypeService` hooks onto `ntdst_auth_registration_complete` and calls `setUserType()` if `$data['profile_type']` is present and valid

This avoids running `setUserType()` on the "already registered" email path (which doesn't fire the hook).

### Validation failure behavior

If the submitted `profile_type` slug doesn't match a known type (deleted between page load and submit, or tampered POST):
- Registration **proceeds normally** — the user is created without a profile type
- `setUserType()` returns `false`, no meta is written
- User can set their type later from the dashboard profile tab

### Graceful degradation

If `stride_profile_types` option is empty (no types configured yet), the registration form omits the profile type field entirely. No errors, no empty dropdowns. The hook listener is a no-op.

---

## Component 5: Dashboard Profile Tab

### Changes to tab-profiel.php

Add a **separate inline-edit section** below the Personal Information section and above Billing, using the same `inlineEditSection` Alpine pattern with its own edit/save controls.

**View mode:**
- Section title: "Profieltype"
- Shows current type label with color dot badge
- If no type set (or orphaned slug): shows "Niet ingesteld" in muted text

**Edit mode:**
- Dropdown `<select>` with all available types
- Current type pre-selected
- Placeholder: "Selecteer je profieltype..."

### Save flow

Uses existing `stride_update_profile` AJAX action:
- `form_type: 'profile_type'`
- `profile_type: 'apotheker'`

`ProfileHandler::handleUpdateProfile()` routes to new `updateProfileType()` method:
- Validates slug against known types via `ProfileTypeService::setUserType()`
- Returns success message "Profieltype bijgewerkt"
- Returns error if slug is invalid

### Graceful degradation

If no profile types are configured, the section is hidden entirely (same check as registration).

---

## File Inventory

### New files

| File | Purpose |
|------|---------|
| `stride-core/Modules/User/ProfileTypeService.php` | Service with public API |
| `stride-core/assets/css/admin/settings.css` | Settings page styles |
| `stride-core/assets/js/admin/settings.js` | Alpine.js settings app |
| `stride-core/templates/admin/settings.php` | Settings page shell template |
| `stride-core/templates/admin/settings/tab-general.php` | General settings tab |
| `stride-core/templates/admin/settings/tab-profile-types.php` | Profile types CRUD tab |

### Modified files

| File | Change |
|------|--------|
| `stride-core/Admin/StrideSettingsService.php` | Rewrite: Alpine.js shell, AJAX save, tab routing, profile type handling |
| `stride-core/plugin-config.php` | Register ProfileTypeService |
| `ntdst-auth/templates/pages/register.php` | Add profile_type select field |
| `ntdst-auth/src/Handlers/AuthHandler.php` | Pass profile_type in $data to register() |
| `stridence/templates/dashboard/tab-profiel.php` | Add profile type inline-edit section |
| `stride-core/Handlers/ProfileHandler.php` | Add updateProfileType() method |

---

## UI Language

All user-facing text in Dutch (nl_BE):

| Context | Text |
|---------|------|
| Admin tab label | "Profieltypes" |
| Admin add button | "Profieltype toevoegen" |
| Admin save button | "Opslaan" |
| Admin save success | "Instellingen opgeslagen" |
| Admin delete confirm (no users) | "Weet je zeker dat je dit profieltype wilt verwijderen?" |
| Admin delete confirm (with users) | "{n} gebruikers hebben dit profieltype. Weet je zeker dat je dit wilt verwijderen?" |
| Admin empty state | "Nog geen profieltypes aangemaakt" |
| Admin duplicate slug error | "Er bestaat al een profieltype met deze slug" |
| Registration label | "Ik ben een..." |
| Registration placeholder | "Selecteer je profieltype..." |
| Registration validation | "Kies een profieltype" |
| Dashboard section title | "Profieltype" |
| Dashboard empty state | "Niet ingesteld" |
| Dashboard save success | "Profieltype bijgewerkt" |
| Dashboard save error | "Ongeldig profieltype" |

---

## Out of Scope (Future)

- Content filtering by profile type (edition/trajectory visibility)
- Discount rules based on profile type
- Multi-select profile types (UI change only — data model supports it)
- Profile type statistics in admin dashboard
- Bulk-assign profile types to existing users
- Drag-to-reorder (can be added later with SortableJS — for now, order follows creation order)
