# Unified Expandable Course Card — Design

**Status:** Draft for approval.
**Author:** Brainstormed with Stefan, 2026-05-16.
**Goal:** Consolidate three near-duplicate course-card implementations (dashboard home, dashboard inschrijvingen tab, trajectory detail) into one expandable partial with mode-aware rendering.

---

## Why

Today, the same conceptual unit — "a course, possibly with the user's enrollment state" — renders three different ways:

| Location | File | Lines | Visual style |
|---|---|---|---|
| Dashboard home "Opleidingen" section | `templates/dashboard/tab-home.php:167-300` | ~130 | Flat card with inline session list and meta |
| Dashboard `Mijn opleidingen` tab | `templates/dashboard/tab-inschrijvingen.php` | 310 | 3 bespoke layouts (active editions / active online / completed) |
| Trajectory detail | `templates/trajectory/course-groups.php:47-148, 187-286` | ~200 of dup | Expandable card with thumb/title/chevron + embedded editions list |

User has explicitly asked for the trajectory card style to be the default course display on the dashboard and elsewhere. Goal is **visual + behavioural consistency** plus removal of duplicated markup.

---

## What changes

**New file:** `themes/stridence/templates/components/course-card.php` — a single partial that renders the expandable card.

**Modified files:**
- `themes/stridence/templates/dashboard/tab-home.php` — replace Opleidingen section's inline card markup with a `get_template_part` call.
- `themes/stridence/templates/dashboard/tab-inschrijvingen.php` — replace `active_editions` + `active_online` + `completed_items` render loops (cancelled keeps its existing minimal rendering).
- `themes/stridence/templates/trajectory/course-groups.php` — replace both near-duplicate required/elective blocks.
- `themes/stridence/helpers/templates.php` — add two builder functions that produce the partial's argument shape.

**Out of scope** for this design (deferred):
- Catalogue pages `/cursussen/`, `/klassikaal/`, `/online/`.
- Cancelled enrollments on the inschrijvingen tab.
- New course-card use in shortcodes or LearnDash overrides.

---

## Architecture

**Partial contract.** `templates/components/course-card.php` accepts `$args` with this shape:

```php
[
    'course_id'    => int,
    'course_title' => string,
    'thumbnail_id' => int|null,                // post thumbnail; null → falls back to book-open icon
    'type'         => 'edition'|'online'|'public',
    'status_pill'  => ['label' => string, 'tone' => 'primary'|'accent'|'muted'] | null,
    'enrolled'     => bool,
    'initial_open' => bool,                    // default false; controls Alpine x-data init state

    'meta' => [                                // collapsed-header secondary line
        'start_date'          => string|null,  // YYYY-MM-DD
        'venue'               => string|null,
        'progress_label'      => string|null,  // e.g. '3 van 5 lessen' or '60%'
        'days_remaining'      => int|null,
        'pending_tasks_count' => int|null,     // > 0 → render warning dot
    ],

    'body' => [                                // expanded body
        'excerpt'           => string|null,
        'progress_pct'      => int|null,        // 0–100 for progress bar
        'sessions'          => array,           // upcoming sessions for enrolled editions
        'upcoming_editions' => array,           // public-mode editions list, max 3
        'task_summary'      => ['total' => int, 'completed' => int] | null,
        'primary_cta'       => ['url' => string, 'label' => string] | null,
        'secondary_cta'     => ['url' => string, 'label' => string] | null,
    ],
]
```

**Internal branching inside the partial.**

| Mode | Trigger | Body content |
|---|---|---|
| Public | `type='public'` (any `enrolled`) | excerpt + `upcoming_editions` list + secondary CTA only |
| Enrolled edition | `enrolled=true` + `type='edition'` | progress bar (sessions attended) + sessions list + task summary + primary CTA + secondary |
| Enrolled online | `enrolled=true` + `type='online'` | progress bar (lessons %) + primary CTA ("Verder leren"/"Start cursus") + secondary |
| Completed | `enrolled=true` + `status_pill.label='Voltooid'` + `primary_cta=null` | progress at 100% + completion date if present + secondary only |

**Alpine.** Use existing `x-data="expandable()"` Alpine component. Add `initial_open` as `x-data="expandable({{ initial_open ? 'true' : 'false' }})"` — the factory accepts an optional initial-open boolean.

