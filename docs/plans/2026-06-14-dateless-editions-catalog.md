# Dateless Editions in Catalog + Admin Grid — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make dateless editions (no sessions → no `start_date`/`end_date` meta) appear in the public catalog and in the default admin editions list, so the interest mechanism they anchor stops being stranded. A dateless **klassikaal** edition is "not yet scheduled" and lists under a "Binnenkort — toon interesse" band with an interest-variant card. A dateless **online** edition is always-on/self-paced and lists as a normal enroll card (no band, no interest framing) — by design.

> **Domain ruling (Stefan, 2026-06-14).** Online courses are 24/7 self-paced — they are ALWAYS available. There is no "awaiting scheduling" state for an online course; that state only exists for klassikaal editions that have no sessions yet. Therefore the interest concept (band + interest CTA) is a **/klassikaal-only** concept. Online dateless editions are normal enrollables. This is a *resolved design*, not a deferred gap — the earlier "online dateless asymmetry / tracked non-goal" is GONE.

**Architecture:** The exclusion is purely query-shape: every catalog caller pairs the shared eligibility `meta_query` with `orderby=meta_value` + `meta_key=start_date`, which forces an implicit `EXISTS` join on `start_date`; the admin list does the same via an `INNER JOIN` on `_ntdst_start_date`. The **inclusion fix is symmetric** across klassikaal and online (and the admin list): (a) drop date-ordering from SQL/WP_Query and (b) permit NULL `start_date` in the shared `meta_query` fallback and the admin JOIN — so every dateless published+active edition is now enumerated everywhere. The **interest treatment is klassikaal-only**: (c) the /klassikaal items path sorts the PHP list into three bands (dated-soon ASC · dateless · dated-grace ASC) with a page-1 guard that hoists the dateless band into the first rendered page; the /online items path returns a **flat enrollable list with no band** (and no band-ordering pass). (d) The card variant ("Geen datum — toon interesse" + "Toon interesse" CTA) keys off the **interest-eligibility signal** (effective status `Announcement` → `allowsInterest()`), NOT off date-absence — which is exactly why an online dateless edition (effective status stays `Open`) renders as a normal enroll card with zero extra branching. The band separator is a **page-1 server-render-only** concern on /klassikaal — the "Toon meer" / theme-filter endpoint returns flat cards (Band B is fully consumed on page 1).

**Tech Stack:** PHP 8.3, WordPress (Bedrock), WP_Query / `$wpdb`, NTDST repositories, PHPUnit (Unit + Integration suites), Alpine.js (catalog paging, untouched logic).

---

## Classification & gate decisions (planner judgment layer)

**Class A** — a multi-task behavior change spanning the public catalog query layer, a new PHP ordering function (klassikaal only), the admin REST list query, and a card variant; routed through TDD with review gates. Stage 0 (brainstorm) is **skipped by instruction**: the product ruling and design are already decided by Stefan (2026-06-14, including the online-always-on clarification). Stage 1c premise ground-truthing was performed against live source before this plan shipped (see "Ground-truth notes" below), and re-confirmed after the clarification (the `Announcement`/`allowsInterest()` trigger is verified against `getEffectiveStatusFromPrefetched()`).

**Gates that FIRE:**

