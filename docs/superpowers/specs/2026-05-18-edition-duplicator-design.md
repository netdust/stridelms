# Edition Duplicator — Design

**Date:** 2026-05-18
**Status:** Approved, pending implementation plan
**Scope:** Phase 1 polish (ship-mode)

## Goal

Let an admin one-click duplicate a `vad_edition` to set up a new edition of an existing course quickly: same form, same pricing, same session structure — only dates need editing.

## Out of scope

- Trajectory duplication — deferred until trajectories are production-ready
- Offerte (quote) duplication — deferred; the real workflow is "create new quote for user", not "clone"
- Moving enrollment-form schema from edition to course — captured as `[[project_form_on_course_deferred]]` for post-launch

## User flow

1. Admin opens **Edities** list (`edit.php?post_type=vad_edition`)
2. Hovers a row → sees standard WP row actions (Bewerken / Snel bewerken / Verwijderen) **+ new "Dupliceren"**
3. Clicks Dupliceren
4. Lands directly on the new draft's edit screen (`post.php?post={new_id}&action=edit`)
5. Title shows `{original title} (kopie)` — visible signal to rename
6. Admin adjusts dates on sessions, renames title, publishes

## Capability gate

Standard `edit_post` on the source edition. No new capability introduced.

## What gets copied — two rules

### Rule A: copy everything by default

Snapshot the source edition's complete post-meta map and write every key to the new post. This is the contract. We do **not** maintain a "fields to copy" list because:

- Any field added to the enrollment-form metabox today lands on the copy automatically
- Any meta key a future module adds (new questionnaire type, new completion flag, new pricing modifier) lands on the copy automatically
- The duplicator stays maintenance-free as the CPT grows

### Rule B: explicit reset list

After Rule A, the duplicator overrides a small, well-named set of keys/fields:

| Meta key / field | New value | Reason |
|---|---|---|
| `notes` | `[]` | Live-cohort operational notes don't apply |
| `_enrollment_count` (if present) | unset | Stale; rebuilds from real registrations |
| `selection_open` | `false` | Don't open session-selection prematurely |
| `documents` | `[]` | Edition-level documents are session-bound for the live cohort; course-level docs survive via the preserved course link |
| post `post_status` | `draft` | Never publish a copy automatically |
| post `post_title` | `{original} (kopie)` | Visible rename signal |
| post `post_date` / `post_date_gmt` | now | Fresh creation timestamp |
| post `post_name` (slug) | auto-regenerated | WP default behaviour for new drafts |

Everything not on this list is preserved verbatim.

### Explicitly preserved (sample, not exhaustive)

- `enrollment_form` — which form template to render. **This is the form-fields linkage protected by Rule A.**
- All `requires_*` flags (`requires_approval`, `requires_questionnaire`, `requires_documents`, `requires_session_selection`, `post_requires_evaluation`, `post_requires_documents`, `post_requires_approval`)
- `completion_mode`, `completion_threshold`
- Pricing (`price`, `price_non_member`, member modifiers)
- Linked `sfwd-courses` LearnDash course ID
- Taxonomy assignments (`stride_format` etc.) — copied via `wp_set_object_terms`
- Any custom meta key added to the edition (whether via metabox or programmatically)

### Sessions

Per source session, create one new `vad_session` post:

| Field | Behaviour |
|---|---|
| Title, notes, time slots (`start_time`, `end_time`), slot config (`_ntdst_session_slots`), capacity, any other meta | Copied verbatim (Rule A applied per-session) |
| `date` | Reset to today's date |
| `parent_edition_id` (or equivalent linkage meta) | Points at the new edition, not the source |
| Attendance records (`vad_session_attendance` rows) | **Not** copied |
| `post_status` | `publish` (or whatever the source session was — sessions don't have a "draft" workflow) |

Result: same number of sessions, identical structure, all on today. Admin spreads them out in one pass after duplicating.

## What is NEVER touched

- Source edition's registrations (`wp_vad_registrations`) — read-only
- Source edition's session attendance — read-only
- Source edition's notifications, audit log entries — read-only
- Source edition itself — read-only
- LearnDash course progress for any user — irrelevant to duplication

## Implementation shape

Following `ntdst-architecture` conventions.

### New service

**File:** `web/app/mu-plugins/stride-core/Modules/Edition/EditionDuplicator.php`

**Namespace:** `Stride\Modules\Edition`

**Class skeleton:**

```php
final class EditionDuplicator implements \NTDST_Service_Meta
{
    public static function metadata(): array
    {
        return [
            'name' => 'Edition Duplicator',
            'description' => 'Duplicates an edition (post + meta + sessions, with safe resets)',
            'priority' => 50,
        ];
    }

    public function __construct(
        private readonly EditionRepository $editions,
        private readonly SessionRepository $sessions,
    ) {
        $this->init();
    }

    private function init(): void
    {
        add_filter('post_row_actions', [$this, 'addDuplicateRowAction'], 10, 2);
        add_action('admin_action_stride_duplicate_edition', [$this, 'handleDuplicate']);
    }

    public function addDuplicateRowAction(array $actions, \WP_Post $post): array;
    public function handleDuplicate(): void;
    public function duplicate(int $sourceEditionId): int|\WP_Error;
}
```

Public `duplicate(int): int|\WP_Error` is the testable seam — handler is the WP-glue.

### Service registration

One line added to `web/app/mu-plugins/stride-core/plugin-config.php`'s `services` array:

```php
\Stride\Modules\Edition\EditionDuplicator::class,
```

No new bindings.

### Row-action flow

1. `post_row_actions` filter → inject `Dupliceren` link only for `vad_edition` post type
2. Link URL: `admin.php?action=stride_duplicate_edition&edition_id={N}&_wpnonce={nonce}`
3. `admin_action_stride_duplicate_edition` hook → `handleDuplicate()`
4. Handler:
   - Verify nonce (`stride_duplicate_edition_{N}`)
   - Verify `current_user_can('edit_post', $sourceId)`
   - Call `$this->duplicate($sourceId)`
   - On success: `wp_safe_redirect(get_edit_post_link($newId, 'raw'))`
   - On error: redirect to list with `?stride_notice=duplicate_failed`; admin-notice hook renders the message

### Reset list implementation

The reset list lives as a class constant or static method so it's discoverable and testable:

```php
private const META_RESET = [
    'notes' => [],
    'documents' => [],
    'selection_open' => false,
];

private const META_UNSET = [
    '_enrollment_count',
];
```

The integration test asserts that every key in `META_RESET` ends up with the reset value, and every key in `META_UNSET` is absent from the copy.

## Tests

Per `testing-workflow`. Three layers.

### Unit — `tests/Unit/Edition/EditionDuplicatorTest.php`

- `duplicate()` returns new ID on success
- `duplicate()` returns `WP_Error` when source doesn't exist
- `duplicate()` returns `WP_Error` when source isn't a `vad_edition`
- Reset list applied: each `META_RESET` key has the reset value
- `META_UNSET` keys are absent from the copy
- Custom meta key (e.g. `arbitrary_form_field`) on source is present on copy — proves Rule A
- Title is `{source title} (kopie)`
- Status is `draft`
- Sessions: count matches, dates all set to today, attendance keys absent
- Capability denied → `WP_Error`

### Integration — `tests/Integration/Edition/EditionDuplicatorIntegrationTest.php`

Full WordPress, real DB:

- Create source edition with: published status, custom `enrollment_form` value, custom `arbitrary_pilot_field` meta, `notes` array with 2 entries, 3 sessions (one with `_ntdst_session_slots` config), 1 registration
- Call `duplicate()`
- Assert new edition: status=draft, title ends with "(kopie)", `enrollment_form` matches source, `arbitrary_pilot_field` present, `notes` is empty, course link preserved
- Assert new sessions: 3 created, all dates = today, `_ntdst_session_slots` preserved on the session that had one
- Assert registrations: zero rows in `wp_vad_registrations` for the new edition
- Assert source unchanged: still published, still has 1 registration, sessions still on original dates

### Acceptance — `tests/Acceptance/EditionDuplicateCest.php`

End-to-end browser:

- Admin logs in, opens Edities list
- Hovers a published edition → "Dupliceren" link visible
- Clicks it → lands on new draft's edit screen
- Title shows "(kopie)"
- Form-fields metabox shows the same fields as source (visible proof of Rule A)
- Sessions metabox shows N sessions all dated today

## File touches summary

**New:**
- `web/app/mu-plugins/stride-core/Modules/Edition/EditionDuplicator.php`
- `tests/Unit/Edition/EditionDuplicatorTest.php`
- `tests/Integration/Edition/EditionDuplicatorIntegrationTest.php`
- `tests/Acceptance/EditionDuplicateCest.php`

**Modified:**
- `web/app/mu-plugins/stride-core/plugin-config.php` — one line added to `services`

No CPT registration changes. No JS. No CSS. No DB migrations.

## Risks and mitigations

| Risk | Mitigation |
|---|---|
| Future meta key gets added that shouldn't be copied | Rule B reset list is the single source of truth. Adding a key to `META_RESET` or `META_UNSET` is one line. The integration test fails loudly if someone adds a clearly-not-copyable key (registration counts, etc.) and forgets to update the list — assuming they also extend the test. |
| `notes` is reset but admin wanted to keep them | Acceptable trade-off — they can copy-paste manually. The opposite default (preserve) silently leaks operational state. |
| Sessions all on today → admin forgets to spread them | Visible in the metabox, all 3 dates identical = obvious. Acceptable. |
| Slug collision on the copy | WP's `wp_insert_post` auto-appends `-2`, `-3` etc. on collision. No code needed. |
| User clicks Dupliceren on a `vad_session`, `vad_quote`, etc. by accident | Row action is filtered by `post_type === 'vad_edition'` — invisible elsewhere. Handler also rejects non-`vad_edition` sources. |

## Open questions

None. Ready to plan.
