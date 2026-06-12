# Seed Feature-Matrix Restructure — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restructure `scripts/seed.php` (2,149 lines, imperative) into a declarative feature-matrix seeder where every feature dimension is an explicit, tagged matrix entry, then unseed + reseed on local DDEV and verify coverage mechanically.

**Architecture:** A top-level PHP matrix (`scripts/seed/matrix.php`) declares every course/edition/registration/quote/voucher with a `covers` tag list. Small builder functions (`scripts/seed/builders.php`) consume the matrix through the existing repositories/services. A verification script (`scripts/seed-verify.php`) asserts each dimension is present by querying the seeded DB against the same tag vocabulary. `scripts/seed.php` stays the WP-CLI entry point; `scripts/unseed.php` keeps the `_stride_seed_data` + manifest contract.

**Tech Stack:** PHP 8.3, WP-CLI `eval-file`, NTDST Data API repositories, LearnDash, DDEV.

**Class:** A (multi-task feature, plan → execute → verify).

---

## Gate decisions (controller record)

| Gate | Fires? | Reason |
|---|---|---|
| 1a Threat model | **NO** | Dev-only local script, guarded by `WP_ENV` check, no user-controlled input, no new attack surface. Trigger list run literally: no URL/auth/parsing/tenancy/credential/outbound surface. |
| 1b Architecture invariants | **NO** | All writes go through existing repositories/services (the existing convergence points); no new data-access path is created. INV-3 (each CPT's `getFields()` is the field-name source of truth) is *obeyed* by this plan, not modified. |
| 1g Feature acceptance | **SKIPPED** | Not user-facing. The verification phase (Task 11/12) IS the acceptance: mechanical dimension-coverage assertions + a manual demo spot-check list. |
| wp-plan-requirements | **SKIPPED** | No AJAX/REST/admin-page/shortcode/Service surface added. |
| 1c Ground-truth | **DONE** | All signatures read from source 2026-06-12; findings below. |

## Ground-truth findings (verified against source 2026-06-12)

These are facts, read from the code — the implementer must NOT re-derive or contradict them.

1. **`EditionRepository::create(array $data): WP_Post|WP_Error`** — generic `AbstractRepository::create` over the Data API model. Use Data API vocabulary: `'title'` (NOT `post_title`), bare field keys from `EditionCPT::getFields()`. Wrong keys are silently prefixed into meta (memory: `gotcha_data_api_vocabulary`).
2. **`EditionCPT::getFields()`** (`Modules/Edition/EditionCPT.php:72`) — authoritative edition field list:
   `course_id` (int, req), `start_date` (req), `end_date`, `capacity` (int, req), `price` (float), `price_non_member` (float), `venue`, `status`, **`speakers` (json — array of `{name, role}`; legacy plain strings)**, **`target_audience` (textarea)**, **`required_experience`**, **`included`**, **`price_includes`**, **`cancellation_policy`**, **`cta_benefits`**, **`enrollment_info`**, `selection_deadline`, `session_slots` (json), `completion_mode`, `completion_threshold`, `notes` (json), `requires_approval|questionnaire|documents|session_selection` (bool), `selection_open` (bool), `post_requires_evaluation|documents|approval` (bool), `enrollment_form`, `documents` (json).
   Content defaults live in `EditionCPT` (`getContentDefaults()`, line ~60).
3. **Speakers shape is now JSON** `[['name' => 'Dr. Paul Verhaeghe', 'role' => 'sportpsycholoog']]`. The current seed writes plain strings (`'speakers' => 'Dr. Paul Verhaeghe, sportpsycholoog'`) — **stale**: json-typed schema reads silently decode legacy strings to `[]` (memory: `gotcha_formatted_read_no_defaults`). The matrix MUST write arrays.
4. **`OfferingStatus`** (`Domain/OfferingStatus.php`) has exactly **9 cases**: `draft, announcement, open, full, in_progress, postponed, cancelled, completed, archived`. **`few_spots` is NOT a status** — it exists only as an admin badge legend entry (`EditionAdminController.php:1384`). The current seed writes `'status' => 'few_spots'` on one edition — an invalid stored value this restructure fixes. "Few spots" as a *demo dimension* = `open` + registrations near capacity (e.g. 10/12). Status semantics: `Open` allows enrollment, `Announcement` allows interest, `Full` allows waitlist.
5. **`RegistrationRepository::create(array $data): int|WP_Error`** (`:208`) — requires `edition_id` OR `trajectory_id`; `user_id` required **except** for `interest`/`waitlist` statuses (anonymous allowed); default status `'confirmed'`; unique per user+edition with reactivation for `cancelled/interest/waitlist`. Path constants: `PATH_INDIVIDUAL`, `PATH_COLLEAGUE`, `PATH_TRAJECTORY`, `PATH_PARTNER`. `RegistrationStatus` cases: `confirmed, completed, cancelled, waitlist, interest, pending` (6).
6. **Quotes** — `QuoteStatus`: `draft, sent, exported, cancelled`. `QuoteService::createQuote(int $userId, int $registrationId, int $editionId, array $items, array $billing = [], ?string $voucherCode = null, ?Money $discount = null)` always creates `Draft` and fires `quote/created`. Cancellation in production runs through `QuoteRepository::updateStatus($id, QuoteStatus::Cancelled)` (also listened on `stride/registration/cancelled`). **For seeding, `QuoteRepository::create(array): WP_Post|WP_Error` accepts `'status'` directly** (it only fires `stride/quote/data_changed`, a cache-invalidation signal — no cascade), so creating a quote pre-`cancelled` is safe and is the chosen approach. Quote field keys (per `QuoteService::createQuote` storage): `title, user_id, registration_id, edition_id, quote_number, status, items, subtotal, discount, tax, total, billing, voucher_code, valid_until` — amounts in **cents** (int).
7. **Vouchers** — `VoucherCPT::getFields()`: `code, discount_type (full|fixed|percentage — DiscountType), discount_value (cents for fixed, 0-100 for percentage), usage_limit, used_count, scope_mode ('all'|'only'|'except'), edition_id, excluded_edition_ids (json), apply_mode ('full'|'single_session'), valid_from, valid_until, status (VoucherStatus)`. **`VoucherService::createVoucher()` does NOT pass `scope_mode`, `excluded_edition_ids`, or `apply_mode`** — seeding scoped vouchers must call `createVoucher()` then `VoucherRepository->update($id, ['scope_mode' => …, …])` (or create via repository directly). Plan uses createVoucher + repository update so code-dedup + the `stride/voucher/created` hook still fire.
8. **Questionnaire forms** — stored in `wp_options` under `QuestionnaireRepository::OPTION_KEY = 'stride_questionnaire_field_groups'` via `getAllGroups()` / `saveGroups(array)`. Valid stages (`STAGES` const): `interest, waitlist, enrollment_personal, enrollment_billing, intake, evaluation`. Assignments = flat int course IDs (wildcards `_all_editions` / `_all_trajectories` exist). Field types `scale` and `description` confirmed in `QuestionnaireValidator`. A "custom enrollment form" = a field group with stage `enrollment_personal` (or `intake`) assigned to a course. Reserved auto-persist fields = names in `EnrollmentService::getUserMetaMapping()` (`:971` — `phone`, `organisation`, `department`, `national_id`, …).
9. **`TrajectoryService::createTrajectory(array $data): int|WP_Error`** — passthrough to repository create; `courses` is a **JSON string** of `[{course_id, required, order?, group?, min_choices?}]` (current seed shape is correct and kept).
10. **`SessionService::createSession(array $data): WP_Post|WP_Error`** — keys: `edition_id, date, start_time, end_time, type (in_person|online|webinar|assignment), location, optional, title, slot`. Lesson linking is bare meta `update_post_meta($sessionId, '_ntdst_lesson_ids', $lessonIds)` (as today).
11. **unseed.php** — deletes by `_stride_seed_data` meta per entity type + truncates seed registrations; `cleanupOptions()` only deletes `stride_seed_manifest` + `stride_seed_timestamp`. **Gaps to close (Task 10):** seed-created questionnaire groups (`qg_*_seed`) are never removed from `stride_questionnaire_field_groups`; `wp_vad_attendance` rows inserted by seed are not deleted (verify — no `vad_attendance` reference found in unseed.php); `stride_company_details` is intentionally left (harmless, keep).
12. **Known-fixture couplings to preserve:** `tests/manual/shake-helpers.php` references seed users; CLAUDE.md documents `seed_student1..` logins + `seedpass123` + `seed_partner` (company_id=1); `test-trajectory` slug is used by E2E tests. Keep all user logins/emails, `test-trajectory`, voucher code `SEEDVOUCHER10`, and the `stride_company_details` payload byte-identical. Course titles may change freely (no manual script greps a course title — verified via grep of `tests/manual/`).

## Open questions for Stefan (answer before or during Cluster 1)

1. **`few_spots`**: confirmed not a real status — plan replaces it with `open` + 10/12 registrations. OK?
2. **Fake user IDs (900000+)**: plan keeps fakes for ONE display-only full edition (cheap capacity display) AND adds a real-users full edition (8 new `seed_filler1..8` users) + waitlist behind it for flow testing. OK, or all-real everywhere (≈40 extra users)?
3. **Branch**: the matrix depends on the new edition content fields, so this work should land on top of `feature/edition-content-fields` (or after its merge). Confirm sequencing.

---

## File structure

```
scripts/
├── seed.php              # Entry point: guards, loads seed/ files, runs SeedRunner (≈100 lines)
├── seed/
│   ├── matrix.php        # THE declarative matrix: returns array of course/trajectory/voucher/user defs
│   ├── builders.php      # StrideSeedBuilders: one build*() per entity kind, consumes matrix entries
│   └── runner.php        # StrideSeedRunner: orchestration, manifest, idempotency, summary
├── seed-verify.php       # Post-seed assertion script: dimension coverage from `covers` tags
└── unseed.php            # Updated: + questionnaire groups, + attendance rows, + new option keys
```

Responsibilities: `matrix.php` is pure data (no function calls except `date()`/`strtotime()` for relative dates and enum `->value`). `builders.php` is the only place that talks to repositories/services. `runner.php` owns ordering, the manifest, and printing. `seed-verify.php` reads the manifest + DB and exits non-zero on a missing dimension.

## The `covers` tag vocabulary (used by matrix AND verifier — single list)

```
model:pure_ld_open  model:closed_single  model:multi_edition  model:trajectory_course
edition_type:in_person  edition_type:online  edition_type:hybrid  edition_type:webinar
status:draft status:announcement status:open status:few_spots(=open+near-full) status:full
status:in_progress status:postponed status:cancelled status:completed status:archived
content:edition_fields  content:speakers_repeater
sessions:slots_choose_n  sessions:selection_deadline  sessions:type_in_person
sessions:type_online  sessions:type_webinar  sessions:type_assignment  sessions:lesson_linked
req:pre_session_selection  req:pre_questionnaire  req:pre_documents  req:pre_approval
req:post_evaluation  req:post_documents  req:post_approval
form:default  form:minimal  form:direct  form:custom_all_types  form:reserved_fields
reg:confirmed reg:pending reg:completed reg:cancelled reg:waitlist reg:interest
path:individual path:colleague path:trajectory path:partner
capacity:full_real_users  capacity:full_fake_display  capacity:waitlist_behind_full  capacity:unlimited
quote:draft quote:sent quote:exported quote:cancelled
voucher:full voucher:fixed voucher:percentage voucher:scope_all voucher:scope_only voucher:scope_except
trajectory:cohort  trajectory:self_paced  trajectory:elective_choose_n
flow:attendance_marked  flow:post_course_ready
```

---

# Phase 1 — Matrix schema + builders (foundation)

### Task 1: Matrix schema, runner skeleton, entry-point rewrite

**Files:**
- Create: `scripts/seed/matrix.php`
- Create: `scripts/seed/runner.php`
- Modify: `scripts/seed.php` (full rewrite)

**Test tier:** `no unit test: Tier B — pure data + orchestration glue; verified by Task 12 reseed run and seed-verify.php`

- [ ] **Step 1: Create `scripts/seed/matrix.php` with the schema doc-block and the first two entries** (full file content for the schema; remaining entries come in Task 8):

```php
<?php
/**
 * Stride seed feature matrix.
 *
 * SHAPE of a course entry:
 * [
 *   'title' => string (Dutch, demo-quality),
 *   'description' => string,
 *   'type' => 'online'|'in-person'|'hybrid'|'webinar',
 *   'format' => string[] (stride_format slugs),
 *   'themes' => string[] (stride_theme slugs),
 *   'ld_price_type' => 'open'|'closed' (default 'open'),
 *   'covers' => string[],            // dimension tags — seed-verify.php asserts these
 *   'lessons' => [['title','content'], ...],
 *   'editions' => [[
 *     'start_date','end_date'?, 'price','price_non_member','capacity','venue',
 *     'status' => OfferingStatus value string,
 *     'speakers' => [['name','role'], ...],                 // JSON repeater — NEVER a plain string
 *     'content' => ['target_audience','required_experience','included','price_includes',
 *                   'cancellation_policy','cta_benefits','enrollment_info'],  // any subset
 *     'enrollment_form' => 'default'|'minimal'|'direct'|null,
 *     'requires' => ['session_selection','questionnaire','documents','approval'],   // pre
 *     'post_requires' => ['evaluation','documents','approval'],                     // post
 *     'selection_deadline'?, 'selection_open'?, 'session_slots'?,
 *     'fake_registered' => int,      // display-only fake-user fill (900000+ IDs)
 *     'registrations' => [[          // REAL registrations
 *       'user' => 'seed_student1'|'admin'|'seed_filler{N}',  // login, or 'admin'
 *       'status' => RegistrationStatus value, 'path' => 'individual'|'colleague'|'trajectory'|'partner',
 *       'attendance' => 'present'|null,  'quote' => 'draft'|'sent'|'exported'|'cancelled'|null,
 *       'init_tasks' => bool, 'init_post_tasks' => bool,
 *     ], ...],
 *     'covers' => string[],
 *     'sessions' => [['date_offset','start','end','type','title','optional'?,'slot'?,'location'?,'link_lesson'?]],
 *   ], ...],
 * ]
 *
 * Top-level keys returned: 'users', 'courses', 'trajectories', 'vouchers',
 * 'questionnaire_groups', 'company_details'.
 */

use Stride\Domain\OfferingStatus;

return [
    'users' => [
        // EXACT logins/emails preserved — tests/manual + CLAUDE.md reference them.
        ['login' => 'seed_admin', 'email' => 'seed_admin@seed.test', 'role' => 'administrator', 'first' => 'Admin', 'last' => 'Seed'],
        ['login' => 'seed_instructor', 'email' => 'instructor@seed.test', 'role' => 'group_leader', 'first' => 'Jan', 'last' => 'De Trainer'],
        ['login' => 'seed_partner', 'email' => 'seed_partner@seed.test', 'role' => 'partner', 'first' => 'Partner', 'last' => 'Test', 'company_id' => 1],
        ['login' => 'seed_student1', 'email' => 'student1@seed.test', 'role' => 'subscriber', 'first' => 'Pieter', 'last' => 'Janssen', 'company_id' => 1],
        ['login' => 'seed_student2', 'email' => 'student2@seed.test', 'role' => 'subscriber', 'first' => 'Anna', 'last' => 'De Vries', 'company_id' => 1],
        ['login' => 'seed_student3', 'email' => 'student3@seed.test', 'role' => 'subscriber', 'first' => 'Thomas', 'last' => 'Bakker', 'company_id' => 1],
        ['login' => 'seed_student4', 'email' => 'student4@seed.test', 'role' => 'subscriber', 'first' => 'Lotte', 'last' => 'Peeters'],
        ['login' => 'seed_student5', 'email' => 'student5@seed.test', 'role' => 'subscriber', 'first' => 'Wout', 'last' => 'Claes'],
        ['login' => 'seed_coordinator', 'email' => 'seed_coordinator@seed.test', 'role' => 'stride_coordinator', 'first' => 'Coordinator', 'last' => 'Seed'],
        ['login' => 'seed_supervisor', 'email' => 'seed_supervisor@seed.test', 'role' => 'stride_supervisor', 'first' => 'Supervisor', 'last' => 'Seed'],
        ['login' => 'seed_enrolled_user', 'email' => 'seed_enrolled_user@seed.test', 'role' => 'subscriber', 'first' => 'Enrolled', 'last' => 'User'],
        ['login' => 'seed_completed_user', 'email' => 'seed_completed_user@seed.test', 'role' => 'subscriber', 'first' => 'Completed', 'last' => 'User'],
        // Capacity fillers for the real-users full edition (Task 8 references these)
        ['login' => 'seed_filler1', 'email' => 'filler1@seed.test', 'role' => 'subscriber', 'first' => 'Filler', 'last' => 'Een'],
        ['login' => 'seed_filler2', 'email' => 'filler2@seed.test', 'role' => 'subscriber', 'first' => 'Filler', 'last' => 'Twee'],
        ['login' => 'seed_filler3', 'email' => 'filler3@seed.test', 'role' => 'subscriber', 'first' => 'Filler', 'last' => 'Drie'],
        ['login' => 'seed_filler4', 'email' => 'filler4@seed.test', 'role' => 'subscriber', 'first' => 'Filler', 'last' => 'Vier'],
        ['login' => 'seed_filler5', 'email' => 'filler5@seed.test', 'role' => 'subscriber', 'first' => 'Filler', 'last' => 'Vijf'],
        ['login' => 'seed_filler6', 'email' => 'filler6@seed.test', 'role' => 'subscriber', 'first' => 'Filler', 'last' => 'Zes'],
    ],
    'courses' => [
        // === Entry 1: pure-LD open (no edition) — preserved from current seed ===
        [
            'title' => 'E-learning: Basiskennis Jeugdgezondheid',
            'description' => 'Uitgebreide online cursus over de pijlers van jeugdgezondheid: beweging, voeding en mentaal welzijn.',
            'type' => 'online', 'format' => ['online', 'e-learning'], 'themes' => ['beweging', 'welzijn'],
            'ld_price_type' => 'open',
            'covers' => ['model:pure_ld_open'],
            'editions' => [],
            'lessons' => [
                ['title' => 'Module 1: Wat is jeugdgezondheid?', 'content' => '<p>De drie pijlers: beweging, voeding en mentaal welzijn.</p>'],
                ['title' => 'Module 2: Bewegen en motoriek', 'content' => '<p>Beweegnormen en motorische vaardigheden.</p>'],
                ['title' => 'Module 3: Eindtoets', 'content' => '<p>25 meerkeuzevragen, 70% om te slagen.</p>'],
            ],
        ],
        // === Entry 2: closed single-edition, FEATURED — all content fields + speakers repeater ===
        [
            'title' => 'Motiverende Gespreksvoering rond Voeding bij Jongeren',
            'description' => 'Leer hoe je jongeren op een niet-veroordelende manier motiveert om gezondere eetgewoontes te ontwikkelen.',
            'type' => 'in-person', 'format' => ['klassikaal'], 'themes' => ['voeding', 'welzijn'],
            'covers' => ['model:closed_single', 'edition_type:in_person', 'content:edition_fields', 'content:speakers_repeater'],
            'editions' => [
                [
                    'start_date' => date('Y-m-d', strtotime('+6 weeks')),
                    'price' => 295.00, 'price_non_member' => 345.00, 'capacity' => 16,
                    'venue' => 'BWEEG Opleidingscentrum Gent',
                    'status' => OfferingStatus::Open->value,
                    'speakers' => [
                        ['name' => 'Lien De Smedt', 'role' => 'Sportpedagoge'],
                        ['name' => 'Drs. Peter Willems', 'role' => 'Diëtist'],
                    ],
                    'content' => [
                        'target_audience' => "Leerkrachten, CLB-medewerkers en jeugdwerkers die met 12-18-jarigen werken.",
                        'required_experience' => 'Geen voorkennis nodig',
                        'included' => "Lunch, koffie en cursusmateriaal. Je ontvangt achteraf een attest van deelname.",
                        'price_includes' => 'incl. lunch en cursusmateriaal',
                        'cancellation_policy' => 'Kosteloos tot 14 dagen vóór de eerste sessie. Daarna kan een collega je plaats overnemen.',
                        'cta_benefits' => "Attest van deelname\nKosteloos annuleren tot 14 dagen vooraf\nKleine groep (max. 16)",
                        'enrollment_info' => 'Na inschrijving ontvang je een mail met de bevestiging van je deelname.',
                    ],
                    'enrollment_form' => 'default',
                    'covers' => ['status:open', 'sessions:type_in_person', 'form:default'],
                    'registrations' => [
                        ['user' => 'seed_student2', 'status' => 'confirmed', 'path' => 'individual', 'quote' => 'sent'],
                        ['user' => 'seed_student4', 'status' => 'confirmed', 'path' => 'colleague', 'quote' => 'draft'],
                    ],
                    'sessions' => [
                        ['date_offset' => 0, 'start' => '09:30', 'end' => '16:30', 'type' => 'in_person', 'title' => 'Motiverende gespreksvoering rond voeding'],
                    ],
                ],
            ],
            'lessons' => [
                ['title' => 'Theorie: Motiverende gespreksvoering', 'content' => 'Achtergrond en principes.'],
                ['title' => 'Praktijk: Rollenspelen en casussen', 'content' => 'Oefenen met gesprekstechnieken.'],
            ],
        ],
        // Remaining entries added in Task 8 per the coverage table there.
    ],
    'trajectories' => [],            // Task 8
    'vouchers' => [],                // Task 8
    'questionnaire_groups' => [],    // Task 7
    'company_details' => [
        'name' => 'BWEEG vzw', 'address' => 'Sportstraat 42', 'postal_code' => '9000',
        'city' => 'Gent', 'country' => 'België', 'vat' => 'BE0420.798.935',
        'email' => 'info@bweeg.be', 'phone' => '+32 9 234 56 78', 'bank_account' => 'BE68 0682 0553 5765',
    ],
];
```

- [ ] **Step 2: Create `scripts/seed/runner.php`**:

```php
<?php
/**
 * Orchestrates the matrix: taxonomy terms → users → questionnaire groups →
 * courses (lessons → editions → sessions → registrations → quotes) →
 * trajectories → vouchers → manifest. Idempotent: every builder checks
 * existence by natural key (login / title / code / group id) before creating.
 */
final class StrideSeedRunner
{
    public const SEED_META_KEY = '_stride_seed_data';

    private array $created = [
        'users' => [], 'courses' => [], 'lessons' => [], 'editions' => [],
        'sessions' => [], 'registrations' => [], 'trajectories' => [],
        'vouchers' => [], 'quotes' => [], 'questionnaire_groups' => [],
    ];
    /** @var array<string,int> login => user_id, plus 'admin' => 1 */
    private array $userMap = ['admin' => 1];
    /** @var array<string,string[]> entity-id-keyed covers index for the manifest */
    private array $covers = [];

    public function __construct(private array $matrix, private StrideSeedBuilders $builders) {}

    public function run(): void
    {
        echo "\n=== Stride LMS Feature-Matrix Seed ===\n\n";
        $this->builders->ensureTaxonomyTerms();
        foreach ($this->matrix['users'] as $u) {
            $id = $this->builders->buildUser($u);
            if ($id) { $this->userMap[$u['login']] = $id; $this->created['users'][] = $id; }
        }
        $this->created['questionnaire_groups'] = $this->builders->buildQuestionnaireGroups(
            $this->matrix['questionnaire_groups']
        );
        foreach ($this->matrix['courses'] as $course) {
            $result = $this->builders->buildCourse($course, $this->userMap);
            $this->merge($result);   // merges courses/lessons/editions/sessions/registrations/quotes + covers
        }
        foreach ($this->matrix['trajectories'] as $t) {
            $id = $this->builders->buildTrajectory($t, $this->created['courses']);
            if ($id) { $this->created['trajectories'][] = $id; $this->covers["trajectory:$id"] = $t['covers'] ?? []; }
        }
        foreach ($this->matrix['vouchers'] as $v) {
            $id = $this->builders->buildVoucher($v, $this->created['editions']);
            if ($id) { $this->created['vouchers'][] = $id; $this->covers["voucher:$id"] = $v['covers'] ?? []; }
        }
        update_option('stride_company_details', $this->matrix['company_details']);
        update_option('stride_seed_manifest', $this->created);
        update_option('stride_seed_covers', $this->covers);   // NEW: verify script reads this
        update_option('stride_seed_timestamp', current_time('mysql'));
        $this->printSummary();
    }

    private function merge(array $result): void
    {
        foreach ($result['created'] as $kind => $ids) {
            $this->created[$kind] = array_merge($this->created[$kind], $ids);
        }
        $this->covers = array_merge($this->covers, $result['covers']);
    }

    private function printSummary(): void
    {
        echo "\n=== Seed Complete ===\n";
        foreach ($this->created as $kind => $ids) {
            echo sprintf("  - %s: %d\n", $kind, count($ids));
        }
        $allTags = array_unique(array_merge(...array_values($this->covers ?: [[]])));
        echo "  Dimensions covered: " . count($allTags) . "\n";
        echo "\nVerify:  ddev exec wp eval-file scripts/seed-verify.php\n";
        echo "Cleanup: ddev exec bash -c 'FORCE_UNSEED=1 wp eval-file scripts/unseed.php'\n";
    }
}
```

- [ ] **Step 3: Rewrite `scripts/seed.php`** (keep guards byte-equivalent; this stays the documented entry point):

```php
<?php
/**
 * Stride LMS - Development Seed Script (declarative feature matrix)
 * Run with: ddev exec wp eval-file scripts/seed.php
 * Matrix:   scripts/seed/matrix.php   Builders: scripts/seed/builders.php
 */
if (!defined('ABSPATH')) {
    echo "This script must be run via WP-CLI: ddev exec wp eval-file scripts/seed.php\n";
    exit(1);
}
if (!defined('WP_ENV') || !in_array(WP_ENV, ['development', 'local'], true)) {
    echo "ERROR: Seed script only allowed in development/local environments!\n";
    exit(1);
}

require __DIR__ . '/seed/builders.php';
require __DIR__ . '/seed/runner.php';
$matrix = require __DIR__ . '/seed/matrix.php';

(new StrideSeedRunner($matrix, new StrideSeedBuilders()))->run();
```

- [ ] **Step 4: Syntax check** — `ddev exec php -l scripts/seed.php && ddev exec php -l scripts/seed/matrix.php && ddev exec php -l scripts/seed/runner.php`. Expected: `No syntax errors`.
- [ ] **Step 5: Commit** — `git add scripts/seed.php scripts/seed/ && git commit -m "refactor(seed): matrix schema + runner skeleton for declarative seeder"`

---

### Task 2: Builders — users, taxonomy, courses, lessons

**Files:**
- Create: `scripts/seed/builders.php`

**Test tier:** `no unit test: Tier B — thin wrappers over wp_insert_user/wp_insert_post, ported behavior-identical from current seed; verified by Task 12 run`

- [ ] **Step 1: Create `scripts/seed/builders.php`** with class header and these methods, ported from the current seed (`scripts/seed.php` lines 115–214 for users/taxonomy, 1003–1070 for courses, 1243–1286 for lessons) with these changes only:

```php
<?php

use Stride\Domain\RegistrationStatus;
use Stride\Domain\QuoteStatus;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Enrollment\EnrollmentCompletion;
use Stride\Modules\Invoicing\QuoteRepository;
use Stride\Modules\Invoicing\VoucherService;
use Stride\Modules\Invoicing\VoucherRepository;
use Stride\Modules\Questionnaire\QuestionnaireRepository;
use Stride\Modules\Trajectory\TrajectoryService;

final class StrideSeedBuilders
{
    // Services resolved lazily via ntdst_get() in each method (script context;
    // matches current seed's null-guard pattern).

    public function ensureTaxonomyTerms(): void { /* port lines 115-154 unchanged */ }

    /** Returns user ID (existing or created). Port of lines 170-213 + createTestUser. */
    public function buildUser(array $u): int { /* existence by login; seedpass123; ntdst_auth_activated; _stride_company_id when set; SEED_META_KEY on created */ }

    /** Returns ['created' => [...], 'covers' => [...]] for one matrix course entry. */
    public function buildCourse(array $course, array $userMap): array
    {
        // Port of createCourseWithEditions (lines 1003-1070):
        // existence by title; wp_insert_post sfwd-courses; _course_type; _sfwd-courses
        // settings (ld_price_type, expire_access); stride_format/stride_theme/ld_course_category
        // terms; then buildLessons(); then buildEdition() per edition entry.
        // Course-level covers tags recorded under key "course:{$id}".
    }

    /** Port of createLessonsForCourse (lines 1243-1286) unchanged, incl. LDLMS_Factory_Post steps. */
    public function buildLessons(int $courseId, array $lessonData): array { /* ... */ }
}
```

Port the bodies verbatim where noted — the change in this task is *structure* (matrix-driven, covers recorded), not behavior. Echo lines keep the existing format.

- [ ] **Step 2: Syntax check** — `ddev exec php -l scripts/seed/builders.php`. Expected: no errors.
- [ ] **Step 3: Smoke-run the partial seeder on DDEV** (creates users + 2 courses from the Task-1 matrix; idempotent re-run safe): `ddev exec wp eval-file scripts/seed.php`. Expected: users + 2 courses created, no PHP notices.
- [ ] **Step 4: Commit** — `git commit -am "refactor(seed): user/course/lesson builders consume matrix"`

---

### Task 3: Builders — editions (content fields, speakers repeater, statuses)

**Files:**
- Modify: `scripts/seed/builders.php`

**Test tier:** `no unit test: Tier B — repository passthrough; the speakers/content correctness is asserted by seed-verify.php (Task 9) which checks a featured edition returns a non-empty speakers array and all 7 content fields`

- [ ] **Step 1: Add `buildEdition()`** — port of `createEdition` (lines 1072–1189) with these REQUIRED changes:

```php
/** @return array{id:int|null, created:array, covers:array} */
public function buildEdition(int $courseId, string $courseTitle, array $data, array $lessonIds, array $userMap): array
{
    $repo = ntdst_get(EditionRepository::class);
    $postTitle = $courseTitle . ' - ' . date('j M Y', strtotime($data['start_date']));
    // end_date derivation from max session offset: port unchanged (lines 1076-1081)
    $effectivePrice = $data['price_non_member'] ?? $data['price'] ?? 0;  // v1 single-price rule, unchanged

    $createData = [
        'title' => $postTitle,
        'post_status' => 'publish',
        'course_id' => $courseId,
        'start_date' => $data['start_date'],
        'end_date' => $endDate,
        'price' => $effectivePrice,
        'price_non_member' => $effectivePrice,
        'capacity' => $data['capacity'],
        'venue' => $data['venue'],
        'status' => $data['status'],                       // MUST be a valid OfferingStatus value — matrix uses ->value
        'speakers' => $data['speakers'] ?? [],             // CHANGED: json array of {name, role}, never a string
    ];
    // CHANGED: content fields go through the repository (schema-registered), not update_post_meta
    foreach (($data['content'] ?? []) as $key => $value) {
        $createData[$key] = $value;   // target_audience, required_experience, included, price_includes,
                                      // cancellation_policy, cta_benefits, enrollment_info
    }
    if (!empty($data['enrollment_form']))    { $createData['enrollment_form'] = $data['enrollment_form']; }
    foreach (($data['requires'] ?? []) as $r)      { $createData['requires_' . $r] = true; }       // boolean schema fields
    foreach (($data['post_requires'] ?? []) as $r) { $createData['post_requires_' . $r] = true; }
    if (!empty($data['selection_open']))     { $createData['selection_open'] = true; }
    if (!empty($data['selection_deadline'])) { $createData['selection_deadline'] = $data['selection_deadline']; }
    if (!empty($data['session_slots']))      { $createData['session_slots'] = $data['session_slots']; }  // json field

    $result = $repo->create($createData);
    if (is_wp_error($result)) { echo "    ! Edition failed: {$result->get_error_message()}\n"; return ['id' => null, 'created' => [], 'covers' => []]; }
    $editionId = $result->ID;
    update_post_meta($editionId, StrideSeedRunner::SEED_META_KEY, true);
    // fake_registered: port the 900000+ wpdb insert block (lines 1145-1164) unchanged,
    // driven by $data['fake_registered'] (renamed from 'registered').
    // Then sessions (Task 4) and registrations/quotes (Task 5/6).
}
```

Note: the current seed wrote `_ntdst_enrollment_form` / `_ntdst_requires_*` / `_ntdst_session_slots` via raw `update_post_meta` — all of these are schema fields in `EditionCPT::getFields()`, so they move into the repository `create()` payload. If any field silently fails to persist on re-run, suspect the `valuesMatch()` batch-rollback gotcha (memory: `gotcha_data_api_valuesmatch`, fixed 2026-05-20) before debugging elsewhere.

- [ ] **Step 2: Update the two matrix entries from Task 1** if any key drifted from the final builder contract (speakers/content keys must match exactly).
- [ ] **Step 3: Smoke-run + inspect** — `ddev exec wp eval-file scripts/seed.php` then:
  `ddev exec wp eval 'var_dump(ntdst_get(\Stride\Modules\Edition\EditionRepository::class)->getField(<edition_id>, "speakers"));'`
  Expected: PHP array with 2 `{name, role}` rows (NOT `[]`, NOT a string).
- [ ] **Step 4: Commit** — `git commit -am "refactor(seed): edition builder writes content fields + speakers repeater via repository"`

### Task 4: Builders — sessions (slots, types, lesson links)

**Files:**
- Modify: `scripts/seed/builders.php`

**Test tier:** `no unit test: Tier B — SessionService passthrough ported from current seed; slot/lesson coverage asserted by seed-verify.php`

- [ ] **Step 1: Add `buildSession()`** — port of `createSession` (lines 1191–1241), unchanged except: lesson linking is driven by an explicit `'link_lesson' => true` key on the matrix session (replacing the implicit "every online session eats the next lesson" counter — declarative, predictable). Keys passed to `SessionService::createSession`: `edition_id, date (start_date + date_offset), start_time, end_time, type, location (?? venue), optional, title, slot`. Lesson link stays `update_post_meta($sessionId, '_ntdst_lesson_ids', [$lessonId])`.
- [ ] **Step 2: Ensure all four session types** (`in_person`, `online`, `webinar`, `assignment`) are accepted — `assignment` exists in `SessionType` but the current seed never used it; the matrix (Task 8) will.
- [ ] **Step 3: Smoke-run** — `ddev exec wp eval-file scripts/seed.php`; expected: sessions echo with date/time/type lines as before.
- [ ] **Step 4: Commit** — `git commit -am "refactor(seed): session builder with explicit lesson links"`

`── REVIEW GATE ── (tier: STANDARD — multi-file restructure of a dev script, no 1a surface; reviewer holds Tasks 1–4: schema contract, Data-API vocabulary, speakers-json correctness, ported-behavior fidelity)`

---

# Phase 2 — Registrations, quotes, vouchers, forms, trajectories

### Task 5: Builders — registrations (6 statuses, 4 paths, real-user full + waitlist)

**Files:**
- Modify: `scripts/seed/builders.php`

**Test tier:** `no unit test: Tier B — drives RegistrationRepository::create which has its own unit suite; status/path coverage asserted by seed-verify.php. The one logic-bearing rule (anonymous allowed only for interest/waitlist) is enforced by the repository itself, not the builder.`

- [ ] **Step 1: Add `buildRegistration()`**:

```php
/** @return int|null registration id */
public function buildRegistration(int $editionId, array $reg, array $userMap): ?int
{
    $repo = ntdst_get(RegistrationRepository::class);
    $userId = $userMap[$reg['user']] ?? null;     // 'admin' => 1; null user only valid for interest/waitlist
    $status = $reg['status'];                      // one of the 6 RegistrationStatus values
    $path = match ($reg['path'] ?? 'individual') {
        'individual' => RegistrationRepository::PATH_INDIVIDUAL,
        'colleague'  => RegistrationRepository::PATH_COLLEAGUE,
        'trajectory' => RegistrationRepository::PATH_TRAJECTORY,
        'partner'    => RegistrationRepository::PATH_PARTNER,
    };
    $payload = ['edition_id' => $editionId, 'status' => $status, 'enrollment_path' => $path, 'notes' => 'Seed: feature matrix'];
    if ($userId) { $payload['user_id'] = $userId; }
    if ($path === RegistrationRepository::PATH_PARTNER) { $payload['company_id'] = 1; }  // Partner API scoping
    $regId = $repo->create($payload);
    if (is_wp_error($regId)) { echo "    ! Registration failed: {$regId->get_error_message()}\n"; return null; }

    // LD access for confirmed/completed (port of ld_update_course_access calls)
    if (in_array($status, ['confirmed', 'completed'], true) && $userId && function_exists('ld_update_course_access')) {
        $courseId = (int) ntdst_get(EditionService::class)->getCourseId($editionId);
        if ($courseId) { ld_update_course_access($userId, $courseId, false); }
    }
    // Pre-enrollment tasks (port of EnrollmentCompletion::buildInitialTasks + wpdb completion_tasks update, lines 1518-1529)
    if (!empty($reg['init_tasks'])) { /* port unchanged */ }
    // Attendance (port of wpdb vad_attendance insert per session, lines 1880-1901) when $reg['attendance'] === 'present'
    // Post-course tasks (port of initializePostCourseTasks call, lines 1903-1932) when $reg['init_post_tasks']
    return (int) $regId;
}
```

Verify before coding: confirm `company_id` is an accepted column in `RegistrationRepository::create()` (it is a column on `wp_vad_registrations` per the Partner API docs; check `create()`'s column allow-list at `RegistrationRepository.php:208ff` and pass it the way `findByCompany` expects).

- [ ] **Step 2: Wire into `buildEdition()`** — after sessions, loop `$data['registrations']`.
- [ ] **Step 3: Smoke-run** — re-run seed; expected: registrations echo per edition; re-run again to confirm reactivation/duplicate guard keeps it idempotent (no duplicate-row errors).
- [ ] **Step 4: Commit** — `git commit -am "refactor(seed): registration builder — all statuses/paths, partner scoping"`

### Task 6: Builders — quotes (all 4 statuses, created safely pre-status)

**Files:**
- Modify: `scripts/seed/builders.php`

**Test tier:** `no unit test: Tier B — direct QuoteRepository::create with literal field values; statuses asserted by seed-verify.php`

- [ ] **Step 1: Add `buildQuote()`** — replaces the random-status `createQuoteForEdition`. Deterministic status from the matrix; cancelled is **created** in that status (ground-truth fact 6: repository create fires no cascade — no transition needed):

```php
public function buildQuote(int $editionId, int $userId, int $registrationId, string $status): ?int
{
    $repo = ntdst_get(QuoteRepository::class);
    $user = get_user_by('ID', $userId);
    $priceCents = (int) round(((float) (ntdst_get(EditionRepository::class)->getField($editionId, 'price') ?: 299.00)) * 100);
    $taxCents = (int) round($priceCents * 0.21);
    $quoteNumber = $repo->generateQuoteNumber();   // real numbering, not rand()

    $result = $repo->create([
        'title' => get_the_title($editionId),
        'user_id' => $userId,
        'registration_id' => $registrationId,
        'edition_id' => $editionId,
        'quote_number' => $quoteNumber,
        'status' => $status,                       // 'draft'|'sent'|'exported'|'cancelled' — QuoteStatus values
        'items' => [[ 'type' => 'edition', 'id' => $editionId, 'title' => get_the_title($editionId),
                      'unit_price' => $priceCents, 'quantity' => 1, 'total' => $priceCents ]],
        'subtotal' => $priceCents, 'discount' => 0, 'tax' => $taxCents, 'total' => $priceCents + $taxCents,
        'billing' => ['name' => $user->display_name, 'email' => $user->user_email],
        'valid_until' => date('Y-m-d', strtotime('+30 days')),
    ]);
    if (is_wp_error($result)) { return null; }
    $quoteId = $result->ID;
    update_post_meta($quoteId, StrideSeedRunner::SEED_META_KEY, true);
    if (in_array($status, ['sent', 'exported'], true)) { /* sent_at meta as today, via repo update */ }
    if ($status === 'exported') { /* locked = 1, via repo update */ }
    return $quoteId;
}
```

Note: the current seed wrote bare meta keys (`quote_number`, `status`, …) via `update_post_meta` — acceptance fixtures showed bare keys ARE the live convention for quotes (memory: `lesson_acceptance_fixtures_bare_keys` — Data API prefixes on read). Going through `QuoteRepository::create` with schema vocabulary is the convergent path; verify one seeded quote renders on the admin quotes list before committing.

- [ ] **Step 2: Wire into `buildRegistration()`'s caller** — when a matrix registration has `'quote' => '<status>'`.
- [ ] **Step 3: Smoke-run + check** — reseed; `ddev exec wp eval 'print_r(ntdst_get(\Stride\Modules\Invoicing\QuoteRepository::class)->findByUser(1));'` shows the statuses written. Open `/wp/wp-admin` quotes list and confirm one renders with number + total.
- [ ] **Step 4: Commit** — `git commit -am "refactor(seed): deterministic quote builder, all four QuoteStatus values"`

### Task 7: Builders — vouchers (3 types × 3 scopes) and questionnaire groups (custom form, all 7 field types)

**Files:**
- Modify: `scripts/seed/builders.php`
- Modify: `scripts/seed/matrix.php` (add `vouchers` + `questionnaire_groups` data)

**Test tier:** `no unit test: Tier B — service/repository passthrough; type/scope/field-type coverage asserted by seed-verify.php`

- [ ] **Step 1: Add `buildVoucher()`** — `VoucherService::createVoucher()` then scope fields via repository (ground-truth fact 7):

```php
public function buildVoucher(array $v, array $editionIds): ?int
{
    $service = ntdst_get(VoucherService::class);
    $existing = $service->getVoucherByCode($v['code']);
    if ($existing) { return (int) $existing['id']; }
    $id = $service->createVoucher([
        'code' => $v['code'], 'discount_type' => $v['discount_type'],
        'discount_value' => $v['discount_value'], 'usage_limit' => $v['usage_limit'],
    ]);
    if (is_wp_error($id)) { return null; }
    update_post_meta($id, StrideSeedRunner::SEED_META_KEY, true);
    // Scope: createVoucher() does not accept these — set via repository update.
    $scope = $v['scope'] ?? 'all';   // 'all' | 'only' | 'except'
    if ($scope !== 'all') {
        $repo = ntdst_get(VoucherRepository::class);
        $update = ['scope_mode' => $scope];
        if ($scope === 'only')   { $update['edition_id'] = $editionIds[0] ?? 0; }
        if ($scope === 'except') { $update['excluded_edition_ids'] = array_slice($editionIds, 0, 2); }  // json field: array
        $repo->update($id, $update);
    }
    return $id;
}
```

Matrix voucher data (6 vouchers; `SEEDVOUCHER10` code preserved):

| code | discount_type | discount_value | scope | covers |
|---|---|---|---|---|
| WELKOM2026 | percentage | 10 | all | voucher:percentage, voucher:scope_all |
| KORTING50 | fixed | 5000 | all | voucher:fixed |
| GRATIS-INTRO | full | 0 | all | voucher:full |
| SEEDVOUCHER10 | percentage | 10 | all | (legacy fixture, preserved) |
| EDITIE-ONLY | percentage | 20 | only | voucher:scope_only |
| NIET-HIER | fixed | 2500 | except | voucher:scope_except |

- [ ] **Step 2: Add `buildQuestionnaireGroups()`** — writes groups into `stride_questionnaire_field_groups` via `QuestionnaireRepository::getAllGroups()`/`saveGroups()`, merging by group `id` (idempotent). Matrix data — two groups:

```php
'questionnaire_groups' => [
    [   // Custom enrollment form exercising ALL 7 field types + reserved auto-persist fields
        'id' => 'qg_enrollment_seed',
        'label' => 'Extra inschrijvingsvragen',
        'stage' => 'enrollment_personal',                  // valid per QuestionnaireRepository::STAGES
        'assign_to_course' => 'Erkenningstraject Jeugdsportcoach',   // resolved to course ID by builder
        'fields' => [
            ['label' => 'Toelichting', 'name' => 'intro_desc', 'type' => 'description',
             'description' => 'Deze gegevens gebruiken we om de opleiding af te stemmen op de groep.'],
            ['label' => 'Telefoonnummer', 'name' => 'phone', 'type' => 'text', 'required' => true],          // reserved → wp_usermeta
            ['label' => 'Organisatie', 'name' => 'organisation', 'type' => 'text', 'required' => true],      // reserved → wp_usermeta
            ['label' => 'Motivatie', 'name' => 'motivatie', 'type' => 'textarea', 'required' => true],
            ['label' => 'Functie', 'name' => 'functie', 'type' => 'select', 'required' => true,
             'options' => ['Leerkracht', 'CLB-medewerker', 'Jeugdwerker', 'Sportcoach', 'Andere']],
            ['label' => 'Ervaring met jeugdsport', 'name' => 'ervaring', 'type' => 'radio', 'required' => true,
             'options' => ['Geen', '1-3 jaar', 'Meer dan 3 jaar']],
            ['label' => 'Interesses', 'name' => 'interesses', 'type' => 'checkbox', 'required' => false,
             'options' => ['Blessurepreventie', 'Voeding', 'Mentaal welzijn']],
            ['label' => 'Hoe schat je je voorkennis in?', 'name' => 'voorkennis_schaal', 'type' => 'scale',
             'required' => true, 'min' => 1, 'max' => 5],
        ],
        'covers' => ['form:custom_all_types', 'form:reserved_fields'],
    ],
    [   // Evaluation group (preserves current qg_eval_seed behavior)
        'id' => 'qg_eval_seed',
        'label' => 'Evaluatie opleiding',
        'stage' => 'evaluation',
        'assign_to_course' => '*post_course*',   // builder assigns to every course whose edition has post_requires_evaluation
        'fields' => [
            ['label' => 'Beoordeling docent', 'name' => 'beoordeling_docent', 'type' => 'scale', 'required' => true, 'min' => 1, 'max' => 5],
            ['label' => 'Beoordeling lesmateriaal', 'name' => 'beoordeling_materiaal', 'type' => 'scale', 'required' => true, 'min' => 1, 'max' => 5],
            ['label' => 'Opmerkingen', 'name' => 'opmerkingen', 'type' => 'textarea', 'required' => false],
        ],
        'covers' => ['req:post_evaluation'],
    ],
],
```

Before coding, verify the exact field-option shape (`'options'` as flat list vs label/value pairs) against one existing group in the questionnaire builder admin (`Modules/Questionnaire/Admin/`) — match whatever `QuestionnaireRenderer` reads.

- [ ] **Step 3: Smoke-run + check** — reseed; `ddev exec wp eval 'print_r(get_option("stride_questionnaire_field_groups"));'` shows both groups, assignments populated. Run twice — no duplicate groups.
- [ ] **Step 4: Commit** — `git commit -am "feat(seed): voucher scopes + custom enrollment form with all 7 field types"`

### Task 8: Author the full coverage matrix

**Files:**
- Modify: `scripts/seed/matrix.php` (this is the bulk of the data work)

**Test tier:** `no unit test: Tier B — pure data; the verifier (Task 9) is its test`

- [ ] **Step 1: Add the remaining course entries.** Each row below is one matrix entry; build it with the same key vocabulary as the Task-1 examples, Dutch demo-quality titles/content (reuse the current seed's Dutch copy wherever a row matches an existing course — courses marked ⟳ keep their current title + lessons verbatim):

| # | Title (⟳ = keep current copy) | Model / type | Editions: status + notable config | Registrations / quotes | covers (beyond the obvious) |
|---|---|---|---|---|---|
| 1 | ⟳ E-learning: Basiskennis Jeugdgezondheid | pure-LD open, online | — (no edition) | admin LD-enrolled | model:pure_ld_open |
| 2 | ⟳ E-learning: Voeding en Prestatie | pure-LD open, online, 90-day expiry | — | — | model:pure_ld_open |
| 3 | ⟳ MGV rond Voeding (Task-1 entry, extended) | closed single, in_person | +1 past edition `completed` w/ post_evaluation+post_documents; student1 completed + attendance + post tasks + quote exported | reg:completed, flow:attendance_marked, flow:post_course_ready | status:completed, quote:exported |
| 4 | ⟳ E-learning: Beweegbeleid Ontwikkelen | multi-edition, online closed | ed.A `in_progress` (started -30d), ed.B `open` (+2w) | admin confirmed on A | model:multi_edition, status:in_progress |
| 5 | ⟳ Sportblessures Voorkomen | closed, in_person, 3-day | ed.A `open` near-full (capacity 12, fake_registered 10) — replaces invalid `few_spots`; ed.B `completed` past w/ all 3 post reqs, student1 attendance | status:few_spots, capacity:full_fake_display, req:post_* |
| 6 | **Masterclass Mentale Veerkracht** (⟳) | closed, in_person | ed.A `full`, capacity 8, **8 REAL regs** (admin+students1-3+enrolled/completed users? no — use seed_filler1..6 + student4+student5) + **1 waitlist reg behind it** (student1, status waitlist); ed.B `open` future | capacity:full_real_users, capacity:waitlist_behind_full, reg:waitlist, status:full |
| 7 | ⟳ Gezonde Tussendoortjes | closed, in_person, slots | `open`, session_slots "Verdieping (kies 1)" (3 parallel sessions), selection_deadline +4w, selection_open, requires_session_selection; student2 pending w/ init_tasks | sessions:slots_choose_n, sessions:selection_deadline, req:pre_session_selection, reg:pending |
| 8 | ⟳ Workshop Yoga en Mindfulness | closed, in_person | ed.A `cancelled`; ed.B `postponed` (NEW — covers postponed); ed.C `open` rescheduled | status:cancelled, status:postponed |
| 9 | ⟳ Gratis Introductie BWEEG | closed, in_person, free | `open`, price 0, capacity 30 | interest reg (anonymous, no user) on an `announcement` ed.B (NEW) | reg:interest, status:announcement, capacity:unlimited on ed w/ capacity 0 |
| 10 | ⟳ Erkenningstraject Jeugdsportcoach | closed, in_person | `open`, requires questionnaire+documents+approval, **enrollment_form 'default' + qg_enrollment_seed custom group assigned (Task 7)**; student3 pending w/ init_tasks; quote draft | req:pre_questionnaire, req:pre_documents, req:pre_approval, form:custom_all_types |
| 11 | ⟳ Bijscholing Bewegingsonderwijs | closed, in_person | `open`, requires_documents; partner-path reg for seed_partner-company user (student1, path partner) + colleague-path reg (student2 by student1) | path:partner, path:colleague, req:pre_documents |
| 12 | ⟳ Beweegbeleid op School (hybrid) | closed, hybrid | `open`; sessions: 2× in_person + 2× online (link_lesson) + 1× webinar + **1× assignment (NEW)**; admin confirmed | edition_type:hybrid, sessions:type_assignment, sessions:lesson_linked |
| 13 | ⟳ Webinarreeks Actuele Thema's | closed, webinar | `open`, 4 weekly webinar sessions; cancelled reg (student5, status cancelled, quote cancelled) | edition_type:webinar, reg:cancelled, quote:cancelled |
| 14 | ⟳ Gratis Webinar Energy Drinks | closed, webinar, free | `open` | — | edition_type:webinar |
| 15 | **Nieuw najaarsaanbod** (new) | closed, in_person | ed.A `draft`; ed.B `archived` (past) | — | status:draft, status:archived |
| 16–20 | ⟳ trajectory courses (Fundament, Assessment, 3 keuzecursussen) | trajectory courses | as current seed, `open` | — | model:trajectory_course |

Every edition entry in rows 3–7 (the "featured" ones) also carries `speakers` as a `{name, role}` array and the full 7-key `content` block (`content:edition_fields`).

- [ ] **Step 2: Add the trajectories** — port the 3 current trajectories (cohort + self-paced + free entry, `courses` JSON shape unchanged, course references by TITLE resolved to IDs by the builder, replacing the brittle index-position scheme) + keep `test-trajectory` (slug-pinned, E2E fixture). covers: `trajectory:cohort`, `trajectory:self_paced`, `trajectory:elective_choose_n`.
- [ ] **Step 3: Registration distribution sanity** — across all entries the matrix must contain ≥1 of each: 6 reg statuses, 4 paths, 4 quote statuses. The table above provides: confirmed (many), pending (7, 10), completed (3), cancelled (13), waitlist (6), interest (9); individual (many), colleague (11), trajectory (add one trajectory-path reg on row 16's edition for seed_enrolled_user), partner (11); draft (10), sent (2), exported (3), cancelled (13).
- [ ] **Step 4: Syntax check + full reseed smoke-run** — `ddev exec php -l scripts/seed/matrix.php && ddev exec wp eval-file scripts/seed.php`. Expected: all entities created, zero `!` error lines.
- [ ] **Step 5: Commit** — `git commit -am "feat(seed): full feature-coverage matrix — 9 statuses, 6 reg statuses, 4 paths, 4 quote statuses, voucher scopes"`

`── REVIEW GATE ── (tier: STANDARD — registration/quote/voucher builders + the coverage matrix; reviewer holds Tasks 5–8: status-value validity, partner company scoping, pre-cancelled quote safety, matrix↔builder key drift)`

---

# Phase 3 — Verification + unseed compatibility

### Task 9: `scripts/seed-verify.php` — mechanical dimension assertions

**Files:**
- Create: `scripts/seed-verify.php`

**Test tier:** `no unit test: Tier B — the verifier IS the test harness; its own correctness is proven by Task 12 (it must fail when a dimension is deliberately removed — see Step 3)`

- [ ] **Step 1: Create the verifier.** It reads `stride_seed_covers` + the DB and asserts (exit 1 + red line per failure):

```php
<?php
/** Run: ddev exec wp eval-file scripts/seed-verify.php  — exits non-zero on any missing dimension. */
if (!defined('ABSPATH')) { exit(1); }

$failures = [];
$check = function (string $label, bool $ok) use (&$failures) {
    echo ($ok ? "  OK   " : "  FAIL ") . $label . "\n";
    if (!$ok) { $failures[] = $label; }
};

global $wpdb;
$manifest = get_option('stride_seed_manifest') ?: [];
$covers = get_option('stride_seed_covers') ?: [];
$allTags = array_unique(array_merge([], ...array_values($covers)));

// 1. Tag-list completeness: every REQUIRED dimension tag is claimed by some entity
$required = [
    'model:pure_ld_open','model:closed_single','model:multi_edition','model:trajectory_course',
    'edition_type:in_person','edition_type:online','edition_type:hybrid','edition_type:webinar',
    'content:edition_fields','content:speakers_repeater',
    'sessions:slots_choose_n','sessions:selection_deadline','sessions:type_assignment','sessions:lesson_linked',
    'req:pre_session_selection','req:pre_questionnaire','req:pre_documents','req:pre_approval',
    'req:post_evaluation','req:post_documents','req:post_approval',
    'form:default','form:minimal','form:direct','form:custom_all_types','form:reserved_fields',
    'capacity:full_real_users','capacity:waitlist_behind_full',
    'trajectory:cohort','trajectory:self_paced','trajectory:elective_choose_n',
    'flow:attendance_marked','flow:post_course_ready',
];
foreach ($required as $tag) { $check("tag claimed: {$tag}", in_array($tag, $allTags, true)); }

// 2. DB truth (claims are not enough — verify against actual rows)
$statuses = $wpdb->get_col("SELECT DISTINCT meta_value FROM {$wpdb->postmeta} pm
    JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'vad_edition'
    WHERE pm.meta_key = '_ntdst_status'");
foreach (['draft','announcement','open','full','in_progress','postponed','cancelled','completed','archived'] as $s) {
    $check("edition status in DB: {$s}", in_array($s, $statuses, true));
}
$regStatuses = $wpdb->get_col("SELECT DISTINCT status FROM {$wpdb->prefix}vad_registrations WHERE user_id < 900000 OR user_id IS NULL");
foreach (['confirmed','pending','completed','cancelled','waitlist','interest'] as $s) {
    $check("registration status in DB: {$s}", in_array($s, $regStatuses, true));
}
$paths = $wpdb->get_col("SELECT DISTINCT enrollment_path FROM {$wpdb->prefix}vad_registrations");
foreach (['individual','colleague','trajectory','partner'] as $p) { $check("enrollment path: {$p}", in_array($p, $paths, true)); }
$quoteStatuses = $wpdb->get_col("SELECT DISTINCT meta_value FROM {$wpdb->postmeta} pm
    JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'vad_quote' WHERE pm.meta_key = '_ntdst_status'");
// NOTE: verify the actual quote status meta key ('_ntdst_status' vs bare 'status') against one seeded row first.
foreach (['draft','sent','exported','cancelled'] as $s) { $check("quote status: {$s}", in_array($s, $quoteStatuses, true)); }

// 3. Spot checks with real reads (catches the json-decode-to-[] gotcha)
$editionRepo = ntdst_get(\Stride\Modules\Edition\EditionRepository::class);
$featured = null;   // first edition tagged content:speakers_repeater, from $covers keys "edition:{id}"
foreach ($covers as $key => $tags) {
    if (str_starts_with($key, 'edition:') && in_array('content:speakers_repeater', $tags, true)) {
        $featured = (int) substr($key, 8); break;
    }
}
$speakers = $featured ? $editionRepo->getField($featured, 'speakers') : null;
$check('featured edition speakers is non-empty {name,role} array',
    is_array($speakers) && !empty($speakers) && isset($speakers[0]['name'], $speakers[0]['role']));
foreach (['target_audience','required_experience','included','price_includes','cancellation_policy','cta_benefits','enrollment_info'] as $f) {
    $check("featured edition content field set: {$f}", $featured && (string) $editionRepo->getField($featured, $f) !== '');
}
// Full-real edition: count(real confirmed regs) == capacity AND a waitlist reg exists behind it
// Voucher scopes: scope_mode values all/only/except each present on >=1 vad_voucher
// Questionnaire: qg_enrollment_seed group exists, has 8 fields, types include all 7
// Partner data: >=1 registration with enrollment_path='partner' AND company_id=1
// Attendance: >=1 row in {$wpdb->prefix}vad_attendance for a seed user
//   → implement each as $check(...) with a direct $wpdb or repository read, same pattern as above.

echo "\n" . (empty($failures) ? "ALL DIMENSIONS COVERED\n" : count($failures) . " FAILURES\n");
exit(empty($failures) ? 0 : 1);
```

- [ ] **Step 2: Run it against the seeded DB** — `ddev exec wp eval-file scripts/seed-verify.php; echo "exit: $?"`. Expected: `ALL DIMENSIONS COVERED`, exit 0. Fix matrix gaps it finds (this is the RED→GREEN loop for the matrix).
- [ ] **Step 3: Prove the verifier bites** — temporarily change one matrix edition status (`postponed` → `open`), reseed onto a clean DB, run verifier: expected FAIL + exit 1. Revert. (This is the Tier-B equivalent of a denial-path test.)
- [ ] **Step 4: Commit** — `git commit -am "feat(seed): seed-verify.php asserts dimension coverage mechanically"`

### Task 10: unseed.php updates for new entity kinds

**Files:**
- Modify: `scripts/unseed.php`

**Test tier:** `no unit test: Tier B — destructive dev tooling; proven by the Task 12 unseed→reseed cycle leaving zero seed residue (Step 2 residue query)`

- [ ] **Step 1: Add to `StrideSeedCleaner`:**
  - `removeAttendance()`: `DELETE a FROM {$wpdb->prefix}vad_attendance a JOIN {$wpdb->prefix}vad_registrations r ON r.edition_id = a.edition_id` is wrong scope — instead delete attendance rows whose `edition_id` is in the manifest's `editions` list, and rows for users in the manifest's `users` list. Run BEFORE `removeRegistrations()`.
  - `removeQuestionnaireGroups()`: load `stride_questionnaire_field_groups`, drop groups whose `id` ends with `_seed` (`qg_enrollment_seed`, `qg_eval_seed`), save back. If the option becomes an empty array, delete it.
  - `cleanupOptions()`: additionally `delete_option('stride_seed_covers')`.
  - Counter keys for the new kinds in `$removed`.
- [ ] **Step 2: Verify clean teardown** — `ddev exec bash -c 'FORCE_UNSEED=1 wp eval-file scripts/unseed.php'` then residue query:
  `ddev exec wp db query "SELECT COUNT(*) FROM ckqp_postmeta WHERE meta_key='_stride_seed_data'"` → 0;
  `ddev exec wp db query "SELECT COUNT(*) FROM ckqp_vad_registrations"` → 0 (on a seed-only dev DB);
  `ddev exec wp eval 'var_dump(get_option("stride_seed_covers"), get_option("stride_seed_manifest"));'` → `bool(false)` twice.
  (Adjust table prefix to the live one — local DDEV uses `ckqp_`, memory: `bug_acceptance_prefix_mismatch`.)
- [ ] **Step 3: Commit** — `git commit -am "fix(unseed): tear down attendance, seed questionnaire groups, covers option"`

### Task 11: Delete the legacy imperative body + docs touch-up

**Files:**
- Modify: `scripts/seed.php` (confirm no legacy `StrideSeedData` remnants)
- Modify: `CLAUDE.md` (seed section: mention `scripts/seed-verify.php` one-liner; commands unchanged)

**Test tier:** `no unit test: Tier B — deletion + docs`

- [ ] **Step 1:** Ensure no dead code: `grep -n "StrideSeedData" scripts/ -r` → no hits.
- [ ] **Step 2:** Update CLAUDE.md "Seed/Unseed Development Data" block: add `ddev exec wp eval-file scripts/seed-verify.php   # assert feature-dimension coverage`.
- [ ] **Step 3: Commit** — `git commit -am "chore(seed): remove legacy seeder remnants, document verify step"`

`── REVIEW GATE ── (tier: STANDARD — verifier correctness + destructive unseed logic; reviewer holds Tasks 9–11: does the verifier actually bite (Step 3 evidence required), is unseed scoped to seed data only)`

---

# Phase 4 — The real run (acceptance)

### Task 12: Unseed + reseed on DDEV, verify, manual spot-check

**Files:** none (execution + evidence)

**Test tier:** `no unit test: Tier B — this task IS the acceptance run; evidence = command output pasted into the PR description`

- [ ] **Step 1: Full cycle:**
```bash
ddev exec bash -c 'FORCE_UNSEED=1 wp eval-file scripts/unseed.php'
ddev exec wp eval-file scripts/seed.php
ddev exec wp eval-file scripts/seed-verify.php; echo "verify exit: $?"
```
Expected: cleanup summary with zero residue, seed summary with all kinds > 0, `ALL DIMENSIONS COVERED`, exit 0.
- [ ] **Step 2: Idempotency:** run `ddev exec wp eval-file scripts/seed.php` a second time without unseeding. Expected: all "exists" lines, zero duplicates, verifier still green.
- [ ] **Step 3: Manual demo spot-check** (browser, https://stride.ddev.site, login `seed_admin@seed.test` / `seedpass123`):
  1. `/vormingen/` catalog — one card per enrollable; full edition shows "Volzet" badge; announcement edition shows interest CTA.
  2. Featured edition detail (MGV rond Voeding) — all content sections render (doelpubliek, voorkennis, inbegrepen, annulering, voordelen) and the speakers repeater shows 2 names with roles.
  3. The full real-users edition (Masterclass) — enrollment closed, waitlist visible in admin; the waitlist registration for student1 appears.
  4. Erkenningstraject enrollment form — custom group renders all 7 field types incl. scale + description.
  5. Admin dashboard "Acties nodig" — pending registrations with tasks appear.
  6. Admin quotes list — all 4 statuses visible.
  7. Partner API smoke: `curl -u` with an application password for `seed_partner` against `/wp-json/stride/v1/partner/enrollments` returns the partner-path registration (company_id=1).
- [ ] **Step 4: Commit any matrix copy fixes found during spot-check** — `git commit -am "fix(seed): demo copy adjustments from spot-check"`.

`── REVIEW GATE ── (tier: LIGHT — evidence review of the run outputs + spot-check list; no code in this cluster)`

---

## Self-review record (writing-plans checklist)

- **Spec coverage:** every bullet of the required-coverage list maps to a matrix row (Task 8 table) + a verifier assertion (Task 9). `few_spots` corrected to a derived dimension (open question 1). Fake-vs-real capacity resolved as both (row 5 fake-display, row 6 real+waitlist).
- **Placeholders:** Task 2/5 use "port lines N–M unchanged" pointers into the existing `scripts/seed.php` — these are concrete references to code the implementer has in-repo, not TBDs. Two deliberate verify-first notes remain (quote status meta key in Task 9; questionnaire option shape in Task 7; `company_id` column in Task 5) — each is a named 30-second check, not an open design question.
- **Type consistency:** builder names (`buildUser/buildCourse/buildLessons/buildEdition/buildSession/buildRegistration/buildQuote/buildVoucher/buildQuestionnaireGroups/buildTrajectory`) and the covers vocabulary are used identically across Tasks 1–9.
