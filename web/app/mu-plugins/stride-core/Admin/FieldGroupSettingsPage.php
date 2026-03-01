<?php

declare(strict_types=1);

namespace Stride\Admin;

use Stride\Modules\Enrollment\EnrollmentFieldGroups;

/**
 * Field Group Settings Page.
 *
 * Admin page for managing reusable enrollment field group templates.
 * Groups can be assigned to editions and/or trajectories.
 *
 * Plain class — owned by EnrollmentService.
 */
final class FieldGroupSettingsPage
{
    private const PAGE_SLUG = 'stride-field-groups';
    private const CAPABILITY = 'manage_options';
    private const NONCE_ACTION = 'stride_save_field_groups';
    private const NONCE_FIELD = 'stride_field_groups_nonce';

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

        $basePath = dirname(__DIR__);
        $cssFile = $basePath . '/assets/css/admin/field-groups.css';
        $jsFile = $basePath . '/assets/js/admin/field-groups.js';

        if (file_exists($cssFile)) {
            wp_enqueue_style(
                'stride-field-groups',
                plugins_url('assets/css/admin/field-groups.css', $basePath . '/stride-core.php'),
                ['select2'],
                (string) filemtime($cssFile)
            );
        }

        if (file_exists($jsFile)) {
            wp_enqueue_script(
                'stride-field-groups',
                plugins_url('assets/js/admin/field-groups.js', $basePath . '/stride-core.php'),
                ['jquery', 'select2', 'jquery-ui-sortable'],
                (string) filemtime($jsFile),
                true
            );

            wp_localize_script('stride-field-groups', 'strideFieldGroups', [
                'assignments' => $this->getAssignmentOptions(),
                'i18n' => [
                    'confirmDeleteGroup' => __('Groep en alle velden verwijderen?', 'stride'),
                    'searchPlaceholder'  => __('Zoek editie of traject...', 'stride'),
                    'noResults'          => __('Geen resultaten', 'stride'),
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

        // Handle migration trigger
        if (isset($_POST['stride_migrate_legacy'])) {
            $service = ntdst_get(EnrollmentFieldGroups::class);
            $count = $service->migrateLegacyData();

            add_settings_error(
                'stride_field_groups',
                'migrated',
                sprintf(__('%d groep(en) gemigreerd vanuit cursus-instellingen.', 'stride'), $count),
                $count > 0 ? 'updated' : 'info'
            );
            return;
        }

        $rawGroups = $_POST['stride_field_groups'] ?? [];
        $sanitized = $this->sanitizeGroups($rawGroups);

        $service = ntdst_get(EnrollmentFieldGroups::class);
        $service->saveGroups($sanitized);

        add_settings_error(
            'stride_field_groups',
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

        $service = ntdst_get(EnrollmentFieldGroups::class);
        $groups = $service->getAllGroups();
        $assignments = $this->getAssignmentOptions();
        $hasLegacy = $this->hasLegacyData();

        $fieldTypes = [
            'text'     => __('Text', 'stride'),
            'textarea' => __('Textarea', 'stride'),
            'select'   => __('Select', 'stride'),
            'checkbox' => __('Checkbox', 'stride'),
        ];
        $steps = [
            'personal' => __('Persoonlijk', 'stride'),
            'billing'  => __('Facturatie', 'stride'),
        ];

        ?>
        <div class="wrap stride-field-groups-wrap">
            <h1><?= esc_html__('Formuliervelden', 'stride') ?></h1>
            <p class="description">
                <?= esc_html__('Beheer extra velden voor het inschrijfformulier. Maak groepen aan en wijs ze toe aan edities of trajecten.', 'stride') ?>
            </p>

            <?php settings_errors('stride_field_groups'); ?>

            <?php if ($hasLegacy && empty($groups)) : ?>
                <div class="notice notice-info">
                    <p>
                        <?= esc_html__('Er zijn velden gevonden op cursus-niveau (oude indeling). Wil je deze migreren naar deze centrale pagina?', 'stride') ?>
                    </p>
                    <form method="post">
                        <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                        <p>
                            <button type="submit" name="stride_migrate_legacy" value="1" class="button">
                                <?= esc_html__('Migreer bestaande velden', 'stride') ?>
                            </button>
                        </p>
                    </form>
                </div>
            <?php endif; ?>

            <form method="post" id="stride-field-groups-form">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>

                <div id="stride-field-groups-container">
                    <?php
                    if (!empty($groups)) {
                        foreach ($groups as $gi => $group) {
                            $this->renderGroup($gi, $group, $fieldTypes, $steps, $assignments);
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
                <?php $this->renderGroup('__GI__', [], $fieldTypes, $steps, $assignments); ?>
            </script>

            <!-- Field row template for JS -->
            <script type="text/template" id="stride-field-template">
                <?php $this->renderFieldRow('__GI__', '__FI__', [], $fieldTypes); ?>
            </script>
        </div>
        <?php
    }

    private function renderGroup($gi, array $group, array $fieldTypes, array $steps, array $assignments): void
    {
        $label = $group['label'] ?? '';
        $step  = $group['step'] ?? 'personal';
        $groupId = $group['id'] ?? 'fg_new_' . $gi;
        $assigned = $group['assignments'] ?? [];
        $fields = $group['fields'] ?? [];

        ?>
        <div class="stride-group-block" data-group-index="<?= esc_attr($gi) ?>">
            <input type="hidden" name="stride_field_groups[<?= esc_attr($gi) ?>][id]" value="<?= esc_attr($groupId) ?>">

            <div class="stride-group-header">
                <span class="stride-drag-handle" title="<?= esc_attr__('Drag to reorder', 'stride') ?>">&#9776;</span>

                <input type="text"
                       name="stride_field_groups[<?= esc_attr($gi) ?>][label]"
                       value="<?= esc_attr($label) ?>"
                       class="stride-group-label"
                       placeholder="<?= esc_attr__('Groepsnaam (bijv. Medische gegevens)', 'stride') ?>">

                <label class="stride-step-select">
                    <span><?= esc_html__('Stap:', 'stride') ?></span>
                    <select name="stride_field_groups[<?= esc_attr($gi) ?>][step]">
                        <?php foreach ($steps as $value => $labelText) : ?>
                            <option value="<?= esc_attr($value) ?>" <?php selected($step, $value); ?>>
                                <?= esc_html($labelText) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <button type="button" class="stride-remove-group" title="<?= esc_attr__('Verwijder groep', 'stride') ?>">&times;</button>
            </div>

            <div class="stride-group-body">
                <!-- Assignments -->
                <div class="stride-assignments-row">
                    <label class="stride-assignments-label"><?= esc_html__('Toegewezen aan:', 'stride') ?></label>
                    <select name="stride_field_groups[<?= esc_attr($gi) ?>][assignments][]"
                            class="stride-assignments-select"
                            multiple="multiple"
                            data-placeholder="<?= esc_attr__('Selecteer edities of trajecten...', 'stride') ?>">
                        <?php foreach ($assignments as $optgroup) : ?>
                            <optgroup label="<?= esc_attr($optgroup['label']) ?>">
                                <?php foreach ($optgroup['options'] as $opt) : ?>
                                    <option value="<?= esc_attr($opt['value']) ?>"
                                        <?php selected(in_array($opt['value'], $assigned, false)); ?>>
                                        <?= esc_html($opt['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Fields table -->
                <table class="stride-repeater-table widefat">
                    <thead>
                        <tr>
                            <th style="width: 30px;"></th>
                            <th><?= esc_html__('Label', 'stride') ?></th>
                            <th><?= esc_html__('Name', 'stride') ?></th>
                            <th><?= esc_html__('Type', 'stride') ?></th>
                            <th><?= esc_html__('Opties', 'stride') ?></th>
                            <th style="width: 70px;"><?= esc_html__('Verplicht', 'stride') ?></th>
                            <th style="width: 40px;"></th>
                        </tr>
                    </thead>
                    <tbody class="stride-fields-rows">
                        <?php
                        if (!empty($fields)) {
                            foreach ($fields as $fi => $field) {
                                $this->renderFieldRow($gi, $fi, $field, $fieldTypes);
                            }
                        }
                        ?>
                    </tbody>
                </table>

                <p class="stride-add-field-wrap">
                    <button type="button" class="button stride-add-field">
                        <?= esc_html__('+ Veld toevoegen', 'stride') ?>
                    </button>
                </p>
            </div>
        </div>
        <?php
    }

    private function renderFieldRow($gi, $fi, array $field, array $fieldTypes): void
    {
        $label    = $field['label'] ?? '';
        $name     = $field['name'] ?? '';
        $type     = $field['type'] ?? 'text';
        $options  = $field['options'] ?? '';
        $required = !empty($field['required']);

        $prefix = "stride_field_groups[{$gi}][fields][{$fi}]";

        ?>
        <tr class="stride-field-row">
            <td>
                <span class="stride-drag-handle" title="<?= esc_attr__('Drag to reorder', 'stride') ?>">&#9776;</span>
            </td>
            <td>
                <input type="text"
                       name="<?= esc_attr($prefix) ?>[label]"
                       value="<?= esc_attr($label) ?>"
                       class="stride-field-label"
                       placeholder="<?= esc_attr__('bijv. BIG-nummer', 'stride') ?>">
            </td>
            <td>
                <input type="text"
                       name="<?= esc_attr($prefix) ?>[name]"
                       value="<?= esc_attr($name) ?>"
                       class="stride-field-name"
                       placeholder="<?= esc_attr__('big_nummer', 'stride') ?>">
            </td>
            <td>
                <select name="<?= esc_attr($prefix) ?>[type]" class="stride-field-type">
                    <?php foreach ($fieldTypes as $value => $labelText) : ?>
                        <option value="<?= esc_attr($value) ?>" <?php selected($type, $value); ?>>
                            <?= esc_html($labelText) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td>
                <input type="text"
                       name="<?= esc_attr($prefix) ?>[options]"
                       value="<?= esc_attr($options) ?>"
                       class="stride-field-options"
                       placeholder="<?= esc_attr__('Optie 1, Optie 2', 'stride') ?>"
                       style="<?= $type !== 'select' ? 'display:none;' : '' ?>">
                <span class="stride-options-hint" style="<?= $type === 'select' ? 'display:none;' : '' ?>">
                    <?= esc_html__('Alleen bij type Select', 'stride') ?>
                </span>
            </td>
            <td style="text-align: center;">
                <input type="checkbox"
                       name="<?= esc_attr($prefix) ?>[required]"
                       value="1"
                       <?php checked($required); ?>>
            </td>
            <td>
                <span class="stride-remove-row" title="<?= esc_attr__('Verwijder', 'stride') ?>">&times;</span>
            </td>
        </tr>
        <?php
    }

    private function sanitizeGroups($rawGroups): array
    {
        $sanitized = [];

        if (!is_array($rawGroups)) {
            return [];
        }

        $nextId = 1;

        foreach ($rawGroups as $group) {
            $label = sanitize_text_field($group['label'] ?? '');
            if (empty($label)) {
                continue;
            }

            $id = sanitize_text_field($group['id'] ?? '');
            if (empty($id) || str_starts_with($id, 'fg_new_')) {
                $id = 'fg_' . $nextId;
            }
            $nextId++;

            $step = in_array($group['step'] ?? '', ['personal', 'billing'], true)
                ? $group['step']
                : 'personal';

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
                    if (empty($field['label']) && empty($field['name'])) {
                        continue;
                    }

                    $fields[] = [
                        'label'    => sanitize_text_field($field['label'] ?? ''),
                        'name'     => sanitize_key($field['name'] ?? ''),
                        'type'     => in_array($field['type'] ?? '', ['text', 'textarea', 'select', 'checkbox'], true)
                            ? $field['type']
                            : 'text',
                        'options'  => sanitize_text_field($field['options'] ?? ''),
                        'required' => !empty($field['required']),
                    ];
                }
            }

            $sanitized[] = [
                'id'          => $id,
                'label'       => $label,
                'step'        => $step,
                'assignments' => $assignments,
                'fields'      => $fields,
            ];
        }

        return $sanitized;
    }

    /**
     * Build assignment options for Select2 (optgroups).
     */
    private function getAssignmentOptions(): array
    {
        $options = [];

        // Wildcards
        $options[] = [
            'label' => __('Snelkeuze', 'stride'),
            'options' => [
                ['value' => '_all_editions', 'label' => __('Alle edities', 'stride')],
                ['value' => '_all_trajectories', 'label' => __('Alle trajecten', 'stride')],
            ],
        ];

        // Editions
        $editions = get_posts([
            'post_type' => 'vad_edition',
            'posts_per_page' => 100,
            'orderby' => 'title',
            'order' => 'ASC',
            'post_status' => ['publish', 'draft'],
        ]);

        $editionOpts = [];
        foreach ($editions as $edition) {
            $courseId = (int) get_post_meta($edition->ID, '_ntdst_course_id', true);
            $courseTitle = $courseId ? get_the_title($courseId) : '';
            $label = $edition->post_title;
            if ($courseTitle) {
                $label = $courseTitle . ' — ' . $label;
            }

            $editionOpts[] = [
                'value' => $edition->ID,
                'label' => $label,
            ];
        }

        if (!empty($editionOpts)) {
            $options[] = [
                'label' => __('Edities', 'stride'),
                'options' => $editionOpts,
            ];
        }

        // Trajectories
        $trajectories = get_posts([
            'post_type' => 'vad_trajectory',
            'posts_per_page' => 100,
            'orderby' => 'title',
            'order' => 'ASC',
            'post_status' => ['publish', 'draft'],
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
                'label' => __('Trajecten', 'stride'),
                'options' => $trajectoryOpts,
            ];
        }

        return $options;
    }

    /**
     * Check if any courses have legacy field data.
     */
    private function hasLegacyData(): bool
    {
        global $wpdb;

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s)",
            '_stride_enrollment_field_groups',
            '_stride_enrollment_fields'
        ));

        return (int) $count > 0;
    }
}
