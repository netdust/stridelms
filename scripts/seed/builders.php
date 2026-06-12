<?php
/**
 * Stride seed builders — the only file that talks to repositories/services.
 * Each build*() consumes one matrix entry; behavior ported from the previous
 * imperative scripts/seed.php (see plan 2026-06-12-seed-feature-matrix.md).
 */

use Stride\Modules\Edition\EditionService; // Task 5: buildRegistration needs getCourseId()
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\SessionRepository;
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

    /**
     * Idempotency lookup by exact post title (get_page_by_title is deprecated).
     * Replaces the duplicated WP_Query title-lookup blocks.
     */
    private function findIdByTitle(string $postType, string $title): ?int
    {
        $found = new WP_Query([
            'post_type' => $postType, 'title' => $title, 'post_status' => 'any',
            'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true,
        ]);

        return !empty($found->posts) ? (int) $found->posts[0] : null;
    }

    /**
     * Ensure stride_format and stride_theme taxonomy terms exist.
     */
    public function ensureTaxonomyTerms(): void
    {
        echo "Ensuring taxonomy terms...\n";

        $formats = [
            'klassikaal' => 'Klassikaal',
            'online'     => 'Online',
            'e-learning' => 'E-learning',
            'webinar'    => 'Webinar',
            'hybrid'     => 'Hybride',
        ];

        foreach ($formats as $slug => $name) {
            if (!term_exists($slug, 'stride_format')) {
                $result = wp_insert_term($name, 'stride_format', ['slug' => $slug]);
                if (!is_wp_error($result)) {
                    echo "  + Format term: {$name}\n";
                }
            }
        }

        $themes = [
            'beweging'       => 'Beweging',
            'voeding'        => 'Voeding',
            'welzijn'        => 'Welzijn',
            'sportblessures' => 'Sportblessures',
            'jeugdwerk'      => 'Jeugdwerk',
            'schoolbeleid'   => 'Schoolbeleid',
        ];

        foreach ($themes as $slug => $name) {
            if (!term_exists($slug, 'stride_theme')) {
                $result = wp_insert_term($name, 'stride_theme', ['slug' => $slug]);
                if (!is_wp_error($result)) {
                    echo "  + Theme term: {$name}\n";
                }
            }
        }
    }

    /** Returns user ID (existing or created), or 0 on failure. */
    public function buildUser(array $userData): int
    {
        $existing = get_user_by('login', $userData['login']);
        if ($existing) {
            if (!get_user_meta($existing->ID, 'ntdst_auth_activated', true)) {
                update_user_meta($existing->ID, 'ntdst_auth_activated', true);
                update_user_meta($existing->ID, 'ntdst_auth_activated_at', time());
                echo "  - User {$userData['login']} exists (ID: {$existing->ID}) - activated\n";
            } else {
                echo "  - User {$userData['login']} exists (ID: {$existing->ID})\n";
            }
            if (isset($userData['company_id'])) {
                update_user_meta($existing->ID, '_stride_company_id', $userData['company_id']);
            }
            return (int) $existing->ID;
        }

        $userId = wp_insert_user([
            'user_login' => $userData['login'],
            'user_email' => $userData['email'],
            'user_pass' => 'seedpass123',
            'first_name' => $userData['first'],
            'last_name' => $userData['last'],
            'display_name' => $userData['first'] . ' ' . $userData['last'],
            'role' => $userData['role'],
        ]);

        if (is_wp_error($userId)) {
            echo "  ! Failed: {$userId->get_error_message()}\n";
            return 0;
        }

        update_user_meta($userId, StrideSeedRunner::SEED_META_KEY, true);
        if (isset($userData['company_id'])) {
            update_user_meta($userId, '_stride_company_id', $userData['company_id']);
        }

        // Activate users for auth (ntdst-auth requires activation)
        update_user_meta($userId, 'ntdst_auth_activated', true);
        update_user_meta($userId, 'ntdst_auth_activated_at', time());

        echo "  + Created: {$userData['login']} (ID: {$userId})\n";
        return (int) $userId;
    }

    /** Returns ['created' => [...], 'covers' => [...]] for one matrix course entry. */
    public function buildCourse(array $courseData, array $userMap): array
    {
        $out = ['created' => ['courses' => [], 'lessons' => [], 'editions' => [], 'sessions' => [], 'registrations' => [], 'quotes' => []], 'covers' => []];

        if (!defined('LEARNDASH_VERSION')) {
            echo "  ! LearnDash not active, skipping course '{$courseData['title']}'\n";
            return $out;
        }

        // Existence check by title (idempotent re-run)
        $courseId = $this->findIdByTitle('sfwd-courses', $courseData['title']);
        if ($courseId) {
            // Existing course: do NOT return early — still walk lessons/editions
            // below (each idempotent by natural key) so the runner's manifest +
            // covers options are reconstructed in full on every re-run.
            echo "  - Course '{$courseData['title']}' exists (ID: {$courseId})\n";
        } else {
            $courseId = wp_insert_post([
                'post_title' => $courseData['title'],
                'post_type' => 'sfwd-courses',
                'post_status' => 'publish',
                'post_content' => $courseData['description'],
            ]);

            if (is_wp_error($courseId)) {
                echo "  ! Failed: {$courseId->get_error_message()}\n";
                return $out;
            }

            update_post_meta($courseId, StrideSeedRunner::SEED_META_KEY, true);
            update_post_meta($courseId, '_course_type', $courseData['type']);

            // --- LearnDash course settings ---
            $ldSettings = ['course_price_type' => $courseData['ld_price_type'] ?? 'open'];
            if (!empty($courseData['ld_expire_access'])) {
                $ldSettings['expire_access'] = $courseData['ld_expire_access'];
                $ldSettings['expire_access_days'] = $courseData['ld_expire_access_days'] ?? 0;
                $ldSettings['expire_access_delete_progress'] = '';
            }
            update_post_meta($courseId, '_sfwd-courses', $ldSettings);

            // --- Taxonomies ---
            $formats = $courseData['format'] ?? [];
            if (!empty($formats)) {
                wp_set_object_terms($courseId, $formats, 'stride_format');
            }
            $themes = $courseData['themes'] ?? [];
            if (!empty($themes)) {
                wp_set_object_terms($courseId, $themes, 'stride_theme');
            }
            $ldCategory = match ($courseData['type']) {
                'online' => 'online',
                'webinar' => 'webinar',
                default => 'in-person',
            };
            wp_set_object_terms($courseId, $ldCategory, 'ld_course_category');

            echo "  + Course: {$courseData['title']} (ID: {$courseId}) [format: " . implode(',', $formats) . "] [themes: " . implode(',', $themes) . "]\n";
        }

        $out['created']['courses'][] = (int) $courseId;
        $out['covers']["course:{$courseId}"] = $courseData['covers'] ?? [];

        // Lessons
        $lessonIds = $this->buildLessons($courseId, $courseData['lessons'] ?? []);
        $out['created']['lessons'] = $lessonIds;

        // Editions (sessions/registrations/quotes hang off each edition)
        foreach ($courseData['editions'] as $editionData) {
            $editionResult = $this->buildEdition($courseId, $courseData['title'], $editionData, $lessonIds, $userMap);
            foreach ($editionResult['created'] as $kind => $ids) {
                $out['created'][$kind] = array_merge($out['created'][$kind] ?? [], $ids);
            }
            $out['covers'] = array_merge($out['covers'], $editionResult['covers']);
        }

        return $out;
    }

    /** Port of createLessonsForCourse, incl. LDLMS_Factory_Post steps. @return int[] lesson IDs */
    public function buildLessons(int $courseId, array $lessonData): array
    {
        $lessonIds = [];

        if (empty($lessonData)) {
            $lessonData = [
                ['title' => 'Introductie', 'content' => "Welkom bij deze cursus."],
                ['title' => 'Theorie', 'content' => "Theoretische basis."],
                ['title' => 'Praktijk', 'content' => "Oefeningen en casussen."],
                ['title' => 'Evaluatie', 'content' => "Toets en certificaat."],
            ];
        }

        foreach ($lessonData as $index => $lesson) {
            // Natural key: lesson title + course_id (titles like 'Introductie' repeat across courses)
            $existing = new WP_Query([
                'post_type' => 'sfwd-lessons', 'title' => $lesson['title'], 'post_status' => 'any',
                'meta_key' => 'course_id', 'meta_value' => $courseId,
                'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true,
            ]);
            if (!empty($existing->posts)) {
                $lessonIds[] = (int) $existing->posts[0];
                echo "      - Lesson '{$lesson['title']}' exists\n";
                continue;
            }

            $lessonId = wp_insert_post([
                'post_title' => $lesson['title'],
                'post_type' => 'sfwd-lessons',
                'post_status' => 'publish',
                'post_content' => $lesson['content'],
                'menu_order' => $index + 1,
            ]);

            if (!is_wp_error($lessonId)) {
                update_post_meta($lessonId, StrideSeedRunner::SEED_META_KEY, true);
                update_post_meta($lessonId, 'course_id', $courseId);
                update_post_meta($lessonId, '_sfwd-lessons', ['sfwd-lessons_course' => $courseId]);
                $lessonIds[] = $lessonId;
                echo "      📖 Lesson: {$lesson['title']}\n";
            }
        }

        // Link lessons to course using LearnDash's expected structure
        if (!empty($lessonIds) && class_exists('LDLMS_Factory_Post')) {
            $courseStepsObj = LDLMS_Factory_Post::course_steps($courseId);
            if ($courseStepsObj) {
                $steps = ['sfwd-lessons' => []];
                foreach ($lessonIds as $lessonId) {
                    $steps['sfwd-lessons'][$lessonId] = [];
                }
                $courseStepsObj->set_steps($steps);
            }
        }

        return $lessonIds;
    }

    /** @return array{id:int|null, created:array, covers:array} */
    public function buildEdition(int $courseId, string $courseTitle, array $data, array $lessonIds, array $userMap): array
    {
        $repo = ntdst_get(EditionRepository::class);
        $postTitle = $courseTitle . ' - ' . date('j M Y', strtotime($data['start_date']));

        // Natural key: derived post title. On re-run, reconstruct the full
        // created/covers picture (edition id + its existing seed sessions),
        // then STILL fall through to the registrations/quotes loop below —
        // those builders are idempotent by natural key themselves, and the
        // manifest must be rebuilt in full on every run.
        $editionId = $this->findIdByTitle('vad_edition', $postTitle);
        if ($editionId) {
            $sessionIds = ntdst_get(SessionRepository::class)->findIdsByEdition($editionId);
            echo "    - Edition '{$postTitle}' exists (ID: {$editionId}, " . count($sessionIds) . " sessions)\n";
            $out = [
                'id' => $editionId,
                'created' => ['editions' => [$editionId], 'sessions' => $sessionIds],
                'covers' => ["edition:{$editionId}" => $data['covers'] ?? []],
            ];
            return $this->buildEditionRegistrations($editionId, $data, $userMap, $out);
        }

        // end_date derivation from max session offset
        $endDate = $data['end_date'] ?? null;
        if ($endDate === null && !empty($data['sessions'])) {
            $maxOffset = max(array_column($data['sessions'], 'date_offset'));
            $endDate = date('Y-m-d', strtotime($data['start_date'] . " +{$maxOffset} days"));
        }

        // v1 has no member feature — both price keys must hold the same value;
        // price_non_member is canonical.
        $effectivePrice = $data['price_non_member'] ?? $data['price'] ?? 0;

        $createData = [
            'title' => $postTitle,
            'post_status' => 'publish',
            'course_id' => $courseId,
            'start_date' => $data['start_date'],
            'end_date' => $endDate ?? $data['start_date'],
            'price' => $effectivePrice,
            'price_non_member' => $effectivePrice,
            'capacity' => $data['capacity'],
            'venue' => $data['venue'],
            'status' => $data['status'],           // MUST be a valid OfferingStatus value — matrix uses ->value
            'speakers' => $data['speakers'] ?? [], // json array of {name, role}, never a string
        ];
        // Content fields go through the repository (schema-registered), not update_post_meta
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
        if (is_wp_error($result)) {
            echo "    ! Edition failed: {$result->get_error_message()}\n";
            return ['id' => null, 'created' => [], 'covers' => []];
        }
        $editionId = $result->ID;
        update_post_meta($editionId, StrideSeedRunner::SEED_META_KEY, true);

        // Display-only fake-user capacity fill (900000+ IDs avoid the unique_user_edition constraint)
        if (!empty($data['fake_registered'])) {
            global $wpdb;
            for ($i = 0; $i < $data['fake_registered']; $i++) {
                $fakeUserId = 900000 + ($editionId * 100) + $i + 1;
                $insert = $wpdb->insert(
                    $wpdb->prefix . 'vad_registrations',
                    [
                        'user_id' => $fakeUserId,
                        'edition_id' => $editionId,
                        'status' => 'confirmed',
                        'enrollment_path' => 'individual',
                        'notes' => 'Seed placeholder',
                        'registered_at' => current_time('mysql'),
                    ]
                );
                if ($insert === false) {
                    echo "    ! Fake registration insert failed: {$wpdb->last_error}\n";
                }
            }
        }

        $out = ['id' => $editionId, 'created' => ['editions' => [$editionId]], 'covers' => ["edition:{$editionId}" => $data['covers'] ?? []]];

        echo "    + Edition: {$data['start_date']} at {$data['venue']} (ID: {$editionId})";
        if (!empty($data['enrollment_form'])) {
            echo " [form: {$data['enrollment_form']}]";
        }
        if (!empty($data['selection_deadline'])) {
            echo " [deadline: {$data['selection_deadline']}]";
        }
        echo "\n";

        // Sessions: lesson linking is DECLARATIVE — only sessions with
        // 'link_lesson' => true consume the next lesson (replaces the old
        // implicit "every online session eats the next lesson" counter).
        $lessonIndex = 0;
        foreach (($data['sessions'] ?? []) as $sessionData) {
            $sessionLessonIds = [];
            if (!empty($sessionData['link_lesson']) && isset($lessonIds[$lessonIndex])) {
                $sessionLessonIds = [$lessonIds[$lessonIndex]];
                $lessonIndex++;
            }
            $sessionId = $this->buildSession($editionId, $data, $sessionData, $sessionLessonIds);
            if ($sessionId) { $out['created']['sessions'][] = $sessionId; }
        }

        return $this->buildEditionRegistrations($editionId, $data, $userMap, $out);
    }

    /**
     * Shared tail of buildEdition: walks the matrix registrations (and their
     * quotes — Task 6) for one edition. Runs on BOTH the create and the
     * exists path so a re-run reconstructs the manifest. Registration/quote
     * covers tags ride the edition's covers key (matrix puts reg:/path:/quote:
     * tags in the edition 'covers' list).
     */
    private function buildEditionRegistrations(int $editionId, array $data, array $userMap, array $out): array
    {
        foreach (($data['registrations'] ?? []) as $reg) {
            $regId = $this->buildRegistration($editionId, $reg, $userMap);
            if (!$regId) {
                continue;
            }
            $out['created']['registrations'][] = $regId;

            if (!empty($reg['quote'])) {
                $userId = (int) ($userMap[$reg['user']] ?? 0);
                if ($userId) {
                    $quoteId = $this->buildQuote($editionId, $userId, $regId, $reg['quote']);
                    if ($quoteId) { $out['created']['quotes'][] = $quoteId; }
                }
            }
        }

        return $out;
    }

    /**
     * Create (or reuse) one registration from a matrix entry.
     *
     * Idempotency: BOTH the user+edition and the anonymous (edition,status)
     * natural keys are pre-checked here, BEFORE calling create(). The
     * pre-check is load-bearing: RegistrationRepository::create() REACTIVATES
     * existing cancelled/interest/waitlist rows (resetting cancelled_at,
     * notes, registered_at), so a re-run that went through create() would
     * churn the timestamps this builder deliberately sets below.
     * Anonymous rows are deduped on (edition, status) — one anonymous row
     * per edition+status by design.
     *
     * Timestamps: create() never sets completed_at/cancelled_at (only
     * updateStatus() does, on transition) — so for completed/cancelled
     * matrix entries we set them post-create via repo->update() (both keys
     * are in update()'s allow-list). registered_at is NOT allow-listed, so
     * past editions get it backdated via direct $wpdb->update.
     *
     * @return int|null registration id
     */
    public function buildRegistration(int $editionId, array $reg, array $userMap): ?int
    {
        global $wpdb;
        $repo = ntdst_get(RegistrationRepository::class);
        $status = $reg['status'];                          // one of the 6 RegistrationStatus values

        // A named user that is missing from the user map is a matrix mistake —
        // warn and skip rather than silently creating an anonymous row.
        $userId = null;
        if (!empty($reg['user'])) {
            $userId = $userMap[$reg['user']] ?? null;      // 'admin' => 1
            if (!$userId) {
                echo "      ! Unknown user login '{$reg['user']}' — registration skipped\n";
                return null;
            }
        }

        $path = match ($reg['path'] ?? 'individual') {
            'individual' => RegistrationRepository::PATH_INDIVIDUAL,
            'colleague'  => RegistrationRepository::PATH_COLLEAGUE,
            'trajectory' => RegistrationRepository::PATH_TRAJECTORY,
            'partner'    => RegistrationRepository::PATH_PARTNER,
            default      => null,
        };
        if ($path === null) {
            echo "      ! Unknown path '{$reg['path']}' — registration skipped\n";
            return null;
        }

        // Named users: pre-check by user+edition so a re-run returns the
        // existing row WITHOUT create()'s reactivation path (see docblock).
        if ($userId) {
            $existing = $repo->findByUserAndEdition((int) $userId, $editionId);
            if ($existing) {
                echo "      - Registration {$reg['user']} ({$existing->status}) exists (ID: {$existing->id})\n";
                return (int) $existing->id;
            }
        }

        // Anonymous rows: repository skips its duplicate check — guard here.
        if (!$userId) {
            $existingId = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}vad_registrations
                 WHERE edition_id = %d AND (user_id IS NULL OR user_id = 0) AND status = %s LIMIT 1",
                $editionId,
                $status,
            ));
            if ($existingId) {
                echo "      - Registration (anonymous, {$status}) exists (ID: {$existingId})\n";
                return (int) $existingId;
            }
        }

        $payload = ['edition_id' => $editionId, 'status' => $status, 'enrollment_path' => $path, 'notes' => 'Seed: feature matrix'];
        if ($userId) { $payload['user_id'] = $userId; }
        if ($path === RegistrationRepository::PATH_PARTNER) { $payload['company_id'] = 1; }  // Partner API scoping

        $regId = $repo->create($payload);
        if (is_wp_error($regId)) {
            // 'duplicate' is unreachable: the user+edition pre-check above runs first.
            echo "      ! Registration failed ({$reg['user']}): {$regId->get_error_message()}\n";
            return null;
        }
        $regId = (int) $regId;
        echo "      + Registration: {$reg['user']} [{$status}/{$path}] (ID: {$regId})\n";

        // Plausible timestamps (see docblock). Edition start anchors them.
        $startDate = (string) ntdst_get(EditionRepository::class)->getField($editionId, 'start_date', '');
        $startTs = $startDate ? strtotime($startDate) : false;
        if ($status === 'completed') {
            $base = $startTs ?: strtotime('-1 week');
            $repo->update($regId, ['completed_at' => date('Y-m-d', strtotime('+1 day', $base)) . ' 17:00:00']);
        }
        if ($status === 'cancelled') {
            $repo->update($regId, ['cancelled_at' => date('Y-m-d H:i:s', strtotime('-2 days'))]);
        }
        if ($startTs && $startTs < time()) {
            // Past edition: registration must predate the start (registered_at
            // is not in update()'s allow-list — direct table update).
            $wpdb->update(
                $wpdb->prefix . 'vad_registrations',
                ['registered_at' => date('Y-m-d', strtotime('-21 days', $startTs)) . ' 10:00:00'],
                ['id' => $regId]
            );
        }

        // LD access for confirmed/completed
        if (in_array($status, ['confirmed', 'completed'], true) && $userId && function_exists('ld_update_course_access')) {
            $courseId = (int) ntdst_get(EditionService::class)->getCourseId($editionId);
            if ($courseId) {
                ld_update_course_access($userId, $courseId, false);
            }
        }

        // Pre-enrollment completion tasks
        if (!empty($reg['init_tasks'])) {
            $completion = ntdst_get(EnrollmentCompletion::class);
            $tasks = $completion->buildInitialTasks($editionId, 'vad_edition');
            if (!empty($tasks)) {
                if (!empty($reg['complete_user_tasks'])) {
                    // Approval-ready pending reg: mark all USER tasks complete via the
                    // product's canonical shape (EnrollmentCompletion::markTaskComplete),
                    // leaving only admin-side approval open — feeds the dashboard
                    // "Wacht op mij" bucket (AdminAPIController::getPendingApprovals).
                    foreach (array_keys($tasks) as $taskType) {
                        if ($taskType === 'approval' || $taskType === 'post_approval') {
                            continue;
                        }
                        $tasks = $completion->markTaskComplete($tasks, $taskType);
                    }
                }
                $wpdb->update(
                    $wpdb->prefix . 'vad_registrations',
                    ['completion_tasks' => wp_json_encode($tasks)],
                    ['id' => $regId]
                );
                echo "        + Completion tasks: " . implode(', ', array_keys($tasks)) . "\n";
            }
        }

        // Attendance for every session of the edition
        if (($reg['attendance'] ?? null) === 'present' && $userId) {
            $attendanceTable = $wpdb->prefix . 'vad_attendance';
            $sessionIds = ntdst_get(SessionRepository::class)->findIdsByEdition($editionId);
            foreach ($sessionIds as $sessionId) {
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$attendanceTable} WHERE session_id = %d AND user_id = %d",
                    $sessionId,
                    $userId,
                ));
                if (!$exists) {
                    $wpdb->insert($attendanceTable, [
                        'edition_id' => $editionId,
                        'session_id' => $sessionId,
                        'user_id' => $userId,
                        'status' => 'present',
                        'marked_by' => (int) ($userMap['seed_admin'] ?? 1),
                        'marked_at' => current_time('mysql'),
                    ]);
                    echo "        + Attendance: session {$sessionId} (present)\n";
                }
            }
        }

        // Post-course completion tasks
        if (!empty($reg['init_post_tasks'])) {
            $completion = ntdst_get(EnrollmentCompletion::class);
            $completion->initializePostCourseTasks($regId, $editionId);
            $reqs = $completion->getPostCourseRequirements($editionId, 'vad_edition');
            echo "        + Post-course tasks: " . implode(', ', array_keys(array_filter($reqs))) . "\n";
        }

        return $regId;
    }

    /**
     * Port of createSession. All four SessionType values are accepted:
     * in_person | online | webinar | assignment.
     *
     * @return int|null session id
     */
    public function buildSession(int $editionId, array $editionData, array $sessionData, array $lessonIds): ?int
    {
        $sessionService = ntdst_get(SessionService::class);
        if (!$sessionService) {
            return null;
        }

        $sessionDate = date('Y-m-d', strtotime($editionData['start_date'] . " +{$sessionData['date_offset']} days"));

        $createData = [
            'edition_id' => $editionId,
            'date' => $sessionDate,
            'start_time' => $sessionData['start'],
            'end_time' => $sessionData['end'],
            'type' => $sessionData['type'],
            'location' => $sessionData['location'] ?? $editionData['venue'],
            'optional' => $sessionData['optional'] ?? false,
        ];

        if (!empty($sessionData['title'])) {
            $createData['title'] = $sessionData['title'];
        }
        if (!empty($sessionData['slot'])) {
            $createData['slot'] = $sessionData['slot'];
        }

        $session = $sessionService->createSession($createData);

        if (is_wp_error($session)) {
            echo "      ! Session failed: {$session->get_error_message()}\n";
            return null;
        }
        // createSession returns WP_Post|WP_Error; WP_Error already handled above
        $sessionId = $session->ID;

        update_post_meta($sessionId, StrideSeedRunner::SEED_META_KEY, true);

        // Link session to LearnDash lessons (explicit link_lesson key)
        if (!empty($lessonIds)) {
            update_post_meta($sessionId, '_ntdst_lesson_ids', $lessonIds);
            $lessonTitles = array_map(fn($id) => get_the_title($id), $lessonIds);
            echo "        🔗 Linked lessons: " . implode(', ', $lessonTitles) . "\n";
        }

        $typeEmoji = match ($sessionData['type']) {
            'online'     => '💻',
            'webinar'    => '📹',
            'assignment' => '📝',
            default      => '🏢',
        };
        $title = $sessionData['title'] ?? $sessionData['type'];
        $slotInfo = !empty($sessionData['slot']) ? " [slot: {$sessionData['slot']}]" : '';
        echo "      {$typeEmoji} {$sessionDate} {$sessionData['start']}-{$sessionData['end']}: {$title}{$slotInfo}\n";

        return (int) $sessionId;
    }

    /**
     * Deterministic-status quote for one registration. Creating pre-`cancelled`
     * is safe: QuoteRepository::create only fires stride/quote/data_changed
     * (cache invalidation), no transition cascade (plan ground-truth fact 6).
     * Idempotent by natural key: one quote per registration_id.
     *
     * @param string $status 'draft'|'sent'|'exported'|'cancelled' — QuoteStatus values
     */
    public function buildQuote(int $editionId, int $userId, int $registrationId, string $status): ?int
    {
        $repo = ntdst_get(QuoteRepository::class);

        $existing = $repo->findByRegistration($registrationId);
        if ($existing) {
            // getPostsFast row shape: lowercase 'id'
            $quoteId = (int) $existing['id'];
            echo "        - Quote for registration {$registrationId} exists (ID: {$quoteId})\n";
            return $quoteId;
        }

        $user = get_user_by('ID', $userId);
        if (!$user) {
            return null;
        }

        // No fallback price: a €0 quote on a paid edition should surface a
        // matrix mistake, not be masked. price_non_member is canonical (v1).
        $priceCents = (int) round(((float) ntdst_get(EditionRepository::class)->getField($editionId, 'price_non_member', 0)) * 100);
        $taxCents = (int) round($priceCents * 0.21);
        $quoteNumber = $repo->generateQuoteNumber();   // real numbering, not rand()

        $result = $repo->create([
            'title' => get_the_title($editionId),
            'user_id' => $userId,
            'registration_id' => $registrationId,
            'edition_id' => $editionId,
            'quote_number' => $quoteNumber,
            'status' => $status,
            'items' => [[
                'type' => 'edition', 'id' => $editionId, 'title' => get_the_title($editionId),
                'unit_price' => $priceCents, 'quantity' => 1, 'total' => $priceCents,
            ]],
            'subtotal' => $priceCents, 'discount' => 0, 'tax' => $taxCents, 'total' => $priceCents + $taxCents,
            'billing' => ['name' => $user->display_name, 'email' => $user->user_email],
            'valid_until' => date('Y-m-d', strtotime('+30 days')),
        ]);
        if (is_wp_error($result)) {
            echo "        ! Quote failed: {$result->get_error_message()}\n";
            return null;
        }
        $quoteId = (int) $result->ID;
        update_post_meta($quoteId, StrideSeedRunner::SEED_META_KEY, true);

        $extra = [];
        if (in_array($status, ['sent', 'exported'], true)) {
            $extra['sent_at'] = current_time('mysql');
        }
        if ($status === 'exported') {
            $extra['locked'] = true;
        }
        if ($extra) {
            $repo->update($quoteId, $extra);
        }

        echo "        + Quote: {$quoteNumber} [{$status}] (ID: {$quoteId})\n";
        return $quoteId;
    }

    /**
     * Merge matrix questionnaire groups into the stride_questionnaire_field_groups
     * option BY GROUP ID (idempotent — a matrix group replaces the stored group
     * with the same id; existing assignments are merged in, never dropped).
     *
     * Field 'options' values are comma-separated STRINGS — that is what the
     * builder admin sanitizes (QuestionnaireSettingsPage:329) and what the
     * renderers explode (dynamic-field.php / field-radio.php).
     *
     * Assignment resolution (flat int EDITION post IDs — the repository's
     * matchesAssignment() strict-matches the rendered post's own ID, which on
     * /inschrijven/ is the vad_edition, never the course):
     * - 'assign_to_course' => '<course title>'  → resolved to ALL seed edition
     *   IDs of that course (vad_edition with _ntdst_course_id, any status)
     * - 'assign_to_course' => '*post_course*'   → every EDITION that has
     *   _ntdst_post_requires_evaluation (port of the old eval-group logic)
     *
     * @return string[] group ids written
     */
    public function buildQuestionnaireGroups(array $matrixGroups): array
    {
        if (empty($matrixGroups)) {
            return [];
        }

        $repo = ntdst_get(QuestionnaireRepository::class);
        $byId = array_column($repo->getAllGroups(), null, 'id');
        $writtenIds = [];

        foreach ($matrixGroups as $mg) {
            $assignments = $this->resolveGroupAssignments($mg['assign_to_course'] ?? null);

            // QuestionnaireSettingsPage::sanitize() preserves only
            // label/name/type/required/help/options/min/max — a seeded
            // 'description' would be dropped on the first admin save. Mirror
            // the text into 'help' (kept by sanitize) while keeping
            // 'description' for the renderer.
            $fields = array_map(static function (array $f): array {
                if (!empty($f['description']) && empty($f['help'])) {
                    $f['help'] = $f['description'];
                }
                return $f;
            }, $mg['fields']);

            $group = [
                'id' => $mg['id'],
                'label' => $mg['label'],
                'stage' => $mg['stage'],
                'assignments' => $assignments,
                'fields' => $fields,
            ];

            if (isset($byId[$mg['id']])) {
                // Preserve assignments made outside the seed (merge, dedupe)
                $group['assignments'] = array_values(array_unique(array_merge(
                    $byId[$mg['id']]['assignments'] ?? [],
                    $assignments,
                ), SORT_REGULAR));
                echo "  - Questionnaire group '{$mg['id']}' exists - updated\n";
            } else {
                echo "  + Questionnaire group: {$mg['label']} ({$mg['id']}, stage: {$mg['stage']})\n";
            }
            $byId[$mg['id']] = $group;
            $writtenIds[] = $mg['id'];
        }

        $repo->saveGroups(array_values($byId));

        return $writtenIds;
    }

    /** @return array<int> flat EDITION post IDs (matchesAssignment strict-matches the edition ID) */
    private function resolveGroupAssignments(?string $target): array
    {
        global $wpdb;

        if ($target === null || $target === '') {
            return [];
        }

        if ($target === '*post_course*') {
            // Every EDITION that has post_requires_evaluation
            $editionIds = $wpdb->get_col(
                "SELECT DISTINCT p.ID
                 FROM {$wpdb->postmeta} pm
                 JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'vad_edition'
                 WHERE pm.meta_key = '_ntdst_post_requires_evaluation' AND pm.meta_value = '1'"
            );
            return array_map('intval', $editionIds);
        }

        // Course title → all seed edition IDs of that course (any post_status)
        $courseId = $this->findIdByTitle('sfwd-courses', $target);
        if (!$courseId) {
            echo "  ! Questionnaire assignment target not found: {$target}\n";
            return [];
        }

        $editionIds = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} cm ON cm.post_id = p.ID
                 AND cm.meta_key = '_ntdst_course_id' AND cm.meta_value = %s
             JOIN {$wpdb->postmeta} sm ON sm.post_id = p.ID
                 AND sm.meta_key = %s
             WHERE p.post_type = 'vad_edition'",
            (string) $courseId,
            StrideSeedRunner::SEED_META_KEY,
        ));

        if (empty($editionIds)) {
            echo "  ! Questionnaire assignment: no seed editions found for course '{$target}' ({$courseId})\n";
            return [];
        }
        return array_map('intval', $editionIds);
    }

    /**
     * Create one trajectory from a matrix entry. Course references are by
     * TITLE, resolved here (replaces the old brittle index-position scheme).
     * Single create path via TrajectoryService::createTrajectory; the
     * slug-pinned E2E fixture (`test-trajectory`) gets its slug pinned with
     * wp_update_post afterwards (Data API create has no slug vocabulary).
     * Idempotent by slug (when pinned) or title.
     */
    public function buildTrajectory(array $t): ?int
    {
        $slug = $t['slug'] ?? null;
        if ($slug) {
            $existing = get_page_by_path($slug, OBJECT, 'vad_trajectory');
            $existingId = $existing ? (int) $existing->ID : null;
        } else {
            $existingId = $this->findIdByTitle('vad_trajectory', $t['title']);
        }
        if ($existingId) {
            echo "  - Trajectory '" . ($slug ?? $t['title']) . "' exists (ID: {$existingId})\n";
            return $existingId;
        }

        // Resolve course titles → IDs (JSON shape per TrajectoryService contract)
        $courses = [];
        foreach (($t['courses'] ?? []) as $c) {
            $courseId = $this->findIdByTitle('sfwd-courses', $c['course_title']);
            if (!$courseId) {
                echo "  ! Trajectory course not found: {$c['course_title']}\n";
                continue;
            }
            $entry = ['course_id' => $courseId, 'required' => (bool) $c['required']];
            foreach (['order', 'group', 'min_choices'] as $key) {
                if (isset($c[$key])) { $entry[$key] = $c[$key]; }
            }
            // Author the admin-UI shape (TrajectoryAdminController::parseCourseItemValue):
            // a course with a seeded edition is an edition-typed entry carrying its
            // edition_id; without one it is a pure-LD 'online' entry. Shake-out
            // BUG-2 (2026-06-12): entries missing these keys are treated as
            // pure-LD by the selection/cascade paths — picks then bypass the
            // edition registration, capacity and quote machinery entirely.
            $editionId = $this->findEditionIdForCourse($courseId);
            if ($editionId) {
                $entry['type'] = 'edition';
                $entry['edition_id'] = $editionId;
            } else {
                $entry['type'] = 'online';
            }
            $courses[] = $entry;
        }

        $payload = [
            'title' => $t['title'],
            'content' => $t['content'] ?? '',   // Data API vocabulary: 'content', not 'post_content'
            'post_status' => 'publish',
            'mode' => $t['mode'],
            'status' => $t['status'],
            'enrollment_deadline' => $t['enrollment_deadline'] ?? '',
            'capacity' => $t['capacity'] ?? 0,
            'price' => $t['price'],
            'price_non_member' => $t['price_non_member'],
        ];
        // Only write keys the entry actually declares — keeps the slug-pinned
        // fixture's stored meta identical to the old wp_insert_post path.
        if (isset($t['choice_available_date'])) { $payload['choice_available_date'] = $t['choice_available_date']; }
        if (isset($t['choice_deadline']))       { $payload['choice_deadline'] = $t['choice_deadline']; }
        if (isset($t['courses']))               { $payload['courses'] = wp_json_encode($courses); }

        $id = ntdst_get(TrajectoryService::class)->createTrajectory($payload);
        if (is_wp_error($id)) {
            echo "  ! Trajectory '{$t['title']}' failed: {$id->get_error_message()}\n";
            return null;
        }
        if ($slug) {
            wp_update_post(['ID' => $id, 'post_name' => $slug]);
        }
        update_post_meta($id, StrideSeedRunner::SEED_META_KEY, true);
        echo "  + Trajectory: {$t['title']} [{$t['mode']}] (ID: {$id}, " . count($courses) . " courses)\n";
        return (int) $id;
    }

    /**
     * Resolve the edition a trajectory course entry should bind to: the
     * course's OPEN seeded edition, falling back to any seeded edition.
     * Returns 0 when the course has no edition (pure-LD).
     */
    private function findEditionIdForCourse(int $courseId): int
    {
        $candidates = get_posts([
            'post_type' => 'vad_edition',
            'post_status' => 'publish',
            'numberposts' => -1,
            'meta_key' => '_ntdst_course_id',
            'meta_value' => (string) $courseId,
            'fields' => 'ids',
        ]);

        $fallback = 0;
        foreach ($candidates as $editionId) {
            $status = (string) get_post_meta((int) $editionId, '_ntdst_status', true);
            if ($status === 'open') {
                return (int) $editionId;
            }
            if (!$fallback) {
                $fallback = (int) $editionId;
            }
        }

        return $fallback;
    }

    /**
     * VoucherService::createVoucher() (code-dedup + stride/voucher/created hook)
     * then scope fields via repository update — createVoucher does not accept
     * scope_mode / excluded_edition_ids (plan ground-truth fact 7).
     * Idempotent by code (getVoucherByCode).
     *
     * @param int[] $editionIds editions created this run (scope targets)
     */
    public function buildVoucher(array $v, array $editionIds): ?int
    {
        $service = ntdst_get(VoucherService::class);

        $existing = $service->getVoucherByCode($v['code']);
        if ($existing) {
            echo "  - Voucher {$v['code']} exists (ID: {$existing['id']})\n";
            return (int) $existing['id'];
        }

        $id = $service->createVoucher([
            'code' => $v['code'],
            'discount_type' => $v['discount_type'],
            'discount_value' => $v['discount_value'],
            'usage_limit' => $v['usage_limit'],
        ]);
        if (is_wp_error($id)) {
            echo "  ! Voucher {$v['code']} failed: {$id->get_error_message()}\n";
            return null;
        }
        update_post_meta($id, StrideSeedRunner::SEED_META_KEY, true);

        // Scope: createVoucher() does not accept these — set via repository update.
        // scope_mode is persisted explicitly even for 'all' so the seed-scoped
        // verifier can assert it from DB truth (cluster-3 review finding).
        $scope = $v['scope'] ?? 'all';   // 'all' | 'only' | 'except'
        $repo = ntdst_get(VoucherRepository::class);
        $update = ['scope_mode' => $scope];
        if ($scope === 'only')   { $update['edition_id'] = $editionIds[0] ?? 0; }
        if ($scope === 'except') { $update['excluded_edition_ids'] = array_slice($editionIds, 0, 2); }  // json field
        $repo->update($id, $update);

        echo "  + Voucher: {$v['code']} [{$v['discount_type']}/{$scope}] (ID: {$id})\n";
        return (int) $id;
    }
}
