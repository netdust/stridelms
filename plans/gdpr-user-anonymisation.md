# GDPR User Anonymisation

**Status:** plan — not yet implemented
**Scope:** §D D-G1 + D-G2 + D-G3 from `docs/LAUNCH-CHECKLIST.md`
**Author:** Stefan + Claude, 2026-05-14

---

## Problem

Belgian training records have a 7–10 year retention requirement. When a user is deleted via `wp_delete_user()` today:

- `wp_users` row gone
- Registrations in `stride_vad_registrations` keep `user_id` pointing to a non-existent row → orphan
- Quotes, certificates, attendance records lose their owner
- `EditionRegistrationMetabox` silently `continue`s on missing `$user` (line 151–153) → admin sees "11 registered" badge but only 1 row
- Excel exporter writes `Gebruiker #2223401` placeholders
- LearnDash access for that user_id stays "granted" forever
- GDPR audit trail is broken: we can't prove the user existed and consented

We need **anonymisation, not deletion**: strip PII, keep historical foreign-key integrity, mark the row so admin tools render it differently.

## Decisions made before writing the plan

1. **WP's `delete_user` action fires *before* deletion but cannot prevent it** — there's no filter. So we replace the WP admin Delete-user row action with a Stride "Anonimiseer" action that calls `anonymise()` instead.
2. **Keep `wp_delete_user()` as the admin escape hatch.** Don't block it globally. Admins should still be able to nuke a spam/test account that has no registrations. The Stride action is the *default* path; nuclear delete stays available behind the standard WP capability.
3. **Keep the `wp_users` row.** Don't reassign foreign keys to a placeholder user — that loses per-registration historical attribution. Anonymise the PII in place.
4. **`display_name` becomes `Verwijderde gebruiker #N`** where N is the user ID. Stable, recognisable, sortable.
5. **`_stride_anonymised_at`** is the marker meta. Presence = anonymised; value = unix timestamp.
6. **No "un-anonymise."** One-way. Backups handle the "oh shit" case.
7. **CLI is for the dev environment now, prod safety net later** — `wp stride anonymise-orphans` finds registrations across both active FK tables (`stride_vad_registrations`, `stride_vad_attendance`) where `user_id` doesn't resolve in `wp_users`, and flags them for admin review.

## Acceptance criteria

- [ ] `UserLifecycleService::anonymise(int $userId): bool|WP_Error` strips PII and sets the marker meta. Idempotent.
- [ ] WP admin Users-list "Verwijder" row action replaced with a Stride "Anonimiseer" action that calls `anonymise()`.
- [ ] Standard `wp_delete_user()` blocked for non-administrators via capability filter; admins keep the option for true deletion (e.g. spam accounts with no registrations).
- [ ] `delete_user` action hooked too: even if something else deletes a user (REST API, WP-CLI, plugin), we anonymise first.
- [ ] `EditionRegistrationMetabox` renders anonymised users as a greyed-out row with "Verwijderd op YYYY-MM-DD" subtitle, no action buttons.
- [ ] Excel exporters use `display_name` (which is now the anonymised label) — no extra code needed if they already do.
- [ ] `wp stride anonymise-orphans` finds orphan registrations and reports a dry-run summary by default; `--commit` applies.
- [ ] Unit tests for the service's anonymisation logic.
- [ ] Integration test that walks the full flow: create user with registration, anonymise, verify registration intact + PII gone + metabox renders correctly.

## NTDST classification

| Component | Type | Why |
|---|---|---|
| `UserLifecycleService` | **New service** | Adds the `delete_user` hook + the admin row-action filter at boot. Has feature-level controllability. Listed in `plugin-config.php`. |
| `Modules/User/UserLifecycleService.php` | Class location | Lives with the other User-module services (`ProfileTypeService`). |
| `AnonymiseUsersCommand` | **WP-CLI command** | One-line registration in service `init()`. Standard `\WP_CLI::add_command()` pattern. |
| `EditionRegistrationMetabox.php` | **Existing class, modify** | Replace the silent `continue` with anonymised-row rendering. |
| Tests | **Unit + integration** | PHPUnit only — no Codeception acceptance yet for this flow. |

## Files changed

