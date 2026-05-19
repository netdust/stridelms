<?php

declare(strict_types=1);

namespace Stride\Modules\Questionnaire\Admin;

use Stride\Modules\Questionnaire\QuestionnaireRepository;

/**
 * Questionnaire Settings Page.
 *
 * Admin page for managing questionnaire field groups.
 * Groups are collapsible, fields use card-based UI with type picker.
 * Replaces the old FieldGroupSettingsPage table-row approach.
 *
 * Plain class — owned by the Questionnaire module.
 */
final class QuestionnaireSettingsPage
{
    private const PAGE_SLUG    = 'stride-questionnaire';
    private const CAPABILITY   = 'manage_options';
    public const NONCE_ACTION = 'stride_save_questionnaire';
    public const NONCE_FIELD  = 'stride_questionnaire_nonce';

    /**
     * Field names that must never reach the form-builder: writing to them
     * from an enrollment form would mean privilege escalation, session
     * hijacking, or password reset.
     */
    private const DENIED_FIELD_NAMES = [
        'wp_capabilities',
        'wp_user_level',
        'session_tokens',
        'user_pass',
        'user_login',
        'user_email',
        'user_activation_key',
        'user_registered',
        'user_status',
        'spam',
        'deleted',
        'primary_blog',
        'source_domain',
    ];

    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        add_action('admin_menu', [$this, 'registerPage'], 20);
        add_action('admin_init', [$this, 'handleSave']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_head', [$this, 'inlineHeadAssets']);
    }

    public function registerPage(): void
    {
        add_submenu_page(
            'stride-dashboard',
            'Formuliervelden',
            'Formuliervelden',
            self::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if (!str_contains($hook, self::PAGE_SLUG)) {
            return;
        }

        // Alpine.js — AdminDashboardService registers/enqueues it only on the
        // dashboard top-level page, so we need to enqueue it ourselves here.
        if (!wp_script_is('alpinejs', 'registered')) {
            wp_register_script(
                'alpinejs',
                'https://cdn.jsdelivr.net/npm/alpinejs@3.14.9/dist/cdn.min.js',
                [],
                '3.14.9',
                true
            );
            wp_script_add_data('alpinejs', 'defer', true);
        }
        wp_enqueue_script('alpinejs');

        wp_enqueue_script('jquery-ui-sortable');

        // CSS (tokens + v2 styles) and the component JS are inlined via
        // admin_head in inlineHeadAssets() — both must run before Alpine's
        // deferred CDN script does. See [[gotcha_alpine_script_load_order]].
    }

    /**
     * Inline CSS tokens, builder CSS, the Alpine component, and the seed
     * state into <head>. Inlining (vs enqueuing) is required so:
     *   - The --sd-* token CSS arrives even though AdminDashboardService
     *     only injects its admin-dashboard.css on the dashboard top-level
     *     page (isStridePage() returns false here).
     *   - The component-registration script runs before Alpine's deferred
     *     CDN script fires `alpine:init`, so `questionnaireBuilder` is
     *     defined when Alpine looks it up. See
     *     [[gotcha_alpine_script_load_order]].
     */
    public function inlineHeadAssets(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !str_contains((string) $screen->id, self::PAGE_SLUG)) {
            return;
        }

        $basePath      = dirname(__DIR__, 3);
        $tokensCssFile = $basePath . '/assets/css/admin-dashboard.css';
        $builderCssFile = $basePath . '/assets/css/admin/questionnaire-builder-v2.css';
        $jsFile        = $basePath . '/assets/js/admin/questionnaire-builder-v2.js';

        if (file_exists($tokensCssFile)) {
            echo '<style id="stride-dashboard-tokens">';
            include $tokensCssFile;
            echo '</style>';
        }

        if (file_exists($builderCssFile)) {
            echo '<style id="stride-questionnaire-builder-v2-css">';
            include $builderCssFile;
            echo '</style>';
        }

        if (file_exists($jsFile)) {
            echo '<script id="stride-questionnaire-state">';
            echo 'window.strideQuestionnaireState = ' . wp_json_encode($this->getStateJson()) . ';';
            echo '</script>';

            echo '<script id="stride-questionnaire-builder-v2">';
            include $jsFile;
            echo '</script>';
        }
    }

