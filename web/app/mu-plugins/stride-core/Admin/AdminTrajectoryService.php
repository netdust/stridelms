<?php

declare(strict_types=1);

namespace Stride\Admin;

use Stride\Domain\OfferingStatus;
use Stride\Domain\RegistrationStatus;
use Stride\Domain\TrajectoryMode;
use Stride\Infrastructure\BatchQueryHelper;
use Stride\Modules\Edition\EditionCPT;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Trajectory\TrajectoryCPT;
use Stride\Modules\Trajectory\TrajectoryDashboardService;
use Stride\Modules\Trajectory\TrajectoryRepository;
use Stride\Modules\Trajectory\TrajectorySelection;
use Stride\Modules\Trajectory\TrajectoryService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Read-model assembly for the admin trajectory surfaces.
 *
 * Strangled out of AdminAPIController (§12.4 / cluster C2) behavior-preserving:
 * the list/detail SQL data-gathering for GET /admin/trajectories and
 * GET /admin/trajectories/{id} lives here; the controller methods are thin
 * delegators. The new GET /admin/users/{id}/trajectories case-view endpoint is
 * BORN here (never in the god class) — it composes existing read compute
 * (getProgressData + the TrajectorySelection read methods, INV-6b) into the
 * per-trajectory progress shape the Dossier section binds. It adds ZERO SQL.
 *
 * The DRIFT-#1 hazard grep at extraction confirmed getTrajectories/getTrajectory
 * use NONE of the AdminBatchHelpers / shared hazard helpers — their only
 * cross-domain call is RegistrationRepository::findByTrajectoryIds (already a
 * repo method). So nothing relocates to AdminBatchHelpers; the moved SQL is
 * service-private. The inline _ntdst_courses parse is moved VERBATIM (DRIFT #2 —
 * a pre-existing hardcoded-meta-key drift; a cleanup would change behavior and
 * is out of scope for the behavior-preserving strangle).
 *
 * Registered in plugin-config.php.
 */
final class AdminTrajectoryService
{
    public function __construct(
        private readonly RegistrationRepository $registrationRepo,
        private readonly TrajectoryDashboardService $dashboardService,
        private readonly TrajectorySelection $trajectorySelection,
        private readonly EnrollmentService $enrollmentService,
        private readonly TrajectoryService $trajectoryService,
        private readonly EditionService $editionService,
        private readonly TrajectoryRepository $trajectoryRepository,
    ) {}

