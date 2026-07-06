<?php

/**
 * Catalog helpers — the batch pre-pass behind /klassikaal, /online, the
 * course archive and the `stride_catalog_page` endpoint (Task G1 / audit 2.2).
 *
 * Shape: build a LIGHT eligible-items list (ids + theme slugs, one query +
 * cache priming), slice it for the page being rendered, then hydrate ONLY
 * that slice through batch reads (effective statuses, registration counts,
 * session counts, the user's enrolled set) and hand the resolved data into
 * the card partials — which are pure renderers and never issue their own
 * service lookups. Query cost is independent of catalog size.
 *
 * INV-7: statuses come from EditionService::getEffectiveStatuses(), which
 * delegates per id to the single decision engine. INV-3: every new query
 * shape lives in a repository; meta keys derive from the model's prefix.
 * The pre-pass is per-request only — no cross-request caching, so a status
 * change is reflected on the next load.
 *
 * @package stridence
 */

declare(strict_types=1);

use Stride\Domain\OfferingStatus;
use Stride\Integrations\LearnDash\LearnDashHelper;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionRepository;
use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Enrollment\RegistrationRepository;

/** Server-render cap per catalog page ("Toon meer" fetches the next slice). */
const STRIDENCE_CATALOG_PER_PAGE = 24;

/** Upper bound on the eligible-items enumeration (ids only — memory guard). */
const STRIDENCE_CATALOG_MAX_ITEMS = 500;

/**
 * Number of dated-soon editions shown ahead of the dateless interest
 * editions (one grid row). Keeps the interest anchors high on page 1 instead
 * of dead-last behind the whole dated list. The interest cards set themselves
 * apart visually (tinted surface — partials/card-edition.php), so no band
 * header/divider is rendered.
 */
const STRIDENCE_CATALOG_BAND_LEAD = 3;

/**
 * Eligible catalog items for a catalog key.
 *
 * @param string $catalog 'klassikaal' or 'online'
 * @return list<array{kind: string, edition?: array<string, mixed>, course_id?: int, themes: list<string>}>
 */
function stridence_catalog_items(string $catalog): array
{
    return $catalog === 'online'
        ? stridence_catalog_online_items()
        : stridence_catalog_klassikaal_items();
}

/**
 * Observability for the enumeration cap (panel perf SF-2): a result filling
 * STRIDENCE_CATALOG_MAX_ITEMS has silently truncated the catalog. RETAINED for
 * the course-card prepass (stridence_prefetch_course_cards) + the archive,
 * which still run their own enumeration. The /klassikaal+/online builders'
 * cap-warning now lives service-side (EditionService::warnIfCapped).
 *
 * @param array<int|WP_Post> $results
 */
function stridence_catalog_warn_if_capped(array $results, string $context): void
{
    if (count($results) >= STRIDENCE_CATALOG_MAX_ITEMS) {
        ntdst_log()->warning('catalog enumeration filled STRIDENCE_CATALOG_MAX_ITEMS — items beyond the cap are silently hidden', [
            'context' => $context,
            'cap' => STRIDENCE_CATALOG_MAX_ITEMS,
        ]);
    }
}

/**
 * Eligible items for /klassikaal: the POLICY (eligibility query +
 * format-exclusion + INF-1 published-course guard + item-shaping) now lives in
 * EditionService::getCatalogItems('klassikaal') (Cluster 3 / Task 3.2). The
 * theme keeps only the KLASSIKAAL-only PRESENTATION on top: band-ordering
 * (dated-soon / dateless / grace) so the dateless "Binnenkort — toon interesse"
 * anchors always land on page 1. The /online builder intentionally does NOT
 * band-order — online courses are always-on (flat enrollable list).
 */
function stridence_catalog_klassikaal_items(): array
{
    $items = ntdst_get(EditionService::class)
        ->getCatalogItems('klassikaal', STRIDENCE_CATALOG_MAX_ITEMS);

    // Attach a sort_date (next UPCOMING session date) to each edition so the
    // band-ordering pass orders by the next session — an edition whose first
    // day has passed but has a later session sorts by that next session, not
    // its start_date — and breaks date ties deterministically by title (fixes
    // the catalog refresh-shuffle).
    $items = stridence_catalog_attach_sort_dates($items);

    return stridence_catalog_order_into_bands($items);
}