    public function handleSave(): void
    {
        if (!isset($_POST[self::NONCE_FIELD])) {
            return;
        }

        if (!wp_verify_nonce($_POST[self::NONCE_FIELD], self::NONCE_ACTION)) {
            return;
        }

        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        // v2 builder posts as JSON; legacy (pre-v2) posted as nested form arrays
        $rawGroups = isset($_POST['stride_questionnaire_groups_json'])
            ? $this->parseSubmittedGroups((string) wp_unslash($_POST['stride_questionnaire_groups_json']))
            : (array) ($_POST['stride_questionnaire_groups'] ?? []);
        $sanitized = $this->sanitizeGroups($rawGroups);

        $repo = ntdst_get(QuestionnaireRepository::class);
        $repo->saveGroups($sanitized);

        add_settings_error(
            'stride_questionnaire',
            'saved',
            __('Formuliervelden opgeslagen.', 'stride'),
            'updated'
        );
    }

    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Geen toegang.', 'stride'));
        }

        ?>
        <div class="wrap">
            <h1 style="margin-bottom:16px"><?php esc_html_e('Vragenlijst', 'stride'); ?></h1>
            <?php
            settings_errors('stride_questionnaire');
            include __DIR__ . '/templates/builder.php';
            ?>
        </div>
        <?php
    }

    /**
     * Decode the v2 builder's JSON payload back into the array shape
     * sanitizeGroups() expects. Returns [] on malformed input — the
     * existing sanitizer treats [] as "no groups", which is safe.
     */
    private function parseSubmittedGroups(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        return $decoded;
    }

    private function sanitizeGroups($rawGroups): array
    {
        if (!is_array($rawGroups)) {
            return [];
        }

        $validTypes  = ['text', 'textarea', 'select', 'radio', 'checkbox', 'scale', 'description'];
        $validStages = QuestionnaireRepository::STAGES;

        $maxId = 0;
        foreach ($rawGroups as $g) {
            if (preg_match('/^qg_(\d+)$/', $g['id'] ?? '', $m)) {
                $maxId = max($maxId, (int) $m[1]);
            }
        }
        $nextId = $maxId + 1;

        $sanitized = [];

        foreach ($rawGroups as $group) {
            $label = sanitize_text_field($group['label'] ?? '');
            if (empty($label)) {
                continue;
            }

            $id = sanitize_text_field($group['id'] ?? '');
            if (empty($id) || str_starts_with($id, 'qg_new_')) {
                $id = 'qg_' . $nextId;
            }
            $nextId++;

            $stage = in_array($group['stage'] ?? '', $validStages, true)
                ? $group['stage']
                : 'enrollment_personal';

            // Sanitize assignments: integers or wildcards
            $assignments = [];
            if (is_array($group['assignments'] ?? null)) {
                foreach ($group['assignments'] as $val) {
                    if (in_array($val, ['_all_editions', '_all_trajectories'], true)) {
                        $assignments[] = $val;
                    } else {
                        $intVal = absint($val);
                        if ($intVal > 0) {
                            $assignments[] = $intVal;
                        }
                    }
                }
            }

            // Sanitize fields
            $fields = [];
            if (is_array($group['fields'] ?? null)) {
                foreach ($group['fields'] as $field) {
                    $fieldType = in_array($field['type'] ?? '', $validTypes, true)
                        ? $field['type']
                        : 'text';

                    // Description fields only need label (used as content)
                    if ($fieldType === 'description') {
                        if (empty($field['label'])) {
                            continue;
                        }
                        $fields[] = [
                            'type'  => 'description',
                            'label' => wp_kses_post($field['label'] ?? ''),
                        ];
                        continue;
                    }

                    // All other types need at least label or name
                    if (empty($field['label']) && empty($field['name'])) {
                        continue;
                    }

                    $fieldName = sanitize_key($field['name'] ?? '');

                    // Names matching getUserMetaMapping() intentionally persist
                    // to wp_usermeta (documented "system fields"). But certain
                    // WP-internal keys must NEVER be writable from a form, or
                    // an admin could craft an enrollment that escalates privs
                    // or hijacks the session.
                    if ($fieldName !== '' && in_array($fieldName, self::DENIED_FIELD_NAMES, true)) {
                        // Skip this field entirely. Admin sees it disappear on
                        // save — clearer than a silent rename.
                        continue;
                    }

                    $sanitizedField = [
                        'label'    => sanitize_text_field($field['label'] ?? ''),
                        'name'     => $fieldName,
                        'type'     => $fieldType,
                        'required' => !empty($field['required']),
                    ];

                    if (!empty($field['help'])) {
                        $sanitizedField['help'] = sanitize_text_field($field['help']);
                    }

                    // Options (select/radio)
                    if (in_array($fieldType, ['select', 'radio'], true)) {
                        $sanitizedField['options'] = sanitize_text_field($field['options'] ?? '');
                    }

                    // Scale min/max
                    if ($fieldType === 'scale') {
                        $sanitizedField['min'] = (int) ($field['min'] ?? 1);
                        $sanitizedField['max'] = (int) ($field['max'] ?? 5);
                    }

                    $fields[] = $sanitizedField;
                }
            }

            $sanitized[] = [
                'id'          => $id,
                'label'       => $label,
                'stage'       => $stage,
                'assignments' => $assignments,
                'fields'      => $fields,
            ];
        }

        return $sanitized;
    }

    /**
     * Returns the 7 supported field types with label.
     *
     * @return array<string, array{label: string}>
     */
    private function getFieldTypes(): array
    {
        return [
            'text'        => ['label' => __('Tekst', 'stride')],
            'textarea'    => ['label' => __('Tekstveld', 'stride')],
            'select'      => ['label' => __('Selectie', 'stride')],
            'radio'       => ['label' => __('Keuze', 'stride')],
            'scale'       => ['label' => __('Schaal', 'stride')],
            'checkbox'    => ['label' => __('Vinkje', 'stride')],
            'description' => ['label' => __('Beschrijving', 'stride')],
        ];
    }

    /**
     * Serialize all admin state for Alpine.js hydration.
     *
     * Returned as plain array; caller wraps in wp_localize_script() for the
     * JSON-only path.
     *
     * @return array{
     *     groups: array,
     *     fieldTypes: array<string, array{label: string}>,
     *     stages: array<string, string>,
     *     assignments: array,
     * }
     */
    private function getStateJson(): array
    {
        $repo = ntdst_get(QuestionnaireRepository::class);
        return [
            'groups'      => $repo->getAllGroups(),
            'fieldTypes'  => $this->getFieldTypes(),
            'stages'      => $this->getStages(),
            'assignments' => $this->getAssignmentOptions(),
        ];
    }

    /**
     * Returns the 5 stage options.
     *
     * @return array<string, string>
     */
    private function getStages(): array
    {
        return [
            'interest'            => __('Interesse', 'stride'),
            'waitlist'            => __('Wachtlijst', 'stride'),
            'enrollment_personal' => __('Inschrijving — Persoonlijk', 'stride'),
            'enrollment_billing'  => __('Inschrijving — Facturatie', 'stride'),
            'intake'              => __('Intake (voor opleiding)', 'stride'),
            'evaluation'          => __('Evaluatie (na opleiding)', 'stride'),
        ];
    }

    /**
     * Build assignment options for Select2 (optgroups).
     *
     * @return array<int, array{label: string, options: array<int, array{value: mixed, label: string}>}>
     */
    private function getAssignmentOptions(): array
    {
        $options = [];

        // Wildcards
        $options[] = [
            'label'   => __('Snelkeuze', 'stride'),
            'options' => [
                ['value' => '_all_editions',     'label' => __('Alle edities', 'stride')],
                ['value' => '_all_trajectories', 'label' => __('Alle trajecten', 'stride')],
            ],
        ];

        // Editions
        $editions = get_posts([
            'post_type'      => 'vad_edition',
            'posts_per_page' => 500,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'post_status'    => ['publish', 'draft'],
        ]);

        $editionOpts = [];
        foreach ($editions as $edition) {
            $editionModel = ntdst_data()->get('vad_edition');
            $courseId    = (int) ($editionModel->getMeta($edition->ID, 'course_id') ?: 0);
            $courseTitle = $courseId ? get_the_title($courseId) : $edition->post_title;
            $startDate   = $editionModel->getMeta($edition->ID, 'start_date') ?: '';
            $dateSuffix  = $startDate ? date_i18n('M Y', strtotime($startDate)) : '';

            $label = $courseTitle;
            if ($dateSuffix) {
                $label .= ' (' . $dateSuffix . ')';
            }

            $editionOpts[] = [
                'value' => $edition->ID,
                'label' => $label,
            ];
        }

        if (!empty($editionOpts)) {
            $options[] = [
                'label'   => __('Edities', 'stride'),
                'options' => $editionOpts,
            ];
        }

        // Trajectories
        $trajectories = get_posts([
            'post_type'      => 'vad_trajectory',
            'posts_per_page' => 500,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'post_status'    => ['publish', 'draft'],
        ]);

        $trajectoryOpts = [];
        foreach ($trajectories as $trajectory) {
            $trajectoryOpts[] = [
                'value' => $trajectory->ID,
                'label' => $trajectory->post_title,
            ];
        }

        if (!empty($trajectoryOpts)) {
            $options[] = [
                'label'   => __('Trajecten', 'stride'),
                'options' => $trajectoryOpts,
            ];
        }

        return $options;
    }
}
