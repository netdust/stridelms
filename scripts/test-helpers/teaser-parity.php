<?php

/**
 * Behaviour-parity guard for Task 3.3: capture the archive teaser strip item
 * IDs the OLD inline query produces vs the NEW EditionService::getArchiveTeaserItems(),
 * against the live seeded DB. Output must be IDENTICAL (same edition ids, same
 * order, same pure-LD course ids).
 *
 * Run: ddev exec wp eval-file scripts/test-helpers/teaser-parity.php
 */

use Stride\Domain\OfferingStatus;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;

$repo    = ntdst_get(EditionRepository::class);
$service = ntdst_get(EditionService::class);
$prefix  = $repo->getMetaPrefix();

/* ---- OLD inline archive logic (verbatim copy of pre-repoint template) ---- */

$online_course_ids = get_posts([
    'post_type'      => 'sfwd-courses',
    'posts_per_page' => 500,
    'post_status'    => 'publish',
    'fields'         => 'ids',
    'tax_query'      => [[
        'taxonomy' => 'stride_format',
        'field'    => 'slug',
        'terms'    => ['online', 'e-learning', 'webinar'],
    ]],
]);

// classroom: active-only, exclude online-course editions, start_date orderby.
$edition_meta_query = [[
    'key'     => $prefix . 'status',
    'value'   => OfferingStatus::activeValues(),
    'compare' => 'IN',
]];
if (!empty($online_course_ids)) {
    $edition_meta_query[] = [
        'relation' => 'OR',
        ['key' => $prefix . 'course_id', 'value' => $online_course_ids, 'compare' => 'NOT IN'],
        ['key' => $prefix . 'course_id', 'compare' => 'NOT EXISTS'],
    ];
}
$old_classroom_ids = array_map('intval', (new WP_Query([
    'post_type'      => 'vad_edition',
    'posts_per_page' => 6,
    'post_status'    => 'publish',
    'fields'         => 'ids',
    'no_found_rows'  => true,
    'meta_query'     => $edition_meta_query,
    'orderby'        => 'meta_value',
    'meta_key'       => $prefix . 'start_date',
    'order'          => 'ASC',
]))->posts);

// online: canonical date-window scoped to online courses, start_date orderby + pure-LD top-up.
$old_online_edition_ids = [];
$old_pure_ld_ids = [];
if (!empty($online_course_ids)) {
    $online_meta_query = [
        ['key' => $prefix . 'status', 'value' => OfferingStatus::activeValues(), 'compare' => 'IN'],
        ['relation' => 'OR',
            ['key' => $prefix . 'end_date', 'value' => date('Y-m-d', strtotime('-2 days')), 'compare' => '>=', 'type' => 'DATE'],
            ['relation' => 'AND',
                ['key' => $prefix . 'end_date', 'compare' => 'NOT EXISTS'],
                ['key' => $prefix . 'start_date', 'value' => date('Y-m-d', strtotime('-2 days')), 'compare' => '>=', 'type' => 'DATE'],
            ],
            ['relation' => 'AND',
                ['key' => $prefix . 'end_date', 'compare' => 'NOT EXISTS'],
                ['key' => $prefix . 'start_date', 'compare' => 'NOT EXISTS'],
            ],
        ],
        ['key' => $prefix . 'course_id', 'value' => $online_course_ids, 'compare' => 'IN'],
    ];
    $old_online_edition_ids = array_map('intval', (new WP_Query([
        'post_type'      => 'vad_edition',
        'posts_per_page' => 6,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_query'     => $online_meta_query,
        'orderby'        => 'meta_value',
        'meta_key'       => $prefix . 'start_date',
        'order'          => 'ASC',
    ]))->posts);

    // INF-1 guard the old hydration applied: drop editions of non-published courses.
    $old_online_edition_ids = array_values(array_filter($old_online_edition_ids, static function (int $id) use ($repo): bool {
        $cid = (int) $repo->getField($id, 'course_id', 0);
        return !$cid || get_post_status($cid) === 'publish';
    }));

    $remaining = 6 - count($old_online_edition_ids);
    if ($remaining > 0) {
        $with_editions = $repo->courseIdsWithAnyEdition(array_map('intval', $online_course_ids));
        $pure = array_values(array_diff(array_map('intval', $online_course_ids), $with_editions));
        foreach (array_slice($pure, 0, $remaining) as $cid) {
            if (get_post($cid)) {
                $old_pure_ld_ids[] = $cid;
            }
        }
    }
}

/* ---- NEW service output ---- */

$editionIds = static function (array $items): array {
    $out = [];
    foreach ($items as $i) {
        if (($i['kind'] ?? '') === 'edition') {
            $out[] = (int) ($i['edition']['id'] ?? 0);
        }
    }
    return $out;
};
$courseIds = static function (array $items): array {
    $out = [];
    foreach ($items as $i) {
        if (($i['kind'] ?? '') === 'course') {
            $out[] = (int) $i['course_id'];
        }
    }
    return $out;
};

$new_classroom = $service->getArchiveTeaserItems('classroom');
$new_online    = $service->getArchiveTeaserItems('online');

$new_classroom_ids     = $editionIds($new_classroom);
$new_online_edition_ids = $editionIds($new_online);
$new_pure_ld_ids        = $courseIds($new_online);

/* ---- Compare ---- */

$fail = 0;
$cmp = static function (string $label, array $old, array $new) use (&$fail): void {
    $ok = $old === $new; // strict: same values AND same order
    printf("%-28s OLD=[%s]  NEW=[%s]  %s\n", $label, implode(',', $old), implode(',', $new), $ok ? 'OK' : 'MISMATCH');
    if (!$ok) {
        $fail++;
    }
};

$cmp('classroom edition ids', $old_classroom_ids, $new_classroom_ids);
$cmp('online edition ids', $old_online_edition_ids, $new_online_edition_ids);
$cmp('online pure-LD course ids', $old_pure_ld_ids, $new_pure_ld_ids);

echo $fail === 0 ? "\nPARITY: IDENTICAL\n" : "\nPARITY: {$fail} MISMATCH(ES)\n";
exit($fail === 0 ? 0 : 1);
