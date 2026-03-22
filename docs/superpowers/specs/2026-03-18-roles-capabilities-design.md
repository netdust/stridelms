# Stride Roles & Capabilities Design

**Date:** 2026-03-18
**Status:** Draft
**Scope:** Admin-side authorization only. Frontend role differentiation deferred.

---

## Problem

Stride's admin access is binary: either full WordPress administrator or nothing. There's no middle ground for VAD staff who need to manage training operations (editions, attendance, enrollments) without system-level access (plugins, settings, user management).

## Roles

| Role | Slug | Purpose |
|------|------|---------|
| **Administrator** | `administrator` | Full system access — Stride + WordPress |
| **Training Coordinator** | `stride_coordinator` | Full Stride management, no WordPress settings/plugins |
| **Supervisor** | `stride_supervisor` | Read-only: view editions, stats, reports |
| **Partner** | `partner` (unchanged) | API-only, company-scoped |
| **Subscriber** | `subscriber` (unchanged) | Student, frontend only |

Training coordinators are VAD staff members. Supervisors handle in-person courses and external speakers/trainers — both need read-only access to editions, stats, and reports.

**Excluded roles:** LearnDash's `group_leader` (instructor) is intentionally not granted `stride_view`. Instructors do not access the Stride admin dashboard. This can be revisited if needed.

## Capabilities

Two Stride-specific capabilities:

| Capability | Purpose |
|------------|---------|
| `stride_manage` | Full Stride operations: mutations, approvals, enrollment management |
| `stride_view` | Read-only Stride access: view dashboard, editions, stats, reports |

Settings pages remain gated by WordPress's `manage_options` — that's system-level admin territory.

### Capability Matrix

| Capability | Administrator | Coordinator | Supervisor |
|------------|:---:|:---:|:---:|
| `stride_manage` | yes | yes | — |
| `stride_view` | yes | yes | yes |
| `manage_options` | yes | — | — |
| `edit_posts` | yes | yes | — |
| `edit_others_posts` | yes | yes | — |
| `publish_posts` | yes | yes | — |
| `delete_posts` | yes | yes | — |
| `upload_files` | yes | yes | — |
| `read` | yes | yes | yes |

**Trade-off:** Coordinators receive `edit_others_posts` because Stride CPTs (editions, quotes, vouchers, trajectories) use the default `post` capability type. This also grants access to regular blog posts. Acceptable for now — custom CPT capability types can be introduced later if needed.

**Trade-off:** Coordinators see the full WordPress admin sidebar (Posts, Pages, Media). WordPress admin menu cleanup via `remove_menu_page()` is deferred to a future iteration.

## Implementation

### Role & Capability Registration

In `stride-core.php`, alongside existing partner role registration. Uses a version option to support re-registration when capabilities change:

```php
$strideRolesVersion = 1;

add_action('init', function () use ($strideRolesVersion): void {
    $currentVersion = (int) get_option('stride_roles_version', 0);

    if ($currentVersion < $strideRolesVersion) {
        // Remove existing roles so capability changes are applied
        remove_role('stride_coordinator');
        remove_role('stride_supervisor');

        // Re-register with current capabilities
        add_role('stride_coordinator', 'Training Coordinator', [
            'read'              => true,
            'edit_posts'        => true,
            'edit_others_posts' => true,
            'publish_posts'     => true,
            'delete_posts'      => true,
            'upload_files'      => true,
            'stride_manage'     => true,
            'stride_view'       => true,
        ]);

        add_role('stride_supervisor', 'Supervisor', [
            'read'        => true,
            'stride_view' => true,
        ]);

        // Ensure administrator has Stride capabilities
        $admin = get_role('administrator');
        if ($admin) {
            $admin->add_cap('stride_manage');
            $admin->add_cap('stride_view');
        }

        update_option('stride_roles_version', $strideRolesVersion);
    }
}, 1);
```

Bump `$strideRolesVersion` whenever the capability set changes during development. Existing installations pick up changes automatically.

### Files Changed

| File | Change |
|------|--------|
| `stride-core.php` | Register capabilities + roles with version-gated re-registration |
| `Admin/AdminDashboardService.php` | Change `CAPABILITY` from `edit_others_posts` to `stride_view`; add `canManage` to `StrideConfig` in `enqueueAssets()` |
| `Admin/AdminAPIController.php` | Delete `canAccessAdmin()`, add `canViewAdmin()` (`stride_view`) and `canManageAdmin()` (`stride_manage`); update all 11 endpoint `permission_callback` references |
| `Admin/AdminGuidePage.php` | Change `CAPABILITY` from `edit_others_posts` to `stride_view` |
| `templates/admin/dashboard.php` | PHP guards on quick action links; Alpine `x-show="canManage"` on mutation buttons |
| `assets/js/admin-dashboard.js` | Add `canManage: StrideConfig.canManage` to Alpine component data |
| `scripts/seed.php` | Add coordinator and supervisor seed users |

### Files NOT Changed

| File | Reason |
|------|--------|
| `Admin/StrideSettingsService.php` | Stays `manage_options` — admin-only, correct |
| `Admin/FieldGroupSettingsPage.php` | Stays `manage_options` — admin-only, correct |
| `Edition/Admin/EditionAdminController.php` | Uses `edit_post`/`edit_posts` — WordPress handles via role capabilities |
| `Invoicing/Admin/QuoteAdminController.php` | Same — CPT-level checks handled by WP |
| `Invoicing/Admin/VoucherAdminController.php` | Same |
| `Trajectory/Admin/TrajectoryAdminController.php` | Same |
| `PartnerAPI/PartnerAPIController.php` | Untouched — partner auth is separate |