    /**
     * GET /admin/trajectories
     *
     * Moved verbatim from AdminAPIController::getTrajectories (behavior-preserving).
     */
    public function getTrajectories(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $page = max(1, (int) ($request->get_param('page') ?: 1));
        $perPage = max(1, (int) ($request->get_param('per_page') ?: 20));
        $search = sanitize_text_field($request->get_param('search') ?? '');
        $status = sanitize_text_field($request->get_param('status') ?? '');
        $scope = sanitize_text_field($request->get_param('scope') ?? '');
        $offset = ($page - 1) * $perPage;

        // Build query
        $where = ["p.post_type = %s", "p.post_status = 'publish'"];
        $params = [TrajectoryCPT::POST_TYPE];

        if (!empty($search)) {
            $where[] = "p.post_title LIKE %s";
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        if (!empty($status)) {
            $where[] = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_status WHERE pm_status.post_id = p.ID AND pm_status.meta_key = '_ntdst_status' AND pm_status.meta_value = %s)";
            $params[] = $status;
        }

        // CR-3: server-side active-scope so the active subset spans ALL pages,
        // not just the loaded one (a client-side filter over a server-paged
        // list hid active trajectories on pages 2+). 'active' = NOT
        // admin-closed (F-T2): the SAME boundary the edition workspace scope
        // uses (OfferingStatus::adminClosedValues — completed/archived;
        // cancelled stays visible because it still carries cleanup work). A
        // trajectory with NO _ntdst_status row counts as active (NOT closed).
        // THE one admin-active trajectory predicate lives in the repo
        // (adminActiveWhereFragment) — the grid typeahead splices the same
        // fragment, so the two surfaces can never drift apart.
        if ($scope === 'active') {
            [$scopeSql, $scopeParams] = $this->trajectoryRepository->adminActiveWhereFragment('p');
            $where[] = $scopeSql;
            array_push($params, ...$scopeParams);
        }

        $whereClause = implode(' AND ', $where);

        // INV-3: the COUNT + list SELECT (the self-flagged "DRIFT #2") now live
        // in TrajectoryRepository::countAdminList / findAdminListRows. The
        // WHERE/scope/status/search assembly above stays here — it is read-model
        // logic, not raw SQL — and the repo owns only the $wpdb->prepare
        // execution. Behavior-preserving: same predicate, same params, same
        // ORDER BY post_date DESC, same paging (the repo appends LIMIT/OFFSET as
        // the final two params, matching the pre-extraction order).

        // Get total count
        $total = $this->trajectoryRepository->countAdminList($whereClause, $params);

        // Get trajectories
        $trajectories = $this->trajectoryRepository->findAdminListRows($whereClause, $params, $perPage, $offset);

        if (empty($trajectories)) {
            return new WP_REST_Response([
                'items' => [],
                'total' => $total,
                'page' => $page,
                'perPage' => $perPage,
                'totalPages' => (int) ceil($total / $perPage),
            ]);
        }

        // === BATCH FETCH ALL DATA UPFRONT ===

        $trajectoryIds = array_map(fn($t) => (int) $t->ID, $trajectories);

        // Batch fetch trajectory meta
        $trajectoryMeta = BatchQueryHelper::batchGetPostMeta($trajectoryIds, [
            '_ntdst_status', '_ntdst_mode', '_ntdst_capacity', '_ntdst_enrollment_deadline', '_ntdst_choice_deadline',
            '_ntdst_courses', '_ntdst_price', '_ntdst_price_non_member', '_ntdst_choice_available_date',
        ]);

        // Collect all edition IDs from trajectory courses meta
        $allEditionIds = [];

        foreach ($trajectories as $trajectory) {
            $trajectoryId = (int) $trajectory->ID;
            $meta = $trajectoryMeta[$trajectoryId] ?? [];

            // Parse courses to collect edition IDs
            $courses = $meta['_ntdst_courses'] ?? null;
            $courseList = [];
            if (is_array($courses)) {
                $courseList = $courses;
            } elseif (is_string($courses) && !empty($courses)) {
                $decoded = json_decode($courses, true);
                if (is_array($decoded)) {
                    $courseList = $decoded;
                }
            }

            foreach ($courseList as $course) {
                $editionId = (int) ($course['edition_id'] ?? 0);
                if ($editionId > 0) {
                    $allEditionIds[] = $editionId;
                }
            }
        }

        // Enrollment COUNTS only via canonical RegistrationRepository
        // (stride_vad_registrations is the unified source; the legacy
        // stride_vad_trajectory_enrollments table is no longer written to.)
        // The list renders NO roster — it binds only enrolledCount — so the
        // old per-trajectory 50-row enrollment fetch + user batch shipped up
        // to ~2,500 rows of names/e-mails per page that were discarded
        // client-side. The roster is fetched ONLY on the detail path.
        $enrollmentCounts = $this->registrationRepo->countByTrajectoryIds($trajectoryIds);

        // Batch fetch editions for courses
        $editionsMap = BatchQueryHelper::batchGetPosts($allEditionIds, EditionCPT::POST_TYPE);

        // === FORMAT TRAJECTORIES ===

        $items = [];
        foreach ($trajectories as $trajectory) {
            $item = $this->formatTrajectoryItem($trajectory, [
                'meta'             => $trajectoryMeta,
                'editions'         => $editionsMap,
                'enrollmentCounts' => $enrollmentCounts,
                'enrollments'      => [],
                'users'            => [],
            ]);
            // Always-empty on this path — never ship a misleading [] roster key.
            unset($item['enrolledUsers']);
            $items[] = $item;
        }

        return new WP_REST_Response([
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) ceil($total / $perPage),
        ]);
    }

    /**
     * Shape ONE admin trajectory-list row into the grid/slide-over read-model.
     *
     * Extracted VERBATIM from the getTrajectories format loop (S5) so the list
     * path AND the single-fetch detail path (getTrajectory) produce a
     * byte-identical item for the same trajectory — single + list parity
     * (pattern_trajectory_edition_parity). Pure shaper: it does NO queries, only
     * reads from the pre-batched $context maps the caller assembled, so it is
     * N+1-safe whether driven by a 20-row list or a single detail fetch.
     *
     * @param object $trajectory  A findAdminListRows/findById row
     *                            (ID, post_title, post_date, post_content).
     * @param array{
     *     meta: array<int, array<string, mixed>>,
     *     editions: array<int, \WP_Post>,
     *     enrollmentCounts: array<int, int>,
     *     enrollments: array<int, array<int, object>>,
     *     users: array<int, \WP_User>
     * } $context  Pre-batched lookups keyed by id.
     * @return array<string, mixed>
     */
    private function formatTrajectoryItem(object $trajectory, array $context): array
    {
        $trajectoryId = (int) $trajectory->ID;
        $meta = $context['meta'][$trajectoryId] ?? [];

        // Get meta values from batch
        $trajectoryStatus = $meta['_ntdst_status'] ?? '';
        $mode = $meta['_ntdst_mode'] ?? '';
        $capacity = (int) ($meta['_ntdst_capacity'] ?? 0);
        $enrollmentDeadline = $meta['_ntdst_enrollment_deadline'] ?? '';
        $choiceDeadline = $meta['_ntdst_choice_deadline'] ?? '';
        $price = (int) ($meta['_ntdst_price'] ?? 0);
        // Both prices are canonical CENTS — one type. The old (float) cast on
        // the non-member price implied a euro-float storage that doesn't exist.
        $priceNonMember = (int) ($meta['_ntdst_price_non_member'] ?? 0);
        $choiceAvailableDate = $meta['_ntdst_choice_available_date'] ?? '';

        // Parse courses
        $courses = $meta['_ntdst_courses'] ?? null;
        $courseList = [];
        if (is_array($courses)) {
            $courseList = $courses;
        } elseif (is_string($courses) && !empty($courses)) {
            $decoded = json_decode($courses, true);
            if (is_array($decoded)) {
                $courseList = $decoded;
            }
        }
        $courseCount = count($courseList);

        // Enrich courses with edition titles (using batch-fetched data)
        $coursesWithDetails = [];
        foreach ($courseList as $course) {
            $editionId = (int) ($course['edition_id'] ?? 0);
            $courseData = [
                'editionId' => $editionId,
                'type' => $course['type'] ?? 'required',
                'title' => '',
            ];
            if ($editionId > 0) {
                $edition = $context['editions'][$editionId] ?? null;
                if ($edition) {
                    $courseData['title'] = $edition->post_title;
                }
            }
            $coursesWithDetails[] = $courseData;
        }

        // Get enrolled users (using batch-fetched data)
        $enrolledCount = $context['enrollmentCounts'][$trajectoryId] ?? 0;
        $enrolledUsers = [];
        $trajectoryEnrollments = $context['enrollments'][$trajectoryId] ?? [];

        foreach ($trajectoryEnrollments as $enrollment) {
            $userId = (int) $enrollment->user_id;
            $user = $context['users'][$userId] ?? null;

            // A deleted WP account must not silently DROP the row (F-T4 —
            // enrolledCount then exceeded the rendered roster with no hint).
            // Identity falls back through THE one presenter, like the grid
            // and the edition roster (lead-identity invariant 2).
            $identity = $user
                ? ['name' => $user->display_name, 'email' => $user->user_email]
                : RegistrationRepository::presentLeadIdentity($enrollment);

            $enrolledUsers[] = [
                // regId is the stable row key (deleted accounts share id 0).
                'regId' => (int) $enrollment->id,
                'id' => $user ? $userId : 0,
                'name' => $identity['name'],
                'email' => $identity['email'],
                'status' => $enrollment->status,
                'enrolledAt' => $enrollment->registered_at,
            ];
        }

        // Get description from already fetched post_content
        $description = $trajectory->post_content;

        // Status label from THE enum (F-T2): trajectories carry OfferingStatus
        // values; the old hand-rolled map knew a nonexistent 'closed', missed
        // the real in_progress/cancelled/completed/announcement/postponed
        // (rendered as ucfirst'd English slugs), and disagreed with the filter
        // dropdown's wording. Meta-less/unknown → Concept, like everywhere else.
        $statusLabel = (OfferingStatus::tryFrom($trajectoryStatus) ?? OfferingStatus::Draft)->label();

        // Mode label from THE enum: the old match knew a fictional 'open' mode
        // (real vocabulary is cohort|self_paced, TrajectoryMode) and ucfirst'd
        // the rest — self_paced rendered "Self_paced". Unknown/empty → Cohort,
        // matching the 'mode' fallback below.
        $modeLabel = (TrajectoryMode::tryFrom($mode) ?? TrajectoryMode::Cohort)->label();

        return [
            'id' => $trajectoryId,
            'title' => $trajectory->post_title,
            'description' => wp_trim_words(wp_strip_all_tags($description), 30, '...'),
            'status' => $trajectoryStatus ?: 'draft',
            'statusLabel' => $statusLabel,
            'mode' => $mode ?: 'cohort',
            'modeLabel' => $modeLabel,
            'capacity' => $capacity,
            'enrolledCount' => $enrolledCount,
            'courseCount' => $courseCount,
            'courses' => $coursesWithDetails,
            'enrolledUsers' => $enrolledUsers,
            // Trajectory price meta is canonical CENTS (TrajectoryAdminController
            // save + seed builders both write cents; the theme divides by 100).
            // Formatting them as euros overstated every price 100× (F-T4:
            // €1.695 rendered "169.500,00") — latent until a template renders
            // these keys, but the payload must be truthful.
            'price' => $price,
            'priceFormatted' => number_format($price / 100, 2, ',', '.'),
            'priceNonMember' => $priceNonMember,
            'priceNonMemberFormatted' => number_format($priceNonMember / 100, 2, ',', '.'),
            'enrollmentDeadline' => $enrollmentDeadline ?: null,
            'choiceAvailableDate' => $choiceAvailableDate ?: null,
            'choiceDeadline' => $choiceDeadline ?: null,
            'editUrl' => admin_url("post.php?post={$trajectoryId}&action=edit"),
        ];
    }

    /**
     * GET /admin/users/{id}/trajectories
     *
     * Case-view trajectory progress for a single user (§11.4 / F8). Composes
     * existing read compute — adds ZERO SQL (INV-3):
     *
     *   getEnrolledTrajectoryIds($userId)            → the user's trajectory ids
     *     → per id: getProgressData($userId, $id)    → counts + required/elective
     *     → enrich each elective group with chosen-vs-required via the
     *       TrajectorySelection read methods (INV-6b — getSelectedCourseIds /
     *       countChosenInGroup / isGroupChosen, NEVER validateSelections)
     *     → header {id,title,status,mode} from TrajectoryService::getTrajectory
     *       (DRIFT #5 — getProgressData returns mode but not title/status).
     *
     * Mirrors the live tab-voortgang.php compose exactly (no second progress
     * definition). Returns an empty `trajectories` list for a non-enrolled user
     * (not an error, not a 404).
     */
    public function getUserTrajectories(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('id');

        $trajectoryIds = $this->enrollmentService->getEnrolledTrajectoryIds($userId);
        if (empty($trajectoryIds)) {
            return new WP_REST_Response(['trajectories' => []]);
        }

        // Parent trajectory registration rows → parent id per trajectory, the
        // anchor getSelectedCourseIds reads the canonical selections from.
        $parentRegByTrajectory = [];
        foreach ($this->registrationRepo->findTrajectoryEnrollmentsByUser($userId) as $parent) {
            $trajId = (int) ($parent->trajectory_id ?? 0);
            if ($trajId > 0 && !isset($parentRegByTrajectory[$trajId])) {
                $parentRegByTrajectory[$trajId] = (int) $parent->id;
            }
        }

        $trajectories = [];
        foreach ($trajectoryIds as $trajectoryId) {
            $trajectoryId = (int) $trajectoryId;

            $progress = $this->dashboardService->getProgressData($userId, $trajectoryId);

            $completedIds = array_map('intval', $progress['completed_courses'] ?? []);
            $inProgressIds = array_map('intval', $progress['in_progress_courses'] ?? []);

            // Header (DRIFT #5): {id, title, status, mode}. getTrajectory returns
            // title + status + mode as strings; null-guard a missing trajectory.
            $traj = $this->trajectoryService->getTrajectory($trajectoryId);
            $mode = $progress['mode'];
            $modeValue = $mode instanceof TrajectoryMode ? $mode->value : (string) $mode;

            $header = [
                'id' => $trajectoryId,
                'title' => $traj['title'] ?? '',
                'status' => $traj['status'] ?? '',
                'mode' => $traj['mode'] ?? $modeValue,
            ];

            // Required courses with per-course state (the Dutch state values the
            // Dossier section binds — same rule as tab-voortgang.php).
            $editionByCourse = $this->buildCourseEditionMap($progress['edition_registrations'] ?? []);
            $requiredCourses = [];
            foreach ($progress['required_courses'] ?? [] as $course) {
                $courseId = (int) $course->ID;
                if (in_array($courseId, $completedIds, true)) {
                    $state = 'afgerond';
                } elseif (in_array($courseId, $inProgressIds, true)) {
                    $state = 'bezig';
                } else {
                    $state = 'nog te volgen';
                }
                $editionId = $editionByCourse[$courseId] ?? 0;

                $requiredCourses[] = [
                    'title' => $course->post_title,
                    // The dossier sub-label — a TITLE, never the raw FK int
                    // (which invited '[object 512]'-class bindings). Post cache
                    // is warm (getProgressData).
                    'edition_title' => $editionId > 0 ? (string) get_the_title($editionId) : '',
                    'state' => $state,
                ];
            }

            // Elective groups enriched with chosen-vs-required (INV-6b). The
            // selected course ids come from the parent registration's selections.
            $parentRegId = $parentRegByTrajectory[$trajectoryId] ?? 0;
            $selectedCourseIds = $parentRegId > 0
                ? $this->trajectorySelection->getSelectedCourseIds($parentRegId)
                : [];

            $electiveGroups = [];
            foreach ($progress['elective_groups'] ?? [] as $group) {
                $courses = $group['courses'] ?? [];
                $countChosen = $this->trajectorySelection->countChosenInGroup($group, $selectedCourseIds);
                $isChosen = $this->trajectorySelection->isGroupChosen($group, $selectedCourseIds);

                $chosen = [];
                foreach ($courses as $course) {
                    if (in_array((int) $course->ID, $selectedCourseIds, true)) {
                        // Title only — the raw edition FK was never rendered.
                        $chosen[] = ['title' => $course->post_title];
                    }
                }

                $electiveGroups[] = [
                    'name' => (string) ($group['name'] ?? ''),
                    'required' => (int) ($group['required'] ?? 0),
                    'total' => count($courses),
                    'countChosen' => $countChosen,
                    'isChosen' => $isChosen,
                    'chosen' => $chosen,
                ];
            }

            $trajectories[] = [
                'trajectory' => $header,
                'completed_count' => (int) ($progress['completed_count'] ?? 0),
                'in_progress_count' => (int) ($progress['in_progress_count'] ?? 0),
                'total_required' => (int) ($progress['total_required'] ?? 0),
                'required_courses' => $requiredCourses,
                'elective_groups' => $electiveGroups,
            ];
        }

        return new WP_REST_Response(['trajectories' => $trajectories]);
    }

    /**
     * Build a course-id → edition-id map from the user's edition registration
     * rows for a trajectory (first edition wins per course). Mirrors
     * tab-voortgang.php's $editionByCourse lookup.
     *
     * @param array<object> $editionRegistrations
     * @return array<int, int>
     */
    private function buildCourseEditionMap(array $editionRegistrations): array
    {
        // CR-4: getProgressData builds the inverse (edition→courseId) map
        // internally but does not return it; this produces the courseId→edition
        // map the Dossier needs. The getCourseId calls hit WP's in-request meta
        // cache already warmed by getProgressData, so the duplication is a few
        // cached reads, not fresh queries — not worth changing the shared
        // frontend compute (tab-voortgang.php) for. Deliberate local mirror.
        $map = [];
        foreach ($editionRegistrations as $edReg) {
            $editionId = (int) ($edReg->edition_id ?? 0);
            if ($editionId <= 0) {
                continue;
            }
            $courseId = $this->editionService->getCourseId($editionId);
            if ($courseId !== null && !isset($map[$courseId])) {
                $map[$courseId] = $editionId;
            }
        }
        return $map;
    }

    /**
     * GET /admin/trajectories/{id}
     *
     * Single-trajectory detail for the admin slide-over (S5: O(1) fetch).
     *
     * Fetches the ONE row via TrajectoryRepository::findById and assembles its
     * batch-context for that single trajectory, then shapes it through the SAME
     * formatTrajectoryItem the list path uses — so the output is byte-identical
     * to the matching list item (single + list parity). Previously this re-ran
     * the entire getTrajectories list pipeline (count + paged list-rows + the
     * 50-enrollment-per-trajectory fetch + edition/user batch) scoped to the
     * target's title and linear-scanned the result for the id — O(100)-shaped
     * work to return one item. findById drives the 404 path off a null row, not
     * off a missed scan, so the F1 regression (a trajectory beyond the first 100)
     * stays fixed structurally.
     */
    public function getTrajectory(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $trajectoryId = (int) $request->get_param('id');

        $trajectory = $this->trajectoryRepository->findById($trajectoryId);
        if ($trajectory === null) {
            return new WP_Error('not_found', 'Trajectory not found', ['status' => 404]);
        }

        // === BATCH-CONTEXT FOR THIS ONE TRAJECTORY ===
        // Same shapes getTrajectories assembles, scoped to a single id so the
        // shared formatter produces an identical item.
        $trajectoryIds = [$trajectoryId];

        $trajectoryMeta = BatchQueryHelper::batchGetPostMeta($trajectoryIds, [
            '_ntdst_status', '_ntdst_mode', '_ntdst_capacity', '_ntdst_enrollment_deadline', '_ntdst_choice_deadline',
            '_ntdst_courses', '_ntdst_price', '_ntdst_price_non_member', '_ntdst_choice_available_date',
        ]);

        // Collect edition IDs from this trajectory's courses meta.
        $meta = $trajectoryMeta[$trajectoryId] ?? [];
        $courses = $meta['_ntdst_courses'] ?? null;
        $courseList = [];
        if (is_array($courses)) {
            $courseList = $courses;
        } elseif (is_string($courses) && !empty($courses)) {
            $decoded = json_decode($courses, true);
            if (is_array($decoded)) {
                $courseList = $decoded;
            }
        }
        $editionIds = [];
        foreach ($courseList as $course) {
            $editionId = (int) ($course['edition_id'] ?? 0);
            if ($editionId > 0) {
                $editionIds[] = $editionId;
            }
        }

        $enrollmentCounts = $this->registrationRepo->countByTrajectoryIds($trajectoryIds);
        $allEnrollments = $this->registrationRepo->findByTrajectoryIds($trajectoryIds, 50);

        $enrollmentUserIds = [];
        foreach (($allEnrollments[$trajectoryId] ?? []) as $row) {
            $enrollmentUserIds[] = (int) $row->user_id;
        }

        $editionsMap = BatchQueryHelper::batchGetPosts($editionIds, EditionCPT::POST_TYPE);
        $usersMap = BatchQueryHelper::batchGetUsers($enrollmentUserIds);

        $item = $this->formatTrajectoryItem($trajectory, [
            'meta'             => $trajectoryMeta,
            'editions'         => $editionsMap,
            'enrollmentCounts' => $enrollmentCounts,
            'enrollments'      => $allEnrollments,
            'users'            => $usersMap,
        ]);

        // Remap enrolledUsers to registrations for the slide-over template.
        // Labels come from THE enum (F-T3): the old hand-rolled map was keyed
        // on a nonexistent 'active' status and rendered the most common real
        // statuses (confirmed, waitlist, interest) as ucfirst'd English.
        $registrations = array_map(static function (array $u) {
            $status = RegistrationStatus::tryFrom((string) ($u['status'] ?? ''));

            return [
                'regId' => $u['regId'],
                'id' => $u['id'],
                'name' => $u['name'],
                'email' => $u['email'],
                'status' => $u['status'],
                'status_label' => $status ? $status->label() : __('Onbekend', 'stride'),
            ];
        }, $item['enrolledUsers'] ?? []);

        // registrations IS the roster — drop the raw duplicate.
        unset($item['enrolledUsers']);

        return new WP_REST_Response(array_merge($item, [
            'registrations' => $registrations,
        ]));
    }
}
