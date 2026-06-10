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
use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Enrollment\RegistrationRepository;

/** Server-render cap per catalog page ("Toon meer" fetches the next slice). */
const STRIDENCE_CATALOG_PER_PAGE = 24;

/** Upper bound on the eligible-items enumeration (ids only — memory guard). */
const STRIDENCE_CATALOG_MAX_ITEMS = 500;

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
 * Eligible items for /klassikaal: active-status editions inside the date
 * window (2-day grace past end_date, start_date fallback), excluding
 * editions of online-only-format courses. Ordered by start_date ASC.
 */
function stridence_catalog_klassikaal_items(): array
{
    $prefix = ntdst_get(EditionRepository::class)->getMetaPrefix();
    $past_cutoff = date('Y-m-d', strtotime('-2 days'));

    $query = new WP_Query([
        'post_type'      => 'vad_edition',
        'posts_per_page' => STRIDENCE_CATALOG_MAX_ITEMS,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_query'     => [
            [
                'key'     => $prefix . 'status',
                'value'   => OfferingStatus::activeValues(),
                'compare' => 'IN',
            ],
            [
                'relation' => 'OR',
                [
                    'key'     => $prefix . 'end_date',
                    'value'   => $past_cutoff,
                    'compare' => '>=',
                    'type'    => 'DATE',
                ],
                [
                    // Fallback when end_date is missing: use start_date
                    'relation' => 'AND',
                    [
                        'key'     => $prefix . 'end_date',
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key'     => $prefix . 'start_date',
                        'value'   => $past_cutoff,
                        'compare' => '>=',
                        'type'    => 'DATE',
                    ],
                ],
            ],
        ],
        'orderby'        => 'meta_value',
        'meta_key'       => $prefix . 'start_date',
        'order'          => 'ASC',
    ]);

    $items = stridence_catalog_edition_items_from_ids(array_map('intval', $query->posts));

    // Exclude editions of online-only-format courses (same rule as before:
    // online format present AND no classroom format).
    return array_values(array_filter($items, static function (array $item): bool {
        $course_id = (int) ($item['edition']['course_id'] ?? 0);
        if (!$course_id) {
            return true;
        }
        $formats = get_the_terms($course_id, 'stride_format');
        if (!$formats || is_wp_error($formats)) {
            return true;
        }
        $format_slugs = wp_list_pluck($formats, 'slug');

        $is_online    = (bool) array_intersect($format_slugs, ['online', 'webinar', 'e-learning']);
        $is_classroom = (bool) array_intersect($format_slugs, ['klassikaal', 'classroom']);

        return !($is_online && !$is_classroom);
    }));
}

/**
 * Eligible items for /online: one card per enrollable — (a) active editions
 * of online-format courses (date window incl. self-paced/dateless), plus
 * (b) pure-LD online courses that never had an edition.
 */
function stridence_catalog_online_items(): array
{
    $editionRepo = ntdst_get(EditionRepository::class);
    $prefix = $editionRepo->getMetaPrefix();

    $online_course_ids = get_posts([
        'post_type'      => 'sfwd-courses',
        'posts_per_page' => STRIDENCE_CATALOG_MAX_ITEMS,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'tax_query'      => [
            [
                'taxonomy' => 'stride_format',
                'field'    => 'slug',
                'terms'    => ['online', 'e-learning', 'webinar'],
            ],
        ],
    ]);

    if (empty($online_course_ids)) {
        return [];
    }

    $items = [];

    // --- (a) Active editions of online courses ---
    $past_cutoff = date('Y-m-d', strtotime('-2 days'));
    $edition_query = new WP_Query([
        'post_type'      => 'vad_edition',
        'posts_per_page' => STRIDENCE_CATALOG_MAX_ITEMS,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_query'     => [
            [
                'key'     => $prefix . 'status',
                'value'   => OfferingStatus::activeValues(),
                'compare' => 'IN',
            ],
            [
                'key'     => $prefix . 'course_id',
                'value'   => $online_course_ids,
                'compare' => 'IN',
            ],
            [
                'relation' => 'OR',
                [
                    'key'     => $prefix . 'end_date',
                    'value'   => $past_cutoff,
                    'compare' => '>=',
                    'type'    => 'DATE',
                ],
                [
                    // Fallback when end_date is missing: use start_date
                    'relation' => 'AND',
                    [
                        'key'     => $prefix . 'end_date',
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key'     => $prefix . 'start_date',
                        'value'   => $past_cutoff,
                        'compare' => '>=',
                        'type'    => 'DATE',
                    ],
                ],
                [
                    // Self-paced online edition (no dates at all) — always show
                    'relation' => 'AND',
                    [
                        'key'     => $prefix . 'end_date',
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key'     => $prefix . 'start_date',
                        'compare' => 'NOT EXISTS',
                    ],
                ],
            ],
        ],
        'orderby'        => 'meta_value',
        'meta_key'       => $prefix . 'start_date',
        'order'          => 'ASC',
    ]);

    $items = stridence_catalog_edition_items_from_ids(array_map('intval', $edition_query->posts));

    // --- (b) Pure-LD online courses (never had an edition at all) ---
    $with_editions = $editionRepo->courseIdsWithAnyEdition(array_map('intval', $online_course_ids));
    $pure_ld_ids = array_values(array_diff(array_map('intval', $online_course_ids), $with_editions));

    if (!empty($pure_ld_ids)) {
        _prime_post_caches($pure_ld_ids, true, true);
        foreach ($pure_ld_ids as $course_id) {
            if (!get_post($course_id)) {
                continue;
            }
            $items[] = [
                'kind'      => 'course',
                'course_id' => $course_id,
                'themes'    => stridence_catalog_theme_slugs($course_id),
            ];
        }
    }

    return $items;
}