| File | Action | Purpose |
|---|---|---|
| `Modules/User/UserLifecycleService.php` | Create | Anonymisation logic + hooks |
| `Modules/User/Admin/AnonymiseUsersCommand.php` | Create | WP-CLI command |
| `Modules/Edition/Admin/EditionRegistrationMetabox.php` | Modify | Render anonymised users |
| `plugin-config.php` | Modify | Register `UserLifecycleService` |
| `tests/Unit/UserLifecycleServiceTest.php` | Create | Stripping logic unit tests |
| `tests/Integration/UserLifecycleServiceIntegrationTest.php` | Create | End-to-end flow |
| `docs/LAUNCH-CHECKLIST.md` | Modify | Mark D-G1/G2/G3 done at end |

## PII inventory — verified by survey across 104 dev users (2026-05-14)

**Survey method:** counted unique users per usermeta key in dev DB. Filtered to keys appearing on ≥2 users to eliminate single-user noise (admin guinea-pig account had 25+ extra keys nobody else has — duplicate billing/invoice schemes from old form testing).

### Core WP fields (`wp_users` row, updated via `wp_update_user()`)

| Field | New value | Why |
|---|---|---|
| `user_email` | `anonymised+{userId}@deleted.local` | Unique constraint + recognisable pattern |
| `user_login` | `anonymised_{userId}` | Unique constraint; effectively disables login |
| `user_url` | `''` | Clear |
| `display_name` | `Verwijderde gebruiker #{userId}` | Renders everywhere user names are shown |
| `user_nicename` | `anonymised-{userId}` | URL-safe variant of login |
| `user_pass` | `wp_generate_password(64)` | Effectively disables login |
| `user_activation_key` | `''` | Clear |
| `user_registered` | unchanged | Historical fact, no PII |

### Stride PII usermeta (clear via `delete_user_meta()`)

Verified present on multiple real users:

| Meta key | Users with it | Group |
|---|---|---|
| `first_name` | 104 (all) | WP profile |
| `last_name` | 104 | WP profile |
| `nickname` | 104 | WP profile |
| `description` | 104 | WP profile (bio) |
| `phone` | 87 | Personal |
| `_stride_profile_type` | 68 | Personal classifier |
| `organisation` | 30 | Personal — employer |
| `department` | 30 | Personal — within employer |
| `billing_company` | 30 | Billing — invoice entity |
| `billing_address_1` | 30 | Billing — street |
| `billing_postcode` | 30 | Billing — postal code |
| `billing_city` | 30 | Billing — city |
| `invoice_email` | 30 | Billing — accounting contact |
| `billing_vat` | 29 | Billing — VAT number |
| `gln_number` | 29 | Billing — GLN |

**That's it.** 15 keys total (including `_stride_profile_type` which is a classification, not PII per se but still user-specific). No `mobile`, no `billing_phone`, no `invoice_address`, no `address_line_1` on real users — those are artefacts on the admin account from old forms.

### Marker meta to ADD

- `_stride_anonymised_at = time()` — presence indicates anonymised
- `_stride_original_email = hash('sha256', $oldEmail)` — optional audit trail (one-way hash, not the email itself)

### Preserve unchanged (operational, no PII)

- `stride_capabilities` (role assignments — but downgrade to `subscriber` via `$user->set_role()`)
- `stride_user_level`
- All foreign-key user references in `stride_vad_registrations`, `stride_vad_trajectory_enrollments`, `stride_vad_attendance`, `vad_quote` postmeta, certificate postmeta — the whole point is to keep these working

## Tables with `user_id` FK (verified 2026-05-14)