- **1b Architecture-invariants — FIRES.** Trigger: the work touches the **INV-7 catalog-card convergence point** (`stridence_catalog_render_cards()` + the batch pre-pass) and the **INV-3** convergence (query shapes in repositories / meta-prefix from the model). Cited inline per-task. The new band-ordering is a *presentation-ordering* layer over INV-7 statuses, not a new status decision — it must consume `getEffectiveStatuses()`, never re-derive status. **The card interest variant likewise keys off the INV-7 effective status (`Announcement`), never off a raw date check** — this is what makes klassikaal-vs-online fall out of the status engine instead of a forked rule. **INF-1** (only a published course may leak its title into a public card) is the existing guard that already lives in the eligible-items builder and `card-edition.php`; the dateless change must not weaken it.
- **1g Feature-acceptance — FIRES (lightweight).** The change alters a user-facing surface (catalog listing + card CTA). An `## Acceptance flows` matrix is embedded below. Driven at shake-out via the existing `tests/acceptance/CatalogShakeoutCest.php` harness. The matrix now includes the **online-dateless-renders-normal-enroll** flow (AF-5) as a first-class assertion, not a deferral.
- **1a Threat-modeling — FIRES (scoped, low).** Trigger hit literally, not by gut: this is a **cross-role visibility surface** change (a previously-hidden published edition's title becomes publicly listable). It is NOT a new input surface — the catalog endpoint, its public-nonce exemption, and its param sanitisation are unchanged. The threat model below is therefore a focused visibility-leak analysis, not a full asset/actor enumeration. Run the `threat-modeling` skill's lens on the one new exposure: *does a draft/private/trashed thing become publicly listable as a side effect of permitting NULL start_date?* The exposure is identical for klassikaal and online — both gain dateless inclusion — so the guard analysis below covers both.

**Gates that DO NOT fire (and why):**

- **API / boundary design (`designing-apis`) — no.** No new endpoint, route, param, or contract. `CatalogEndpoint::handleCatalogPage()` keeps its exact signature and response shape; only the *ordering of items inside the existing klassikaal list* changes (online returns a flat list as before). `getEditions` keeps its REST contract.
- **`doubting-decisions` — no.** The big decision (mid-page band placement + interest framing, scoped to klassikaal) is already made and ground-truthed as coherent with the existing `Announcement`/`allowsInterest()` machinery; the online-always-on clarification *removes* the one open question that previously lingered (the asymmetry), so there is now no fresh architectural bet to attack.
- **`refining-ideas` / brainstorming — no.** Intent is concrete and specified.

---

## Threat / scope note (1a — scoped visibility analysis)

**Asset at risk:** the set of `vad_edition` posts that become publicly listable (both klassikaal and online).

**The one new exposure.** Permitting NULL `start_date` widens the catalog corpus to include dateless published editions on **both** /klassikaal and /online. The question is whether anything *not already intended for the public* slips in. The interest-band scoping (klassikaal-only) is a presentation concern and does NOT affect the visibility analysis — the inclusion fix is symmetric and so is the guard set below.

**Mitigations — all pre-existing, must be preserved (the change must not bypass them):**

| Guard | Where | Effect |
|---|---|---|
| `post_status = 'publish'` on every edition query | all catalog WP_Query + the admin JOIN's WHERE | a **draft / private / trashed edition** is never enumerated. Permitting NULL start_date does NOT touch this clause. |
| status IN `OfferingStatus::activeValues()` | `stridence_catalog_date_window_meta_query()` (unchanged clause) | only `announcement / open / full / in_progress` editions list; `draft`-status editions are excluded even if published. |
| Published-course guard (INF-1) | `stridence_catalog_edition_items_from_ids()` (`get_post_status($course_id) !== 'publish'` → skip) **and** mirrored in `card-edition.php` | a dateless edition whose linked **course** is draft/trashed produces no card and leaks no title. Course-less editions (`course_id 0`) stay eligible by existing rule. |
| Effective-status filter on course-card pre-pass | `stridence_prefetch_course_cards()` (`!$eff->isActive()` skip) | unchanged. |

**Conclusion / intended-behavior confirmation.** A dateless edition becoming publicly listable **is the intended behavior** (Stefan, 2026-06-14) for both kinds — klassikaal lists it as an interest anchor, online lists it as an always-on enrollable — and is correct *only* for `post_status=publish` + active-status editions whose course (if any) is published, which is exactly what the four guards above already enforce. The fix changes ordering and the NULL-permitting fallback; it must add **zero** new bypass of these guards. **Test contract for the visibility edge is mandatory** (Task 2, the integration test asserts a draft edition and a publish-edition-with-draft-course both stay OUT while a publish dateless edition with a publish course comes IN — asserted on the klassikaal builder; the online builder shares the identical guard path).

**Deferrals (out of scope):** rate-limiting / abuse of the public catalog endpoint (unchanged, pre-existing). **No status-engine deferral remains** — the online-vs-klassikaal distinction is fully resolved by reading the effective status, not deferred (see "Resolved design" below).

---

## Architecture invariants touched (1b)

- **INV-7 (display status via `getEffectiveStatus`)** + its **catalog-card convergence point.** Two consumers of the effective status here, both reading it, neither re-deriving it:
  1. The klassikaal band classifier keys off `start_date` presence (a calendar fact) for *placement only*, and renders status/CTA from `getEffectiveStatuses()`.
  2. **The card interest variant keys off the effective status value (`Announcement`)** — `allowsInterest()` — for the "toon interesse" framing. This is the load-bearing change from the clarification: it is the effective status, not a date check, that distinguishes a klassikaal dateless edition (`Announcement`) from an online dateless edition (`Open`). Both come from the one INV-7 decision point. ✅ enforced by Task 5's contract (asserts an online dateless edition with effective status `Open` renders the normal enroll CTA).
- **INV-3 (query shapes in repositories; meta keys from the model prefix).** No new meta key is hardcoded: the shared builder already derives `$prefix . 'start_date'` from `EditionRepository::getMetaPrefix()`. The admin `getEditions` query lives in the **accepted-zone** `AdminAPIController` (INV-3 docblock explicitly grandfathers this file's `$wpdb` reads pending the post-launch service extraction); the meta key `_ntdst_start_date` there matches the model prefix and is left in place per that accepted-zone ruling — we change the JOIN type, not introduce a new raw shape. ✅
- **INF-1 (published-course leak guard).** Preserved verbatim; Task 2's test asserts it still holds for dateless editions.

---

## Resolved design — klassikaal interest vs online always-on (was: "Known nuance")

The status engine already encodes the exact distinction the product ruling needs, so **no status-engine reconciliation is required** — the card simply reads the effective status it is already handed.

**Ground-truthed against live source (`EditionService::getEffectiveStatusFromPrefetched()` + `OfferingStatus`):**

| Edition kind | `isClassroom` | sessions | Effective status (rule 3) | `allowsInterest()` | `allowsEnrollment()` | Catalog card treatment |
|---|---|---|---|---|---|---|
| **Klassikaal, dateless** | `true` | 0 | **`Announcement`** | **true** | false | **Band B + "Geen datum — toon interesse" + "Toon interesse" CTA** |
| **Online, dateless** | `false` | 0 | rule 3 skipped → stored **`Open`** | false | true | **Flat list, normal "Bekijk editie"/enroll card — NO band, NO interest framing** |

Rule 3 of the decision engine — `if ($isClassroom && $publishedSessionCount === 0) return Announcement;` — fires only for classroom editions, so an online dateless edition keeps its stored `Open` status. `allowsInterest()` is true **only** for `Announcement`. Therefore:

- **The card interest variant trigger is `$status === 'announcement'`** (equivalently `OfferingStatus::from($status)->allowsInterest()`), where `$status` is the prefetched **effective** status the catalog pre-pass already passes to `card-edition.php` (`@type string $status` — INV-7). It is **NOT** `empty($start_date)`. This single change makes online dateless editions render as normal enroll cards with no special-casing.
- **The "Binnenkort — toon interesse" band and its separator are a /klassikaal concept only.** `page-online.php` does **not** render the band; `stridence_catalog_online_items()` does **not** call the band-ordering function — it returns a flat enrollable list. Only `stridence_catalog_klassikaal_items()` band-orders.

This replaces the previous "Known nuance / online dateless asymmetry" non-goal entirely. There is no follow-up to track and no code comment about an unreconciled asymmetry — the design IS the reconciliation.

---

## Acceptance flows (1g)

| # | Flow | Steps | Edges (six classes: empty · error · boundary · concurrent · unauthorized · malformed) |
|---|---|---|---|
| AF-1 | Dateless klassikaal edition lists in `/klassikaal` under the "Binnenkort — toon interesse" band | publish a dateless klassikaal edition (active status, published course) → load `/klassikaal` | **empty:** no dateless klassikaal editions → no band header renders (Band B empty). **boundary:** Band A alone fills page 1 (≥24 dated-soon) → page-1 guard still hoists Band B onto page 1. **error:** N/A (read path). **concurrent:** N/A. **unauthorized:** guest sees the same listing (catalog is public). **malformed:** edition with `start_date` present but `end_date` missing must stay a *dated* edition (Band A/C), not fall into Band B. |
| AF-2 | Dateless klassikaal card shows interest framing + CTA destination is real | render the dateless klassikaal card (effective status `Announcement`) → click "Toon interesse" | **boundary:** card with `course_id 0` (no course) still renders. **unauthorized:** guest reaches `/interesse/?editie=ID`. **error:** CTA target resolves (single-edition page routes `Announcement` → interest). **empty:** dateless card has no date meta line — renders "Geen datum — toon interesse" instead. |
| AF-3 | Dateless edition appears in default admin editions list (list view) | GET `/admin/editions?view=list` with no `date_from` → dateless edition present | **empty:** no editions at all → empty list, no SQL error. **boundary:** mix of dated + dateless ordered with dateless not crashing the `ORDER BY`. **unauthorized:** endpoint keeps its `permission_callback` (unchanged). **malformed:** `date_from`/`date_to` supplied → dateless (NULL start) correctly excluded by the range filter (range implies dated intent). |
| AF-4 | Visibility leak guard holds | publish a dateless edition whose **course is draft**; also a **draft** dateless edition | both stay OUT of `/klassikaal` and `/online`; a publish dateless edition with publish course comes IN | **unauthorized:** the draft-course case must not leak the course title. This is the threat-model edge — covered by Task 2's integration test. |
| AF-5 | **Dateless ONLINE edition lists in `/online` as a normal enroll card (no interest band, no interest CTA)** | publish a dateless online edition (active status, published online course) → load `/online` | **empty:** /online renders no "Binnenkort" header at all (band is klassikaal-only). **boundary:** a /online page containing both dated and dateless online editions renders one flat enrollable grid (no separator). **error:** card CTA is the normal enroll/"Bekijk editie" path, NOT "Toon interesse". **unauthorized:** guest sees the enroll card. **malformed:** an online edition that is somehow `Announcement` (mis-set) would show interest — out of scope; we assert the *normal online* case (status stays `Open` → enroll). |

Driven at shake-out via `tests/acceptance/CatalogShakeoutCest.php` (extend with both a dateless **klassikaal** fixture and a dateless **online** fixture so AF-5's "no band on /online" and "enroll CTA, not interest CTA" are exercised, not assumed).

---

## Ground-truth notes (Stage 1c — verified against live source 2026-06-14)

- `stridence_catalog_date_window_meta_query()` (`helpers/catalog.php:68`) — confirmed the OR-fallback's dateless branch still requires `start_date >= cutoff`, and the docblock (`:56-63`) documents the runtime-proven exclusion. ✅ (shared by both klassikaal and online builders — the inclusion fix is symmetric.)
- Four `orderby=meta_value` + `meta_key=…start_date` sites confirmed: `stridence_catalog_klassikaal_items()` (`:137-139`), `stridence_catalog_online_items()` (`:211-213`), `archive-sfwd-courses.php` online section (`:73-74` and `:113-114`), and the classroom-teaser query in the archive (`:74` — note this query has NO date window, status-only). `stridence_prefetch_course_cards()` (`:455-472`) has NO `start_date` orderby (default order) — so it does **not** exclude dateless editions and needs no change for inclusion; it is the per-course "primary edition" picker, out of scope for band ordering.
- Admin `getEditions` list view: `INNER JOIN … pm_start … _ntdst_start_date` + default `pm_start.meta_value >= $twoDaysAgo` confirmed (`AdminAPIController.php:783-844`). The **agenda** view delegates to `getEditionsAgendaView()` (`:778`) — by definition session-date rows, so a sessionless edition has no agenda row; **agenda view is out of scope**, only **list view** gets the dateless fix.
- `EditionService::isPastDates()` (`:277-285`) — "no dates at all → not past" confirmed. `getEffectiveStatusFromPrefetched()` (`:337-355`) — **rule 3: `if ($isClassroom && $publishedSessionCount === 0) return Announcement;`** confirmed verbatim; an online (non-classroom) dateless edition skips rule 3 and returns its stored status (`Open`). This is the load-bearing fact for the klassikaal-vs-online card split. ✅
- `OfferingStatus` (`Domain/OfferingStatus.php:29-40`): `allowsEnrollment()` true **only** for `Open`; `allowsInterest()` true **only** for `Announcement`. `Announcement` is in `activeValues()` (`:64-72`) so the status IN-clause already permits dateless editions — confirming the only blocker is the `start_date` join/order. ✅
- `card-edition.php` (`:23`, `:55`) — the card already receives `@type string $status` = the **prefetched EFFECTIVE status** from `EditionService::getEffectiveStatuses()` (INV-7), and binds it to `$status` (with a stored-value fallback). **So the interest-variant trigger `$status === 'announcement'` reads data the card already has — no new arg, no service call, pure-renderer contract preserved.** ✅
- `single-vad_edition.php` (`:638-644`) routes `$status->allowsInterest()` → "Interesse melden" → `/interesse/?editie=ID`. **The CTA destination is real for klassikaal dateless editions.** An online dateless edition (status `Open`) routes to the normal enroll CTA on the same page — consistent with the card. ✅
- `card-edition.php` is a pure renderer with the INF-1 guard at `:63-65`; meta block at `:155-170` already guards `if ($start_date)`. ✅
- `CatalogEndpoint::handleCatalogPage()` returns flat `array_slice` cards (`:67-76`); the page templates `innerHTML = res.html` on filter-replace and `insertAdjacentHTML` on append (`page-klassikaal.php:87-92`). **This is why the band separator must be page-1-server-render-only — and why it is moot for /online, which has no band.** ✅
- Test infra: root `phpunit.xml.dist` (Unit) + `phpunit-integration.xml.dist`; existing `tests/Integration/CatalogEndpointTest.php`, `CatalogBatchHydrationTest.php`, `CatalogTrashedCourseTest.php`, `tests/Unit/EditionServiceEffectiveStatusTest.php`, `tests/acceptance/CatalogShakeoutCest.php`. ✅

---

## File structure

| File | Responsibility | Change |
|---|---|---|
| `web/app/themes/stridence/helpers/catalog.php` | shared eligibility `meta_query` + per-catalog item builders + the new band-ordering pure function | Modify: NULL-permit the fallback (symmetric); drop SQL date-order from BOTH builders (symmetric inclusion); add `stridence_catalog_order_into_bands()` and apply it **only in `stridence_catalog_klassikaal_items()`** — `stridence_catalog_online_items()` returns a flat list |
| `web/app/themes/stridence/page-klassikaal.php` | `/klassikaal` page render + page-1 slice + Alpine paging | Modify: render the Band-B separator inside the page-1 slice only |
| `web/app/themes/stridence/page-online.php` | `/online` page render | **No band change** — renders a flat grid as today; only inherits the inclusion fix (more cards). Add a one-line comment noting the band is klassikaal-only by design |
| `web/app/themes/stridence/partials/card-edition.php` | pure renderer | Modify: interest-variant meta line + CTA label, triggered by `$status === 'announcement'` (effective status), data-in only |
| `web/app/mu-plugins/stride-core/Admin/AdminAPIController.php` | admin REST `getEditions` list view | Modify: `INNER JOIN`→`LEFT JOIN` + `OR pm_start.meta_value IS NULL`; ORDER BY NULL-last |
| `web/app/themes/stridence/services/frontend/CatalogEndpoint.php` | catalog paging endpoint | **No change** — already returns flat ordered slices; documented as the reason the separator is page-1-only |
| `tests/Unit/CatalogBandOrderingTest.php` | unit test for the pure band-ordering + page-1 guard | Create |
| `tests/Unit/CardEditionInterestVariantTest.php` | unit test for the card interest-variant trigger (announcement → interest; open → enroll) | Create |
| `tests/Integration/CatalogDatelessInclusionTest.php` | real-DB inclusion (klassikaal + online) + visibility-leak test | Create |
| `tests/Integration/AdminEditionsDatelessTest.php` | real-DB admin list inclusion + range-exclusion | Create |

---

## Band-ordering + page-1-guard design (the load-bearing spec — KLASSIKAAL ONLY)

> **Scope:** This entire section applies to the **/klassikaal items path only.** `stridence_catalog_online_items()` returns a flat enrollable list and never calls `stridence_catalog_order_into_bands()` — online has no "Binnenkort" band by design (online courses are always-on). The page-1 hoist guard and the separator-render therefore only run for klassikaal.

A **pure function** `stridence_catalog_order_into_bands(array $items): array` re-orders an already-built **klassikaal** item list. It receives the light item arrays (each `edition.start_date` is `?string`) and returns the same items, reordered, with the dateless band guaranteed inside the first `STRIDENCE_CATALOG_PER_PAGE`.

**Banding rule** (dates compared as `Y-m-d` strings; `today = date('Y-m-d')`, `grace_cutoff = date('Y-m-d', strtotime('-2 days'))`):

- **Band A** — items where `start_date` is non-empty AND `start_date >= today`, sorted `start_date` ASC (soonest first).
- **Band B** — items where `start_date` is empty/null (the "Binnenkort" group). Stable order (enumeration order preserved).
- **Band C** — items where `start_date` is non-empty AND `start_date < today` (already started, still inside the grace window because the eligibility query already excluded anything past `grace_cutoff`), sorted `start_date` ASC.
- **`course` kind items** (pure-LD courses) have no edition/start_date → treat as Band A tail (dated-soon group, sorted after dated editions by appending in enumeration order). They are evergreen enrollables; keeping them in Band A keeps them on page 1 and out of the interest band. (In practice pure-LD courses only appear on /online, so this branch is defensive on the klassikaal path — but the function stays kind-aware so it never mis-bands a course item.)

Output order: `A ++ B ++ C`.

**Page-1 guard.** If `count(A) >= STRIDENCE_CATALOG_PER_PAGE`, Band B would fall onto page 2+. To honor "dateless always on page 1," hoist B ahead of the overflow tail of A:

- Let `P = STRIDENCE_CATALOG_PER_PAGE`.
- If `count(A) + count(B) <= P` → plain `A ++ B ++ C` (B already on page 1). No hoist needed.
- Else → reserve the last `count(B)` slots of page 1 for B: take the first `P - count(B)` of A, then B, then the **remaining** A, then C. Concretely: `head_A = array_slice(A, 0, P - count(B))`, `tail_A = array_slice(A, P - count(B))`, result = `head_A ++ B ++ tail_A ++ C`.
- Degenerate guard: if `count(B) >= P`, page 1 is all B (`B_page1 = array_slice(B,0,P)`), result = `B ++ A ++ C` (B fully ahead). The function never needs to know about paging beyond page 1 — it just guarantees the *prefix* up to P contains all of B (when `count(B) <= P`) or starts with B.

**Why a pure function, not SQL ordering:** the list is fully enumerated (capped at `STRIDENCE_CATALOG_MAX_ITEMS = 500`) before slicing, so PHP ordering is O(n log n) on ≤500 items — cheap. SQL `ORDER BY meta_value` is exactly what forces the dateless-excluding EXISTS join. Dropping it is the inclusion fix (applied to BOTH builders); the band-ordering pass is the klassikaal-only presentation layer that replaces it on /klassikaal. /online drops the SQL order too but re-orders nothing beyond its existing flat sequence (or a simple dated-ASC sort if the current online render relies on date order — verify at Task 2 Step 3; if /online needs dated ordering, sort it inline ASC by start_date with NULLs last, WITHOUT introducing a band).

**Separator placement (page-1-server-render-only, /klassikaal only).** The "Binnenkort — toon interesse" header is a DOM element rendered between Band A cards and Band B cards **in the page-1 server render of `page-klassikaal.php` only**. `page-online.php` never renders it. The endpoint (`CatalogEndpoint`) returns flat card HTML with no separator — correct, because:
- Band B is fully consumed within page 1 (the guard guarantees it), so pages ≥2 contain only Band-C / Band-A-tail cards and never need a separator.
- The theme-filter `replace` path does `innerHTML = res.html` (page 1 of the filtered set). A filtered page-1 fetch goes through the endpoint and would therefore lose the separator. **Resolution:** the separator is rendered as a card-grid child only in the initial server render; when a theme filter is applied the grid is replaced by endpoint HTML (no separator) — this is acceptable because filtering is a deliberate narrowing and the "Binnenkort" framing is a discovery affordance on the default unfiltered view. **Document this explicitly** in the klassikaal page template and accept it as the design (confirmed consistent with the append-only "Toon meer" path). Do NOT try to make the endpoint emit the separator — that would require the endpoint to know band boundaries and reintroduce ordering knowledge in two places.

To render the separator in the page-1 slice, the page template must know where Band A ends and Band B begins **within the first slice**. A second renderer (`..._render_cards_with_band()`) is **NOT** introduced (avoid a second renderer); instead the page template splits the page-1 slice into the A-prefix and the B-run by the same date predicate, renders `stridence_catalog_render_cards(A_prefix)`, emits the separator markup, then `stridence_catalog_render_cards(B_run)`, then `stridence_catalog_render_cards(rest)`. The split predicate is the same `start_date` emptiness check — reused, not forked.

---

## Tasks

### Task 1: NULL-permit the shared eligibility meta_query (remove the dateless exclusion at the source — symmetric for klassikaal + online)

**Tier A** — query-shape logic with a visibility consequence. **Test contract:** the RED test asserts `stridence_catalog_date_window_meta_query()` produces clauses that, against a real dateless published active edition, MATCH it (inclusion), while still EXCLUDING an edition whose `start_date` is before the grace cutoff. Denial path: a `draft`-status edition must still be excluded (status IN-clause unchanged). The builder is shared, so the same widening serves both /klassikaal and /online.

**Files:**
- Modify: `web/app/themes/stridence/helpers/catalog.php:68-102` (the builder) and its docblock `:51-63`
- Test: `tests/Integration/CatalogDatelessInclusionTest.php` (created in Task 2 — Task 1's behavior is proven there; this task is the minimal builder edit)

- [ ] **Step 1: Write the failing test** — defer the assertion to Task 2's integration test (the builder is only meaningfully testable against a real `WP_Query`/DB). Here, write the builder change to satisfy that test. Mark this task RED-linked to Task 2 Step 2.

- [ ] **Step 2: Edit the meta_query builder to permit fully-dateless editions**

Replace the OR group so the "no end_date" fallback also matches when **start_date is absent entirely** (fully dateless), in addition to the existing start_date-within-grace case:

```php
return [
    [
        'key'     => $prefix . 'status',
        'value'   => OfferingStatus::activeValues(),
        'compare' => 'IN',
    ],
    [
        'relation' => 'OR',
        // (1) dated: end_date within the grace window
        [
            'key'     => $prefix . 'end_date',
            'value'   => $past_cutoff,
            'compare' => '>=',
            'type'    => 'DATE',
        ],
        // (2) end_date missing but start_date within the grace window
        [
            'relation' => 'AND',
            ['key' => $prefix . 'end_date', 'compare' => 'NOT EXISTS'],
            [
                'key'     => $prefix . 'start_date',
                'value'   => $past_cutoff,
                'compare' => '>=',
                'type'    => 'DATE',
            ],
        ],
        // (3) fully dateless: neither end_date nor start_date set. For a
        //     KLASSIKAAL edition these are the "Binnenkort — toon interesse"
        //     anchors; for an ONLINE edition these are always-on enrollables.
        //     Inclusion is gated by post_status=publish + the active-status
        //     IN-clause above + the published-course guard downstream (INF-1)
        //     — see plan threat note. Treatment differs per kind, inclusion
        //     does not.
        [
            'relation' => 'AND',
            ['key' => $prefix . 'end_date', 'compare' => 'NOT EXISTS'],
            ['key' => $prefix . 'start_date', 'compare' => 'NOT EXISTS'],
        ],
    ],
];
```

Update the docblock `:51-63`: remove the "dateless are EXCLUDED" note; replace with "dateless editions are now INCLUDED for both klassikaal and online; the start_date orderby that previously forced the EXISTS join has been removed from all callers. Klassikaal band-orders the result via `stridence_catalog_order_into_bands()`; online returns a flat enrollable list — see plan 2026-06-14-dateless-editions-catalog.md."

- [ ] **Step 3: Commit**

```bash
git add web/app/themes/stridence/helpers/catalog.php
git commit -m "fix(catalog): permit fully-dateless editions in the eligibility meta_query"
```

### Task 2: Drop SQL date-ordering from BOTH catalog item builders + integration-prove inclusion (klassikaal + online) and the visibility guard

**Tier A** — query-shape change with the threat-model visibility edge. **Test contract:** RED integration test asserts (a) a publish, active, dateless **klassikaal** edition with a publish course is RETURNED by `stridence_catalog_klassikaal_items()`; (b) a publish, active, dateless **online** edition with a publish online course is RETURNED by `stridence_catalog_online_items()`; (c) a **draft** dateless edition is NOT returned; (d) a publish dateless edition whose **course is draft** is NOT returned and its title does not appear (INF-1 denial path); (e) a dated edition older than the grace cutoff is still excluded.

**Files:**
- Modify: `web/app/themes/stridence/helpers/catalog.php` — remove `orderby`/`meta_key`/`order` from the `WP_Query` in `stridence_catalog_klassikaal_items()` (`:137-139`) and `stridence_catalog_online_items()` (`:211-213`)
- Create: `tests/Integration/CatalogDatelessInclusionTest.php`

- [ ] **Step 1: Write the failing integration test**

```php
<?php
declare(strict_types=1);

namespace Stride\Tests\Integration;

use WP_UnitTestCase;

/**
 * Dateless editions list in the catalog (klassikaal AND online); the
 * publish/active/published-course guards (INF-1 + threat note) still hold.
 */
final class CatalogDatelessInclusionTest extends WP_UnitTestCase
{
    private string $prefix;

    public function set_up(): void
    {
        parent::set_up();
        $this->prefix = ntdst_get(\Stride\Modules\Edition\EditionRepository::class)->getMetaPrefix();
        require_once get_template_directory() . '/helpers/catalog.php';
    }

    private function makeCourse(string $status, array $formats): int
    {
        $id = self::factory()->post->create(['post_type' => 'sfwd-courses', 'post_status' => $status]);
        wp_set_object_terms($id, $formats, 'stride_format');
        return (int) $id;
    }

    private function makeEdition(string $status, int $courseId, ?string $start): int
    {
        $id = (int) self::factory()->post->create([
            'post_type' => 'vad_edition', 'post_status' => $status, 'post_title' => 'Edition ' . uniqid(),
        ]);
        update_post_meta($id, $this->prefix . 'status', 'announcement');
        update_post_meta($id, $this->prefix . 'course_id', $courseId);
        if ($start !== null) {
            update_post_meta($id, $this->prefix . 'start_date', $start);
            update_post_meta($id, $this->prefix . 'end_date', $start);
        }
        return $id;
    }

    public function test_publish_dateless_klassikaal_edition_with_publish_course_is_included(): void
    {
        $course = $this->makeCourse('publish', ['klassikaal']);
        $edition = $this->makeEdition('publish', $course, null);

        $ids = array_column(array_column(stridence_catalog_klassikaal_items(), 'edition'), 'id');
        $this->assertContains($edition, $ids, 'Dateless publish klassikaal edition must list');
    }

    public function test_publish_dateless_online_edition_with_publish_course_is_included(): void
    {
        // An always-on online edition with no dates must ALSO list — it is a
        // normal enrollable, just dateless. (Card treatment differs, inclusion
        // does not.)
        $course = $this->makeCourse('publish', ['online']);
        // Online edition: status should resolve effective Open (non-classroom),
        // but the builder includes it regardless of klass/online kind.
        $id = (int) self::factory()->post->create([
            'post_type' => 'vad_edition', 'post_status' => 'publish', 'post_title' => 'Online ' . uniqid(),
        ]);
        update_post_meta($id, $this->prefix . 'status', 'open');
        update_post_meta($id, $this->prefix . 'course_id', $course);

        $ids = array_column(array_column(stridence_catalog_online_items(), 'edition'), 'id');
        $this->assertContains($id, $ids, 'Dateless always-on online edition must list');
    }

    public function test_draft_dateless_edition_is_excluded(): void
    {
        $course = $this->makeCourse('publish', ['klassikaal']);
        $edition = $this->makeEdition('draft', $course, null);

        $ids = array_column(array_column(stridence_catalog_klassikaal_items(), 'edition'), 'id');
        $this->assertNotContains($edition, $ids);
    }

    public function test_dateless_edition_with_draft_course_does_not_leak(): void
    {
        $course = $this->makeCourse('draft', ['klassikaal']);
        $edition = $this->makeEdition('publish', $course, null);

        $items = stridence_catalog_klassikaal_items();
        $ids = array_column(array_column($items, 'edition'), 'id');
        $this->assertNotContains($edition, $ids, 'INF-1: draft course must not list its edition');
    }

    public function test_dated_edition_before_grace_cutoff_is_excluded(): void
    {
        $course = $this->makeCourse('publish', ['klassikaal']);
        $old = date('Y-m-d', strtotime('-30 days'));
        $edition = $this->makeEdition('publish', $course, $old);

        $ids = array_column(array_column(stridence_catalog_klassikaal_items(), 'edition'), 'id');
        $this->assertNotContains($edition, $ids);
    }
}
```

- [ ] **Step 2: Run it — expect RED**

Run: `ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter CatalogDatelessInclusionTest`
Expected: the two `*_is_included` tests FAIL (dateless excluded by the start_date orderby), others may pass.

- [ ] **Step 3: Remove the SQL date-ordering from both builders**

In `stridence_catalog_klassikaal_items()` delete these three array keys from the `WP_Query` args:
```php
'orderby'        => 'meta_value',
'meta_key'       => $prefix . 'start_date',
'order'          => 'ASC',
```
Do the same in `stridence_catalog_online_items()` (the section-(a) `$edition_query`). Leave `posts_per_page`, `post_status`, `fields`, `no_found_rows`, `meta_query` unchanged. (Task 1 already widened the meta_query; with the orderby gone, dateless editions now match.)

**Online ordering check:** with the SQL order gone, `stridence_catalog_online_items()` returns editions in default (post) order followed by pure-LD courses. If the current /online render visibly depends on dated-ASC ordering, add an inline ASC sort by `start_date` with **dateless last** at the end of the online builder (a flat sort, NOT a band) so the dateless always-on online editions sit after the dated ones in a single flat grid. Confirm against the live `/online` render before deciding; if order is not user-visible there, leave it flat. **Do not call `stridence_catalog_order_into_bands()` from the online builder.**

- [ ] **Step 4: Run it — expect GREEN**

Run: `ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter CatalogDatelessInclusionTest`
Expected: all PASS (both klassikaal and online inclusion green).

- [ ] **Step 5: Commit**

```bash
git add web/app/themes/stridence/helpers/catalog.php tests/Integration/CatalogDatelessInclusionTest.php
git commit -m "fix(catalog): drop start_date SQL ordering so dateless editions are enumerated (klassikaal + online)"
```

── REVIEW GATE ── (tier: FULL — cluster touches the catalog query layer's public-visibility boundary; the 1a visibility-leak surface is exercised here for BOTH klassikaal and online. Run all finders + security-sentinel + feature-acceptance against the dateless inclusion (both kinds) + INF-1 denial path. Covers Tasks 1–2.)

### Task 3: Pure band-ordering function + page-1 guard (KLASSIKAAL only)

**Tier A** — pure ordering logic with the page-1 boundary guarantee. **Test contract:** RED unit test asserts: (1) `A ++ B ++ C` order for a simple mix; (2) when `count(A) >= PER_PAGE`, every Band-B item appears within the first `PER_PAGE` of the output; (3) dateless items keep stable enumeration order within Band B; (4) `course`-kind items land in Band A (page 1), never Band B; (5) an item with start_date but no end_date is treated as dated (Band A/C by its start_date), not dateless. (The function is invoked ONLY by the klassikaal builder; online never calls it.)

**Files:**
- Modify: `web/app/themes/stridence/helpers/catalog.php` — add `stridence_catalog_order_into_bands()`
- Test: `tests/Unit/CatalogBandOrderingTest.php`

- [ ] **Step 1: Write the failing unit test**

```php
<?php
declare(strict_types=1);

namespace Stride\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CatalogBandOrderingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('STRIDENCE_CATALOG_PER_PAGE')) {
            define('STRIDENCE_CATALOG_PER_PAGE', 24);
        }
        require_once dirname(__DIR__, 1) . '/../web/app/themes/stridence/helpers/catalog.php';
    }

    /** @return array<string,mixed> */
    private function ed(int $id, ?string $start): array
    {
        return ['kind' => 'edition', 'edition' => ['id' => $id, 'start_date' => $start], 'themes' => []];
    }
    private function course(int $id): array
    {
        return ['kind' => 'course', 'course_id' => $id, 'themes' => []];
    }
    private function ids(array $items): array
    {
        return array_map(static fn(array $i): int => (int) ($i['edition']['id'] ?? $i['course_id']), $items);
    }

    public function test_orders_dated_soon_then_dateless_then_grace(): void
    {
        $today = date('Y-m-d');
        $soon  = date('Y-m-d', strtotime('+5 days'));
        $past  = date('Y-m-d', strtotime('-1 day')); // inside grace, < today
        $items = [
            $this->ed(3, $past),
            $this->ed(1, $soon),
            $this->ed(2, null),
        ];
        $out = $this->ids(stridence_catalog_order_into_bands($items));
        $this->assertSame([1, 2, 3], $out); // A(soon) ++ B(dateless) ++ C(grace)
    }

    public function test_dateless_is_hoisted_onto_page_one_when_band_a_overflows(): void
    {
        $soon = date('Y-m-d', strtotime('+1 day'));
        $items = [];
        for ($i = 1; $i <= 30; $i++) {       // 30 dated-soon (> PER_PAGE)
            $items[] = $this->ed($i, $soon);
        }
        $items[] = $this->ed(999, null);      // one dateless
        $out = $this->ids(stridence_catalog_order_into_bands($items));
        $page1 = array_slice($out, 0, STRIDENCE_CATALOG_PER_PAGE);
        $this->assertContains(999, $page1, 'dateless must be on page 1');
    }

    public function test_course_items_stay_in_band_a_not_dateless(): void
    {
        $items = [$this->course(50), $this->ed(2, null)];
        $out = $this->ids(stridence_catalog_order_into_bands($items));
        $this->assertSame([50, 2], $out); // course before dateless
    }

    public function test_start_without_end_is_dated_not_dateless(): void
    {
        $soon = date('Y-m-d', strtotime('+3 days'));
        $items = [$this->ed(2, null), $this->ed(1, $soon)];
        $out = $this->ids(stridence_catalog_order_into_bands($items));
        $this->assertSame([1, 2], $out); // dated-soon first, dateless after
    }
}
```

- [ ] **Step 2: Run it — expect RED**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit --filter CatalogBandOrderingTest`
Expected: FAIL with "Call to undefined function stridence_catalog_order_into_bands()".

- [ ] **Step 3: Implement the pure function**

Add to `helpers/catalog.php` (near `stridence_catalog_items()`):

```php
/**
 * Re-order KLASSIKAAL catalog items into three placement bands, guaranteeing
 * the dateless ("Binnenkort — toon interesse") band falls inside page 1.
 *
 * KLASSIKAAL ONLY. The /online builder does NOT call this — online courses
 * are always-on, so dateless online editions are normal enrollables in a
 * flat grid with no interest band (Stefan, 2026-06-14).
 *
 * Band A: dated editions with start_date >= today, ASC (soonest first) —
 *         plus any course items (evergreen enrollables) at the tail.
 * Band B: dateless editions (no start_date), stable enumeration order.
 * Band C: dated editions already started but inside the -2-day grace, ASC.
 *
 * Page-1 guard: when Band A alone fills STRIDENCE_CATALOG_PER_PAGE, the
 * last count(B) slots of page 1 are reserved for Band B so dateless
 * editions are never paged off page 1. PHP ordering is cheap — the list is
 * capped at STRIDENCE_CATALOG_MAX_ITEMS and fully enumerated before slicing.
 *
 * Pure: data-in, no service calls. Status/CTA still come from the INV-7
 * pre-pass at render time; this only decides ORDER.
 *
 * @param list<array<string, mixed>> $items
 * @return list<array<string, mixed>>
 */
function stridence_catalog_order_into_bands(array $items): array
{
    $today = date('Y-m-d');
    $a = [];   // dated-soon editions + course items
    $b = [];   // dateless editions
    $c = [];   // dated-grace editions

    foreach ($items as $item) {
        if (($item['kind'] ?? 'edition') === 'course') {
            $a[] = $item;
            continue;
        }
        $start = $item['edition']['start_date'] ?? null;
        if ($start === null || $start === '') {
            $b[] = $item;
        } elseif ($start >= $today) {
            $a[] = $item;
        } else {
            $c[] = $item;
        }
    }

    // Sort dated editions ASC by start_date; keep course items at the A tail
    // in enumeration order.
    $a_editions = array_values(array_filter($a, static fn($i) => ($i['kind'] ?? 'edition') === 'edition'));
    $a_courses  = array_values(array_filter($a, static fn($i) => ($i['kind'] ?? 'edition') === 'course'));
    $cmp = static fn(array $x, array $y): int
        => strcmp((string) ($x['edition']['start_date'] ?? ''), (string) ($y['edition']['start_date'] ?? ''));
    usort($a_editions, $cmp);
    usort($c, $cmp);
    $a = [...$a_editions, ...$a_courses];

    $p = defined('STRIDENCE_CATALOG_PER_PAGE') ? STRIDENCE_CATALOG_PER_PAGE : 24;
    $countB = count($b);

    if ($countB === 0) {
        return [...$a, ...$c];
    }
    if ($countB >= $p) {
        // Page 1 is all dateless; rest follows.
        return [...$b, ...$a, ...$c];
    }
    if (count($a) + $countB <= $p) {
        // B already lands on page 1 with A.
        return [...$a, ...$b, ...$c];
    }
    // Reserve the last count(B) slots of page 1 for B.
    $headA = array_slice($a, 0, $p - $countB);
    $tailA = array_slice($a, $p - $countB);
    return [...$headA, ...$b, ...$tailA, ...$c];
}
```

- [ ] **Step 4: Run it — expect GREEN**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit --filter CatalogBandOrderingTest`
Expected: 4 PASS.

- [ ] **Step 5: Apply band-ordering in the KLASSIKAAL builder only**

At the end of `stridence_catalog_klassikaal_items()`, wrap the returned list:
```php
return stridence_catalog_order_into_bands(array_values(array_filter($items, /* existing klassikaal-only filter closure */)));
```
(Apply `stridence_catalog_order_into_bands()` to the final array the klassikaal function already returns — it is the last transform.) **Do NOT touch `stridence_catalog_online_items()` here** — its `return $items;` (flat list, possibly with the inline online sort from Task 2 Step 3) stays as-is. The online list is intentionally band-free.

- [ ] **Step 6: Run the catalog integration suite — confirm no regression**

Run: `ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter Catalog`
Expected: PASS (existing `CatalogEndpointTest`, `CatalogBatchHydrationTest`, `CatalogTrashedCourseTest` + new inclusion test).

- [ ] **Step 7: Commit**

```bash
git add web/app/themes/stridence/helpers/catalog.php tests/Unit/CatalogBandOrderingTest.php
git commit -m "feat(catalog): band-order klassikaal items (dated-soon / dateless / grace) with page-1 guard"
```

### Task 4: Render the "Binnenkort — toon interesse" separator in the page-1 server render (KLASSIKAAL only)

**Tier B** — presentational glue in the klassikaal page template; the band-split predicate is the same emptiness check already unit-tested in Task 3. `no unit test: Tier B, presentational separator placement — band logic is covered by Task 3; behavior is verified at shake-out via CatalogShakeoutCest (AF-1).`

**Files:**
- Modify: `web/app/themes/stridence/page-klassikaal.php:34-36` + grid block `:151-155`
- Modify: `web/app/themes/stridence/page-online.php` — **comment only** (no band): add a one-line note that /online renders a flat grid by design (no Binnenkort band)

- [ ] **Step 1: Split the page-1 slice into A-prefix / B-run / rest and render with the separator (page-klassikaal.php)**

In `page-klassikaal.php`, replace the single `$initial_html = stridence_catalog_render_cards($initial_slice, …)` with a band-aware render of the **page-1 slice** (the slice already has B inside it thanks to the guard):

```php
$initial_slice = array_slice($catalog_items, 0, $per_page);
$uid = get_current_user_id() ?: null;

// Split the page-1 slice at the dateless run for the "Binnenkort" header.
// Same emptiness predicate as stridence_catalog_order_into_bands() — reused,
// not forked. Bands are contiguous in the ordered list (A.. B.. C..).
// KLASSIKAAL ONLY — page-online.php never does this.
$today = date('Y-m-d');
$is_dateless = static fn(array $i): bool =>
    ($i['kind'] ?? 'edition') === 'edition'
    && empty($i['edition']['start_date'] ?? null);

$b_indexes = array_keys(array_filter($initial_slice, $is_dateless));
$has_band  = !empty($b_indexes);
```

Then in the grid block, render in three contiguous chunks with the header between A and B:

```php
<div class="grid grid-cols-[repeat(auto-fill,minmax(300px,1fr))] gap-[18px]" x-ref="grid">
<?php
if ($has_band) {
    $first = (int) min($b_indexes);
    $last  = (int) max($b_indexes);
    $before = array_slice($initial_slice, 0, $first);
    $band   = array_slice($initial_slice, $first, $last - $first + 1);
    $after  = array_slice($initial_slice, $last + 1);

    echo stridence_catalog_render_cards($before, $uid);
    // Full-row band header — server-render only, KLASSIKAAL only (the
    // "Toon meer" / filter endpoint returns flat cards; Band B is fully
    // consumed on page 1, so pages >=2 and filtered replaces never need
    // this separator). /online has no band at all. See the dateless-catalog
    // plan for why this stays page-1-only and klassikaal-only.
    ?>
    <div class="col-span-full mt-2 mb-1 flex items-center gap-3">
        <h2 class="text-[15px] font-bold text-text"><?php esc_html_e('Binnenkort — toon interesse', 'stridence'); ?></h2>
        <span class="flex-1 h-px bg-border-soft" aria-hidden="true"></span>
    </div>
    <?php
    echo stridence_catalog_render_cards($band, $uid);
    echo stridence_catalog_render_cards($after, $uid);
} else {
    echo stridence_catalog_render_cards($initial_slice, $uid);
}
?>
</div>
```

(Remove the now-unused single `$initial_html` variable; keep `count($initial_slice)` for the Alpine `shown:` init.)

- [ ] **Step 2: page-online.php — confirm it stays a flat grid (comment only)**

Do **NOT** port the band logic to `page-online.php`. Add a one-line comment at the grid block, e.g.:
```php
// /online renders a flat enrollable grid by design — online courses are
// always-on, so there is no "Binnenkort — toon interesse" band here. Dateless
// online editions are normal enroll cards (see dateless-catalog plan).
```
The only behavior change on /online is that more cards appear (the inclusion fix from Tasks 1–2); the rendering path is unchanged.

- [ ] **Step 3: Manual smoke — confirm separator renders on /klassikaal, absent on /online, append path clean**

Run: `ddev exec wp eval-file scripts/seed.php` (ensure at least one dateless klassikaal AND one dateless online edition exist; if the seeder lacks them, note it as a seeder gap — see Task 7).
Visit `https://stride.ddev.site/klassikaal` — confirm the "Binnenkort — toon interesse" header appears between dated and dateless cards. Click "Toon meer" — confirm appended cards have no stray separator.
Visit `https://stride.ddev.site/online` — confirm there is **NO** "Binnenkort" header and the dateless online edition appears as a normal enroll card in the flat grid.

- [ ] **Step 4: Commit**

```bash
git add web/app/themes/stridence/page-klassikaal.php web/app/themes/stridence/page-online.php
git commit -m "feat(catalog): render Binnenkort band header in /klassikaal page-1 server render (online stays flat)"
```

── REVIEW GATE ── (tier: STANDARD — UI band-separator render on the klassikaal page template + a no-op flat-grid confirmation on online; no 1a surface, no data layer. 2 finders + simplicity + feature-acceptance browser pass on AF-1 AND AF-5 (separator absent on /online); no security-sentinel. Covers Tasks 3–4.)

### Task 5: Card interest variant — triggered by effective status `Announcement`, NOT date-absence (meta + CTA)

**Tier A** — the trigger is a behavior decision (which dateless editions get interest framing) with an explicit denial path (online dateless → enroll, not interest). It is unit-testable in isolation by rendering the card with different `$status` values and asserting the CTA. **Test contract:** RED unit test renders `card-edition.php` with (a) `$status='announcement'` + no start_date → output contains "Geen datum — toon interesse" and the "Toon interesse" CTA; (b) `$status='open'` + no start_date (the online dateless case) → output contains the normal enroll/"Bekijk editie" CTA and does NOT contain "Toon interesse"; (c) `$status='open'` + a real start_date → normal dated card (regression). The trigger is `$status === 'announcement'` (the effective status, INV-7), never `empty($start_date)`.

**Files:**
- Modify: `web/app/themes/stridence/partials/card-edition.php` — meta block `:155-170`, CTA label `:93-100`
- Create: `tests/Unit/CardEditionInterestVariantTest.php`

- [ ] **Step 1: Write the failing unit test**

```php
<?php
declare(strict_types=1);

namespace Stride\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The card interest variant is gated by the EFFECTIVE status (Announcement),
 * not by date-absence — so a dateless ONLINE edition (status Open) renders a
 * normal enroll card, while a dateless KLASSIKAAL edition (status Announcement)
 * renders the interest variant. (Stefan, 2026-06-14.)
 */
final class CardEditionInterestVariantTest extends TestCase
{
    private function render(array $args): string
    {
        // Minimal WP stubs the pure renderer needs (esc_url/esc_html_e/__/
        // get_permalink/get_post_status) are provided by tests/Stubs — extend
        // there if a needed function is missing. The card calls no services.
        ob_start();
        $GLOBALS['__card_args'] = $args;
        // Render via the project's existing card-render harness if one exists;
        // otherwise include the partial with $args in scope. Pin the exact
        // include shim to whatever tests/Stubs already use for partial tests.
        (function (array $args): void {
            include dirname(__DIR__, 1) . '/../web/app/themes/stridence/partials/card-edition.php';
        })($args);
        return (string) ob_get_clean();
    }

    public function test_announcement_dateless_klassikaal_renders_interest_variant(): void
    {
        $html = $this->render([
            'edition' => ['id' => 1, 'start_date' => null, 'price' => 0, 'course_id' => 0],
            'status'  => 'announcement',
        ]);
        $this->assertStringContainsString('Geen datum', $html);
        $this->assertStringContainsString('Toon interesse', $html);
    }

    public function test_open_dateless_online_renders_normal_enroll_not_interest(): void
    {
        $html = $this->render([
            'edition' => ['id' => 2, 'start_date' => null, 'price' => 0, 'course_id' => 0],
            'status'  => 'open',
        ]);
        $this->assertStringNotContainsString('Toon interesse', $html, 'online dateless must NOT get interest CTA');
        $this->assertStringNotContainsString('Geen datum — toon interesse', $html);
        // It is a normal enrollable card — assert the default CTA label.
        $this->assertStringContainsString('Bekijk editie', $html);
    }

    public function test_open_dated_renders_normal_card(): void
    {
        $soon = date('Y-m-d', strtotime('+5 days'));
        $html = $this->render([
            'edition' => ['id' => 3, 'start_date' => $soon, 'price' => 0, 'course_id' => 0],
            'status'  => 'open',
        ]);
        $this->assertStringNotContainsString('Toon interesse', $html);
        $this->assertStringContainsString('Bekijk editie', $html);
    }
}
```

> **Note for the implementer:** confirm the exact partial-render shim `tests/Stubs` / existing partial tests use (the project already unit-tests pure renderers — match that harness, including the stub set for `esc_url`, `esc_html_e`, `__`, `get_permalink`, `get_post_status`). If no partial-render harness exists, the fallback is to assert on the small helper that decides the labels; but prefer rendering the real partial so the trigger wiring is what's under test.

- [ ] **Step 2: Run it — expect RED**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit --filter CardEditionInterestVariantTest`
Expected: `test_announcement_dateless_klassikaal_renders_interest_variant` FAILS (no interest branch yet).

- [ ] **Step 3: Add the interest-variant flag keyed off effective status**

Derive a flag from the **effective status** already passed in (`$status` — line 55, the prefetched INV-7 value), NOT from `$start_date`. After `$is_free` (`:87`), add:
```php
// Interest variant — the "Binnenkort — toon interesse" anchor. Keyed off the
// EFFECTIVE status (INV-7), NOT date-absence: a KLASSIKAAL dateless edition
// resolves to 'announcement' (allowsInterest), while an ONLINE dateless edition
// stays 'open' (allowsEnrollment) and therefore renders a normal enroll card.
// This is the whole reason online always-on editions need no special-casing
// here (Stefan, 2026-06-14). Pure data-in: $status is already passed by the
// catalog pre-pass; no service call.
$is_interest = ($status === 'announcement') && !$is_cancelled && !$is_enrolled;
```

In the meta block, add the interest branch (it shows the "Geen datum" line because an `Announcement` dateless edition has no dates; if an `Announcement` edition somehow had a start_date the existing dated branch still wins for the date line — but the CTA below is the interest CTA):
```php
<?php if ($is_interest && !$start_date) : ?>
    <div class="font-semibold text-text"><?php esc_html_e('Geen datum — toon interesse', 'stridence'); ?></div>
<?php elseif ($start_date) : ?>
    ... existing dated meta ...
<?php endif; ?>
```

- [ ] **Step 4: Override the CTA label for the interest case**

In the CTA-label block (`:93-100`), add the interest branch (before the generic else):
```php
if ($is_cancelled) {
    $cta_label = __('Bekijk alternatieven', 'stridence');
} elseif ($is_enrolled) {
    $cta_label = __('Bekijk je inschrijving', 'stridence');
} elseif ($is_interest) {
    $cta_label = __('Toon interesse', 'stridence');
} else {
    $cta_label = __('Bekijk editie', 'stridence');
}
```

The card link target stays `get_permalink($edition_id)` — the single-edition page routes an `Announcement` edition (klassikaal dateless) to the interest CTA, and an `Open` edition (online dateless or dated) to the enroll CTA (ground-truthed). Do NOT hardcode `/interesse/?editie=` in the card; keep the card a pure renderer that links to the edition and lets the single-edition page's INV-7 status decide the action.

- [ ] **Step 5: Run it — expect GREEN**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit --filter CardEditionInterestVariantTest`
Expected: 3 PASS.

- [ ] **Step 6: Manual smoke**

Visit `/klassikaal`: confirm the dateless klassikaal card shows "Geen datum — toon interesse" + "Toon interesse →", clicking it lands on the edition page whose CTA is "Interesse melden".
Visit `/online`: confirm the dateless online card shows the normal enroll CTA (NOT "Toon interesse") and clicking it lands on the edition page whose CTA is "Schrijf je in".

- [ ] **Step 7: Commit**

```bash
git add web/app/themes/stridence/partials/card-edition.php tests/Unit/CardEditionInterestVariantTest.php
git commit -m "feat(catalog): card interest variant gated by effective status (announcement), online dateless stays enroll"
```

── REVIEW GATE ── (tier: STANDARD — pure-renderer behavior change on the card; the trigger decision is unit-covered and is a presentation concern over the existing INV-7 status, no 1a surface, no data layer. 2 finders + simplicity + feature-acceptance browser pass on AF-2 AND AF-5 (online dateless → enroll CTA). Covers Task 5.)

### Task 6: Admin getEditions — permit NULL start_date in the default list view

**Tier A** — admin query-shape change with a denial-path (range filter must still exclude dateless). **Test contract:** RED integration test asserts (a) with no `date_from`, a dateless publish edition is RETURNED by the list view; (b) with a `date_from` supplied, the dateless edition is EXCLUDED (range implies dated intent); (c) ordering does not error on NULL start_date.

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Admin/AdminAPIController.php:783-844` (list view query)
- Create: `tests/Integration/AdminEditionsDatelessTest.php`

- [ ] **Step 1: Write the failing integration test**

```php
<?php
declare(strict_types=1);

namespace Stride\Tests\Integration;

use WP_REST_Request;
use WP_UnitTestCase;

final class AdminEditionsDatelessTest extends WP_UnitTestCase
{
    private string $prefix;

    public function set_up(): void
    {
        parent::set_up();
        $this->prefix = ntdst_get(\Stride\Modules\Edition\EditionRepository::class)->getMetaPrefix();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function makeDatelessEdition(): int
    {
        $id = (int) self::factory()->post->create([
            'post_type' => 'vad_edition', 'post_status' => 'publish', 'post_title' => 'Dateless',
        ]);
        update_post_meta($id, $this->prefix . 'status', 'announcement');
        return $id;
    }

    private function listIds(array $params): array
    {
        $req = new WP_REST_Request('GET', '/stride/v1/admin/editions');
        $req->set_param('view', 'list');
        foreach ($params as $k => $v) {
            $req->set_param($k, $v);
        }
        $res = ntdst_get(\Stride\Admin\AdminAPIController::class)->getEditions($req);
        $data = $res->get_data();
        return array_map(static fn($e) => (int) ($e['id'] ?? $e['ID'] ?? 0), $data['items'] ?? $data['editions'] ?? []);
    }

    public function test_dateless_edition_appears_in_default_list(): void
    {
        $id = $this->makeDatelessEdition();
        $this->assertContains($id, $this->listIds([]));
    }

    public function test_dateless_edition_excluded_when_date_range_supplied(): void
    {
        $id = $this->makeDatelessEdition();
        $ids = $this->listIds(['date_from' => date('Y-m-d', strtotime('-7 days'))]);
        $this->assertNotContains($id, $ids, 'A date range implies dated intent — dateless excluded');
    }
}
```

(Confirm the actual data-array key for items — `items` vs `editions` — by reading the `getEditions` list-view return shape before running; the test accommodates both but pin it to the real key.)

- [ ] **Step 2: Run it — expect RED**

Run: `ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AdminEditionsDatelessTest`
Expected: `test_dateless_edition_appears_in_default_list` FAILS (INNER JOIN drops dateless).

- [ ] **Step 3: LEFT JOIN + NULL-permit the default clause + NULL-last ordering**

In the list-view query (`:783-844`):
- Change the default no-`date_from` clause (`:787-790`) from `pm_start.meta_value >= %s` to permit NULL:
```php
if (empty($dateFrom)) {
    $where[] = "(pm_start.meta_value >= %s OR pm_start.meta_value IS NULL)";
    $params[] = $twoDaysAgo;
}
```
- Change both the count query (`:824`) and the results query (`:837`) JOIN from `INNER JOIN` to `LEFT JOIN`:
```php
LEFT JOIN {$wpdb->postmeta} pm_start ON p.ID = pm_start.post_id AND pm_start.meta_key = '_ntdst_start_date'
```
- Change the `ORDER BY pm_start.meta_value ASC` (`:841`) to put NULLs last:
```php
ORDER BY pm_start.meta_value IS NULL, pm_start.meta_value ASC
```
(The `date_from`/`date_to` range clauses `:803-810` stay as `>= %s` / `<= %s` — they reference `pm_start.meta_value`, which a LEFT JOIN leaves NULL for dateless rows, so a NULL `meta_value` fails `>=`/`<=` and the dateless edition is excluded when a range is set. That is the intended denial path — confirmed by Step 1's second test.)

Add a code comment cross-referencing the workspace spec:
```php
// LEFT JOIN + NULL-permit so dateless editions (no sessions → no start_date,
// the interest-list anchors) show in the default scope. Same fix the Admin
// Workspace spec §10.7 / Task 1.2 inherits — see docs/plans/2026-06-13-admin-workspace-spec.md.
```

- [ ] **Step 4: Run it — expect GREEN**

Run: `ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AdminEditionsDatelessTest`
Expected: both PASS.

- [ ] **Step 5: Regression — run the existing admin editions test**

Run: `ddev exec vendor/bin/phpunit --filter AdminAPIController; ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AdminEdition`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add web/app/mu-plugins/stride-core/Admin/AdminAPIController.php tests/Integration/AdminEditionsDatelessTest.php
git commit -m "fix(admin): permit NULL start_date in default editions list (dateless visible)"
```

── REVIEW GATE ── (tier: FULL — cluster rewrites a `$wpdb` query JOIN in the admin REST data layer; the data-layer + cross-role-visibility surface (1a) is touched. All finders + security-sentinel; verify the range-filter denial path and that `$wpdb->prepare` placeholders stay balanced after the JOIN/ORDER change. Covers Task 6.)

### Task 7: Seeder coverage + acceptance fixture + sibling-site audit

**Tier B** — test/seed data + cross-cutting audit, no production logic. `no unit test: Tier B, fixture/seed + audit task — verification is the seed-verify assertion + the acceptance run, not a bespoke unit test.`

**Files:**
- Modify: `scripts/seed.php` (add one dateless klassikaal edition AND one dateless online edition if absent) — confirm against current seeder before editing
- Modify: `tests/acceptance/CatalogShakeoutCest.php` (assert AF-1/AF-2/AF-5 against the dateless fixtures)

- [ ] **Step 1: Confirm the seeder produces ≥1 dateless klassikaal AND ≥1 dateless online edition**

Read `scripts/seed.php`; if every seeded edition gets sessions/dates, add:
- one published, active (`announcement`-status) **klassikaal** edition with NO sessions and NO `start_date`/`end_date` meta, linked to a published klassikaal course (the interest anchor);
- one published, active (`open`-status) **online** edition with NO sessions and NO dates, linked to a published online course (the always-on enrollable).

Run:
```bash
ddev exec wp eval-file scripts/seed.php && ddev exec wp eval-file scripts/seed-verify.php
```
Expected: exit 0 (add a dateless-dimension assertion to seed-verify if it has a coverage matrix — cover BOTH the klassikaal-interest and online-always-on dimensions).

- [ ] **Step 2: Extend the acceptance Cest for AF-1 / AF-2 / AF-5**

In `tests/acceptance/CatalogShakeoutCest.php`, add tests that:
- load `/klassikaal`, assert the "Binnenkort — toon interesse" header is present, assert a dateless klassikaal card shows "Geen datum — toon interesse" and a "Toon interesse" CTA, and follow the CTA to an edition page whose primary CTA is "Interesse melden" (AF-1, AF-2);
- load `/online`, assert there is **NO** "Binnenkort" header, assert the dateless online edition renders a normal enroll card (CTA is the enroll/"Bekijk editie" path, NOT "Toon interesse"), and follow it to an edition page whose CTA is the enroll action (AF-5).

- [ ] **Step 3: Sibling-site audit (cross-cutting)**

`## Sibling-site audit` — the `start_date` SQL-ordering pattern that excludes dateless editions appears in **four** query sites. This plan fixes the two catalog-list builders (Task 2) and the admin list view (Task 6). Audit the remaining two and confirm they are correctly left as-is or filed:
- `archive-sfwd-courses.php` online section (`:113-114`) — same `orderby start_date` pattern; the archive is a 6-item teaser, not a full catalog. **Decision:** leave as-is for this plan (teaser, not the canonical catalog) but add a `// dateless excluded here — teaser only; canonical inclusion is /klassikaal + /online` comment and a `tasks/todo.md` note. If the product wants dateless in the archive teaser too, that is a follow-up.
- `stridence_prefetch_course_cards()` (`:455-472`) — has **no** start_date orderby (default order), so it does not exclude dateless; no change needed. Confirm by reading.
- The archive classroom teaser (`:66-76`) uses `orderby start_date` but status-only meta_query (no date window) — it would also drop dateless. Same teaser decision; same comment + todo note.

Record the audit result in `tasks/todo.md` (one line per site: fixed / left-as-teaser / N-A).

- [ ] **Step 4: Run the full catalog + admin suites**

Run:
```bash
ddev exec vendor/bin/phpunit --testsuite Unit --filter "Catalog|Card|Edition"
ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter "Catalog|AdminEdition"
```
Expected: all GREEN.

- [ ] **Step 5: Commit**

```bash
git add scripts/seed.php tests/acceptance/CatalogShakeoutCest.php tasks/todo.md
git commit -m "test(catalog): seed + acceptance coverage for dateless editions (klassikaal interest + online always-on); sibling-site audit"
```

── REVIEW GATE ── (tier: LIGHT — seed/fixture/acceptance + audit notes; no product logic, no 1a surface. Single generalist pass. Covers Task 7.)

---

## Integration gates (per phase)

- **Phase 1 (Tasks 1–2, catalog query):** `ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter Catalog` green; manual `/klassikaal` AND `/online` each show a dateless edition.
- **Phase 2 (Tasks 3–5, ordering + render + card):** `CatalogBandOrderingTest` + `CardEditionInterestVariantTest` green; browser AF-1/AF-2/AF-5 confirmed; "Toon meer" append has no stray separator; /online has no band and online dateless cards show the enroll CTA.
- **Phase 3 (Task 6, admin):** `AdminEditionsDatelessTest` green; admin editions list view shows the dateless edition by default and hides it under a date range.
- **Spec-close (Task 7 + shake-out):** full Unit+Integration catalog/edition suites green; `CatalogShakeoutCest` drives AF-1..AF-5; reviewer panel per the highest tier reached (FULL — promoted by the 1a visibility surface in Tasks 2 and 6).

## Self-review (writing-plans checklist)

- **Spec coverage:** clarification implications 1–5 → (1) band is klassikaal-only: Tasks 3/4 scoped to klassikaal, online flat; (2) card variant triggers on interest-eligibility (`$status === 'announcement'`): Task 5, with the exact trigger condition stated + ground-truthed against `getEffectiveStatusFromPrefetched()` rule 3; (3) inclusion fix symmetric (klassikaal + online + admin): Tasks 1/2/6, online inclusion asserted; (4) "online dateless asymmetry" non-goal removed and replaced by the "Resolved design" table; (5) band-ordering + page-1 hoist + separator run only on the klassikaal path: Tasks 3/4 scope notes. Microcopy embedded verbatim in Tasks 4 & 5. ✅
- **Scope is REMOVED, not added:** the only new artifact is `CardEditionInterestVariantTest` — required because the trigger moved from a Tier-B date check to a Tier-A behavior decision (announcement→interest, open→enroll) that needs the denial path proven; everything else is a tightening of existing tasks. No new feature, endpoint, or surface. ✅
- **Placeholder scan:** all code shown; the one deferred assertion (Task 1 → Task 2) is explicit and linked. ✅
- **Type consistency:** `stridence_catalog_order_into_bands()` signature identical across Tasks 3 and 4; card trigger `$status === 'announcement'` reads the existing `@type string $status` arg; item-array shape (`['kind','edition'=>['id','start_date'],'themes']`) matches `stridence_catalog_edition_items_from_ids()` output. ✅
