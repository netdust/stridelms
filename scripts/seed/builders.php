<?php
/**
 * Stride seed builders — the only file that talks to repositories/services.
 * Each build*() consumes one matrix entry; behavior ported from the previous
 * imperative scripts/seed.php (see plan 2026-06-12-seed-feature-matrix.md).
 */

use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Enrollment\RegistrationRepository;

final class StrideSeedBuilders
{
    // Services resolved lazily via ntdst_get() in each method (script context;
    // matches current seed's null-guard pattern).

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

        // Existence check by title (idempotent re-run; get_page_by_title is deprecated)
        $existingQuery = new WP_Query([
            'post_type' => 'sfwd-courses', 'title' => $courseData['title'],
            'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true,
        ]);
        if (!empty($existingQuery->posts)) {
            $existingId = (int) $existingQuery->posts[0];
            echo "  - Course '{$courseData['title']}' exists\n";
            $out['created']['courses'][] = $existingId;
            $out['covers']["course:{$existingId}"] = $courseData['covers'] ?? [];
            return $out;
        }

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

        $out['created']['courses'][] = (int) $courseId;
        $out['covers']["course:{$courseId}"] = $courseData['covers'] ?? [];
        echo "  + Course: {$courseData['title']} (ID: {$courseId}) [format: " . implode(',', $formats) . "] [themes: " . implode(',', $themes) . "]\n";

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

        // end_date derivation from max session offset
        $endDate = $data['end_date'] ?? null;
        if (!$endDate && !empty($data['sessions'])) {
            $maxOffset = max(array_column($data['sessions'], 'date_offset'));
            $endDate = date('Y-m-d', strtotime($data['start_date'] . " +{$maxOffset} days"));
        }
        $endDate = $endDate ?? $data['start_date'];

        // v1 has no member feature — both price keys must hold the same value;
        // price_non_member is canonical.
        $effectivePrice = $data['price_non_member'] ?? $data['price'] ?? 0;

        $createData = [
            'title' => $postTitle,
            'post_status' => 'publish',
            'course_id' => $courseId,
            'start_date' => $data['start_date'],
            'end_date' => $endDate,
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

        // Sessions (Task 4)
        foreach (($data['sessions'] ?? []) as $sessionData) {
            $sessionId = $this->buildSession($editionId, $data, $sessionData, $lessonIds);
            if ($sessionId) { $out['created']['sessions'][] = $sessionId; }
        }
        // Registrations/quotes wired in Task 5/6.

        return $out;
    }

    /** @return int|null session id — Task 4 implement */
    public function buildSession(int $editionId, array $editionData, array $sessionData, array $lessonIds): ?int
    {
        return null;
    }

    /** @return string[] group ids written */
    public function buildQuestionnaireGroups(array $groups): array
    {
        // Task 7 implement
        return [];
    }

    public function buildTrajectory(array $t, array $courseIds): ?int
    {
        // Task 8 implement
        return null;
    }

    public function buildVoucher(array $v, array $editionIds): ?int
    {
        // Task 7/8 implement
        return null;
    }
}
