<?php

declare(strict_types=1);

namespace Stride\Modules\Trajectory;

use Stride\Domain\TrajectoryMode;
use Stride\Domain\OfferingStatus;
use Stride\Infrastructure\AbstractRepository;
use WP_Error;
use WP_Post;

/**
 * Trajectory repository for CRUD operations.
 */
final class TrajectoryRepository extends AbstractRepository
{
    protected string $postType = TrajectoryCPT::POST_TYPE;

    /**
     * The single source of truth for "active trajectory" status values.
     *
     * A trajectory is active when its stored status is one of these
     * non-terminal offering statuses. Both findActive() (catalog) and the
     * admin trajectory typeahead (AdminAPIController::getTrajectoryOptions)
     * reference THIS const — never re-list the set inline, or the two
     * surfaces drift when the domain definition changes.
     *
     * @var list<string>
     */
    public const ACTIVE_STATUSES = [
        OfferingStatus::Announcement->value,
        OfferingStatus::Open->value,
        OfferingStatus::InProgress->value,
    ];

    /**
     * Get a single field value.
     */
    public function getField(int $id, string $field, mixed $default = null): mixed
    {
        $value = $this->model()->getMeta($id, $field);

        return $value !== null ? $value : $default;
    }

    /**
     * Find active trajectories.
     *
     * @return array<array<string, mixed>>
     */
    public function findActive(): array
    {
        $rows = $this->model()
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->orderBy('post_title', 'ASC')
            ->limit(-1) // catalog shows ALL active trajectories — not the default page cap
            ->withMeta()
            ->get();

        // exclude_from_catalog (§6.1): drop flagged trajectories from the catalog
        // enumeration. This uses a POST-QUERY PHP filter, NOT a builder predicate,
        // deliberately: the fluent builder can express a flat AND meta clause but
        // NOT the required "NOT EXISTS OR != '1'" group (an unflagged trajectory
        // has no meta row at all, so a bare != '1' clause would WRONGLY drop it —
        // WP's != compare requires the meta to exist). orWhere() would OR the
        // whole query and break the status AND. The set is bounded (limit(-1) =
        // all ACTIVE trajectories, a small catalog set), so filtering in PHP via
        // the prefix-aware getExcludeFromCatalog() accessor is cheap and correct.
        return array_values(array_filter(
            $rows,
            fn(array $row): bool => !$this->getExcludeFromCatalog(
                (int) ($row['id'] ?? $row['ID'] ?? 0),
            ),
        ));
    }

