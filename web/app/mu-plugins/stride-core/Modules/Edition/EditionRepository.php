<?php

declare(strict_types=1);

namespace Stride\Modules\Edition;

use Stride\Domain\OfferingStatus;
use Stride\Infrastructure\AbstractRepository;

/**
 * Repository for edition data access.
 */
final class EditionRepository extends AbstractRepository
{
    protected string $postType = EditionCPT::POST_TYPE;

    /**
     * Get editions for a specific course (all post-status=publish, any status).
     *
     * @return array<array<string, mixed>>
     */
    public function findByCourse(int $courseId): array
    {
        return $this->model()
            ->where('course_id', $courseId)
            ->where('post_status', 'publish')
            ->orderBy('start_date', 'ASC')
            ->withMeta()
            ->get();
    }

    /**
     * Get IDs of editions for a course that are "active" — i.e. publicly
     * visible, listed in catalogs, reachable via slug routing. Excludes
     * terminal statuses (cancelled, completed, archived) and drafts.
     *
     * Status set lives on OfferingStatus::activeCases().
     *
     * @return list<int>
     */
    public function findActiveIdsByCourse(int $courseId): array
    {
        $rows = $this->model()
            ->where('course_id', $courseId)
            ->where('post_status', 'publish')
            ->whereIn('status', OfferingStatus::activeValues())
            ->get();

        return array_map(static fn(array $row): int => (int) ($row['id'] ?? $row['ID'] ?? 0), $rows);
    }

