<?php

declare(strict_types=1);

namespace Stride\Modules\Edition\Admin;

use Stride\Domain\AttendanceStatus;
use Stride\Domain\SessionType;
use Stride\Modules\Attendance\AttendanceRepository;
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
 *
 * Plain class — owned by EditionService.
 */
final class EditionAdminController
{
    public const NONCE_SAVE = 'stride_save_edition';
    public const NONCE_FIELD = 'stride_edition_nonce';
    public const REGISTRATIONS_TABLE = 'vad_registrations';
    private const NONCE_AJAX = 'stride_edition_admin';

    public function __construct(
        private readonly EditionService $editionService,
        private readonly EditionRepository $editionRepository,
        private readonly SessionService $sessionService,
        private readonly SessionRepository $sessionRepository,
        private readonly AttendanceRepository $attendanceRepository,
    ) {
        $this->init();
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

        // Registration approval AJAX endpoints
        add_action('wp_ajax_stride_confirm_registration', [$this, 'ajaxConfirmRegistration']);
        add_action('wp_ajax_stride_reject_registration', [$this, 'ajaxRejectRegistration']);
        add_action('wp_ajax_stride_approve_post_course', [$this, 'ajaxApprovePostCourse']);

        // Bulk lock/unlock quotes for this edition
        add_action('wp_ajax_stride_bulk_lock_quotes', [$this, 'ajaxBulkLockQuotes']);

        // Registration export
        add_action('wp_ajax_stride_export_registrations', [$this, 'ajaxExportRegistrations']);

        // Admin list columns
        add_filter('manage_' . EditionCPT::POST_TYPE . '_posts_columns', [$this, 'defineListColumns']);
        add_action('manage_' . EditionCPT::POST_TYPE . '_posts_custom_column', [$this, 'renderListColumn'], 10, 2);
        add_filter('manage_edit-' . EditionCPT::POST_TYPE . '_sortable_columns', [$this, 'defineSortableColumns']);
        add_action('pre_get_posts', [$this, 'handleColumnSorting']);

        // List table filters
        add_action('restrict_manage_posts', [$this, 'renderListFilters']);
        add_action('pre_get_posts', [$this, 'applyListFilters']);

        // Remove bulk actions
        add_filter('bulk_actions-edit-' . EditionCPT::POST_TYPE, [$this, 'removeBulkActions']);
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

        // Sessions metabox
        add_meta_box(
            'stride_edition_sessions',
            __('Sessies', 'stride'),
            [$this, 'renderSessionsMetabox'],
            EditionCPT::POST_TYPE,
            'normal',
            'default'
        );

        // Registrations & Attendance metabox
        add_meta_box(
            'stride_edition_attendance',    // keep ID for position
            __('Deelnemers', 'stride'),
            [$this, 'renderRegistrationMetabox'],
            EditionCPT::POST_TYPE,
            'normal',
            'default'
        );

        // Notes metabox
        add_meta_box(
            'stride_edition_notes',
            __('Notities', 'stride'),
            [$this, 'renderNotesMetabox'],
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

        // WP Media Library (for document uploads)
        wp_enqueue_media();

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

        // Edition admin styles (from stride-core mu-plugin)
        $basePath = dirname(__DIR__, 3);
        $cssFile = $basePath . '/assets/css/admin/edition-admin.css';
        if (file_exists($cssFile)) {
            wp_enqueue_style(
                'stride-edition-admin',
                plugins_url('assets/css/admin/edition-admin.css', $basePath . '/stride-core.php'),
                [],
                filemtime($cssFile)
            );
        }

        // Edition admin scripts (from stride-core mu-plugin)
        $jsFile = $basePath . '/assets/js/admin/edition-admin.js';
        if (file_exists($jsFile)) {
            wp_enqueue_script(
                'stride-edition-admin',
                plugins_url('assets/js/admin/edition-admin.js', $basePath . '/stride-core.php'),
                ['jquery', 'select2'],
                filemtime($jsFile),
                true
            );

            $currentUser = wp_get_current_user();
            wp_localize_script('stride-edition-admin', 'strideEditionAdmin', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce(self::NONCE_AJAX),
                'editionId' => $post ? $post->ID : 0,
                'currentUser' => $currentUser->display_name ?: $currentUser->user_login,
                'onlineCourseIds' => $this->getOnlineCourseIds(),
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
                    // Registration i18n
                    'confirmApproval' => __('Inschrijving goedkeuren?', 'stride'),
                    'confirmReject' => __('Inschrijving afwijzen? Dit kan niet ongedaan gemaakt worden.', 'stride'),
                    // Notes i18n
                    'enterNote' => __('Vul een notitie in.', 'stride'),
                    'noNotes' => __('Nog geen notities toegevoegd.', 'stride'),
                    'remove' => __('Verwijderen', 'stride'),
                    'todo' => __('Todo', 'stride'),
                    'email' => __('E-mail', 'stride'),
                    'userinfo' => __('Info', 'stride'),
                ],
            ]);
        }
    }

    /**
     * Get IDs of courses categorized as online.
     * @return int[]
     */
    private function getOnlineCourseIds(): array
    {
        $courses = get_posts([
            'post_type' => 'sfwd-courses',
            'posts_per_page' => 500,
            'fields' => 'ids',
            'tax_query' => [[
                'taxonomy' => 'stride_format',
                'field' => 'slug',
                'terms' => ['online', 'webinar', 'e-learning'],
            ]],
        ]);

        return array_map('intval', $courses);
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

    public function renderRegistrationMetabox(WP_Post $post): void
    {
        $metabox = new EditionRegistrationMetabox($this->sessionService, $this->editionService, $this->attendanceRepository);
        $metabox->render($post);
    }

    public function renderActionsMetabox(WP_Post $post): void
    {
        $metabox = new EditionActionsMetabox($this->editionService);
        $metabox->render($post);
    }

    public function renderNotesMetabox(WP_Post $post): void
    {
        // For new editions, show placeholder
        if ($post->post_status === 'auto-draft') {
            echo '<p class="description">' . esc_html__('Sla de editie eerst op om notities toe te voegen.', 'stride') . '</p>';
            return;
        }

        $notes = $this->editionRepository->getField($post->ID, 'notes', []);
        if (is_string($notes)) {
            $notes = json_decode($notes, true) ?: [];
        }

        // Note types configuration
        $noteTypes = [
            'todo' => [
                'label' => __('Todo', 'stride'),
                'icon' => 'yes-alt',
                'color' => 'todo',
            ],
            'email' => [
                'label' => __('E-mail', 'stride'),
                'icon' => 'email',
                'color' => 'email',
            ],
            'userinfo' => [
                'label' => __('Info', 'stride'),
                'icon' => 'info-outline',
                'color' => 'userinfo',
            ],
        ];
        ?>
        <!-- Notes Timeline -->
        <div id="stride-notes-list" class="stride-notes-timeline">
            <?php if (empty($notes)): ?>
                <div class="stride-empty-notes">
                    <?php esc_html_e('Nog geen notities toegevoegd.', 'stride'); ?>
                </div>
            <?php else: ?>
                <?php foreach ($notes as $index => $note): ?>
                    <?php if (!empty($note['_deleted'])) continue; ?>
                    <?php
                    $type = $note['type'] ?? 'userinfo';
                    $typeConfig = $noteTypes[$type] ?? $noteTypes['userinfo'];
                    ?>
                    <div class="stride-note-item" data-index="<?php echo esc_attr($index); ?>">
                        <div class="stride-note-icon <?php echo esc_attr($type); ?>">
                            <span class="dashicons dashicons-<?php echo esc_attr($typeConfig['icon']); ?>"></span>
                        </div>
                        <div class="stride-note-body">
                            <div class="stride-note-meta">
                                <span class="author"><?php echo esc_html($note['author'] ?? __('Onbekend', 'stride')); ?></span>
                                <span class="type-badge <?php echo esc_attr($type); ?>"><?php echo esc_html($typeConfig['label']); ?></span>
                                <span class="date"><?php echo esc_html($note['date'] ?? ''); ?></span>
                            </div>
                            <div class="stride-note-content"><?php echo esc_html($note['content'] ?? ''); ?></div>
                        </div>
                        <span class="stride-note-delete dashicons dashicons-no-alt" title="<?php esc_attr_e('Verwijderen', 'stride'); ?>"></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Add Note Form -->
        <div class="stride-add-note-form">
            <textarea id="stride-note-content" placeholder="<?php esc_attr_e('Schrijf een notitie...', 'stride'); ?>"></textarea>
            <div class="form-row">
                <div class="type-selector">
                    <?php foreach ($noteTypes as $typeKey => $typeConfig): ?>
                        <label>
                            <input type="radio" name="stride_note_type" value="<?php echo esc_attr($typeKey); ?>" <?php checked($typeKey, 'userinfo'); ?>>
                            <span class="type-icon <?php echo esc_attr($typeKey); ?>"><span class="dashicons dashicons-<?php echo esc_attr($typeConfig['icon']); ?>"></span></span>
                            <?php echo esc_html($typeConfig['label']); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="button" id="stride-add-note">
                    <?php esc_html_e('Notitie toevoegen', 'stride'); ?>
                </button>
            </div>
        </div>

        <input type="hidden" id="stride_notes_data" name="ntdst_fields[notes]" value="<?php echo esc_attr(json_encode($notes)); ?>">
        <?php
    }

    public function handleSave(int $postId, WP_Post $post): void
    {
        // Verify nonce
        if (!isset($_POST['stride_edition_nonce']) ||
            !wp_verify_nonce($_POST['stride_edition_nonce'], self::NONCE_SAVE)) {
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
            'enrollment_form' => 'text',
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

        // Process pricing fields (convert to cents).
        // v1 has no member tier — the admin form posts a single value as
        // `price_non_member` (canonical for v1). Sync `price` to the same
        // value so any reader still routing through member/non-member meta
        // sees one source of truth.
        if (isset($fields['price_non_member'])) {
            $cents = (int) round((float) $fields['price_non_member'] * 100);
            $updateData['price_non_member'] = $cents;
            $updateData['price'] = $cents;
        } elseif (isset($fields['price'])) {
            // Back-compat path if a caller still posts the legacy `price` key.
            $cents = (int) round((float) $fields['price'] * 100);
            $updateData['price'] = $cents;
            $updateData['price_non_member'] = $cents;
        }

        // Process boolean checkbox fields (sidebar requirements)
        $booleanFields = [
            'requires_approval',
            'requires_questionnaire',
            'requires_documents',
            'requires_session_selection',
            'selection_open',
            'post_requires_evaluation',
            'post_requires_documents',
            'post_requires_approval',
        ];
        foreach ($booleanFields as $boolField) {
            if (isset($fields[$boolField])) {
                $updateData[$boolField] = (bool) $fields[$boolField];
            }
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

        // Process notes (stored as JSON)
        if (isset($fields['notes'])) {
            $jsonString = stripslashes($fields['notes']);
            $notes = json_decode($jsonString, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                // Log JSON decode error for debugging
                error_log(sprintf(
                    'Stride Edition: JSON decode error for notes on post %d: %s',
                    $postId,
                    json_last_error_msg()
                ));
            } elseif (is_array($notes)) {
                // Filter out deleted notes and sanitize
                $sanitizedNotes = [];
                foreach ($notes as $note) {
                    if (empty($note['_deleted'])) {
                        $sanitizedNotes[] = [
                            'type' => sanitize_key($note['type'] ?? 'userinfo'),
                            'content' => sanitize_textarea_field($note['content'] ?? ''),
                            'author' => sanitize_text_field($note['author'] ?? ''),
                            'date' => sanitize_text_field($note['date'] ?? ''),
                        ];
                    }
                }
                $updateData['notes'] = $sanitizedNotes;
            }
        }

        // Process documents (stored as JSON array of attachment IDs)
        if (isset($fields['documents'])) {
            $jsonString = wp_unslash($fields['documents']);
            $docs = is_string($jsonString) ? json_decode($jsonString, true) : $jsonString;
            if (is_array($docs)) {
                $updateData['documents'] = array_values(array_map('absint', array_filter($docs)));
            }
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

        if (!current_user_can('edit_post', $editionId)) {
            wp_send_json_error(['message' => __('Geen toegang.', 'stride')], 403);
        }

        $data = $this->sanitizeSessionData($_POST);
        $data['edition_id'] = $editionId;

        $result = $this->sessionService->createSession($data);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }

        // Clear cache so renderSessionsTableBody gets fresh meta
        ntdst_invalidate_post_type('vad_session');

        wp_send_json_success([
            'session_id' => $result->ID,
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

        $editionId = (int) ($session['edition_id'] ?? 0);
        if (!current_user_can('edit_post', $editionId)) {
            wp_send_json_error(['message' => __('Geen toegang.', 'stride')], 403);
        }

        $data = $this->sanitizeSessionData($_POST);

        $result = $this->sessionService->updateSession($sessionId, $data);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }

        ntdst_invalidate_post_type('vad_session');

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

        // Verify user can edit the parent edition
        if (!current_user_can('edit_post', $editionId)) {
            wp_send_json_error(['message' => __('Geen toegang.', 'stride')], 403);
        }

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
        $statusValue = sanitize_text_field($_POST['status'] ?? 'present');

        if (!$sessionId || !$userId) {
            wp_send_json_error(['message' => __('Ongeldige gegevens.', 'stride')], 400);
        }

        // Validate status
        $validStatuses = ['unmarked', 'present', 'absent', 'excused'];
        if (!in_array($statusValue, $validStatuses, true)) {
            $statusValue = 'unmarked';
        }

        // Get session to find edition_id
        $session = $this->sessionService->getSession($sessionId);
        if (!$session) {
            wp_send_json_error(['message' => __('Sessie niet gevonden.', 'stride')], 404);
        }

        $editionId = (int) $session['edition_id'];

        if ($statusValue === 'unmarked') {
            // Delete attendance record
            $existing = $this->attendanceRepository->findBySessionAndUser($sessionId, $userId);
            if ($existing) {
                $this->attendanceRepository->delete((int) $existing->id);
            }
        } else {
            // Record attendance via service (fires events for audit + auto-complete)
            $attendanceService = ntdst_get(\Stride\Modules\Attendance\AttendanceService::class);
            $status = AttendanceStatus::tryFrom($statusValue);
            if ($status) {
                match ($status) {
                    AttendanceStatus::Present => $attendanceService->markPresent($sessionId, $userId),
                    AttendanceStatus::Absent => $attendanceService->markAbsent($sessionId, $userId),
                    AttendanceStatus::Excused => $attendanceService->markExcused($sessionId, $userId),
                };
            }
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

        $editionId = (int) $session['edition_id'];

        // Get all registrations for this edition
        $registrations = $this->getEditionRegistrations($editionId);

        // Mark all as present via service (fires events for audit + auto-complete)
        $attendanceService = ntdst_get(\Stride\Modules\Attendance\AttendanceService::class);
        foreach ($registrations as $registration) {
            $attendanceService->markPresent($sessionId, (int) $registration['user_id']);
        }

        $totals = $this->getAttendanceTotals($sessionId);

        wp_send_json_success($totals);
    }

    // === Quote bulk lock/unlock ===

    /**
     * Bulk lock or unlock all quotes linked to an edition.
     *
     * POST params:
     *   edition_id  int    — the edition whose quotes to update
     *   locked      bool   — '1' or '0'
     *
     * Returns a summary {total, changed, unchanged} so the UI can show
     * "12 offertes vergrendeld" or "Alle offertes waren al ontgrendeld".
     */
    public function ajaxBulkLockQuotes(): void
    {
        if (!$this->verifyAjaxNonce()) {
            return;
        }

        $editionId = absint($_POST['edition_id'] ?? 0);
        if (!$editionId) {
            wp_send_json_error(['message' => __('Ongeldige editie.', 'stride')], 400);
        }

        if (!current_user_can('edit_post', $editionId)) {
            wp_send_json_error(['message' => __('Onvoldoende rechten.', 'stride')], 403);
        }

        $locked = filter_var($_POST['locked'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $quoteService = ntdst_get(\Stride\Modules\Invoicing\QuoteService::class);
        $summary = $quoteService->bulkSetLockedByEdition($editionId, $locked);

        wp_send_json_success($summary);
    }

    // === Registration Approval AJAX Endpoints ===

    public function ajaxConfirmRegistration(): void
    {
        if (!$this->verifyAjaxNonce()) {
            return;
        }

        $registrationId = absint($_POST['registration_id'] ?? 0);
        if (!$registrationId) {
            wp_send_json_error(['message' => __('Ongeldige registratie.', 'stride')], 400);
        }

        $enrollmentService = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);
        $result = $enrollmentService->confirmRegistration($registrationId);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }

        wp_send_json_success(['message' => __('Inschrijving goedgekeurd.', 'stride')]);
    }

    public function ajaxRejectRegistration(): void
    {
        if (!$this->verifyAjaxNonce()) {
            return;
        }

        $registrationId = absint($_POST['registration_id'] ?? 0);
        if (!$registrationId) {
            wp_send_json_error(['message' => __('Ongeldige registratie.', 'stride')], 400);
        }

        $enrollmentService = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);
        $result = $enrollmentService->cancel($registrationId);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }

        wp_send_json_success(['message' => __('Inschrijving afgewezen.', 'stride')]);
    }

    public function ajaxApprovePostCourse(): void
    {
        if (!$this->verifyAjaxNonce()) {
            return;
        }

        $registrationId = absint($_POST['registration_id'] ?? 0);
        if (!$registrationId) {
            wp_send_json_error(['message' => __('Ongeldige registratie.', 'stride')], 400);
        }

        $completion = ntdst_get(\Stride\Modules\Enrollment\EnrollmentCompletion::class);
        $result = $completion->completeTask($registrationId, 'post_approval');

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }

        wp_send_json_success(['message' => __('Dossier afgetekend.', 'stride')]);
    }

    // === Export ===

    public function ajaxExportRegistrations(): void
    {
        if (!check_ajax_referer(self::NONCE_AJAX, 'nonce', false)) {
            wp_die('Invalid security token', 403);
        }

        if (!current_user_can('edit_posts')) {
            wp_die('Unauthorized', 403);
        }

        $editionId = absint($_GET['edition_id'] ?? 0);
        if (!$editionId) {
            wp_die('Invalid edition', 400);
        }

        $type = sanitize_key($_GET['type'] ?? 'excel');

        switch ($type) {
            case 'namecards':
                $exporter = new EditionNamecardExporter(
                    $this->editionService,
                    $this->editionRepository,
                );
                $exporter->export($editionId);
                break;

            case 'attendance':
                $exporter = new EditionAttendanceExporter(
                    $this->editionService,
                    $this->editionRepository,
                    $this->sessionService,
                );
                $exporter->export($editionId);
                break;

            case 'files':
                $filesExporter = new EditionFilesZipExporter(
                    $this->editionRepository,
                    ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class),
                );
                $filesExporter->export($editionId);
                break;

            case 'bundle':
                $filesExporter = new EditionFilesZipExporter(
                    $this->editionRepository,
                    ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class),
                );
                $bundleExporter = new EditionBundleZipExporter(
                    new EditionRegistrationExporter(
                        $this->editionService,
                        $this->editionRepository,
                        $this->sessionService,
                        $this->attendanceRepository,
                    ),
                    new EditionNamecardExporter(
                        $this->editionService,
                        $this->editionRepository,
                    ),
                    new EditionAttendanceExporter(
                        $this->editionService,
                        $this->editionRepository,
                        $this->sessionService,
                    ),
                    $filesExporter,
                );
                $bundleExporter->export($editionId);
                break;

            default: // 'excel'
                $exporter = new EditionRegistrationExporter(
                    $this->editionService,
                    $this->editionRepository,
                    $this->sessionService,
                    $this->attendanceRepository,
                );
                $exporter->export($editionId);
                break;
        }
    }

    // === List Table Filters & Bulk Actions ===

    /**
     * Remove bulk actions from edition list table.
     */
    public function removeBulkActions(array $actions): array
    {
        return [];
    }

    /**
     * Render filter dropdowns: Course, Status, Format.
     */
    public function renderListFilters(string $postType): void
    {
        if ($postType !== EditionCPT::POST_TYPE) {
            return;
        }

        // Course filter
        $currentCourse = (int) ($_GET['stride_course'] ?? 0);
        $courses = get_posts([
            'post_type'      => 'sfwd-courses',
            'posts_per_page' => 500,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);
        ?>
        <select name="stride_course">
            <option value=""><?php esc_html_e('Alle cursussen', 'stride'); ?></option>
            <?php foreach ($courses as $course) : ?>
                <option value="<?php echo esc_attr($course->ID); ?>" <?php selected($currentCourse, $course->ID); ?>>
                    <?php echo esc_html($course->post_title); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php

        // Status filter
        $currentStatus = sanitize_text_field($_GET['stride_status'] ?? '');
        $statuses = [
            'draft'        => __('Concept', 'stride'),
            'announcement' => __('Vooraankondiging', 'stride'),
            'open'         => __('Open', 'stride'),
            'full'         => __('Volzet', 'stride'),
            'in_progress'  => __('Lopend', 'stride'),
            'postponed'    => __('Uitgesteld', 'stride'),
            'cancelled'    => __('Geannuleerd', 'stride'),
            'completed'    => __('Afgerond', 'stride'),
            'archived'     => __('Gearchiveerd', 'stride'),
        ];
        ?>
        <select name="stride_status">
            <option value=""><?php esc_html_e('Alle statussen', 'stride'); ?></option>
            <?php foreach ($statuses as $value => $label) : ?>
                <option value="<?php echo esc_attr($value); ?>" <?php selected($currentStatus, $value); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php

        // Format filter (name must NOT match taxonomy slug to avoid WP auto tax_query)
        $currentFormat = sanitize_text_field($_GET['edition_format'] ?? '');
        ?>
        <select name="edition_format">
            <option value=""><?php esc_html_e('Alle formaten', 'stride'); ?></option>
            <option value="online" <?php selected($currentFormat, 'online'); ?>><?php esc_html_e('Online', 'stride'); ?></option>
            <option value="classroom" <?php selected($currentFormat, 'classroom'); ?>><?php esc_html_e('Klassikaal', 'stride'); ?></option>
        </select>
        <?php
    }

    /**
     * Apply list filters to the query.
     */
    public function applyListFilters(\WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }
        if (($query->get('post_type') ?? '') !== EditionCPT::POST_TYPE) {
            return;
        }

        $metaQuery = $query->get('meta_query') ?: [];

        // Course filter
        $courseId = (int) ($_GET['stride_course'] ?? 0);
        if ($courseId > 0) {
            $metaQuery[] = [
                'key'     => '_ntdst_course_id',
                'value'   => $courseId,
                'compare' => '=',
                'type'    => 'NUMERIC',
            ];
        }

        // Status filter
        $status = sanitize_text_field($_GET['stride_status'] ?? '');
        if ($status) {
            $metaQuery[] = [
                'key'     => '_ntdst_status',
                'value'   => $status,
                'compare' => '=',
            ];
        }

        // Format filter
        $format = sanitize_text_field($_GET['edition_format'] ?? '');
        if ($format) {
            $onlineSlugs = ['online', 'webinar', 'e-learning'];

            $courseIds = get_posts([
                'post_type'      => 'sfwd-courses',
                'posts_per_page' => 500,
                'fields'         => 'ids',
                'tax_query'      => [
                    [
                        'taxonomy' => 'stride_format',
                        'field'    => 'slug',
                        'terms'    => $onlineSlugs,
                        'operator' => $format === 'online' ? 'IN' : 'NOT IN',
                    ],
                ],
            ]);

            if (empty($courseIds)) {
                $query->set('post__in', [0]);
                return;
            }

            $metaQuery[] = [
                'key'     => '_ntdst_course_id',
                'value'   => $courseIds,
                'compare' => 'IN',
                'type'    => 'NUMERIC',
            ];
        }

        if (!empty($metaQuery)) {
            $query->set('meta_query', $metaQuery);
        }

    }

    // === Helper Methods ===

    private function verifyAjaxNonce(): bool
    {
        if (!check_ajax_referer(self::NONCE_AJAX, 'nonce', false)) {
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

        // Convert euro input to cents for storage
        $priceModifierInput = $input['price_modifier'] ?? '';
        if ($priceModifierInput !== '' && $priceModifierInput !== null) {
            $data['price_modifier'] = (int) round(floatval(str_replace(',', '.', (string) $priceModifierInput)) * 100);
        } else {
            $data['price_modifier'] = 0;
        }

        // Type-specific fields
        $type = SessionType::tryFrom($data['type']) ?? SessionType::InPerson;

        switch ($type) {
            case SessionType::InPerson:
                $data['title'] = sanitize_text_field($input['title'] ?? '');
                $data['location'] = sanitize_text_field($input['location'] ?? '');
                $data['description'] = sanitize_textarea_field($input['description'] ?? '');
                break;

            case SessionType::Webinar:
                $data['title'] = sanitize_text_field($input['title'] ?? '');
                $data['webinar_link'] = esc_url_raw($input['webinar_link'] ?? '');
                $data['description'] = sanitize_textarea_field($input['description'] ?? '');
                $data['location'] = 'Online';
                break;

            case SessionType::Online:
            case SessionType::Assignment:
                $lessonId = absint($input['lesson_id'] ?? 0);
                if ($lessonId) {
                    $lesson = get_post($lessonId);
                    $data['title'] = $lesson ? $lesson->post_title : '';
                    $data['lesson_ids'] = [$lessonId];
                }
                $data['location'] = 'Online';
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
        <?php
        // Prepare lesson_ids as comma-separated string
        $lessonIds = '';
        if (!empty($session['lesson_ids']) && is_array($session['lesson_ids'])) {
            $lessonIds = implode(',', array_map('intval', $session['lesson_ids']));
        }
        ?>
        <tr class="session-row"
            data-session-id="<?php echo esc_attr($session['id']); ?>"
            data-date="<?php echo esc_attr($session['date']); ?>"
            data-start-time="<?php echo esc_attr($session['start_time']); ?>"
            data-end-time="<?php echo esc_attr($session['end_time']); ?>"
            data-location="<?php echo esc_attr($session['location'] ?? ''); ?>"
            data-session-type="<?php echo esc_attr($session['type']); ?>"
            data-session-slot="<?php echo esc_attr($session['slot'] ?? ''); ?>"
            data-title="<?php echo esc_attr($session['title'] ?? ''); ?>"
            data-description="<?php echo esc_attr($session['description'] ?? ''); ?>"
            data-webinar-link="<?php echo esc_attr($session['webinar_link'] ?? ''); ?>"
            data-lesson-ids="<?php echo esc_attr($lessonIds); ?>"
            data-price-modifier="<?php echo esc_attr((string) ($session['price_modifier'] ?? 0)); ?>">
            <td class="column-date"><?php echo esc_html($dateFormatted); ?></td>
            <td class="column-time"><?php echo esc_html($timeFormatted ?: '-'); ?></td>
            <td class="column-type">
                <span class="session-type-badge session-type-<?php echo esc_attr($session['type']); ?>">
                    <?php echo esc_html($type->label()); ?>
                </span>
            </td>
            <td class="column-slot"><?php echo esc_html($session['slot'] ? ($session['slot']) : '-'); ?></td>
            <td class="column-location"><?php echo esc_html($session['location'] ?: '-'); ?></td>
            <td class="column-price-mod" style="white-space: nowrap;">
                <?php
                $modifier = (int) ($session['price_modifier'] ?? 0);
                if ($modifier !== 0):
                    $sign = $modifier > 0 ? '+' : '';
                    echo esc_html($sign . number_format($modifier / 100, 2, ',', '.'));
                else:
                    echo '-';
                endif;
                ?>
            </td>
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

        $table = $wpdb->prefix . self::REGISTRATIONS_TABLE;

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

        // Count present from attendance table
        $presentUserIds = $this->attendanceRepository->getPresentUserIds($sessionId);
        $presentCount = count($presentUserIds);

        return [
            'presentCount' => $presentCount,
            'totalCount' => $totalCount,
        ];
    }

    // =========================================================================
    // Admin List Columns
    // =========================================================================

    /**
     * Define admin list columns.
     *
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public function defineListColumns(array $columns): array
    {
        $newColumns = [];
        $newColumns['title'] = __('Editie', 'stride');
        $newColumns['course'] = __('Cursus', 'stride');
        $newColumns['format'] = __('Formaat', 'stride');
        $newColumns['start_date'] = __('Startdatum', 'stride');
        $newColumns['venue'] = __('Locatie', 'stride');
        $newColumns['capacity'] = __('Capaciteit', 'stride');
        $newColumns['status'] = __('Status', 'stride');

        return $newColumns;
    }

    /**
     * Render admin list column content.
     */
    public function renderListColumn(string $column, int $postId): void
    {
        switch ($column) {
            case 'course':
                $courseId = (int) $this->editionRepository->getField($postId, 'course_id', 0);
                if ($courseId) {
                    $courseTitle = get_the_title($courseId);
                    $editUrl = get_edit_post_link($courseId);
                    if ($editUrl) {
                        echo '<a href="' . esc_url($editUrl) . '">' . esc_html($courseTitle) . '</a>';
                    } else {
                        echo esc_html($courseTitle);
                    }
                } else {
                    echo '<span style="color:#999;">—</span>';
                }
                break;

            case 'format':
                $courseId = (int) $this->editionRepository->getField($postId, 'course_id', 0);
                $isOnline = false;
                if ($courseId) {
                    $cats = get_the_terms($courseId, 'stride_format');
                    if ($cats && !is_wp_error($cats)) {
                        foreach ($cats as $cat) {
                            if (in_array($cat->slug, ['online', 'webinar', 'e-learning'], true)) {
                                $isOnline = true;
                                break;
                            }
                        }
                    }
                }
                echo $isOnline
                    ? '<span style="color:#0284c7">● Online</span>'
                    : '<span style="color:#7c3aed">● Klassikaal</span>';
                break;

            case 'start_date':
                $startDate = $this->editionRepository->getField($postId, 'start_date', '');
                $endDate = $this->editionRepository->getField($postId, 'end_date', '');
                if ($startDate) {
                    echo esc_html(date_i18n('j M Y', strtotime($startDate)));
                    if ($endDate && $endDate !== $startDate) {
                        echo ' – ' . esc_html(date_i18n('j M Y', strtotime($endDate)));
                    }
                } else {
                    echo '<span style="color:#999;">—</span>';
                }
                break;

            case 'venue':
                $venue = $this->editionRepository->getField($postId, 'venue', '');
                echo $venue ? esc_html($venue) : '<span style="color:#999;">—</span>';
                break;

            case 'capacity':
                $capacity = (int) $this->editionRepository->getField($postId, 'capacity', 0);
                $registrations = $this->editionService->getRegisteredCount($postId);

                if ($capacity > 0) {
                    $percentage = min(100, round(($registrations / $capacity) * 100));
                    $color = $percentage >= 100 ? '#d63638' : ($percentage >= 80 ? '#dba617' : '#00a32a');
                    echo '<span style="color:' . $color . ';font-weight:500;">' . $registrations . '/' . $capacity . '</span>';
                } else {
                    echo '<span>' . $registrations . '</span>';
                }
                break;

            case 'status':
                $status = $this->editionRepository->getField($postId, 'status', 'draft');
                $statusLabels = [
                    'draft'        => ['label' => __('Concept', 'stride'),         'color' => '#787c82'],
                    'announcement' => ['label' => __('Vooraankondiging', 'stride'), 'color' => '#dba617'],
                    'open'         => ['label' => __('Open', 'stride'),            'color' => '#00a32a'],
                    'few_spots'    => ['label' => __('Bijna volzet', 'stride'),    'color' => '#dba617'],
                    'full'         => ['label' => __('Volzet', 'stride'),          'color' => '#dba617'],
                    'in_progress'  => ['label' => __('Lopend', 'stride'),          'color' => '#2271b1'],
                    'postponed'    => ['label' => __('Uitgesteld', 'stride'),      'color' => '#dba617'],
                    'cancelled'    => ['label' => __('Geannuleerd', 'stride'),     'color' => '#d63638'],
                    'completed'    => ['label' => __('Afgerond', 'stride'),        'color' => '#646970'],
                    'archived'     => ['label' => __('Gearchiveerd', 'stride'),    'color' => '#787c82'],
                ];
                $config = $statusLabels[$status] ?? ['label' => ucfirst($status), 'color' => '#787c82'];
                echo '<span style="display:inline-block;padding:2px 8px;border-radius:3px;background:' . $config['color'] . '20;color:' . $config['color'] . ';font-size:12px;">';
                echo esc_html($config['label']);
                echo '</span>';
                break;
        }
    }

    /**
     * Define sortable columns.
     *
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public function defineSortableColumns(array $columns): array
    {
        $columns['start_date'] = 'start_date';
        $columns['status'] = 'status';
        return $columns;
    }

    /**
     * Handle sorting by custom meta columns.
     */
    public function handleColumnSorting(\WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        if ($query->get('post_type') !== EditionCPT::POST_TYPE) {
            return;
        }

        $orderby = $query->get('orderby');

        // Meta keys use _ntdst_ prefix as defined in EditionCPT
        if ($orderby === 'start_date') {
            $query->set('meta_key', '_ntdst_start_date');
            $query->set('orderby', 'meta_value');
        } elseif ($orderby === 'status') {
            $query->set('meta_key', '_ntdst_status');
            $query->set('orderby', 'meta_value');
        }
    }
}