| Table | Status | GDPR action |
|---|---|---|
| `stride_vad_registrations` | ✅ **Active** (211 rows, 191 users). Unified table for both edition AND trajectory enrollments — `trajectory_id` is nullable. New trajectory enrollments land here (3 rows already), not in the legacy table. | **Primary GDPR target.** Orphan check + scan in CLI. |
| `stride_vad_attendance` | ✅ **Active** | **Primary GDPR target.** Orphan check + scan in CLI. |
| `stride_vad_trajectory_enrollments` | ⚠️ **Legacy** — 2 rows, 0 writes from current code. `AdminAPIController.php:1655` still reads it for the admin trajectory dashboard counts, but `RegistrationRepository` (the unified design) writes to `stride_vad_registrations` instead. | **Skip for GDPR.** Tracked separately as a post-launch table-retirement task (#21). Anonymising rows here would mask an existing data-staleness bug, not solve it. |
| `stride_vad_session_registrations` | ❌ **Dead** (0 rows, 0 code refs) | **Skip.** Drop post-launch (also part of task #21). |

Quote post meta also references users (`_stride_user_id`, `_stride_invoice_user_id`) — those stay intact; the display layer reads the anonymised user's display_name automatically.

## Implementation steps

### Step 1 — `UserLifecycleService` skeleton

```php
namespace Stride\Modules\User;

final class UserLifecycleService implements \NTDST_Service_Meta
{
    public const META_ANONYMISED_AT = '_stride_anonymised_at';

    public function __construct() {
        $this->init();
    }

    private function init(): void
    {
        if (is_admin()) {
            add_filter('user_row_actions', [$this, 'replaceRowActions'], 10, 2);
            add_action('admin_post_stride_anonymise_user', [$this, 'handleAdminAnonymisePost']);
        }
        add_action('delete_user', [$this, 'onDeleteUser'], 10, 3);
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('stride anonymise-orphans', Admin\AnonymiseUsersCommand::class);
        }
    }

    public function anonymise(int $userId): bool|WP_Error
    {
        $user = get_userdata($userId);
        if (!$user) {
            return new WP_Error('user_not_found', "User $userId does not exist");
        }
        if ($this->isAnonymised($userId)) {
            return true; // idempotent
        }

        // ... strip PII (detailed in step 2) ...
        update_user_meta($userId, self::META_ANONYMISED_AT, time());

        do_action('stride/user/anonymised', $userId);
        return true;
    }

    public function isAnonymised(int $userId): bool
    {
        return (bool) get_user_meta($userId, self::META_ANONYMISED_AT, true);
    }
}
```

### Step 2 — PII stripping inside `anonymise()`

Three groups:
- **`wp_update_user()`** for the `wp_users` row fields (`user_email`, `user_login`, `user_url`, `display_name`, `user_nicename`, `user_pass`)
- **`delete_user_meta()`** for the cleared meta keys
- **`wp_update_user()`** with `role => 'subscriber'` to demote

The full PII key list lives as a class constant `PII_META_KEYS` so the test can assert nothing was missed.

### Step 3 — UI replacement: row action

In `replaceRowActions(array $actions, WP_User $user)`:
- Remove `delete` action
- Add `anonymise` action linking to `admin-post.php?action=stride_anonymise_user&user=ID&_wpnonce=…`
- If user already anonymised, show "Reeds geanonimiseerd op YYYY-MM-DD" plain text instead of an action

### Step 4 — `delete_user` hook (audit trail, not blocking)

`onDeleteUser($userId, $reassign, $user)` fires when an admin clicks the standard "Delete" action (still available as the nuclear option per decision #2). This hook does NOT anonymise — admin chose nuclear delete on purpose. Instead it:

- Writes an audit log entry: `stride/user/hard-deleted` with the user's last known display_name, email hash, and the count of FK references that just orphaned
- Leaves orphan FK references in our 3 tables (`stride_vad_registrations`, `stride_vad_trajectory_enrollments`, `stride_vad_attendance`) — these surface in the `wp stride anonymise-orphans` report so admin can clean up later

**Why not block delete?** Per decision #2: admin nuking a spam test account with no registrations is a legitimate flow. The Stride anonymise action is the *default* for users with data; the WP delete stays for the edge case.

### Step 5 — `EditionRegistrationMetabox` rendering

Replace lines 150–153:

```php
$userId = (int) $registration['user_id'];
$user = $users[$userId] ?? null;
$isAnonymised = $user ? get_user_meta($userId, '_stride_anonymised_at', true) : null;
$isOrphan = !$user;
// Render a row regardless, with greyed-out styling + no actions when anonymised/orphan
```

For anonymised users: row with `display_name` (now `Verwijderde gebruiker #N`), grey text, "Geanonimiseerd op DD/MM/YYYY" subtitle, no action buttons, no impersonate link.

For orphan users (deleted without anonymisation): "Gebruiker #N (verwijderd)" with `_stride_user_orphaned` styling. Same visual treatment, different reason.

### Step 6 — `wp stride anonymise-orphans` command

Scans `stride_vad_registrations` and `stride_vad_attendance` for `user_id` values that don't resolve in `wp_users`:

```bash
# Dry-run (default)
wp stride anonymise-orphans
# → Found 5 orphan references:
# →   stride_vad_registrations: reg #234 user_id=8821 (deleted)
# →   stride_vad_registrations: reg #239 user_id=8821 (deleted)
# →   stride_vad_attendance: 3 rows for user_id=8821
# → 1 distinct deleted user referenced. Run with --commit to flag rows as orphaned.

wp stride anonymise-orphans --commit
# → Flagged 5 rows with notes='orphan: user deleted on {date}'.
```

Implementation: LEFT JOIN each FK table against `wp_users` for missing rows. Don't delete the registration rows — admin training records are 7-10 year retention, even orphaned. Just flag them so reports/UI render the "Gebruiker #N (verwijderd)" state.

**Not scanned:** `stride_vad_trajectory_enrollments` (legacy table, post-launch retirement — task #21) and `stride_vad_session_registrations` (dead).

### Step 7 — Tests

**Unit** — `UserLifecycleServiceTest`:
- `anonymise()` clears all `PII_META_KEYS`
- `anonymise()` sets `_stride_anonymised_at`
- `anonymise()` is idempotent (second call returns true, no double-strip)
- `anonymise()` on non-existent user returns `WP_Error('user_not_found')`
- `isAnonymised()` reflects state

**Integration** — `UserLifecycleServiceIntegrationTest`:
- Create user with PII + registration → anonymise → registration intact, user PII gone, display_name = "Verwijderde gebruiker #N"
- `EditionRegistrationMetabox::renderRegistrationsTable()` ob_get_clean check: anonymised user renders as greyed row with the right copy
- WP admin: `delete_user_form` capability check blocks delete action for editor role

### Step 8 — Mark §D.3 GDPR block done

Update `docs/LAUNCH-CHECKLIST.md`:
- [x] D-G1 — `UserLifecycleService::anonymise()` exists
- [x] D-G2 — metabox renders anonymised users
- [x] D-G3 — `wp stride anonymise-orphans` works in dev

## Risks & mitigations

| Risk | Mitigation |
|---|---|
| `wp_update_user()` triggers email "your account was changed" notifications | Wrap call in `add_filter('send_email_change_email', '__return_false')` and `add_filter('send_password_change_email', '__return_false')` for the duration of the anonymise call |
| Anonymising the admin user by accident | `anonymise()` refuses to operate on users with `manage_options` capability |
| Anonymised user's certificates from LearnDash still show their old name | LearnDash certificates use `display_name` at render time → our anonymised name shows up. Verify with a test certificate. |
| Existing orphan registrations in dev (per the LAUNCH-CHECKLIST note about edition 13234) | The CLI command's first run cleans these up |
| Reactivating a "deleted" user via WP admin would fail because email/login are scrambled | Document: anonymisation is one-way. To "restore," create a fresh account and use admin-side post reassignment. |

## Out of scope (post-launch)

- Bulk anonymise UI (right now: row-by-row)
- "Right to be forgotten" self-service request flow (right now: admin-driven)
- Anonymisation of historical Stride v3 records (separate migration)
- Backup-restore policy for accidental anonymisation

## Effort estimate

| Step | LOC |
|---|---|
| Step 1 — service skeleton | 60 |
| Step 2 — PII stripping (15 keys, not 25 as first thought) | 50 |
| Step 3 — row action UI | 40 |
| Step 4 — delete_user hook (audit log only, not blocking) | 15 |
| Step 5 — metabox rendering | 40 |
| Step 6 — CLI command (scans 3 tables) | 70 |
| Step 7 — tests (unit + integration) | 180 |
| **Total** | **~455 LOC** |

Smaller than the first draft (505 LOC) because:
- PII inventory shrank from 25 keys to 15 (survey of real users, not admin-only)
- `delete_user` hook simplified from "anonymise + audit" to "audit only" (decision #2)