/**
 * Build light edition items (data array + course theme slugs) for a list of
 * edition ids — batched: posts + meta + course terms are primed first.
 *
 * @param array<int> $edition_ids
 * @return list<array{kind: string, edition: array<string, mixed>, themes: list<string>}>
 */
function stridence_catalog_edition_items_from_ids(array $edition_ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $edition_ids))));
    if (empty($ids)) {
        return [];
    }

    $editionRepo = ntdst_get(EditionRepository::class);

    _prime_post_caches($ids, false, true);

    $course_ids = [];
    foreach ($ids as $id) {
        $course_id = (int) $editionRepo->getField($id, 'course_id', 0);
        if ($course_id) {
            $course_ids[$course_id] = $course_id;
        }
    }
    if (!empty($course_ids)) {
        _prime_post_caches(array_values($course_ids), true, true);
    }

    $items = [];
    foreach ($ids as $id) {
        $post = get_post($id);
        if (!$post) {
            continue;
        }
        $fields = $editionRepo->findFields($id);
        $course_id = (int) ($fields['course_id'] ?? 0);

        $items[] = [
            'kind'    => 'edition',
            'edition' => [
                'id'              => $id,
                'title'           => $post->post_title,
                'course_id'       => $course_id ?: null,
                'start_date'      => $fields['start_date'] ?? null,
                'end_date'        => $fields['end_date'] ?? null,
                'venue'           => $fields['venue'] ?? null,
                'price'           => $fields['price'] ?? null,
                'capacity'        => $fields['capacity'] ?? null,
                'status'          => $fields['status'] ?? 'open',
                'spots_remaining' => $fields['spots_remaining'] ?? null,
            ],
            'themes'  => $course_id ? stridence_catalog_theme_slugs($course_id) : [],
        ];
    }

    return $items;
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
    // one registration GROUP BY, one cached enrolled-set read.
    $statuses = ntdst_get(EditionService::class)->getEffectiveStatuses($ids);
    $reg_counts = ntdst_get(RegistrationRepository::class)->countByEditions($ids);
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
    // "first best" pick stays identical.
    $edition_ids = get_posts([
        'post_type'      => 'vad_edition',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
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
        ],
    ]);
    $edition_ids = array_map('intval', $edition_ids);

    $primary = [];
    if (!empty($edition_ids)) {
        $statuses = ntdst_get(EditionService::class)->getEffectiveStatuses($edition_ids);

        $best_rank = [];
        foreach ($edition_ids as $edition_id) {
            $course_id = (int) $editionRepo->getField($edition_id, 'course_id', 0);
            $eff = $statuses[$edition_id] ?? null;
            // Effective status may flip a past edition to Completed — filter
            // that out (stale visibility leak), same rule as before.
            if (!$course_id || !$eff || !$eff->isActive()) {
                continue;
            }
            $rank = $eff->allowsEnrollment() ? 2 : 1;
            if ($rank > ($best_rank[$course_id] ?? -1)) {
                $primary[$course_id] = ['id' => $edition_id, 'status' => $eff, 'spots' => null];
                $best_rank[$course_id] = $rank;
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

    $out = [];
    foreach ($ids as $course_id) {
        $user_state = null;
        if ($ld_active && LearnDashHelper::isEnrolled($course_id, $user_id)) {
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