/**
 * Attach `sort_date` (next upcoming in-person/webinar session date, >= today)
 * to each edition item, for catalog ordering.
 *
 * sort_date falls back to the edition's start_date when it has no future dated
 * session (so single-session and dateless editions keep their meaning). The
 * next-session lookup is ONE batched query over all editions on the page — not
 * per-edition — to avoid an N+1 in the catalog render. Online / assignment
 * sessions are excluded: they are e-learning steps, not calendar dates that
 * should drive catalog ordering.
 *
 * @param list<array<string,mixed>> $items
 * @return list<array<string,mixed>>
 */
function stridence_catalog_attach_sort_dates(array $items): array
{
    global $wpdb;

    $editionIds = [];
    foreach ($items as $item) {
        if (($item['kind'] ?? 'edition') === 'edition' && !empty($item['edition']['id'])) {
            $editionIds[] = (int) $item['edition']['id'];
        }
    }

    $nextByEdition = [];
    if ($editionIds) {
        $prefix = ntdst_get(\Stride\Modules\Edition\SessionRepository::class)->getMetaPrefix();
        $today  = current_time('Y-m-d');
        $in     = implode(',', array_fill(0, count($editionIds), '%d'));

        // One query: per edition, the MIN session date that is today-or-later.
        // Sessions are vad_session posts with edition_id + date meta. Online /
        // assignment sessions are excluded — they are e-learning steps.
        // Build args in placeholder order: 3 meta-key strings, the IN(...) ids,
        // then the date. Pass as a single array so unpacking is never followed
        // by a positional argument (PHP fatal otherwise).
        $args = array_merge(
            [$prefix . 'edition_id', $prefix . 'date', $prefix . 'type'],
            $editionIds,
            [$today],
        );
        $sql = $wpdb->prepare(
            "SELECT ed.meta_value AS edition_id, MIN(dt.meta_value) AS next_date
               FROM {$wpdb->posts} p
               JOIN {$wpdb->postmeta} ed ON ed.post_id = p.ID AND ed.meta_key = %s
               JOIN {$wpdb->postmeta} dt ON dt.post_id = p.ID AND dt.meta_key = %s
               LEFT JOIN {$wpdb->postmeta} ty ON ty.post_id = p.ID AND ty.meta_key = %s
              WHERE p.post_type = 'vad_session'
                AND p.post_status = 'publish'
                AND ed.meta_value IN ($in)
                AND dt.meta_value >= %s
                AND (ty.meta_value IS NULL OR ty.meta_value NOT IN ('online', 'assignment'))
              GROUP BY ed.meta_value",
            $args,
        );

        foreach ($wpdb->get_results($sql) as $row) {
            $nextByEdition[(int) $row->edition_id] = (string) $row->next_date;
        }
    }

    foreach ($items as &$item) {
        if (($item['kind'] ?? 'edition') !== 'edition') {
            continue;
        }
        $id = (int) ($item['edition']['id'] ?? 0);
        $item['edition']['sort_date'] = $nextByEdition[$id]
            ?? ($item['edition']['start_date'] ?? null);
        // Ensure title is a string so the tiebreak comparator is total.
        $item['edition']['title'] = (string) ($item['edition']['title'] ?? '');
    }
    unset($item);

    return $items;
}

/**
 * Eligible items for /online: one card per enrollable — active editions of
 * online-format courses (incl. dateless always-on) plus pure-LD online courses
 * that never had an edition. The POLICY lives in
 * EditionService::getCatalogItems('online') (Task 3.2); the theme is a thin
 * pass to it. Returns a FLAT enrollable list — no band-ordering.
 */
function stridence_catalog_online_items(): array
{
    return ntdst_get(EditionService::class)
        ->getCatalogItems('online', STRIDENCE_CATALOG_MAX_ITEMS);
}

