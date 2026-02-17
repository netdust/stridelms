<?php

declare(strict_types=1);

namespace Stride\Modules\Edition\Admin;

use Stride\Domain\SessionType;
use Stride\Infrastructure\AbstractService;
use Stride\Modules\Edition\EditionCPT;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Edition\SessionRepository;
use WP_Post;

/**
 * Edition Admin Controller.
 *
 * Orchestrates admin interface for editions:
 * - Registers metaboxes
 * - Enqueues admin assets
 * - Handles save operations
 * - AJAX endpoints for sessions and attendance
 */
final class EditionAdminController extends AbstractService
{
    public function __construct(
        private readonly EditionService $editionService,
        private readonly EditionRepository $editionRepository,
        private readonly SessionService $sessionService,
        private readonly SessionRepository $sessionRepository,
    ) {
        parent::__construct();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Edition Admin Controller',
            'description' => 'Admin interface for edition management',
            'priority' => 100, // Late priority, after services
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'edition-admin';
    }

    protected function init(): void
    {
        if (!is_admin()) {
            return;
        }

        add_action('add_meta_boxes', [$this, 'registerMetaboxes']);
        add_action('save_post_' . EditionCPT::POST_TYPE, [$this, 'handleSave'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);

        // Session AJAX endpoints
        add_action('wp_ajax_stride_add_session', [$this, 'ajaxAddSession']);
        add_action('wp_ajax_stride_update_session', [$this, 'ajaxUpdateSession']);
        add_action('wp_ajax_stride_delete_session', [$this, 'ajaxDeleteSession']);
        add_action('wp_ajax_stride_get_course_lessons', [$this, 'ajaxGetCourseLessons']);

        // Attendance AJAX endpoints
        add_action('wp_ajax_stride_mark_attendance', [$this, 'ajaxMarkAttendance']);
        add_action('wp_ajax_stride_bulk_attendance', [$this, 'ajaxBulkAttendance']);
    }

    public function registerMetaboxes(): void
    {
        // Remove default editor
        remove_post_type_support(EditionCPT::POST_TYPE, 'editor');

        // Main edition details
        add_meta_box(
            'stride_edition_details',
            __('Editie Details', 'stride'),
            [$this, 'renderDetailsMetabox'],
            EditionCPT::POST_TYPE,
            'normal',
            'high'
        );

        // Sessions metabox (only for existing editions)
        add_meta_box(
            'stride_edition_sessions',
            __('Sessies', 'stride'),
            [$this, 'renderSessionsMetabox'],
            EditionCPT::POST_TYPE,
            'normal',
            'default'
        );

        // Attendance metabox (only for existing editions with sessions)
        add_meta_box(
            'stride_edition_attendance',
            __('Aanwezigheid', 'stride'),
            [$this, 'renderAttendanceMetabox'],
            EditionCPT::POST_TYPE,
            'normal',
            'default'
        );

        // Status & actions sidebar
        add_meta_box(
            'stride_edition_actions',
            __('Status & Acties', 'stride'),
            [$this, 'renderActionsMetabox'],
            EditionCPT::POST_TYPE,
            'side',
            'high'
        );
    }

    public function enqueueAssets(string $hook): void
    {
        global $post_type, $post;

        if ($post_type !== EditionCPT::POST_TYPE) {
            return;
        }

        // Select2 from CDN
        wp_enqueue_style(
            'select2',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
            [],
            '4.1.0'
        );
        wp_enqueue_script(
            'select2',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
            ['jquery'],
            '4.1.0',
            true
        );

        // Edition admin styles
        $cssFile = get_stylesheet_directory() . '/assets/css/admin/edition-admin.css';
        if (file_exists($cssFile)) {
            wp_enqueue_style(
                'stride-edition-admin',
                get_stylesheet_directory_uri() . '/assets/css/admin/edition-admin.css',
                [],
                filemtime($cssFile)
            );
        }

        // Edition admin scripts
        $jsFile = get_stylesheet_directory() . '/assets/js/admin/edition-admin.js';
        if (file_exists($jsFile)) {
            wp_enqueue_script(
                'stride-edition-admin',
                get_stylesheet_directory_uri() . '/assets/js/admin/edition-admin.js',
                ['jquery', 'select2'],
                filemtime($jsFile),
                true
            );

            wp_localize_script('stride-edition-admin', 'strideEditionAdmin', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('stride_edition_admin'),
                'editionId' => $post ? $post->ID : 0,
                'i18n' => [
                    'searchCourse' => __('Zoek cursus...', 'stride'),
                    'noResults' => __('Geen resultaten gevonden', 'stride'),
                    'searching' => __('Zoeken...', 'stride'),
                    'noSessions' => __('Nog geen sessies toegevoegd.', 'stride'),
                    'confirmDelete' => __('Weet je zeker dat je deze sessie wilt verwijderen?', 'stride'),
                    'error' => __('Er ging iets mis. Probeer het opnieuw.', 'stride'),
                    'selectLesson' => __('Selecteer een les...', 'stride'),
                    'selectLessonOrQuiz' => __('Selecteer les of quiz...', 'stride'),
                    'noLessonsAvailable' => __('Geen lessen beschikbaar', 'stride'),
                ],
            ]);
        }
    }

