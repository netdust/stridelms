<?php

declare(strict_types=1);

namespace Stride\Admin;

use Stride\Domain\TrajectoryMode;
use Stride\Infrastructure\BatchQueryHelper;
use Stride\Modules\Edition\EditionCPT;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Trajectory\TrajectoryCPT;
use Stride\Modules\Trajectory\TrajectoryDashboardService;
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
        // list hid active trajectories on pages 2+). 'active' excludes the
        // terminal statuses; a trajectory with NO _ntdst_status row counts as
        // active (NOT terminal) — match how the tab treats an unset status.
        if ($scope === 'active') {
            $terminal = ['closed', 'archived'];
            $placeholders = implode(',', array_fill(0, count($terminal), '%s'));
            $where[] = "NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_scope WHERE pm_scope.post_id = p.ID AND pm_scope.meta_key = '_ntdst_status' AND pm_scope.meta_value IN ({$placeholders}))";
            array_push($params, ...$terminal);
        }

        $whereClause = implode(' AND ', $where);

        // Get total count
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$whereClause}",
            ...$params,
        ));

        // Get trajectories
        $params[] = $perPage;
        $params[] = $offset;

        $trajectories = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_date, p.post_content FROM {$wpdb->posts} p
             WHERE {$whereClause}
             ORDER BY p.post_date DESC
             LIMIT %d OFFSET %d",
            ...$params,
        ));

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

        // Batch fetch trajectory enrollments via canonical RegistrationRepository
        // (stride_vad_registrations is the unified source; the legacy
        // stride_vad_trajectory_enrollments table is no longer written to.)
        $registrationRepo = $this->registrationRepo;
        $enrollmentCounts = $registrationRepo->countByTrajectoryIds($trajectoryIds);
        $allEnrollments = $registrationRepo->findByTrajectoryIds($trajectoryIds, 50);

        $allEnrollmentUserIds = [];
        foreach ($allEnrollments as $rows) {
            foreach ($rows as $row) {
                $allEnrollmentUserIds[] = (int) $row->user_id;
            }
        }

        // Batch fetch editions for courses
        $editionsMap = BatchQueryHelper::batchGetPosts($allEditionIds, EditionCPT::POST_TYPE);

        // Batch fetch users for enrollments
        $usersMap = BatchQueryHelper::batchGetUsers($allEnrollmentUserIds);

        // === FORMAT TRAJECTORIES ===

        $items = [];
        foreach ($trajectories as $trajectory) {
            $trajectoryId = (int) $trajectory->ID;
            $meta = $trajectoryMeta[$trajectoryId] ?? [];

            // Get meta values from batch
            $trajectoryStatus = $meta['_ntdst_status'] ?? '';
            $mode = $meta['_ntdst_mode'] ?? '';
            $capacity = (int) ($meta['_ntdst_capacity'] ?? 0);
            $enrollmentDeadline = $meta['_ntdst_enrollment_deadline'] ?? '';
            $choiceDeadline = $meta['_ntdst_choice_deadline'] ?? '';
            $price = (int) ($meta['_ntdst_price'] ?? 0);
            $priceNonMember = (float) ($meta['_ntdst_price_non_member'] ?? 0);
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
                    $edition = $editionsMap[$editionId] ?? null;
                    if ($edition) {
                        $courseData['title'] = $edition->post_title;
                    }
                }
                $coursesWithDetails[] = $courseData;
            }

            // Get enrolled users (using batch-fetched data)
            $enrolledCount = $enrollmentCounts[$trajectoryId] ?? 0;
            $enrolledUsers = [];
            $trajectoryEnrollments = $allEnrollments[$trajectoryId] ?? [];

            foreach ($trajectoryEnrollments as $enrollment) {
                $userId = (int) $enrollment->user_id;
                $user = $usersMap[$userId] ?? null;
                if ($user) {
                    $enrolledUsers[] = [
                        'id' => $userId,
                        'name' => $user->display_name,
                        'email' => $user->user_email,
                        'status' => $enrollment->status,
                        'enrolledAt' => $enrollment->registered_at,
                    ];
                }
            }

            // Get description from already fetched post_content
            $description = $trajectory->post_content;

            // Get status label
            $statusLabel = match ($trajectoryStatus) {
                'open' => 'Open',
                'closed' => 'Gesloten',
                'full' => 'Volzet',
                'archived' => 'Gearchiveerd',
                'draft' => 'Concept',
                default => ucfirst($trajectoryStatus ?: 'draft'),
            };

            // Get mode label
            $modeLabel = match ($mode) {
                'cohort' => 'Cohort',
                'open' => 'Open inschrijving',
                default => ucfirst($mode ?: 'cohort'),
            };

            $items[] = [
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
                'price' => $price,
                'priceFormatted' => number_format($price, 2, ',', '.'),
                'priceNonMember' => $priceNonMember,
                'priceNonMemberFormatted' => number_format($priceNonMember, 2, ',', '.'),
                'enrollmentDeadline' => $enrollmentDeadline ?: null,
                'choiceAvailableDate' => $choiceAvailableDate ?: null,
                'choiceDeadline' => $choiceDeadline ?: null,
                'editUrl' => admin_url("post.php?post={$trajectoryId}&action=edit"),
            ];
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
                    'edition' => $editionId,
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
                        $chosen[] = [
                            'title' => $course->post_title,
                            'edition' => $editionByCourse[(int) $course->ID] ?? 0,
                        ];
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
     * Moved verbatim from AdminAPIController::getTrajectory (behavior-preserving).
     * Reuses the same logic as the list endpoint but returns a single item by
     * fetching the list for that ID. The internal call is now intra-service.
     */
    public function getTrajectory(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $trajectoryId = (int) $request->get_param('id');

        $post = get_post($trajectoryId);
        if (!$post || $post->post_type !== TrajectoryCPT::POST_TYPE) {
            return new WP_Error('not_found', 'Trajectory not found', ['status' => 404]);
        }

        // Reuse the list assembly but SCOPE it to this trajectory's title so the
        // target is guaranteed to be in the (small) result set regardless of how
        // many trajectories exist. Previously this fetched page 1 / per_page 100
        // and linear-scanned for the id, so any trajectory outside the first 100
        // (ID-desc) 404'd even though it was a valid published post — broken once
        // the DB holds >100 trajectories. The title LIKE narrows to a handful;
        // the id-match loop below still disambiguates same-titled trajectories.
        $listRequest = new WP_REST_Request('GET', '/stride/v1/admin/trajectories');
        $listRequest->set_param('page', 1);
        $listRequest->set_param('per_page', 100);
        $listRequest->set_param('search', $post->post_title);
        $listRequest->set_param('status', '');

        $listResponse = $this->getTrajectories($listRequest);
        $items = $listResponse->get_data()['items'] ?? [];

        foreach ($items as $item) {
            if ($item['id'] === $trajectoryId) {
                // Remap enrolledUsers to registrations for slide-over template
                $regStatusLabels = [
                    'active' => 'Actief', 'completed' => 'Afgerond',
                    'cancelled' => 'Geannuleerd', 'pending' => 'In afwachting',
                ];
                $registrations = array_map(function (array $u) use ($regStatusLabels) {
                    return [
                        'id' => $u['id'],
                        'name' => $u['name'],
                        'email' => $u['email'],
                        'status' => $u['status'],
                        'status_label' => $regStatusLabels[$u['status']] ?? ucfirst($u['status'] ?? ''),
                    ];
                }, $item['enrolledUsers'] ?? []);

                return new WP_REST_Response(array_merge($item, [
                    'registrations' => $registrations,
                ]));
            }
        }

        return new WP_Error('not_found', 'Trajectory not found', ['status' => 404]);
    }
}
