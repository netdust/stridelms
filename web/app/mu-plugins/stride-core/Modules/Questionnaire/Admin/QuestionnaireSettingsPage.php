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

        // Matches the existing enqueue pattern in this file: from
        // Modules/Questionnaire/Admin, go up 3 levels to stride-core root.
        $basePath = dirname(__DIR__, 3);
        $cssFile  = $basePath . '/assets/css/admin/questionnaire-builder-v2.css';
        $jsFile   = $basePath . '/assets/js/admin/questionnaire-builder-v2.js';

        // The admin-dashboard.css stylesheet defines all --sd-* tokens.
        // AdminDashboardService registers it at handle `stride-admin-dashboard`.
        if (wp_style_is('stride-admin-dashboard', 'registered')) {
            wp_enqueue_style('stride-admin-dashboard');
        }

        // Alpine.js — AdminDashboardService registers it at handle `alpinejs`
        // (3.14.9, CDN, defer). Register here if we're outside its scope so the
        // call is idempotent.
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

        if (file_exists($cssFile)) {
            wp_enqueue_style(
                'stride-questionnaire-builder-v2',
                plugins_url('assets/css/admin/questionnaire-builder-v2.css', $basePath . '/stride-core.php'),
                [],
                (string) filemtime($cssFile)
            );
        }

        if (file_exists($jsFile)) {
            wp_enqueue_script(
                'stride-questionnaire-builder-v2',
                plugins_url('assets/js/admin/questionnaire-builder-v2.js', $basePath . '/stride-core.php'),
                ['jquery', 'jquery-ui-sortable'],
                (string) filemtime($jsFile),
                true
            );

            wp_localize_script(
                'stride-questionnaire-builder-v2',
                'strideQuestionnaireState',
                $this->getStateJson()
            );
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

    private function renderGroup($gi, array $group, array $fieldTypes, array $stages, array $assignments): void
    {
        $label      = $group['label'] ?? '';
        $stage      = $group['stage'] ?? 'enrollment_personal';
        $groupId    = $group['id'] ?? 'qg_new_' . $gi;
        $assigned   = $group['assignments'] ?? [];
        $fields     = $group['fields'] ?? [];
        $isNew      = empty($group);
        $collapsed  = !$isNew; // new groups start expanded, existing collapsed

        $stageLabel = $stages[$stage] ?? $stage;
        $fieldCount = count($fields);
        $assignCount = count($assigned);

        // Stage badge color map
        $stageBadgeColors = [
            'interest'            => '#0d7a3e',
            'enrollment_personal' => '#2271b1',
            'enrollment_billing'  => '#135e96',
            'intake'              => '#6c3483',
            'evaluation'          => '#8c5e10',
        ];
        $stageBadgeColor = $stageBadgeColors[$stage] ?? '#666';

        $blockClass = 'stride-group-block' . ($collapsed ? ' is-collapsed' : '');
        $toggleIcon = $collapsed ? '&#9658;' : '&#9660;'; // ▸ or ▾

        ?>
        <div class="<?= esc_attr($blockClass) ?>" data-group-index="<?= esc_attr((string) $gi) ?>">
            <input type="hidden"
                   name="stride_questionnaire_groups[<?= esc_attr((string) $gi) ?>][id]"
                   value="<?= esc_attr($groupId) ?>">

            <div class="stride-group-header">
                <span class="stride-drag-handle" title="<?= esc_attr__('Versleep om te sorteren', 'stride') ?>">&#9776;</span>

                <span class="stride-group-label-preview">
                    <?= $label ? esc_html($label) : esc_html__('Nieuwe groep', 'stride') ?>
                </span>

                <span class="stride-stage-badge"
                      style="background-color: <?= esc_attr($stageBadgeColor) ?>;"
                      data-stage="<?= esc_attr($stage) ?>">
                    <?= esc_html($stageLabel) ?>
                </span>

                <span class="stride-assignment-count">
                    <?php
                    if ($assignCount > 0) {
                        /* translators: %d: number of assignments */
                        echo esc_html(sprintf(_n('%d toewijzing', '%d toewijzingen', $assignCount, 'stride'), $assignCount));
                    } else {
                        echo esc_html__('Niet toegewezen', 'stride');
                    }
                    ?>
                </span>

                <button type="button" class="stride-group-toggle" title="<?= esc_attr__('In-/uitklappen', 'stride') ?>">
                    <?= $toggleIcon ?>
                </button>

                <button type="button" class="stride-remove-group" title="<?= esc_attr__('Verwijder groep', 'stride') ?>">&times;</button>
            </div>

            <div class="stride-group-body" <?= $collapsed ? 'style="display:none;"' : '' ?>>
                <!-- Group label input -->
                <div class="stride-group-label-row">
                    <label>
                        <span><?= esc_html__('Groepsnaam:', 'stride') ?></span>
                        <input type="text"
                               name="stride_questionnaire_groups[<?= esc_attr((string) $gi) ?>][label]"
                               value="<?= esc_attr($label) ?>"
                               class="stride-group-label-input regular-text"
                               placeholder="<?= esc_attr__('bijv. Medische gegevens', 'stride') ?>">
                    </label>
                </div>

                <!-- Stage dropdown -->
                <div class="stride-stage-row">
                    <label>
                        <span><?= esc_html__('Fase:', 'stride') ?></span>
                        <select name="stride_questionnaire_groups[<?= esc_attr((string) $gi) ?>][stage]"
                                class="stride-stage-select">
                            <?php foreach ($stages as $value => $stageText) : ?>
                                <option value="<?= esc_attr($value) ?>" <?php selected($stage, $value); ?>>
                                    <?= esc_html($stageText) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <!-- Assignments Select2 -->
                <div class="stride-assignments-row">
                    <label class="stride-assignments-label"><?= esc_html__('Toegewezen aan:', 'stride') ?></label>
                    <select name="stride_questionnaire_groups[<?= esc_attr((string) $gi) ?>][assignments][]"
                            class="stride-assignments-select"
                            multiple="multiple"
                            data-placeholder="<?= esc_attr__('Selecteer edities of trajecten...', 'stride') ?>">
                        <?php foreach ($assignments as $optgroup) : ?>
                            <optgroup label="<?= esc_attr($optgroup['label']) ?>">
                                <?php foreach ($optgroup['options'] as $opt) : ?>
                                    <option value="<?= esc_attr((string) $opt['value']) ?>"
                                        <?php selected(in_array($opt['value'], $assigned, false)); ?>>
                                        <?= esc_html($opt['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Field cards -->
                <div class="stride-field-cards" data-group-index="<?= esc_attr((string) $gi) ?>">
                    <?php
                    if (!empty($fields)) {
                        foreach ($fields as $fi => $field) {
                            $this->renderFieldCard($gi, $fi, $field, $fieldTypes);
                        }
                    }
                    ?>
                </div>

                <!-- Type picker + add field button -->
                <div class="stride-add-field-wrap">
                    <button type="button" class="button stride-add-field-btn">
                        <?= esc_html__('+ Veld toevoegen', 'stride') ?>
                    </button>
                    <div class="stride-type-picker" style="display:none;">
                        <?php foreach ($fieldTypes as $typeKey => $typeDef) : ?>
                            <button type="button"
                                    class="stride-type-pick-btn"
                                    data-type="<?= esc_attr($typeKey) ?>"
                                    style="border-color: <?= esc_attr($typeDef['color']) ?>; color: <?= esc_attr($typeDef['color']) ?>;">
                                <?= esc_html($typeDef['label']) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function renderFieldCard($gi, $fi, array $field, array $fieldTypes): void
    {
        $label    = $field['label'] ?? '';
        $name     = $field['name'] ?? '';
        $type     = $field['type'] ?? 'text';
        $options  = $field['options'] ?? '';
        $required = !empty($field['required']);
        $min      = isset($field['min']) ? (int) $field['min'] : 1;
        $max      = isset($field['max']) ? (int) $field['max'] : 5;
        $isNew    = empty($field);

        $typeDef  = $fieldTypes[$type] ?? $fieldTypes['text'];
        $typeColor = $typeDef['color'];
        $typeLabel = $typeDef['label'];

        $prefix = "stride_questionnaire_groups[{$gi}][fields][{$fi}]";

        // New field cards start expanded, existing start collapsed
        $cardCollapsed = !$isNew;
        $cardClass = 'stride-field-card' . ($cardCollapsed ? ' is-collapsed' : '');

        ?>
        <div class="<?= esc_attr($cardClass) ?>" data-type="<?= esc_attr($type) ?>" data-field-index="<?= esc_attr((string) $fi) ?>">
            <div class="stride-field-card-header">
                <span class="stride-drag-handle" title="<?= esc_attr__('Versleep om te sorteren', 'stride') ?>">&#9776;</span>

                <span class="stride-type-pill"
                      style="background-color: <?= esc_attr($typeColor) ?>;"
                      data-type="<?= esc_attr($type) ?>">
                    <?= esc_html($typeLabel) ?>
                </span>

                <span class="stride-field-label-preview">
                    <?= $label ? esc_html($label) : esc_html__('Nieuw veld', 'stride') ?>
                </span>

                <button type="button" class="stride-remove-field" title="<?= esc_attr__('Verwijder veld', 'stride') ?>">&times;</button>
            </div>

            <div class="stride-field-card-body" <?= $cardCollapsed ? 'style="display:none;"' : '' ?>>
                <!-- Hidden type field -->
                <input type="hidden"
                       name="<?= esc_attr($prefix) ?>[type]"
                       class="stride-field-type-input"
                       value="<?= esc_attr($type) ?>">

                <!-- Label — for description type this acts as content textarea -->
                <div class="stride-field-config-row stride-config-label" data-show-for="text textarea select radio scale checkbox description">
                    <?php if ($type === 'description') : ?>
                        <label>
                            <span><?= esc_html__('Inhoud:', 'stride') ?></span>
                            <textarea name="<?= esc_attr($prefix) ?>[label]"
                                      class="stride-field-label-text large-text"
                                      rows="3"><?= esc_textarea($label) ?></textarea>
                        </label>
                    <?php else : ?>
                        <label>
                            <span><?= esc_html__('Label:', 'stride') ?></span>
                            <input type="text"
                                   name="<?= esc_attr($prefix) ?>[label]"
                                   value="<?= esc_attr($label) ?>"
                                   class="stride-field-label-text regular-text"
                                   placeholder="<?= esc_attr__('bijv. BIG-nummer', 'stride') ?>">
                        </label>
                    <?php endif; ?>
                </div>

                <!-- Name — NOT for description -->
                <div class="stride-field-config-row stride-config-name" data-show-for="text textarea select radio scale checkbox">
                    <label>
                        <span><?= esc_html__('Veldnaam (technisch):', 'stride') ?></span>
                        <input type="text"
                               name="<?= esc_attr($prefix) ?>[name]"
                               value="<?= esc_attr($name) ?>"
                               class="stride-field-name-input regular-text"
                               placeholder="<?= esc_attr__('big_nummer', 'stride') ?>">
                    </label>
                </div>

                <!-- Options — for select and radio only -->
                <div class="stride-field-config-row stride-config-options" data-show-for="select radio">
                    <label>
                        <span><?= esc_html__('Opties (kommagescheiden):', 'stride') ?></span>
                        <input type="text"
                               name="<?= esc_attr($prefix) ?>[options]"
                               value="<?= esc_attr($options) ?>"
                               class="stride-field-options-input large-text"
                               placeholder="<?= esc_attr__('Optie 1, Optie 2, Optie 3', 'stride') ?>">
                    </label>
                </div>

                <!-- Scale min/max — for scale only -->
                <div class="stride-field-config-row stride-config-scale" data-show-for="scale">
                    <label>
                        <span><?= esc_html__('Minimum:', 'stride') ?></span>
                        <input type="number"
                               name="<?= esc_attr($prefix) ?>[min]"
                               value="<?= esc_attr((string) $min) ?>"
                               class="stride-field-min-input small-text"
                               min="0"
                               max="100">
                    </label>
                    <label>
                        <span><?= esc_html__('Maximum:', 'stride') ?></span>
                        <input type="number"
                               name="<?= esc_attr($prefix) ?>[max]"
                               value="<?= esc_attr((string) $max) ?>"
                               class="stride-field-max-input small-text"
                               min="1"
                               max="100">
                    </label>
                </div>

                <!-- Required — for text, textarea, select, radio, scale only -->
                <div class="stride-field-config-row stride-config-required" data-show-for="text textarea select radio scale">
                    <label class="stride-inline-label">
                        <input type="checkbox"
                               name="<?= esc_attr($prefix) ?>[required]"
                               value="1"
                               <?php checked($required); ?>>
                        <span><?= esc_html__('Verplicht veld', 'stride') ?></span>
                    </label>
                </div>
            </div>
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

    /**
     * Field names that map to user meta (and will overwrite on enrollment).
     *
     * Delegates to {@see \Stride\Modules\Enrollment\EnrollmentService::getUserMetaMapping()}
     * so the admin "reserved name" warning and the actual persistence path
     * read from one source.
     *
     * @return array<string, string>
     */
    private function getUserMetaFieldNames(): array
    {
        return \Stride\Modules\Enrollment\EnrollmentService::getUserMetaMapping();
    }

    /**
     * Render the "Systeemvelden" help panel at the top of the form-builder.
     *
     * Discoverability for the reserved-names mechanism: when admin gives a
     * field one of these names, its value automatically persists to the
     * user's profile (wp_usermeta) in addition to the per-enrollment snapshot.
     */
    private function renderSystemFieldsHelp(): void
    {
        $groups = [
            __('Persoonlijk', 'stride') => [
                'phone'                       => __('Telefoonnummer', 'stride'),
                'organisation'                => __('Organisatie / werkgever', 'stride'),
                'department'                  => __('Afdeling', 'stride'),
                'national_id'                 => __('Rijksregisternummer', 'stride'),
                'date_of_birth'               => __('Geboortedatum', 'stride'),
                'professional_license_number' => __('Erkenningsnummer / RIZIV', 'stride'),
            ],
            __('Facturatie', 'stride') => [
                'company'       => __('Bedrijfsnaam (factuur)', 'stride'),
                'vat_number'    => __('BTW-nummer', 'stride'),
                'address'       => __('Factuuradres', 'stride'),
                'postal_code'   => __('Postcode', 'stride'),
                'city'          => __('Stad', 'stride'),
                'invoice_email' => __('Factuur-emailadres', 'stride'),
                'gln_number'    => __('GLN-nummer', 'stride'),
            ],
        ];
        ?>
        <details class="stride-system-fields-help" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:0;margin:16px 0;">
            <summary style="cursor:pointer;padding:12px 16px;font-weight:600;list-style:none;">
                <span class="dashicons dashicons-info-outline" style="color:#2271b1;"></span>
                <?= esc_html__('Systeemvelden — namen die opgeslagen worden op het gebruikersprofiel', 'stride') ?>
                <span style="font-weight:400;color:#646970;font-size:12px;">
                    <?= esc_html__('(klik om uit te klappen)', 'stride') ?>
                </span>
            </summary>
            <div style="padding:0 16px 16px;">
                <p style="margin-top:0;color:#3c434a;">
                    <?= esc_html__('Als je een veld in dit formulier exact één van onderstaande namen geeft, wordt de waarde automatisch op het profiel van de gebruiker opgeslagen — niet alleen bij deze inschrijving. Zo hoeft de gebruiker bv. zijn rijksregisternummer maar één keer in te vullen, en kan een admin het later terugvinden in het gebruikersbeheer.', 'stride') ?>
                </p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">
                    <?php foreach ($groups as $groupLabel => $fields): ?>
                        <div>
                            <h4 style="margin:0 0 8px;font-size:13px;color:#1d2327;">
                                <?= esc_html($groupLabel) ?>
                            </h4>
                            <table class="widefat" style="border:0;">
                                <tbody>
                                    <?php foreach ($fields as $name => $label): ?>
                                        <tr>
                                            <td style="border:0;padding:4px 8px 4px 0;width:1%;white-space:nowrap;">
                                                <code style="background:#f0f0f1;padding:2px 6px;border-radius:3px;font-size:12px;">
                                                    <?= esc_html($name) ?>
                                                </code>
                                            </td>
                                            <td style="border:0;padding:4px 0;color:#3c434a;font-size:12px;">
                                                <?= esc_html($label) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p style="margin:12px 0 0;color:#646970;font-size:12px;">
                    <?= esc_html__('Gebruik je een andere naam? Dan blijft de waarde enkel bij deze ene inschrijving bewaard.', 'stride') ?>
                </p>
            </div>
        </details>
        <?php
    }
}
