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
    private const NONCE_ACTION = 'stride_save_questionnaire';
    private const NONCE_FIELD  = 'stride_questionnaire_nonce';

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

        // jQuery UI sortable (bundled with WP)
        wp_enqueue_script('jquery-ui-sortable');

        $basePath = dirname(__DIR__, 3); // stride-core root (Questionnaire/Admin -> Modules -> stride-core)
        $cssFile  = $basePath . '/assets/css/admin/questionnaire-builder.css';
        $jsFile   = $basePath . '/assets/js/admin/questionnaire-builder.js';

        if (file_exists($cssFile)) {
            wp_enqueue_style(
                'stride-questionnaire-builder',
                plugins_url('assets/css/admin/questionnaire-builder.css', $basePath . '/stride-core.php'),
                ['select2'],
                (string) filemtime($cssFile)
            );
        }

        if (file_exists($jsFile)) {
            wp_enqueue_script(
                'stride-questionnaire-builder',
                plugins_url('assets/js/admin/questionnaire-builder.js', $basePath . '/stride-core.php'),
                ['jquery', 'select2', 'jquery-ui-sortable'],
                (string) filemtime($jsFile),
                true
            );

            $fieldTypes = $this->getFieldTypes();
            $stages     = $this->getStages();

            wp_localize_script('stride-questionnaire-builder', 'strideQuestionnaire', [
                'assignments'    => $this->getAssignmentOptions(),
                'userMetaFields' => array_keys($this->getUserMetaFieldNames()),
                'fieldTypes'     => array_map(
                    static fn(array $t) => ['label' => $t['label'], 'color' => $t['color']],
                    $fieldTypes
                ),
                'stages'         => $stages,
                'i18n'           => [
                    'confirmDeleteGroup' => __('Groep en alle velden verwijderen?', 'stride'),
                    'confirmDeleteField' => __('Veld verwijderen?', 'stride'),
                    'searchPlaceholder'  => __('Zoek editie of traject...', 'stride'),
                    'noResults'          => __('Geen resultaten', 'stride'),
                    'userMetaWarning'    => __('Let op: dit veld overschrijft gebruikersgegevens bij inschrijving.', 'stride'),
                    'addField'           => __('+ Veld toevoegen', 'stride'),
                    'addGroup'           => __('+ Groep toevoegen', 'stride'),
                ],
            ]);
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

        $rawGroups = $_POST['stride_questionnaire_groups'] ?? [];
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
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        $repo        = ntdst_get(QuestionnaireRepository::class);
        $groups      = $repo->getAllGroups();
        $assignments = $this->getAssignmentOptions();
        $fieldTypes  = $this->getFieldTypes();
        $stages      = $this->getStages();

        ?>
        <div class="wrap stride-questionnaire-wrap">
            <h1><?= esc_html__('Formuliervelden', 'stride') ?></h1>
            <p class="description">
                <?= esc_html__('Beheer vragenlijsten en extra velden voor inschrijf-, intake- en evaluatieformulieren. Maak groepen aan en wijs ze toe aan edities of trajecten.', 'stride') ?>
            </p>

            <?php settings_errors('stride_questionnaire'); ?>

            <form method="post" id="stride-questionnaire-form">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>

                <div id="stride-questionnaire-container">
                    <?php
                    if (!empty($groups)) {
                        foreach ($groups as $gi => $group) {
                            $this->renderGroup($gi, $group, $fieldTypes, $stages, $assignments);
                        }
                    }
                    ?>
                </div>

                <p class="stride-add-group-wrap">
                    <button type="button" class="button button-primary" id="stride-add-group">
                        <?= esc_html__('+ Groep toevoegen', 'stride') ?>
                    </button>
                </p>

                <?php submit_button(__('Wijzigingen opslaan', 'stride')); ?>
            </form>

            <!-- Group template for JS -->
            <script type="text/template" id="stride-group-template">
                <?php $this->renderGroup('__GI__', [], $fieldTypes, $stages, $assignments); ?>
            </script>

            <!-- Field card template for JS -->
            <script type="text/template" id="stride-field-card-template">
                <?php $this->renderFieldCard('__GI__', '__FI__', [], $fieldTypes); ?>
            </script>
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

                    $sanitizedField = [
                        'label'    => sanitize_text_field($field['label'] ?? ''),
                        'name'     => sanitize_key($field['name'] ?? ''),
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
     * Returns the 7 supported field types with label and badge color.
     *
     * @return array<string, array{label: string, color: string}>
     */
    private function getFieldTypes(): array
    {
        return [
            'text'        => ['label' => __('Tekst', 'stride'),        'color' => '#2271b1'],
            'textarea'    => ['label' => __('Tekstveld', 'stride'),     'color' => '#135e96'],
            'select'      => ['label' => __('Selectie', 'stride'),      'color' => '#8c5e10'],
            'radio'       => ['label' => __('Keuze', 'stride'),         'color' => '#6c3483'],
            'scale'       => ['label' => __('Schaal', 'stride'),        'color' => '#0d7a3e'],
            'checkbox'    => ['label' => __('Vinkje', 'stride'),        'color' => '#b26200'],
            'description' => ['label' => __('Beschrijving', 'stride'),  'color' => '#666666'],
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
}