/**
 * Re-order KLASSIKAAL catalog items into three placement bands, guaranteeing
 * the dateless interest editions fall inside page 1.
 *
 * KLASSIKAAL ONLY. The /online builder does NOT call this — online courses
 * are always-on, so dateless online editions are normal enrollables in a
 * flat grid (Stefan, 2026-06-14).
 *
 * Band A: dated editions with start_date >= today, ASC (soonest first) —
 *         plus any course items (evergreen enrollables) at the tail.
 * Band B: dateless editions (no start_date), stable enumeration order.
 * Band C: dated editions already started but inside the -2-day grace, ASC.
 *
 * Placement: Band B sits after the first STRIDENCE_CATALOG_BAND_LEAD dated-soon
 * editions (one grid row), NOT after the whole dated list — so the interest
 * anchors surface high on page 1 instead of dead-last. They set themselves
 * apart with a tinted card surface (no band header). Output is
 * head(A) ++ B ++ tail(A) ++ C. Because the lead row is far smaller than
 * STRIDENCE_CATALOG_PER_PAGE, B always lands on page 1; the only special case
 * left is B alone overflowing a page (degenerate, handled first). PHP ordering
 * is cheap — the list is capped at STRIDENCE_CATALOG_MAX_ITEMS and fully
 * enumerated before slicing.
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
        // Band classification + sorting use sort_date — the edition's next
        // UPCOMING session date (caller-computed by attach_sort_dates), falling
        // back to start_date. This keeps an edition whose first session has
        // passed but has a later session in the dated-soon band, ordered by
        // that next session.
        $sort = $item['edition']['sort_date'] ?? ($item['edition']['start_date'] ?? null);
        if ($sort === null || $sort === '') {
            $b[] = $item;
        } elseif ($sort >= $today) {
            $a[] = $item;
        } else {
            $c[] = $item;
        }
    }

    // Sort dated editions ASC by next-session date; tie-break A->Z by title so
    // editions sharing a date keep a STABLE order across requests. The eligible
    // query has no orderby (to include dateless editions), so without a
    // deterministic tiebreak tied dates shuffle on refresh. Course items stay
    // at the A tail in enumeration order.
    $a_editions = array_values(array_filter($a, static fn($i) => ($i['kind'] ?? 'edition') === 'edition'));
    $a_courses  = array_values(array_filter($a, static fn($i) => ($i['kind'] ?? 'edition') === 'course'));
    $cmp = static function (array $x, array $y): int {
        $sx = (string) ($x['edition']['sort_date'] ?? $x['edition']['start_date'] ?? '');
        $sy = (string) ($y['edition']['sort_date'] ?? $y['edition']['start_date'] ?? '');
        return strcmp($sx, $sy)
            ?: strcmp((string) ($x['edition']['title'] ?? ''), (string) ($y['edition']['title'] ?? ''));
    };
    usort($a_editions, $cmp);
    usort($c, $cmp);
    $a = [...$a_editions, ...$a_courses];

    $p = defined('STRIDENCE_CATALOG_PER_PAGE') ? STRIDENCE_CATALOG_PER_PAGE : 24;
    $countB = count($b);

    if ($countB === 0) {
        return [...$a, ...$c];
    }
    if ($countB >= $p) {
        // Degenerate: B alone overflows page 1 — lead it, dated follows.
        return [...$b, ...$a, ...$c];
    }
    // Surface B after one lead row of dated-soon editions (or all of A when A
    // is shorter than a row), then the rest of A, then grace. Lead < page size,
    // so B is always on page 1.
    $lead   = STRIDENCE_CATALOG_BAND_LEAD;
    $headA  = array_slice($a, 0, $lead);
    $tailA  = array_slice($a, $lead);
    return [...$headA, ...$b, ...$tailA, ...$c];
}

/**
 * stride_theme slugs for a course (term cache expected to be primed).
 *
 * @return list<string>
 */
function stridence_catalog_theme_slugs(int $course_id): array
{
    $terms = get_the_terms($course_id, 'stride_theme');
    if (!$terms || is_wp_error($terms)) {
        return [];
    }

    return array_values(wp_list_pluck($terms, 'slug'));
}

/**
 * Render a slice of catalog items as card HTML.
 *
 * Runs the batch pre-pass over the slice, then feeds resolved data into the
 * card partials (pure renderers — they issue no service lookups of their own).
 *
 * @param list<array<string, mixed>> $items Slice of stridence_catalog_items()
 * @param int|null                   $user_id Logged-in user for enrolled state, null for guests
 */
function stridence_catalog_render_cards(array $items, ?int $user_id = null): string
{
    $edition_items = [];
    $course_ids = [];
    foreach ($items as $item) {
        if (($item['kind'] ?? 'edition') === 'course') {
            $course_ids[] = (int) ($item['course_id'] ?? 0);
        } elseif (!empty($item['edition'])) {
            $edition_items[] = $item['edition'];
        }
    }

    $edition_data = stridence_prefetch_edition_cards($edition_items, $user_id);
    $course_data = stridence_prefetch_course_cards($course_ids, $user_id);

    $html = '';
    foreach ($items as $item) {
        if (($item['kind'] ?? 'edition') === 'course') {
            $course_id = (int) ($item['course_id'] ?? 0);
            $course = $course_id ? get_post($course_id) : null;
            if (!$course) {
                continue;
            }
            $html .= stridence_template_html('partials/card-course', null, [
                'course' => $course,
            ] + ($course_data[$course_id] ?? []));
        } elseif (!empty($item['edition'])) {
            $edition_id = (int) ($item['edition']['id'] ?? 0);
            $html .= stridence_template_html('partials/card-edition', null, [
                'edition' => $item['edition'],
            ] + ($edition_data[$edition_id] ?? []));
        }
    }

    return $html;
}