**Empty states.**
- No thumbnail → `book-open` icon in `bg-surface-alt` square.
- `upcoming_editions` empty in public mode → "Nog geen edities gepland" italic line.
- `sessions` empty in enrolled-edition mode → omit the block (don't render an empty-state inside expanded body).
- `excerpt` empty → omit the paragraph.
- `primary_cta` null + `secondary_cta` null → omit the actions row.

---

## Builder functions

Live in `themes/stridence/helpers/templates.php` (existing file).

### `stridence_build_course_card_args_from_enrollment(array $enrollment, bool $completed = false): array`

Maps the `UserDashboardService::getEnrollmentData()` enrollment shape → partial contract. Handles edition + online types. When `$completed=true`: sets `status_pill = ['label' => 'Voltooid', 'tone' => 'muted']`, clears `primary_cta`, keeps secondary "Bekijk cursus".

### `stridence_build_course_card_args_from_trajectory_course(WP_Post $course, array $statusPill): array`

Maps a course post + status pill → partial contract with `type='public'`, `enrolled=false`. Populates `body.upcoming_editions` from `EditionService::getEditionsForCourse()` filtered by `canEnroll()`. Builds secondary CTA "Bekijk cursus" → course permalink.

Both builders return the contract shape verbatim. Partial has no fallback logic — if a key is missing, that's a builder bug.

**`initial_open` is set by the call-site, not the builder.** Builders set it to `false`; call-sites override per their auto-expand logic (see next section).

---

## Call-site changes (in detail)

### `tab-home.php` — Opleidingen section

The existing `foreach (array_slice($enrollmentsJson, 0, 4) as $i => $enrollment)` loop stays. Inside, replace the ~130 lines of inline card markup with:

```php
$args = stridence_build_course_card_args_from_enrollment($enrollment);
$args['initial_open'] = ($i === 0);  // first card auto-expanded
get_template_part('templates/components/course-card', null, $args);
```

### `tab-inschrijvingen.php` — three sections

Replace the three section loops:
- `active_editions` → `stridence_build_course_card_args_from_enrollment($reg)`. First card in the list gets `initial_open=true`.
- `active_online` → same builder. First card in the list gets `initial_open=true` only if `active_editions` is empty.
- `completed_items` → `stridence_build_course_card_args_from_enrollment($item, completed: true)`. Never auto-expanded.

Section headings ("Klassikale opleidingen" / "Online cursussen" / "Voltooid") stay. Cancelled section unchanged.

### `course-groups.php` — trajectory detail

Replace both near-duplicate blocks (required + elective). Each course in each block:

```php
$pill = $isRequired
    ? ['label' => __('Verplicht', 'stridence'), 'tone' => 'primary']
    : ['label' => __('Keuzevak', 'stridence'), 'tone' => 'accent'];
$args = stridence_build_course_card_args_from_trajectory_course($course, $pill);
get_template_part('templates/components/course-card', null, $args);
```

`initial_open` stays `false` for trajectory cards (preserves current behavior).

---

## Initial-open behaviour

Per design decision: only the **first card in the dashboard's "Opleidingen" list and the first card in `tab-inschrijvingen`'s active sections** gets `initial_open=true`. All other cards (trajectory, completed, online when editions exist) start collapsed.

This is a hint to the reader that the cards are interactive and provides immediate detail for the most relevant entry without forcing a click.

---

## Testing

### Unit tests (PHPUnit, in `tests/Unit/`)

`tests/Unit/CourseCardBuilderTest.php` — covers both builder functions:

| Function | Cases |
|---|---|
| `from_enrollment` | Edition with pending tasks → `meta.pending_tasks_count > 0` and `body.task_summary` present |
|  | Online course at 60% → `body.progress_pct = 60`, `meta.progress_label = '3 van 5 lessen'` |
|  | Completed flag → `body.primary_cta = null`, `status_pill.label = 'Voltooid'` |
|  | Edition with no upcoming sessions → `body.sessions = []`, `body.primary_cta` reflects task CTA |
| `from_trajectory_course` | Course with editions → `body.upcoming_editions` non-empty, `meta.start_date` from next |
|  | Course with no editions → `body.upcoming_editions = []`, `meta.start_date = null` |
|  | Required vs elective `status_pill` passed through unchanged |

Stub `EditionService` + `LearnDashHelper` at call boundaries.

### Acceptance tests (Codeception)

Three new test methods (placed in existing cests where they fit):

1. `DashboardCest::courseCardOnHomeExpandsOnClick` — seed student with at least one edition + one online enrollment; visit `/mijn-account/`; assert the first Opleidingen card is initially expanded (body visible without click); assert the second is collapsed; click the second → body becomes visible.
2. `DashboardCest::courseCardOnInschrijvingenExpandsOnClick` — same pattern at `/mijn-account/?tab=inschrijvingen`.
3. `TrajectoryCest::courseCardOnDetailExpandsOnClick` — seed a trajectory with linked editions; visit `/trajecten/{slug}/`; assert cards collapsed; expand one and verify upcoming-editions list appears.

### Visual sanity

Before committing, manually exercise both pages via `chrome-devtools` as `seed_student1`. Confirm:
- No visible regression in card density or spacing
- Expand/collapse animation works (existing `x-collapse` directive)
- Mobile breakpoint (≤640px) — card doesn't break layout
- Pending-tasks warning dot still appears where applicable

---

## Risks and mitigations

| Risk | Mitigation |
|---|---|
| Existing dashboard cards have CSS classes the partial doesn't preserve, causing client-CSS overrides to break | Audit `web/app/mu-plugins/stride-client-*` for selectors targeting Opleidingen card structure before implementing |
| Builder functions duplicate logic from `UserDashboardService` | Builders are pure mappers, not data sources — they consume the service's output. No business logic moves into theme. |
| Acceptance tests already cover dashboard rendering; new partial breaks them silently | Run full acceptance suite after each call-site swap. Update assertions if they target specific old DOM nodes. |
| First-card auto-expand may hide pending-tasks dot on rest of list (user assumes only one card has tasks) | The pending-tasks dot is shown in the collapsed header AND in the expanded body's task summary — visible in both states. |

---

## Out of scope (explicit)

- Catalogue pages `/cursussen/`, `/klassikaal/`, `/online/` — these have their own page-template patterns; defer to a separate design.
- Cancelled enrollments — keep current minimal rendering on the inschrijvingen tab.
- Refactoring `UserDashboardService::getEnrollmentData()` shape — builders adapt to current shape; data layer untouched.
- Storybook/component-isolation tooling — not in the project today.
- Multi-card animations or list-level transitions.

---

## Definition of done

- `templates/components/course-card.php` exists and renders for all three modes (public / enrolled-edition / enrolled-online).
- Two builder functions in `helpers/templates.php`, fully unit-tested.
- Three call-sites swapped: `tab-home.php`, `tab-inschrijvingen.php`, `course-groups.php`.
- Acceptance tests: 3 new methods passing.
- Unit + Integration + Acceptance suites green.
- Manual visual sanity check on dashboard + trajectory detail as seed_student1.
- No new lines in client mu-plugins (Kindred, BWEEG, etc.) — visual changes inherit existing tokens.