    /**
     * Canonical ADMIN-ACTIVE edition-ID set — the single source of the
     * admin-workspace scope rule (CR-6). Active = published editions the
     * admin has NOT closed (status outside OfferingStatus::adminClosedValues).
     * Dateless editions (the sessionless interest anchors, §10.7) are active
     * by construction — the rule never looks at dates. Every scope consumer
     * (worklist queues, stats, the grid's default scope via
     * AdminRegistrationQueryService) routes through THIS set instead of
     * re-deriving a predicate.
     *
     * @return list<int>
     */
    public function findActiveDateScopedIds(int $graceDays = 2): array
    {
        global $wpdb;

        // REDEFINED (decision 2026-07-14, F-V3): "active" is STATUS-based, not
        // date-based. The old predicate (start_date >= today − 2d) dropped an
        // edition out of every worklist queue and the default grid TWO DAYS
        // AFTER ITS FIRST SESSION — exactly when the post-course work the
        // queues describe (approvals, quote follow-up, certificates) happens;
        // the nocert queue was structurally ~0 for dated editions. An edition
        // now stays active until the ADMIN closes it (status completed /
        // archived — OfferingStatus::adminClosedValues; stored status only
        // changes by admin action, there is no auto-recompute cron). Editions
        // without a status meta row count as active (defensive: a missing row
        // must never hide live work). $graceDays is retained for signature
        // compatibility but no longer used.
        unset($graceDays);
        $prefix = $this->getMetaPrefix();
        $closed = \Stride\Domain\OfferingStatus::adminClosedValues();
        $closedIn = implode(',', array_fill(0, count($closed), '%s'));

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm_status
                    ON p.ID = pm_status.post_id AND pm_status.meta_key = '{$prefix}status'
             WHERE p.post_type = %s AND p.post_status = 'publish'
               AND (pm_status.meta_value IS NULL OR pm_status.meta_value NOT IN ({$closedIn}))",
            array_merge([EditionCPT::POST_TYPE], $closed),
        ));

        return array_map('intval', $ids);
    }

    /**
     * THE single catalog-eligibility meta_query (Cluster 3 / Task 3.1, moved
     * out of the theme's stridence_catalog_date_window_meta_query()).
     * Active status + the 3-branch date window, so the window rule has exactly
     * ONE home and can no longer fork across /klassikaal, /online and the
     * course archive.
     *
     * Note: the grace cutoff uses wp_date() (site timezone) rather than the
     * theme original's date() (server/UTC), matching this repository's existing
     * convention (see buildOptionsWhere). For a Belgian-timezone site this is
     * the correct, intended boundary; on a UTC site it is identical to the old
     * behaviour. The 3-branch structure below is otherwise a verbatim lift:
     *   (1) dated:   end_date within a 2-day grace past today,
     *   (2) fallback: end_date missing AND start_date within the grace,
     *   (3) dateless: neither end_date nor start_date set (the "Binnenkort —
     *       toon interesse" anchors for klassikaal, always-on enrollables for
     *       online — treatment differs per kind downstream; inclusion does not).
     *
     * Inclusion is additionally gated by post_status=publish (applied by the
     * caller's WP_Query) + the published-course guard downstream (INF-1).
     *
     * INV-3: the meta prefix derives from $this->getMetaPrefix(); it is no
     * longer passed in as a string — the builder is now internal to the repo.
     *
     * @return array<int, array<string, mixed>> meta_query clauses (AND-joined)
     */
    private function catalogDateWindowMetaQuery(int $graceDays = 2): array
    {
        $prefix     = $this->getMetaPrefix();
        $pastCutoff = wp_date('Y-m-d', strtotime('-' . max(0, $graceDays) . ' days'));

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
                    'value'   => $pastCutoff,
                    'compare' => '>=',
                    'type'    => 'DATE',
                ],
                // (2) end_date missing but start_date within the grace window
                [
                    'relation' => 'AND',
                    [
                        'key'     => $prefix . 'end_date',
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key'     => $prefix . 'start_date',
                        'value'   => $pastCutoff,
                        'compare' => '>=',
                        'type'    => 'DATE',
                    ],
                ],
                // (3) fully dateless: neither end_date nor start_date set
                [
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
        ];
    }

    /**
     * The single `exclude_from_catalog` predicate, appended to every catalog
     * ENUMERATION meta_query (primary list + both teaser strips). Matches rows
     * where the flag is NOT true — i.e. the meta is absent (NOT EXISTS) OR the
     * meta exists but is not the truthy '1'.
     *
     * Storage note (verified against a written value, 2026-07-06): a `bool`
     * field's true persists as the string '1' and false as '' (empty, meta
     * present). So NOT EXISTS (never flagged) OR != '1' (flag off) is exactly
     * "listed"; only value === '1' (flagged) is excluded. INV-3: the key is
     * built from getMetaPrefix(), never hardcoded. Listing-only — find($id) is
     * deliberately NOT filtered (the flag is not access control).
     *
     * @return array<string, mixed> A single OR-grouped meta_query clause.
     */
    private function excludeFromCatalogMetaQuery(): array
    {
        $key = $this->getMetaPrefix() . 'exclude_from_catalog';

        return [
            'relation' => 'OR',
            [
                'key'     => $key,
                'compare' => 'NOT EXISTS',
            ],
            [
                'key'     => $key,
                'value'   => '1',
                'compare' => '!=',
            ],
        ];
    }

    /**
     * Catalog-eligible edition IDs — the raw enumeration that used to live in
     * stridence_catalog_klassikaal_items() / _online_items(): published
     * editions matching the single date-window predicate, capped to $limit.
     *
     * When $courseIdFilter is a non-empty list, the result is AND-restricted to
     * editions whose course_id is in that set (the /online path: editions of
     * online-format courses). A null/empty filter enumerates all eligible
     * editions (the /klassikaal path).
     *
     * Returns IDs only — item-shaping/hydration (themes, prices, statuses) stays
     * in the theme prepass (INV-7 convergence point), which is unchanged.
     *
     * @param list<int>|null $courseIdFilter
     * @return list<int>
     */
    public function findCatalogEligibleIds(?array $courseIdFilter = null, int $limit = 200): array
    {
        $metaQuery = $this->catalogDateWindowMetaQuery();
        $metaQuery[] = $this->excludeFromCatalogMetaQuery();

        $courseIds = $courseIdFilter === null
            ? []
            : array_values(array_unique(array_filter(array_map('intval', $courseIdFilter))));

        if ($courseIdFilter !== null && empty($courseIds)) {
            // An explicit but empty filter restricts to nothing.
            return [];
        }

        if (!empty($courseIds)) {
            $metaQuery[] = [
                'key'     => $this->getMetaPrefix() . 'course_id',
                'value'   => $courseIds,
                'compare' => 'IN',
            ];
        }

        $query = new \WP_Query([
            'post_type'      => $this->postType,
            'posts_per_page' => max(1, $limit),
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'no_found_rows'  => true,
            // No start_date orderby: ordering by meta_value forces an EXISTS
            // join on start_date which would drop fully-dateless editions.
            // Dated ordering is presentation, applied downstream by the theme.
            'meta_query'     => $metaQuery,
        ]);

        return array_map('intval', $query->posts);
    }

    /**
     * Homepage-teaser classroom strip edition IDs (archive-sfwd-courses.php,
     * Cluster 3 / Task 3.3 — moved verbatim out of the theme). DISTINCT from
     * findCatalogEligibleIds: the SEO teaser deliberately filters on ACTIVE
     * STATUS ONLY (NO date window — a past-end active classroom edition still
     * shows) and excludes editions of online-format courses. The start_date
     * orderby forces an EXISTS join that drops fully-dateless editions — also
     * deliberate (the interest anchors live on /klassikaal, not the teaser).
     *
     * This is a PRODUCT RULING (Stefan, 2026-06-30), not a refactor: the teaser
     * is NOT converged to the canonical date-window rule. Behaviour-preserving
     * lift of the inline WP_Query so no raw query lives in the theme (INV-3).
     *
     * @param array<int> $excludeCourseIds Editions of these courses are dropped
     *                                     (the online-format course set).
     * @return list<int>
     */
    public function findArchiveClassroomTeaserIds(array $excludeCourseIds = [], int $limit = 6): array
    {
        $prefix = $this->getMetaPrefix();

        $metaQuery = [
            [
                'key'     => $prefix . 'status',
                'value'   => OfferingStatus::activeValues(),
                'compare' => 'IN',
            ],
        ];

        $excludeIds = array_values(array_unique(array_filter(array_map('intval', $excludeCourseIds))));
        if (!empty($excludeIds)) {
            $metaQuery[] = [
                'relation' => 'OR',
                [
                    'key'     => $prefix . 'course_id',
                    'value'   => $excludeIds,
                    'compare' => 'NOT IN',
                ],
                [
                    'key'     => $prefix . 'course_id',
                    'compare' => 'NOT EXISTS',
                ],
            ];
        }

        $metaQuery[] = $this->excludeFromCatalogMetaQuery();

        return $this->teaserQuery($metaQuery, $limit);
    }

    /**
     * Homepage-teaser online strip edition IDs (archive-sfwd-courses.php,
     * Cluster 3 / Task 3.3). Unlike the classroom strip, the online strip IS
     * date-windowed — it reuses the CANONICAL eligibility predicate
     * (catalogDateWindowMetaQuery) scoped to the online-format course set. Like
     * the classroom strip, the start_date orderby drops dateless editions (the
     * teaser shows only dated-soon enrollables; dateless always-on online cards
     * live on /online). Behaviour-preserving lift of the inline WP_Query.
     *
     * @param array<int> $courseIds The online-format course set to scope to.
     * @return list<int>
     */
    public function findArchiveOnlineTeaserIds(array $courseIds, int $limit = 6): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $courseIds))));
        if (empty($ids)) {
            return [];
        }

        $metaQuery = $this->catalogDateWindowMetaQuery();
        $metaQuery[] = [
            'key'     => $this->getMetaPrefix() . 'course_id',
            'value'   => $ids,
            'compare' => 'IN',
        ];
        $metaQuery[] = $this->excludeFromCatalogMetaQuery();

        return $this->teaserQuery($metaQuery, $limit);
    }

    /**
     * Shared teaser WP_Query: published editions matching $metaQuery, capped to
     * $limit, ordered by start_date ASC. The meta_value orderby forces an
     * EXISTS join on start_date — which is exactly why dateless editions drop
     * out of both teaser strips (deliberate, product ruling). The full catalog
     * (findCatalogEligibleIds) avoids this orderby precisely to KEEP dateless.
     *
     * @param array<int, array<string, mixed>> $metaQuery
     * @return list<int>
     */
    private function teaserQuery(array $metaQuery, int $limit): array
    {
        $query = new \WP_Query([
            'post_type'      => $this->postType,
            'posts_per_page' => max(1, $limit),
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => $metaQuery,
            // start_date EXISTS-join → dateless editions excluded (teaser only).
            'orderby'        => 'meta_value',
            'meta_key'       => $this->getMetaPrefix() . 'start_date',
            'order'          => 'ASC',
        ]);

        return array_map('intval', $query->posts);
    }

    /**
     * Published sfwd-courses IDs tagged with an online stride_format
     * (online / e-learning / webinar) — the online-course enumeration the
     * /online catalog path used to run inline. The diff against
     * courseIdsWithAnyEdition() (the pure-LD set) stays in the policy layer.
     *
     * @return list<int>
     */
    public function findOnlineFormatCourseIds(int $limit = 200): array
    {
        $ids = get_posts([
            'post_type'      => 'sfwd-courses',
            'posts_per_page' => max(1, $limit),
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

        return array_map('intval', $ids);
    }

    /**
     * Build the shared WHERE clause + bound params for the edition typeahead
     * picker (AdminAPIController::getEditionOptions). Centralises the picker's
     * SQL predicate in the repo (its sanctioned home) instead of the
     * controller. M4: every dynamic value is a $wpdb->prepare placeholder.
     *
     * - Base predicate: published editions of POST_TYPE.
     * - $dateScoped (scope=active) → NOT-yet-past start_date OR NULL start_date,
     *   so dateless (sessionless §10.7) editions stay in scope (mirrors
     *   getEditions list-view default predicate, commit e2ace22b).
     * - $q → server-side title LIKE, bound + esc_like (never interpolated, M4).
     *
     * Returns [whereClause, params, startKey] where startKey is the prefixed
     * start_date meta key (e.g. '_ntdst_start_date') the JOIN must use.
     *
     * @return array{0: string, 1: list<mixed>, 2: string}
     */
    private function buildOptionsWhere(string $q, bool $dateScoped): array
    {
        global $wpdb;

        $startKey = $this->getMetaPrefix() . 'start_date';

        $where  = ['p.post_type = %s', "p.post_status = 'publish'"];
        $params = [EditionCPT::POST_TYPE];

        if ($dateScoped) {
            $cutoff   = wp_date('Y-m-d', strtotime('-2 days'));
            $where[]  = '(pm_start.meta_value >= %s OR pm_start.meta_value IS NULL)';
            $params[] = $cutoff;
        }

        if ($q !== '') {
            $where[]  = 'p.post_title LIKE %s';
            $params[] = '%' . $wpdb->esc_like($q) . '%';
        }

        return [implode(' AND ', $where), $params, $startKey];
    }

    /**
     * Edition typeahead picker rows (id, title, start_date), NULL-last ordered
     * by start_date. Date-pre-filtered candidate set for the admin grid filter /
     * group-by source. $limit === null returns the whole filtered set (used by
     * the scope=active path, which paginates in PHP after the effective-status
     * drop); a non-null $limit applies SQL LIMIT/OFFSET (scope=all path).
     *
     * @return array<int, object{ID: int, post_title: string, start_date: ?string}>
     */
    public function findEditionOptions(string $q, bool $dateScoped, ?int $limit = null, int $offset = 0): array
    {
        global $wpdb;

        [$whereClause, $params, $startKey] = $this->buildOptionsWhere($q, $dateScoped);

        $sql = "SELECT DISTINCT p.ID, p.post_title, pm_start.meta_value AS start_date
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm_start ON p.ID = pm_start.post_id AND pm_start.meta_key = '{$startKey}'
                WHERE {$whereClause}
                ORDER BY pm_start.meta_value IS NULL, pm_start.meta_value ASC";

        if ($limit !== null) {
            $sql .= ' LIMIT %d OFFSET %d';
            $params[] = $limit;
            $params[] = $offset;
        }

        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    /**
     * COUNT of the edition typeahead picker corpus for the given predicate —
     * the pre-filter total for the scope=all paging path (consistent with its
     * SQL LIMIT/OFFSET, since scope=all has no PHP effective-status drop).
     */
    public function countEditionOptions(string $q, bool $dateScoped): int
    {
        global $wpdb;

        [$whereClause, $params, $startKey] = $this->buildOptionsWhere($q, $dateScoped);

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm_start ON p.ID = pm_start.post_id AND pm_start.meta_key = '{$startKey}'
             WHERE {$whereClause}",
            ...$params,
        ));
    }

    /**
     * COUNT of the admin list-view edition corpus for a pre-built predicate.
     *
     * The caller (AdminAPIController::getEditions) assembles the WHERE clause +
     * bound params + the optional course-taxonomy JOIN fragment (the taxonomy
     * helper is shared with the agenda view and stays in the controller). This
     * method owns ONLY the $wpdb execution — moved here from getEditions so no
     * raw query lives in the controller (INV-3), mirroring countEditionOptions.
     *
     * Behavior-preserving: the LEFT JOIN on _ntdst_start_date + the
     * NULL-permitting default-scope predicate (§10.7 / bug_sessionless_edition_cutoff)
     * are decided by the caller's $whereClause and reproduced VERBATIM here.
     * M4: every dynamic value arrives as a $wpdb->prepare placeholder param.
     *
     * @param string     $whereClause  Pre-built, placeholdered WHERE body.
     * @param list<mixed> $params       Bound params matching the placeholders.
     * @param string     $tagJoin      Optional taxonomy JOIN fragment ('' if none).
     */
    public function countAdminList(string $whereClause, array $params, string $tagJoin): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm_start ON p.ID = pm_start.post_id AND pm_start.meta_key = '_ntdst_start_date'
             {$tagJoin}
             WHERE {$whereClause}",
            ...$params,
        ));
    }

    /**
     * One paged page of admin list-view edition rows (id, title, start_date).
     *
     * Companion to countAdminList — owns the $wpdb execution moved out of
     * getEditions (INV-3). NULL-last ordering by start_date (so dateless
     * sessionless editions sort to the end, §10.7) is reproduced VERBATIM.
     * $limit/$offset are appended as the final two placeholders, matching the
     * pre-extraction param order.
     *
     * @param string     $whereClause  Pre-built, placeholdered WHERE body.
     * @param list<mixed> $params       Bound params matching the WHERE placeholders.
     * @param string     $tagJoin      Optional taxonomy JOIN fragment ('' if none).
     * @return array<int, object{ID: int, post_title: string, start_date: ?string}>
     */
    public function findAdminListRows(string $whereClause, array $params, string $tagJoin, int $limit, int $offset): array
    {
        global $wpdb;

        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT p.ID, p.post_title, pm_start.meta_value as start_date
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm_start ON p.ID = pm_start.post_id AND pm_start.meta_key = '_ntdst_start_date'
             {$tagJoin}
             WHERE {$whereClause}
             ORDER BY pm_start.meta_value IS NULL, pm_start.meta_value ASC
             LIMIT %d OFFSET %d",
            ...$params,
        ));
    }

    /**
     * Count distinct sessions for the admin AGENDA view (one row per session).
     *
     * Companion to findAgendaRows — owns ONLY the $wpdb execution moved out of
     * AdminAPIController::getEditionsAgendaView (INV-3, strangle Task 2a.5),
     * mirroring countAdminList. The caller assembles the WHERE clause + bound
     * params + the optional course-taxonomy JOIN fragment (the taxonomy helper
     * is shared with the list view and stays in the controller).
     *
     * Behavior-preserving: the session->edition->date INNER JOINs and the
     * default 2-days-ago session-date scope are decided by the caller's
     * $whereClause and reproduced VERBATIM here. Sessions ALWAYS carry a date
     * (INNER JOIN on _ntdst_date) — the §10.7 NULL-permitting carve-out is a
     * LIST-view concern (dateless editions have no sessions, so they never
     * appear in the agenda at all); the agenda predicate is intentionally NOT
     * NULL-permitting and must stay so.
     * M4: every dynamic value arrives as a $wpdb->prepare placeholder param.
     *
     * @param string      $whereClause  Pre-built, placeholdered WHERE body.
     * @param list<mixed> $params       Bound params matching the placeholders.
     * @param string      $tagJoin      Optional taxonomy JOIN fragment ('' if none).
     */
    public function countAgendaRows(string $whereClause, array $params, string $tagJoin): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT s.ID)
             FROM {$wpdb->posts} s
             INNER JOIN {$wpdb->postmeta} pm_edition ON s.ID = pm_edition.post_id AND pm_edition.meta_key = '_ntdst_edition_id'
             INNER JOIN {$wpdb->posts} e ON pm_edition.meta_value = e.ID
             INNER JOIN {$wpdb->postmeta} pm_date ON s.ID = pm_date.post_id AND pm_date.meta_key = '_ntdst_date'
             {$tagJoin}
             WHERE {$whereClause}",
            ...$params,
        ));
    }

    /**
     * One paged page of admin AGENDA-view session rows (session + edition + date).
     *
     * Companion to countAgendaRows — owns the $wpdb execution moved out of
     * getEditionsAgendaView (INV-3). The ORDER BY (session date ASC, then
     * edition id ASC) is reproduced VERBATIM. $limit/$offset are appended as the
     * final two placeholders, matching the pre-extraction param order.
     *
     * @param string      $whereClause  Pre-built, placeholdered WHERE body.
     * @param list<mixed> $params       Bound params matching the WHERE placeholders.
     * @param string      $tagJoin      Optional taxonomy JOIN fragment ('' if none).
     * @return array<int, object{session_id: int, session_title: string, edition_id: int, edition_title: string, session_date: ?string}>
     */
    public function findAgendaRows(string $whereClause, array $params, string $tagJoin, int $limit, int $offset): array
    {
        global $wpdb;

        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT s.ID as session_id, s.post_title as session_title,
                    e.ID as edition_id, e.post_title as edition_title,
                    pm_date.meta_value as session_date
             FROM {$wpdb->posts} s
             INNER JOIN {$wpdb->postmeta} pm_edition ON s.ID = pm_edition.post_id AND pm_edition.meta_key = '_ntdst_edition_id'
             INNER JOIN {$wpdb->posts} e ON pm_edition.meta_value = e.ID
             INNER JOIN {$wpdb->postmeta} pm_date ON s.ID = pm_date.post_id AND pm_date.meta_key = '_ntdst_date'
             {$tagJoin}
             WHERE {$whereClause}
             ORDER BY pm_date.meta_value ASC, pm_edition.meta_value ASC
             LIMIT %d OFFSET %d",
            ...$params,
        ));
    }

    /**
     * Get upcoming editions (start date >= today).
     *
     * @return array<array<string, mixed>>
     */
    public function findUpcoming(int $limit = 10): array
    {
        $today = date('Y-m-d');

        return $this->model()
            ->where('start_date', ['>=', $today])
            ->where('post_status', 'publish')
            ->whereNot('status', OfferingStatus::Cancelled->value)
            ->orderBy('start_date', 'ASC')
            ->limit($limit)
            ->withMeta()
            ->get();
    }

    /**
     * Get editions with available spots.
     *
     * @return array<array<string, mixed>>
     */
    public function findWithAvailability(): array
    {
        return $this->model()
            ->where('status', OfferingStatus::Open->value)
            ->where('post_status', 'publish')
            ->orderBy('start_date', 'ASC')
            ->withMeta()
            ->get();
    }

    /**
     * Query editions with flexible filters.
     *
     * @param array{
     *     course_id?: int,
     *     status?: string,
     *     start_date_from?: string,
     *     start_date_to?: string,
     *     limit?: int,
     * } $filters
     * @return array<array<string, mixed>>
     */
    public function findByFilters(array $filters = [], int $limit = 100): array
    {
        $query = $this->model()
            ->where('post_status', 'publish')
            ->orderBy('start_date', 'ASC')
            ->withMeta();

        if (!empty($filters['course_id'])) {
            $query->where('course_id', (int) $filters['course_id']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['start_date_from'])) {
            $query->where('start_date', ['>=', $filters['start_date_from']]);
        }

        if (!empty($filters['start_date_to'])) {
            $query->where('start_date', ['<=', $filters['start_date_to']]);
        }

        return $query->limit($limit)->get();
    }

    /**
     * Batch-fetch editions by ID, keyed by edition_id.
     *
     * Returns only editions that exist; missing IDs are silently dropped.
     * Includes all post statuses (publish, draft, trash) so callers that
     * need to render historical references don't lose data.
     *
     * @param int[] $editionIds
     * @return array<int, \WP_Post>
     */
    public function findManyById(array $editionIds): array
    {
        if (empty($editionIds)) {
            return [];
        }

        $posts = get_posts([
            'post_type' => $this->postType,
            'post__in' => array_map('intval', $editionIds),
            'posts_per_page' => count($editionIds),
            'post_status' => 'any',
        ]);

        $map = [];
        foreach ($posts as $post) {
            $map[(int) $post->ID] = $post;
        }

        return $map;
    }

    /**
     * Batch-resolve edition_id → course_id for a list of edition IDs.
     *
     * Returns a map of edition_id => course_id, only for editions that
     * actually have a course_id assigned. IDs with no course are omitted
     * (use `array_key_exists()` rather than `??`-coalesce if you need to
     * distinguish "no course" from "unknown id").
     *
     * @param int[] $editionIds
     * @return array<int, int>
     */
    public function findCourseIdsForEditions(array $editionIds): array
    {
        if (empty($editionIds)) {
            return [];
        }

        $rows = $this->model()
            ->whereIn('ID', array_map('intval', $editionIds))
            ->where('post_status', 'publish')
            ->withMeta()
            ->get();

        $prefixedKey = $this->getMetaPrefix() . 'course_id';

        $map = [];
        foreach ($rows as $row) {
            $editionId = (int) ($row['id'] ?? $row['ID'] ?? 0);
            $courseId = (int) ($row['meta'][$prefixedKey] ?? 0);
            if ($editionId > 0 && $courseId > 0) {
                $map[$editionId] = $courseId;
            }
        }

        return $map;
    }

    /**
     * Of the given course ids, return those that have at least one edition.
     *
     * Used by the catalog (Task G1 / audit 2.2) to tell pure-LD courses
     * (never had an edition) apart from courses whose editions all expired —
     * the latter go off-catalog until a new edition is scheduled. Matches
     * the relationship semantics the theme templates previously inlined as
     * raw SQL: ANY edition post row counts, in ANY post_status (publish,
     * draft, trash, …) — deliberately no post_status filter (INF-3): an
     * edition row, even drafted or trashed, signals admin intent to run
     * editions for the course, so the course is not "pure LD". Kept as-is
     * for parity with the pre-refactor inline SQL (ship-mode).
     *
     * @param array<int> $courseIds
     * @return list<int>
     */
    public function courseIdsWithAnyEdition(array $courseIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $courseIds)));
        if (empty($ids)) {
            return [];
        }

        global $wpdb;

        $courseKey    = $this->getMetaPrefix() . 'course_id';
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT pm.meta_value + 0
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s
               AND p.post_type = %s
               AND pm.meta_value IN ({$placeholders})",
            array_merge([$courseKey, $this->postType], $ids),
        ));

        return array_map('intval', $rows);
    }

    /**
     * Update edition status.
     */
    public function updateStatus(int $editionId, OfferingStatus $status): void
    {
        $this->model()->updateMetaBatch($editionId, ['status' => $status->value]);
    }

    /**
     * Update edition meta fields.
     */
    public function updateMeta(int $editionId, array $data): bool
    {
        return $this->model()->updateMetaBatch($editionId, $data);
    }

    /**
     * Speakers as a normalized list of ['name' => string, 'role' => string].
     *
     * The meta is a JSON array of {name, role} since 2026-06; legacy values
     * are plain strings ("Lien De Smedt, sportpedagoge") and are returned as
     * a single entry with the whole string as name and an empty role.
     *
     * @return array<int, array{name: string, role: string}>
     */
    public function getSpeakers(int $editionId): array
    {
        // Raw meta, NOT getField(): the json-typed schema decodes legacy
        // plain-string values to [] on the formatted read path, which would
        // silently drop them before this normalization can see them.
        $raw = $this->rawSpeakersMeta($editionId);

        if (is_string($raw)) {
            $raw = trim($raw);
            if ($raw === '') {
                return [];
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                // Legacy plain-string value
                return [['name' => $raw, 'role' => '']];
            }
            $raw = $decoded;
        }

        if (!is_array($raw)) {
            return [];
        }

        $speakers = [];
        foreach ($raw as $entry) {
            if (is_string($entry)) {
                $entry = ['name' => $entry];
            }
            if (!is_array($entry)) {
                continue;
            }
            $name = trim((string) ($entry['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $speakers[] = [
                'name' => $name,
                'role' => trim((string) ($entry['role'] ?? '')),
            ];
        }

        return $speakers;
    }

    /**
     * Speakers as a flat display string (joined names) — for API payloads
     * and exports that consumed the legacy string field.
     */
    public function getSpeakersLabel(int $editionId): string
    {
        return implode(', ', array_column($this->getSpeakers($editionId), 'name'));
    }

    /**
     * Unformatted speakers meta (overridable seam for unit tests).
     */
    protected function rawSpeakersMeta(int $editionId): mixed
    {
        return get_post_meta($editionId, $this->model()->getMetaPrefix() . 'speakers', true);
    }

    /**
     * Per-profiletype enrollment rules for this edition.
     *
     * Thin typed wrapper over the `profiletype_rules` (json) field. Shape:
     * { "<slug>": { "block": bool, "minimal": bool, "voucher": "<code>|null" } }.
     * Empty / absent / legacy non-array value coerces to [] (erosion guard —
     * never null, never a raw string). No prefix hardcoded: getField() applies
     * the CPT meta_prefix (_ntdst_).
     *
     * @return array<string, mixed>
     */
    public function getProfiletypeRules(int $editionId): array
    {
        $rules = $this->getField($editionId, 'profiletype_rules', []);

        return is_array($rules) ? $rules : [];
    }

    /**
     * Whether this edition is excluded from the public catalog listing.
     *
     * Thin typed wrapper over the `exclude_from_catalog` (bool) field. Absent
     * or falsey ⇒ false (listed). Not a security boundary — a listing flag.
     */
    public function getExcludeFromCatalog(int $editionId): bool
    {
        return (bool) $this->getField($editionId, 'exclude_from_catalog', false);
    }
}