/**
 * Batch pre-pass for edition cards: effective statuses (INV-7 batch entry),
 * spots remaining, the user's enrolled set and LD progress for enrolled
 * cards — all N-independent, all per-request.
 *
 * @param list<array<string, mixed>> $editions Edition data arrays (id, course_id, capacity, ...)
 * @return array<int, array{status: string, spots_remaining: ?int, is_enrolled: bool, progress: ?int}>
 */
function stridence_prefetch_edition_cards(array $editions, ?int $user_id = null): array
{
    $ids = [];
    foreach ($editions as $edition) {
        $id = (int) ($edition['id'] ?? $edition['ID'] ?? 0);
        if ($id) {
            $ids[$id] = $id;
        }
    }
    if (empty($ids)) {
        return [];
    }
    $ids = array_values($ids);

    // One INV-7 batch call (primes edition + course caches internally),
    // one registration GROUP BY, one cached enrolled-set read, one session
    // GROUP BY for the "· N sessie" meta line.
    $statuses = ntdst_get(EditionService::class)->getEffectiveStatuses($ids);
    $reg_counts = ntdst_get(RegistrationRepository::class)->countByEditions($ids);
    $session_counts = ntdst_get(SessionRepository::class)->countByEditions($ids);
    $enrolled_ids = $user_id
        ? ntdst_get(EnrollmentService::class)->getEnrolledEditionIds($user_id)
        : [];

    stridence_catalog_prime_thumbnails(array_filter(array_map(
        static fn(array $e): int => (int) ($e['course_id'] ?? 0),
        $editions,
    )));

    $out = [];
    foreach ($editions as $edition) {
        $id = (int) ($edition['id'] ?? $edition['ID'] ?? 0);
        if (!$id) {
            continue;
        }
        $course_id = (int) ($edition['course_id'] ?? 0);
        $capacity = (int) ($edition['capacity'] ?? 0);
        $is_enrolled = in_array($id, $enrolled_ids, true);

        $out[$id] = [
            'status'          => isset($statuses[$id]) ? $statuses[$id]->value : (string) ($edition['status'] ?? 'open'),
            'spots_remaining' => $capacity > 0 ? max(0, $capacity - (int) ($reg_counts[$id] ?? 0)) : null,
            'session_count'   => (int) ($session_counts[$id] ?? 0),
            'is_enrolled'     => $is_enrolled,
            // Progress only matters for enrolled cards — bounded by the
            // user's own enrollments, not by catalog size.
            'progress'        => ($is_enrolled && $course_id && $user_id)
                ? LearnDashHelper::getProgress($course_id, $user_id)
                : null,
        ];
    }

    return $out;
}

/**
 * Batch pre-pass for course cards: each course's primary visible edition
 * (enrollable > active, same ranking the partial previously computed with
 * a WP_Query PER CARD) plus the visitor's own LD state.
 *
 * @param array<int> $course_ids
 * @return array<int, array{primary_edition: ?array{id: int, status: OfferingStatus, spots: ?int}, user_state: ?array{enrolled: bool, progress: int}}>
 */