    public function renderDetailsMetabox(WP_Post $post): void
    {
        $metabox = new EditionDetailsMetabox($this->editionService, $this->editionRepository);
        $metabox->render($post);
    }

    public function renderSessionsMetabox(WP_Post $post): void
    {
        $metabox = new EditionSessionsMetabox($this->sessionService, $this->editionRepository);
        $metabox->render($post);
    }

    public function renderAttendanceMetabox(WP_Post $post): void
    {
        $metabox = new EditionAttendanceMetabox($this->sessionService, $this->editionService);
        $metabox->render($post);
    }

    public function renderActionsMetabox(WP_Post $post): void
    {
        $metabox = new EditionActionsMetabox($this->editionService);
        $metabox->render($post);
    }

    public function handleSave(int $postId, WP_Post $post): void
    {
        // Verify nonce
        if (!isset($_POST['stride_edition_nonce']) ||
            !wp_verify_nonce($_POST['stride_edition_nonce'], 'stride_save_edition')) {
            return;
        }

        // Check autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Check permissions
        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        $fields = $_POST['ntdst_fields'] ?? [];
        $updateData = [];

        // Process basic fields
        $basicFields = [
            'course_id' => 'int',
            'start_date' => 'date',
            'end_date' => 'date',
            'capacity' => 'int',
            'venue' => 'text',
            'speakers' => 'text',
        ];

        foreach ($basicFields as $field => $type) {
            if (isset($fields[$field])) {
                $updateData[$field] = match ($type) {
                    'int' => absint($fields[$field]),
                    'date' => sanitize_text_field($fields[$field]),
                    'text' => sanitize_text_field($fields[$field]),
                    default => sanitize_text_field($fields[$field]),
                };
            }
        }

        // Process pricing fields (convert to cents)
        if (isset($fields['price'])) {
            $updateData['price'] = (int) round((float) $fields['price'] * 100);
        }
        if (isset($fields['price_non_member'])) {
            $updateData['price_non_member'] = (int) round((float) $fields['price_non_member'] * 100);
        }

        // Process status
        if (!empty($_POST['stride_change_status'])) {
            $updateData['status'] = sanitize_text_field($_POST['stride_change_status']);
        }

        // Process session slots configuration
        if (isset($fields['session_slots']) && is_array($fields['session_slots'])) {
            $slots = [];
            foreach ($fields['session_slots'] as $slot) {
                if (!empty($slot['slot'])) {
                    $slots[] = [
                        'slot' => sanitize_text_field($slot['slot']),
                        'label' => sanitize_text_field($slot['label'] ?? ''),
                        'max_selections' => absint($slot['max_selections'] ?? 1),
                        'required' => !empty($slot['required']),
                    ];
                }
            }
            $updateData['session_slots'] = $slots;
        }

        // Process selection deadline
        if (isset($fields['selection_deadline'])) {
            $updateData['selection_deadline'] = sanitize_text_field($fields['selection_deadline']);
        }

        // Update if we have data
        if (!empty($updateData)) {
            $this->editionRepository->updateMeta($postId, $updateData);
        }
    }

    // === Session AJAX Endpoints ===

    public function ajaxAddSession(): void
    {
        if (!$this->verifyAjaxNonce()) {
            return;
        }

        $editionId = absint($_POST['edition_id'] ?? 0);
        if (!$editionId || !$this->editionService->exists($editionId)) {
            wp_send_json_error(['message' => __('Ongeldige editie.', 'stride')], 400);
        }

        $data = $this->sanitizeSessionData($_POST);
        $data['edition_id'] = $editionId;

        $result = $this->sessionService->createSession($data);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }

        wp_send_json_success([
            'session_id' => $result,
            'html' => $this->renderSessionsTableBody($editionId),
        ]);
    }

    public function ajaxUpdateSession(): void
    {
        if (!$this->verifyAjaxNonce()) {
            return;
        }

        $sessionId = absint($_POST['session_id'] ?? 0);
        if (!$sessionId) {
            wp_send_json_error(['message' => __('Ongeldige sessie.', 'stride')], 400);
        }

        $session = $this->sessionService->getSession($sessionId);
        if (!$session) {
            wp_send_json_error(['message' => __('Sessie niet gevonden.', 'stride')], 404);
        }

        $data = $this->sanitizeSessionData($_POST);

        $result = $this->sessionService->updateSession($sessionId, $data);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }

        wp_send_json_success([
            'html' => $this->renderSessionsTableBody($session['edition_id']),
        ]);
    }

    public function ajaxDeleteSession(): void
    {
        if (!$this->verifyAjaxNonce()) {
            return;
        }

        $sessionId = absint($_POST['session_id'] ?? 0);
        if (!$sessionId) {
            wp_send_json_error(['message' => __('Ongeldige sessie.', 'stride')], 400);
        }

        $session = $this->sessionService->getSession($sessionId);
        if (!$session) {
            wp_send_json_error(['message' => __('Sessie niet gevonden.', 'stride')], 404);
        }

        $editionId = $session['edition_id'];

        wp_delete_post($sessionId, true);

        wp_send_json_success([
            'html' => $this->renderSessionsTableBody($editionId),
        ]);
    }

    public function ajaxGetCourseLessons(): void
    {
        if (!$this->verifyAjaxNonce()) {
            return;
        }

        $editionId = absint($_POST['edition_id'] ?? 0);
        if (!$editionId) {
            wp_send_json_error(['message' => __('Ongeldige editie.', 'stride')], 400);
        }

        $courseId = $this->editionService->getCourseId($editionId);
        if (!$courseId) {
            wp_send_json_success(['lessons' => []]);
            return;
        }

        $includeQuizzes = !empty($_POST['include_quizzes']);
        $lessons = $this->getCourseLessons($courseId, $includeQuizzes);

        wp_send_json_success(['lessons' => $lessons]);
    }

    // === Attendance AJAX Endpoints ===

    public function ajaxMarkAttendance(): void
    {
        if (!$this->verifyAjaxNonce()) {
            return;
        }

        $sessionId = absint($_POST['session_id'] ?? 0);
        $userId = absint($_POST['user_id'] ?? 0);
        $status = sanitize_text_field($_POST['status'] ?? 'present');

        if (!$sessionId || !$userId) {
            wp_send_json_error(['message' => __('Ongeldige gegevens.', 'stride')], 400);
        }

        // TODO: Implement attendance tracking via a dedicated service
        // For now, store in user meta as session_attendance_{session_id}
        $validStatuses = ['unmarked', 'present', 'absent', 'excused'];
        if (!in_array($status, $validStatuses, true)) {
            $status = 'unmarked';
        }

        if ($status === 'unmarked') {
            delete_user_meta($userId, "session_attendance_{$sessionId}");
        } else {
            update_user_meta($userId, "session_attendance_{$sessionId}", $status);
        }

        // Get totals for the session
        $totals = $this->getAttendanceTotals($sessionId);

        wp_send_json_success($totals);
    }

    public function ajaxBulkAttendance(): void
    {
        if (!$this->verifyAjaxNonce()) {
            return;
        }

        $sessionId = absint($_POST['session_id'] ?? 0);
        if (!$sessionId) {
            wp_send_json_error(['message' => __('Ongeldige sessie.', 'stride')], 400);
        }

        $session = $this->sessionService->getSession($sessionId);
        if (!$session) {
            wp_send_json_error(['message' => __('Sessie niet gevonden.', 'stride')], 404);
        }

        // Get all registrations for this edition
        $registrations = $this->getEditionRegistrations($session['edition_id']);

        // Mark all as present
        foreach ($registrations as $registration) {
            $userId = $registration['user_id'];
            update_user_meta($userId, "session_attendance_{$sessionId}", 'present');
        }

        $totals = $this->getAttendanceTotals($sessionId);

        wp_send_json_success($totals);
    }

    // === Helper Methods ===

    private function verifyAjaxNonce(): bool
    {
        if (!check_ajax_referer('stride_edition_admin', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token'], 403);
            return false;
        }

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
            return false;
        }

        return true;
    }

    private function sanitizeSessionData(array $input): array
    {
        $data = [
            'date' => sanitize_text_field($input['date'] ?? ''),
            'start_time' => sanitize_text_field($input['start_time'] ?? ''),
            'end_time' => sanitize_text_field($input['end_time'] ?? ''),
            'slot' => sanitize_text_field($input['slot'] ?? ''),
            'type' => sanitize_text_field($input['session_type'] ?? 'in_person'),
        ];

        // Type-specific fields
        $type = SessionType::tryFrom($data['type']) ?? SessionType::InPerson;

        switch ($type) {
            case SessionType::InPerson:
                $data['post_title'] = sanitize_text_field($input['title'] ?? '');
                $data['location'] = sanitize_text_field($input['location'] ?? '');
                $data['description'] = sanitize_textarea_field($input['description'] ?? '');
                break;

            case SessionType::Webinar:
                $data['post_title'] = sanitize_text_field($input['title'] ?? '');
                $data['webinar_link'] = esc_url_raw($input['webinar_link'] ?? '');
                $data['description'] = sanitize_textarea_field($input['description'] ?? '');
                break;

            case SessionType::Online:
            case SessionType::Assignment:
                $lessonId = absint($input['lesson_id'] ?? 0);
                if ($lessonId) {
                    $lesson = get_post($lessonId);
                    $data['post_title'] = $lesson ? $lesson->post_title : '';
                    $data['lesson_ids'] = [$lessonId];
                }
                break;
        }

        return $data;
    }

    private function renderSessionsTableBody(int $editionId): string
    {
        $sessions = $this->sessionService->getSessionsForEdition($editionId);

        if (empty($sessions)) {
            return '';
        }

        ob_start();
        foreach ($sessions as $session) {
            $this->renderSessionRow($session);
        }
        return ob_get_clean();
    }

    private function renderSessionRow(array $session): void
    {
        $type = SessionType::tryFrom($session['type']) ?? SessionType::InPerson;
        $dateFormatted = !empty($session['date']) ? date_i18n('d M Y', strtotime($session['date'])) : '-';
        $timeFormatted = '';
        if (!empty($session['start_time'])) {
            $timeFormatted = $session['start_time'];
            if (!empty($session['end_time'])) {
                $timeFormatted .= ' - ' . $session['end_time'];
            }
        }
        ?>
        <tr class="session-row"
            data-session-id="<?php echo esc_attr($session['id']); ?>"
            data-date="<?php echo esc_attr($session['date']); ?>"
            data-start-time="<?php echo esc_attr($session['start_time']); ?>"
            data-end-time="<?php echo esc_attr($session['end_time']); ?>"
            data-location="<?php echo esc_attr($session['location'] ?? ''); ?>"
            data-session-type="<?php echo esc_attr($session['type']); ?>"
            data-session-slot="<?php echo esc_attr($session['slot'] ?? ''); ?>">
            <td class="column-date"><?php echo esc_html($dateFormatted); ?></td>
            <td class="column-time"><?php echo esc_html($timeFormatted ?: '-'); ?></td>
            <td class="column-type">
                <span class="session-type-badge session-type-<?php echo esc_attr($session['type']); ?>">
                    <?php echo esc_html($type->label()); ?>
                </span>
            </td>
            <td class="column-location"><?php echo esc_html($session['location'] ?: '-'); ?></td>
            <td class="column-actions">
                <button type="button" class="button-link stride-edit-session" title="<?php esc_attr_e('Bewerken', 'stride'); ?>">
                    <span class="dashicons dashicons-edit"></span>
                </button>
                <button type="button" class="button-link stride-delete-session" title="<?php esc_attr_e('Verwijderen', 'stride'); ?>">
                    <span class="dashicons dashicons-trash"></span>
                </button>
            </td>
        </tr>
        <?php
    }

    private function getCourseLessons(int $courseId, bool $includeQuizzes = false): array
    {
        $lessons = [];

        // Get LearnDash lessons
        $lessonPosts = learndash_get_lesson_list($courseId);
        foreach ($lessonPosts as $lesson) {
            $lessons[] = [
                'id' => $lesson->ID,
                'title' => $lesson->post_title,
                'type' => 'lesson',
            ];
        }

        // Optionally include quizzes
        if ($includeQuizzes) {
            $quizPosts = learndash_get_course_quiz_list($courseId);
            foreach ($quizPosts as $quiz) {
                $lessons[] = [
                    'id' => $quiz['post']->ID ?? $quiz->ID,
                    'title' => ($quiz['post']->post_title ?? $quiz->post_title) . ' (Quiz)',
                    'type' => 'quiz',
                ];
            }
        }

        return $lessons;
    }

    private function getEditionRegistrations(int $editionId): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'vad_registrations';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT user_id FROM {$table} WHERE edition_id = %d AND status = 'confirmed'",
            $editionId
        ), ARRAY_A) ?: [];
    }

    private function getAttendanceTotals(int $sessionId): array
    {
        $session = $this->sessionService->getSession($sessionId);
        if (!$session) {
            return ['presentCount' => 0, 'totalCount' => 0];
        }

        $registrations = $this->getEditionRegistrations($session['edition_id']);
        $totalCount = count($registrations);
        $presentCount = 0;

        foreach ($registrations as $registration) {
            $status = get_user_meta($registration['user_id'], "session_attendance_{$sessionId}", true);
            if ($status === 'present') {
                $presentCount++;
            }
        }

        return [
            'presentCount' => $presentCount,
            'totalCount' => $totalCount,
        ];
    }
}
