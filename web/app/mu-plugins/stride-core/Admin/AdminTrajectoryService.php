<?php

declare(strict_types=1);

namespace Stride\Admin;

use Stride\Infrastructure\BatchQueryHelper;
use Stride\Modules\Edition\EditionCPT;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Trajectory\TrajectoryCPT;
use Stride\Modules\Trajectory\TrajectoryDashboardService;
use Stride\Modules\Trajectory\TrajectoryMode;
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
    ) {
    }

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

        // Reuse the list endpoint with a filter that returns just this one
        $listRequest = new WP_REST_Request('GET', '/stride/v1/admin/trajectories');
        $listRequest->set_param('page', 1);
        $listRequest->set_param('per_page', 100);
        $listRequest->set_param('search', '');
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