function stridence_prefetch_course_cards(array $course_ids, ?int $user_id = null): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $course_ids))));
    if (empty($ids)) {
        return [];
    }

    $editionRepo = ntdst_get(EditionRepository::class);
    $prefix = $editionRepo->getMetaPrefix();

    _prime_post_caches($ids, true, true);
    stridence_catalog_prime_thumbnails($ids);

    // One query: active-status editions for the whole course set, in the
    // same default order (date DESC) the per-card get_posts() used, so the
    // "first best" pick stays identical. Bounded by the same enumeration
    // cap as the catalog lists (perf SF-2 — was an unbounded -1).
    $edition_ids = get_posts([
        'post_type'      => 'vad_edition',
        'post_status'    => 'publish',
        'posts_per_page' => STRIDENCE_CATALOG_MAX_ITEMS,
        'fields'         => 'ids',
        'meta_query'     => [
            [
                'key'     => $prefix . 'course_id',
                'value'   => $ids,
                'compare' => 'IN',
            ],
            [
                'key'     => $prefix . 'status',
                'value'   => OfferingStatus::activeValues(),
                'compare' => 'IN',
            ],
            // exclude_from_catalog (§6.1): keep flagged editions off the course
            // cards. $prefix is already the CPT's _ntdst_ meta_prefix (line 426),
            // so this resolves to the literal _ntdst_exclude_from_catalog the
            // field persists under (gotcha_cpt_getfields_ntdst_prefix) — same
            // pattern as the course_id/status clauses above, not a new hardcode.
            // NOT EXISTS (never flagged) OR != '1' (flag off, stored as ''); only
            // value === '1' (flagged true) is excluded. Listing-only.
            [
                'relation' => 'OR',
                [
                    'key'     => $prefix . 'exclude_from_catalog',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key'     => $prefix . 'exclude_from_catalog',
                    'value'   => '1',
                    'compare' => '!=',
                ],
            ],
        ],
    ]);
    stridence_catalog_warn_if_capped($edition_ids, 'course-card pre-pass editions');
    $edition_ids = array_map('intval', $edition_ids);

    $primary = [];
    if (!empty($edition_ids)) {
        $editionService = ntdst_get(EditionService::class);
        $statuses = $editionService->getEffectiveStatuses($edition_ids);

        // Bin the active editions by course, dropping any whose EFFECTIVE status
        // is no longer active (a past edition may have flipped to Completed —
        // stale visibility leak). Then defer the "which cohort is primary" pick
        // to the single-home policy in stride-core (EditionService::
        // getPrimaryEdition, INV-7 / B4) so the catalog card and the single-
        // course CTA rank cohorts identically (enrollable wins over running).
        $active_by_course = [];
        foreach ($edition_ids as $edition_id) {
            $course_id = (int) $editionRepo->getField($edition_id, 'course_id', 0);
            $eff = $statuses[$edition_id] ?? null;
            if (!$course_id || !$eff || !$eff->isActive()) {
                continue;
            }
            $active_by_course[$course_id][] = $edition_id;
        }

        foreach ($active_by_course as $course_id => $course_edition_ids) {
            $primary_id = (int) $editionService->getPrimaryEdition($course_edition_ids);
            if ($primary_id) {
                $primary[$course_id] = ['id' => $primary_id, 'status' => $statuses[$primary_id], 'spots' => null];
            }
        }

        if (!empty($primary)) {
            $chosen_ids = array_column($primary, 'id');
            $reg_counts = ntdst_get(RegistrationRepository::class)->countByEditions($chosen_ids);
            foreach ($primary as $course_id => $entry) {
                $capacity = (int) $editionRepo->getField($entry['id'], 'capacity', 0);
                $primary[$course_id]['spots'] = $capacity > 0
                    ? max(0, $capacity - (int) ($reg_counts[$entry['id']] ?? 0))
                    : null;
            }
        }
    }

    $ld_active = $user_id && LearnDashHelper::isActive();

    // CR-G5: resolve the user's enrolled-course set ONCE and check membership
    // per card — a per-card LearnDashHelper::isEnrolled() was an N+1 outside
    // the budget contract (152 queries measured at a 16-course-card slice).
    // Nuance vs isEnrolled(): an OPEN-access course the user never started is
    // no longer flagged enrolled — correct for a catalog card.
    $enrolled_courses = $ld_active
        ? array_map('intval', LearnDashHelper::getEnrolledCourses($user_id))
        : [];

    $out = [];
    foreach ($ids as $course_id) {
        $user_state = null;
        if ($ld_active && in_array($course_id, $enrolled_courses, true)) {
            $user_state = [
                'enrolled' => true,
                'progress' => LearnDashHelper::getProgress($course_id, $user_id),
            ];
        }
        $out[$course_id] = [
            'primary_edition' => $primary[$course_id] ?? null,
            'user_state'      => $user_state,
        ];
    }

    return $out;
}

/**
 * Prime the attachment posts + meta behind the courses' featured images so
 * get_the_post_thumbnail() in the cards stays query-free.
 *
 * @param array<int> $course_ids
 */
function stridence_catalog_prime_thumbnails(array $course_ids): void
{
    $thumb_ids = [];
    foreach (array_unique(array_filter(array_map('intval', $course_ids))) as $course_id) {
        $thumb_id = (int) get_post_thumbnail_id($course_id);
        if ($thumb_id) {
            $thumb_ids[$thumb_id] = $thumb_id;
        }
    }
    if (!empty($thumb_ids)) {
        _prime_post_caches(array_values($thumb_ids), false, true);
    }
}

/**
 * Per-theme item counts for the filter tabs.
 *
 * @param list<array<string, mixed>> $items
 * @return array<string, int>
 */
function stridence_catalog_theme_counts(array $items): array
{
    $counts = [];
    foreach ($items as $item) {
        foreach ($item['themes'] as $slug) {
            $counts[$slug] = ($counts[$slug] ?? 0) + 1;
        }
    }

    return $counts;
}