    /**
     * Build the shared WHERE clause + bound params for the trajectory typeahead
     * picker (AdminAPIController::getTrajectoryOptions). Centralises the picker's
     * SQL predicate in the repo (its sanctioned home). M4: every dynamic value
     * is a $wpdb->prepare placeholder.
     *
     * - Base predicate: published trajectories of POST_TYPE.
     * - $q → server-side title LIKE, bound + esc_like (never interpolated, M4).
     * - $activeOnly (scope=active) → EXISTS subquery restricting to the
     *   non-terminal ACTIVE_STATUSES (this class' single source of truth, so the
     *   typeahead and findActive never drift). No date carve-out — trajectories
     *   have no dates. The status meta key is built from getMetaPrefix() (D1).
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildOptionsWhere(string $q, bool $activeOnly): array
    {
        global $wpdb;

        $where  = ['p.post_type = %s', "p.post_status = 'publish'"];
        $params = [TrajectoryCPT::POST_TYPE];

        if ($q !== '') {
            $where[]  = 'p.post_title LIKE %s';
            $params[] = '%' . $wpdb->esc_like($q) . '%';
        }

        if ($activeOnly) {
            $statusKey          = $this->getMetaPrefix() . 'status';
            $statusPlaceholders = implode(',', array_fill(0, count(self::ACTIVE_STATUSES), '%s'));
            $where[]            = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_status
                WHERE pm_status.post_id = p.ID
                AND pm_status.meta_key = '{$statusKey}'
                AND pm_status.meta_value IN ({$statusPlaceholders}))";
            foreach (self::ACTIVE_STATUSES as $st) {
                $params[] = $st;
            }
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * COUNT of the trajectory typeahead picker corpus for the given predicate.
     */
    public function countTrajectoryOptions(string $q, bool $activeOnly): int
    {
        global $wpdb;

        [$whereClause, $params] = $this->buildOptionsWhere($q, $activeOnly);

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$whereClause}",
            ...$params,
        ));
    }

    /**
     * Trajectory typeahead picker rows (id, title), title-ordered, paged via
     * SQL LIMIT/OFFSET — the admin grid Traject filter / group-by source.
     * Status is composed by the caller (batched, no N+1).
     *
     * @return array<int, object{ID: int, post_title: string}>
     */
    public function findTrajectoryOptions(string $q, bool $activeOnly, int $limit, int $offset): array
    {
        global $wpdb;

        [$whereClause, $params] = $this->buildOptionsWhere($q, $activeOnly);
        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title FROM {$wpdb->posts} p
             WHERE {$whereClause}
             ORDER BY p.post_title ASC
             LIMIT %d OFFSET %d",
            ...$params,
        ));
    }

    /**
     * COUNT of the admin trajectory-list corpus for a caller-built predicate.
     *
     * The caller (AdminTrajectoryService::getTrajectories) assembles the WHERE
     * clause + bound params (the post_type/post_status base + the optional
     * search/status/active-scope sub-selects). This method owns ONLY the $wpdb
     * execution — moved here from the controller-era inline query so no raw
     * query lives in the read-model service (INV-3), mirroring
     * EditionRepository::countAdminList / QuoteRepository::countAdminList.
     *
     * Behavior-preserving: the COUNT(*) over wp_posts aliased `p` and the
     * caller's WHERE are reproduced VERBATIM from the pre-extraction query.
     * Every dynamic value arrives as a $wpdb->prepare placeholder param.
     *
     * @param string      $whereClause Pre-built, placeholdered WHERE body.
     * @param list<mixed> $params      Bound params matching the placeholders.
     */
    public function countAdminList(string $whereClause, array $params): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$whereClause}",
            ...$params,
        ));
    }

    /**
     * One paged page of admin trajectory-list rows (id, title, date, content).
     *
     * Companion to countAdminList — owns the $wpdb execution moved out of
     * AdminTrajectoryService::getTrajectories (INV-3 / the self-flagged
     * "DRIFT #2"), mirroring EditionRepository::findAdminListRows /
     * QuoteRepository::findAdminListRows. Unlike the quote/edition list rows,
     * the trajectory read-model derives its description from post_content, so
     * post_content is part of the SELECT (reproduced VERBATIM).
     *
     * The ORDER BY p.post_date DESC and the LIMIT/OFFSET (appended as the final
     * two placeholders, matching the pre-extraction param order) are reproduced
     * VERBATIM.
     *
     * @param string      $whereClause Pre-built, placeholdered WHERE body.
     * @param list<mixed> $params      Bound params matching the WHERE placeholders.
     * @return array<int, object{ID: int, post_title: string, post_date: string, post_content: string}>
     */
    public function findAdminListRows(string $whereClause, array $params, int $limit, int $offset): array
    {
        global $wpdb;

        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_date, p.post_content FROM {$wpdb->posts} p
             WHERE {$whereClause}
             ORDER BY p.post_date DESC
             LIMIT %d OFFSET %d",
            ...$params,
        ));
    }

    /**
     * Fetch a SINGLE admin trajectory-list row by id (id, title, date, content).
     *
     * Companion to findAdminListRows for the single-item detail path (S5): the
     * GET /admin/trajectories/{id} endpoint needs the SAME row shape one list
     * row carries, for ONE trajectory — so the per-item formatter that shapes a
     * list row can shape the detail row identically (single + list parity).
     *
     * Returns the row only for a PUBLISHED post of this CPT (the same predicate
     * the list SELECT enforces), or null when no such trajectory exists — so the
     * caller's 404 path is driven by null, not by a linear scan of a paged list.
     * The SELECT column set is VERBATIM the findAdminListRows column set.
     *
     * @return object{ID: int, post_title: string, post_date: string, post_content: string}|null
     */
    public function findById(int $id): ?object
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_date, p.post_content FROM {$wpdb->posts} p
             WHERE p.ID = %d AND p.post_type = %s AND p.post_status = 'publish'",
            $id,
            TrajectoryCPT::POST_TYPE,
        ));

        return $row ?: null;
    }

    /**
     * Find trajectories open for enrollment.
     *
     * @return array<array<string, mixed>>
     */
    public function findOpen(): array
    {
        return $this->model()
            ->where('status', OfferingStatus::Open->value)
            ->orderBy('post_title', 'ASC')
            ->withMeta()
            ->get();
    }

    /**
     * Validate trajectory data before create/update.
     */
    public function validate(array $data): true|WP_Error
    {
        // Validate mode if provided
        if (!empty($data['mode'])) {
            $mode = TrajectoryMode::tryFrom($data['mode']);
            if ($mode === null) {
                return new WP_Error('invalid_mode', 'Invalid trajectory mode');
            }
        }

        // Validate status if provided
        if (!empty($data['status'])) {
            $status = OfferingStatus::tryFrom($data['status']);
            if ($status === null) {
                return new WP_Error('invalid_status', 'Invalid trajectory status');
            }
        }

        // Validate deadline order if both provided
        if (!empty($data['choice_available_date']) && !empty($data['choice_deadline'])) {
            if (strtotime($data['choice_deadline']) <= strtotime($data['choice_available_date'])) {
                return new WP_Error('invalid_deadlines', 'Choice deadline must be after choice available date');
            }
        }

        return true;
    }

    /**
     * Create trajectory with validation.
     */
    public function create(array $data): WP_Post|WP_Error
    {
        $validation = $this->validate($data);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Set defaults
        if (empty($data['mode'])) {
            $data['mode'] = TrajectoryMode::Cohort->value;
        }
        if (empty($data['status'])) {
            $data['status'] = OfferingStatus::Draft->value;
        }

        return parent::create($data);
    }

    /**
     * Get course configuration for trajectory.
     *
     * @return array<array<string, mixed>>
     */
    public function getCourses(int $trajectoryId): array
    {
        $courses = $this->getField($trajectoryId, 'courses', []);

        if (empty($courses)) {
            return [];
        }

        return is_array($courses) ? $courses : (json_decode($courses, true) ?: []);
    }

    /**
     * Get required courses for trajectory.
     *
     * Returns actual WP_Post objects for each required course, with config data attached.
     *
     * @return array<WP_Post>
     */
    public function getRequiredCourses(int $trajectoryId): array
    {
        $courses = $this->getCourses($trajectoryId);
        $required = array_filter($courses, fn($c) => ($c['required'] ?? false) === true);

        return $this->resolveCoursePosts($required);
    }

    /**
     * Get elective groups for trajectory.
     *
     * Returns groups with actual WP_Post objects, with config data attached.
     *
     * @return array<array{name: string, required: int, courses: array<WP_Post>}>
     */
    public function getElectiveGroups(int $trajectoryId): array
    {
        $courses = $this->getCourses($trajectoryId);
        $electives = array_filter($courses, fn($c) => ($c['required'] ?? false) === false);

        $groups = [];
        foreach ($electives as $config) {
            $groupName = $config['group'] ?? 'Keuze';
            if (!isset($groups[$groupName])) {
                $groups[$groupName] = [
                    'name' => $groupName,
                    'required' => (int) ($config['pick_count'] ?? $config['min_choices'] ?? 0),
                    'courses' => [],
                ];
            }
            $groups[$groupName]['courses'][] = $config;
        }

        // Resolve course posts for each group
        foreach ($groups as $groupName => &$group) {
            $group['courses'] = $this->resolveCoursePosts($group['courses']);
        }

        return array_values($groups);
    }

    /**
     * Find trajectory by slug.
     */
    public function findBySlug(string $slug): ?WP_Post
    {
        $results = $this->model()
            ->where('post_name', $slug)
            ->where('post_status', 'publish')
            ->limit(1)
            ->get();

        if (empty($results)) {
            return null;
        }

        $id = (int) ($results[0]['id'] ?? $results[0]['ID'] ?? 0);

        return $id > 0 ? get_post($id) : null;
    }

    /**
     * Get messages for trajectory.
     *
     * @return array<array<string, mixed>>
     */
    public function getMessages(int $trajectoryId): array
    {
        $messages = $this->getField($trajectoryId, 'trajectory_messages', []);

        if (empty($messages) || !is_array($messages)) {
            return [];
        }

        // Filter deleted and sort by date descending
        $messages = array_filter($messages, fn($m) => empty($m['_deleted']));
        usort($messages, fn($a, $b) => strtotime($b['date'] ?? '') - strtotime($a['date'] ?? ''));

        return $messages;
    }

    /**
     * Resolve course configurations to WP_Post objects.
     *
     * @param array<array<string, mixed>> $courseConfigs
     * @return array<WP_Post>
     */
    private function resolveCoursePosts(array $courseConfigs): array
    {
        // Sort by order
        usort($courseConfigs, fn($a, $b) => ($a['order'] ?? 99) <=> ($b['order'] ?? 99));

        $posts = [];
        foreach ($courseConfigs as $config) {
            $courseId = (int) ($config['course_id'] ?? 0);
            if ($courseId <= 0) {
                continue;
            }

            $post = get_post($courseId);
            if (!$post || $post->post_status !== 'publish') {
                continue;
            }

            // Attach config data to post object for template use
            $post->trajectory_config = $config;
            $posts[] = $post;
        }

        return $posts;
    }

    /**
     * Per-profiletype enrollment rules for this trajectory.
     *
     * Thin typed wrapper over the `profiletype_rules` (json) field. Shape:
     * { "<slug>": { "block": bool, "minimal": bool, "voucher": "<code>|null" } }.
     * Empty / absent / legacy non-array value coerces to [] (erosion guard —
     * never null, never a raw string). No prefix hardcoded: getField() applies
     * the CPT meta_prefix (_ntdst_).
     *
     * @return array<string, mixed>
     */
    public function getProfiletypeRules(int $trajectoryId): array
    {
        $rules = $this->getField($trajectoryId, 'profiletype_rules', []);

        return is_array($rules) ? $rules : [];
    }

    /**
     * Whether this trajectory is excluded from the public catalog listing.
     *
     * Thin typed wrapper over the `exclude_from_catalog` (bool) field. Absent
     * or falsey ⇒ false (listed). Not a security boundary — a listing flag.
     */
    public function getExcludeFromCatalog(int $trajectoryId): bool
    {
        return (bool) $this->getField($trajectoryId, 'exclude_from_catalog', false);
    }
}