### API Endpoint Permission Mapping

| Endpoint | Method | Permission Check |
|----------|--------|-----------------|
| `/admin/stats` | GET | `canViewAdmin()` → `stride_view` |
| `/admin/editions` | GET | `canViewAdmin()` → `stride_view` |
| `/admin/editions/{id}` | GET | `canViewAdmin()` → `stride_view` |
| `/admin/editions/{id}/registrations` | GET | `canViewAdmin()` → `stride_view` |
| `/admin/course-tags` | GET | `canViewAdmin()` → `stride_view` |
| `/admin/quotes` | GET | `canViewAdmin()` → `stride_view` |
| `/admin/trajectories` | GET | `canViewAdmin()` → `stride_view` |
| `/admin/pending-approvals` | GET | `canViewAdmin()` → `stride_view` |
| `/admin/attendance` | POST | `canManageAdmin()` → `stride_manage` |
| `/admin/approve-registration` | POST | `canManageAdmin()` → `stride_manage` |
| `/admin/approve-post-course` | POST | `canManageAdmin()` → `stride_manage` |

### Dashboard Read-Only Logic

**`canManage` propagation:** Added to `StrideConfig` via `wp_localize_script` in `AdminDashboardService::enqueueAssets()`, consistent with the existing pattern for `apiUrl`, `nonce`, and `user`:

```php
// In enqueueAssets()
wp_localize_script('alpinejs', 'StrideConfig', [
    'apiUrl'    => rest_url('stride/v1'),
    'nonce'     => wp_create_nonce('wp_rest'),
    'user'      => [...],
    'canManage' => current_user_can('stride_manage'),  // NEW
]);
```

In `admin-dashboard.js`, initialize in the Alpine component:

```javascript
canManage: StrideConfig.canManage,
```

**Two types of permission guards in the template:**

1. **PHP guards** for static HTML links (rendered server-side):
   - "Nieuwe Edition" quick action link
   - "Nieuw Traject" quick action link
   - "Gebruikers" quick action link
   - "New Edition" button in editions list header
   ```php
   <?php if (current_user_can('stride_manage')): ?>
       <a href="post-new.php?post_type=vad_edition">Nieuwe Edition</a>
   <?php endif; ?>
   ```

2. **Alpine `x-show="canManage"` guards** for dynamic elements:
   - Approve/aftekenen buttons in Pending Approvals
   - Mark attendance toggle
   - "Bewerken in WP Admin" links in edition/quote/trajectory detail panels

This prevents supervisors from seeing non-functional action buttons or links that lead to WordPress permission error pages.

**Supervisor POST handling:** If a supervisor somehow triggers a POST endpoint (e.g., via dev tools), the REST API returns a `403 Forbidden` response with `rest_forbidden` error code. The existing Alpine error handling displays this to the user — no additional error handling needed.

### Seed Script Update

Add coordinator and supervisor seed users for development/testing:

```php
['login' => 'seed_coordinator', 'email' => 'seed_coordinator@seed.test', 'role' => 'stride_coordinator'],
['login' => 'seed_supervisor', 'email' => 'seed_supervisor@seed.test', 'role' => 'stride_supervisor'],
```

Password: `seedpass123` (same as all seed users).

## Testing Strategy

### Unit Tests

- Verify capability registration: administrator has `stride_manage` + `stride_view`
- Verify role creation: `stride_coordinator` has expected capabilities
- Verify role creation: `stride_supervisor` has only `stride_view` + `read`
- Verify `canViewAdmin()` returns true for admin, coordinator, and supervisor
- Verify `canManageAdmin()` returns true only for admin + coordinator
- Verify `canManageAdmin()` returns false for supervisor
- Verify version-gated re-registration applies capability changes

### Integration Tests

- Coordinator can access admin dashboard
- Coordinator can call all admin API endpoints (GET and POST)
- Coordinator cannot access settings pages (`manage_options`)
- Supervisor can access admin dashboard
- Supervisor can call GET admin API endpoints
- Supervisor gets 403 on POST admin API endpoints
- Subscriber cannot access admin dashboard at all
- Supervisor does not see quick action links (PHP-guarded)
- Supervisor does not see mutation buttons (Alpine-guarded)

### Manual Smoke Test

- Log in as `seed_coordinator` — see full dashboard with all action buttons, can mark attendance, cannot see Settings/Formuliervelden submenu
- Log in as `seed_supervisor` — see dashboard read-only, no action buttons, no quick action links, cannot submit mutations via API
- Log in as `seed_admin` — everything works as before, no regressions

## Future Considerations

- **Frontend role differentiation:** User dashboard may show different content for students vs speakers. Deferred — `stride_view` capability is ready to use when needed.
- **Fine-grained capabilities:** `stride_manage` can be split into `stride_manage_editions`, `stride_manage_enrollments`, etc. if different coordinators need different access levels.
- **Custom CPT capability types:** Register editions/quotes/etc. with their own capability types to decouple from `edit_posts`. Low priority unless coordinators editing blog posts becomes a problem.
- **WordPress admin menu cleanup:** Use `remove_menu_page()` to hide Posts/Pages/Media from coordinators. Deferred — acceptable trade-off for now.
- **Login redirect for supervisors:** Supervisors may land on `wp-admin/profile.php` after login rather than the Stride dashboard. A `login_redirect` hook could route them to `?page=stride-dashboard`. Not in scope for this iteration.
