<?php
declare(strict_types=1);

/**
 * Auto Metabox Generator
 *
 * Automatically generates metaboxes from registered field definitions
 * Works with NTDST Data.php ORM
 *
 * @package NTDST
 * @version 1.0.0
 */

defined('ABSPATH') || exit;

final class NTDST_MetaboxGenerator
{
    private static ?self $instance = null;
    private array $registered_models = [];

    private function __construct()
    {
        add_action('add_meta_boxes', [$this, 'register_metaboxes']);
        add_action('save_post', [$this, 'save_metabox_data'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_metabox_scripts']);
    }

    /**
     * Enqueue metabox field scripts (relation, gallery, repeater)
     */
    public function enqueue_metabox_scripts(string $hook): void
    {
        // Only on post edit screens
        if (!in_array($hook, ['post.php', 'post-new.php'])) {
            return;
        }

        // Get current post type
        global $post_type;

        // Check if this post type has registered metaboxes
        if (!isset($this->registered_models[$post_type])) {
            return;
        }

        // Enqueue theme-services.js (includes metabox-fields.js) if the
        // active theme actually ships it. Themes without this build artefact
        // (e.g. Tailwind-only stridence) work fine without it.
        $theme_dist_path = get_stylesheet_directory() . '/assets/dist/theme-services.js';
        if (file_exists($theme_dist_path)) {
            $theme_dist_uri = get_stylesheet_directory_uri() . '/assets/dist';
            wp_enqueue_script(
                'ntdst-theme-services',
                $theme_dist_uri . '/theme-services.js',
                ['jquery', 'jquery-ui-sortable'],
                null,
                true,
            );

            // Add type="module" for ES module support
            add_filter('script_loader_tag', function ($tag, $handle) {
                if ($handle === 'ntdst-theme-services') {
                    return str_replace(' src', ' type="module" src', $tag);
                }
                return $tag;
            }, 10, 2);
        }
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register a model for auto-metabox generation
     */
    public function register(string $model_name, array $config): void
    {
        $this->registered_models[$model_name] = $config;
    }

    /**
     * Register metaboxes for all registered models
     */
    public function register_metaboxes(): void
    {
        foreach ($this->registered_models as $model_name => $config) {
            // Skip if auto_metabox is explicitly set to false
            // This allows services to handle their own metabox rendering
            if (isset($config['auto_metabox']) && $config['auto_metabox'] === false) {
                continue;
            }

            $fields = $config['fields'] ?? [];

            if (empty($fields)) {
                continue;
            }

            // Check if field groups are defined
            $field_groups = $config['field_groups'] ?? null;

            if ($field_groups && is_array($field_groups)) {
                // Check if tabbed interface is requested
                if (!empty($config['use_tabs'])) {
                    $this->register_tabbed_metabox($model_name, $config, $fields, $field_groups);
                } else {
                    // Create multiple metaboxes based on groups
                    $this->register_grouped_metaboxes($model_name, $config, $fields, $field_groups);
                }
            } else {
                // Create single metabox with all fields (default behavior)
                add_meta_box(
                    "ntdst_{$model_name}_fields",
                    $config['metabox_title'] ?? ucwords(str_replace('_', ' ', $model_name)) . ' Fields',
                    [$this, 'render_metabox'],
                    $model_name,
                    $config['metabox_context'] ?? 'normal',
                    $config['metabox_priority'] ?? 'high',
                    ['model_name' => $model_name, 'fields' => $fields],
                );
            }
        }
    }

    /**
     * Register multiple metaboxes based on field groups
     */
    private function register_grouped_metaboxes(string $model_name, array $config, array $all_fields, array $field_groups): void
    {
        $used_fields = [];

        foreach ($field_groups as $group_key => $group_config) {
            $group_fields_keys = $group_config['fields'] ?? [];

            if (empty($group_fields_keys)) {
                continue;
            }

            // Extract only the fields for this group
            $group_fields = [];
            foreach ($group_fields_keys as $field_key) {
                if (isset($all_fields[$field_key])) {
                    $group_fields[$field_key] = $all_fields[$field_key];
                    $used_fields[] = $field_key;
                }
            }

            if (empty($group_fields)) {
                continue;
            }

            add_meta_box(
                "ntdst_{$model_name}_{$group_key}",
                $group_config['title'] ?? ucwords(str_replace('_', ' ', $group_key)),
                [$this, 'render_metabox'],
                $model_name,
                $group_config['context'] ?? 'normal',
                $group_config['priority'] ?? 'default',
                ['model_name' => $model_name, 'fields' => $group_fields],
            );
        }

        // Create "Other Fields" metabox for ungrouped fields
        $ungrouped_fields = array_diff_key($all_fields, array_flip($used_fields));

        if (!empty($ungrouped_fields)) {
            add_meta_box(
                "ntdst_{$model_name}_other",
                'Other Fields',
                [$this, 'render_metabox'],
                $model_name,
                'normal',
                'low',
                ['model_name' => $model_name, 'fields' => $ungrouped_fields],
            );
        }
    }

    /**
     * Register single tabbed metabox for field groups
     */
    private function register_tabbed_metabox(string $model_name, array $config, array $all_fields, array $field_groups): void
    {
        add_meta_box(
            "ntdst_{$model_name}_tabbed",
            $config['metabox_title'] ?? ucwords(str_replace('_', ' ', $model_name)),
            [$this, 'render_tabbed_metabox'],
            $model_name,
            $config['tabs_context'] ?? 'normal',
            $config['tabs_priority'] ?? 'high',
            [
                'model_name' => $model_name,
                'fields' => $all_fields,
                'field_groups' => $field_groups,
            ],
        );
    }

    /**
     * Render tabbed metabox HTML
     */
    public function render_tabbed_metabox(\WP_Post $post, array $metabox): void
    {
        static $nonce_rendered = [];

        $model_name = $metabox['args']['model_name'];
        $all_fields = $metabox['args']['fields'];
        $field_groups = $metabox['args']['field_groups'];

        // Check if this is a registered Data model
        $is_data_model = $this->isDataModel($model_name);

        // Get current values
        if ($is_data_model) {
            $data = ntdst_data()->get($model_name)->find($post->ID);
            $values = ($data && !is_wp_error($data)) ? $data->fields : [];
        } else {
            $values = [];
            foreach (array_keys($all_fields) as $field_name) {
                $values[$field_name] = get_post_meta($post->ID, $field_name, true);
            }
        }

        // Render nonce once per post type
        if (!isset($nonce_rendered[$model_name])) {
            wp_nonce_field("ntdst_save_{$model_name}", "ntdst_{$model_name}_nonce");
            $nonce_rendered[$model_name] = true;
        }

        // Render tab navigation
        echo '<div class="ntdst-tabbed-metabox">';
        echo '<h2 class="nav-tab-wrapper">';

        $first_tab = true;
        foreach ($field_groups as $group_key => $group_config) {
            $group_fields_keys = $group_config['fields'] ?? [];
            if (empty($group_fields_keys)) {
                continue;
            }

            $tab_title = $group_config['title'] ?? ucwords(str_replace('_', ' ', $group_key));
            $active_class = $first_tab ? ' nav-tab-active' : '';

            echo '<a href="#tab-' . esc_attr($group_key) . '" class="nav-tab' . $active_class . '" data-tab="' . esc_attr($group_key) . '">';
            echo esc_html($tab_title);
            echo '</a>';

            $first_tab = false;
        }

        echo '</h2>';

        // Render tab content
        $first_tab = true;
        foreach ($field_groups as $group_key => $group_config) {
            $group_fields_keys = $group_config['fields'] ?? [];
            if (empty($group_fields_keys)) {
                continue;
            }

            $display_style = $first_tab ? '' : ' style="display:none;"';

            echo '<div id="tab-' . esc_attr($group_key) . '" class="ntdst-tab-content"' . $display_style . '>';

            // Render fields for this group
            echo '<div class="ntdst-metabox-fields">';

            foreach ($group_fields_keys as $field_key) {
                if (isset($all_fields[$field_key])) {
                    $this->render_field($field_key, $all_fields[$field_key], $values[$field_key] ?? null);
                }
            }

            echo '</div>';
            echo '</div>';

            $first_tab = false;
        }

        echo '</div>';

        // Add JavaScript for tab switching
        ?>
        <script>
        jQuery(document).ready(function($) {
            $('.ntdst-tabbed-metabox .nav-tab').on('click', function(e) {
                e.preventDefault();

                var $this = $(this);
                var tab = $this.data('tab');

                // Update active tab
                $this.siblings().removeClass('nav-tab-active');
                $this.addClass('nav-tab-active');

                // Show/hide content
                $this.closest('.ntdst-tabbed-metabox').find('.ntdst-tab-content').hide();
                $('#tab-' + tab).show();

                // Save active tab to localStorage
                localStorage.setItem('ntdst_active_tab_<?php echo esc_js($model_name); ?>', tab);
            });

            // Restore active tab from localStorage
            var activeTab = localStorage.getItem('ntdst_active_tab_<?php echo esc_js($model_name); ?>');
            if (activeTab) {
                $('.ntdst-tabbed-metabox .nav-tab[data-tab="' + activeTab + '"]').trigger('click');
            }
        });
        </script>
        <style>
        /* Tabbed Metabox Styling */
        .ntdst-tabbed-metabox {
            margin: -12px -12px 0;
        }

        .ntdst-tabbed-metabox .nav-tab-wrapper {
            margin: 0 !important;
            padding: 12px 12px 0 12px !important;
            background: transparent;
            border-bottom: 1px solid #c3c4c7;
            line-height: 1 !important;
            font-size: inherit !important;
        }

        .ntdst-tabbed-metabox .nav-tab {
            position: relative;
            margin: 0 8px -2px 0;
            padding: 10px 16px;
            background: transparent;
            border: none;
            border-bottom: 2px solid transparent;
            font-size: 13px;
            line-height: 1.4;
            color: #646970;
            text-decoration: none;
            transition: all 0.15s ease-in-out;
        }

        .ntdst-tabbed-metabox .nav-tab:hover {
            color: #1d2327;
            border-bottom-color: #8c8f94;
        }

        .ntdst-tabbed-metabox .nav-tab-active {
            color: #1d2327;
            font-weight: 500;
            border-bottom-color: #2271b1;
        }

        .ntdst-tabbed-metabox .nav-tab-active:hover {
            border-bottom-color: #2271b1;
        }

        .ntdst-tab-content {
            background: #fff;
            padding: 20px 12px 12px;
            border-top: 1px solid #c3c4c7;
            margin-top: -1px;
        }

        .ntdst-tab-content .ntdst-metabox-fields {
            padding: 0;
        }

        .ntdst-tab-content .ntdst-field {
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #f0f0f1;
        }

        .ntdst-tab-content .ntdst-field:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .ntdst-tab-content .ntdst-field label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 14px;
            color: #1d2327;
        }

        .ntdst-tab-content .ntdst-field .description {
            margin-top: 6px;
            font-size: 13px;
            color: #646970;
            font-style: normal;
        }

        .ntdst-tab-content .ntdst-field input[type="text"],
        .ntdst-tab-content .ntdst-field input[type="email"],
        .ntdst-tab-content .ntdst-field input[type="url"],
        .ntdst-tab-content .ntdst-field input[type="number"],
        .ntdst-tab-content .ntdst-field textarea,
        .ntdst-tab-content .ntdst-field select {
            width: 100%;
            max-width: 600px;
        }

        .ntdst-tab-content .ntdst-field textarea {
            min-height: 100px;
        }

        /* Repeater fields in tabs */
        .ntdst-tab-content .ntdst-repeater-field {
            margin-top: 10px;
        }

        .ntdst-tab-content .ntdst-repeater-table {
            margin-top: 10px;
        }

        .ntdst-tab-content .ntdst-repeater-add {
            margin-top: 10px;
        }

        /* Gallery fields in tabs */
        .ntdst-tab-content .ntdst-gallery-container {
            margin-top: 10px;
        }

        /* Image fields in tabs */
        .ntdst-tab-content .ntdst-image-preview {
            margin-top: 10px;
        }

        /* Relation fields in tabs */
        .ntdst-tab-content .ntdst-relation-field {
            margin-top: 10px;
        }
        </style>
        <?php

        // Output shared field styles (only once)
        static $shared_styles_rendered = false;
        if (!$shared_styles_rendered) {
            $this->render_shared_field_styles();
            $shared_styles_rendered = true;
        }
    }

    /**
     * Render shared field styles (used by both tabbed and normal metaboxes)
     */
    private function render_shared_field_styles(): void
    {
        echo '<style>
            /* Relation Field Styles */
            .ntdst-relation-field {
                margin-top: 8px;
            }
            .ntdst-relation-selected {
                display: flex;
                flex-wrap: wrap;
                gap: 6px;
                margin-bottom: 10px;
                min-height: 32px;
            }
            .ntdst-relation-tag {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                background: #2271b1;
                color: #fff;
                padding: 4px 8px 4px 12px;
                border-radius: 3px;
                font-size: 13px;
                line-height: 1.4;
            }
            .ntdst-relation-tag:hover {
                background: #135e96;
            }
            .ntdst-relation-remove {
                background: transparent;
                border: none;
                color: #fff;
                font-size: 18px;
                line-height: 1;
                cursor: pointer;
                padding: 0;
                width: 16px;
                height: 16px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 2px;
            }
            .ntdst-relation-remove:hover {
                background: rgba(255, 255, 255, 0.2);
            }
            .ntdst-relation-search {
                position: relative;
            }
            .ntdst-relation-input {
                width: 100%;
                max-width: 500px;
            }
            .ntdst-relation-results {
                position: absolute;
                top: 100%;
                left: 0;
                right: 0;
                max-width: 500px;
                background: #fff;
                border: 1px solid #8c8f94;
                border-top: none;
                max-height: 300px;
                overflow-y: auto;
                z-index: 1000;
                box-shadow: 0 2px 6px rgba(0,0,0,0.1);
            }
            .ntdst-relation-result-item {
                padding: 8px 12px;
                cursor: pointer;
                border-bottom: 1px solid #f0f0f1;
                font-size: 13px;
            }
            .ntdst-relation-result-item:hover {
                background: #f6f7f7;
            }
            .ntdst-relation-result-item:last-child {
                border-bottom: none;
            }
            .ntdst-relation-result-empty,
            .ntdst-relation-result-loading {
                padding: 12px;
                text-align: center;
                color: #646970;
                font-size: 13px;
            }
        </style>';
    }

    /**
     * Render metabox HTML
     */
    public function render_metabox(\WP_Post $post, array $metabox): void
    {
        static $nonce_rendered = [];

        $model_name = $metabox['args']['model_name'];
        $fields = $metabox['args']['fields'];

        // Check if this is a registered Data model or native post type
        $is_data_model = $this->isDataModel($model_name);

        // Get current values
        if ($is_data_model) {
            // Use Data.php ORM for registered models
            $data = ntdst_data()->get($model_name)->find($post->ID);
            $values = ($data && !is_wp_error($data)) ? $data->fields : [];
        } else {
            // Use WordPress native functions for unregistered/native post types
            $values = [];
            foreach (array_keys($fields) as $field_name) {
                $values[$field_name] = get_post_meta($post->ID, $field_name, true);
            }
        }

        // Nonce for security - only render once per post type
        if (!isset($nonce_rendered[$model_name])) {
            wp_nonce_field("ntdst_save_{$model_name}", "ntdst_{$model_name}_nonce");
            $nonce_rendered[$model_name] = true;
        }

        echo '<div class="ntdst-metabox-fields">';

        foreach ($fields as $field_name => $field_type) {
            $this->render_field($field_name, $field_type, $values[$field_name] ?? null);
        }

        echo '</div>';

        // Add basic metabox styling (non-tabbed)
        echo '<style>
            .ntdst-metabox-fields { padding: 10px 0; }
            .ntdst-field { margin-bottom: 20px; }
            .ntdst-field label {
                display: block;
                font-weight: 600;
                margin-bottom: 5px;
                font-size: 13px;
            }
            .ntdst-field input[type="text"],
            .ntdst-field input[type="number"],
            .ntdst-field input[type="email"],
            .ntdst-field textarea,
            .ntdst-field select {
                width: 100%;
                max-width: 500px;
            }
            .ntdst-field textarea { min-height: 100px; }
            .ntdst-field .description {
                font-size: 12px;
                color: #666;
                margin-top: 5px;
            }
            .ntdst-field-array {
                background: #f5f5f5;
                padding: 10px;
                border-radius: 4px;
            }
        </style>';

        // Output shared field styles
        static $shared_styles_rendered = false;
        if (!$shared_styles_rendered) {
            $this->render_shared_field_styles();
            $shared_styles_rendered = true;
        }
    }

    /**
     * Render individual field based on type.
     *
     * Defense-in-depth: $name, $field_id, $field_name, and $label all come
     * from CPT-config field keys (developer-controlled, not user input), but
     * we esc_attr/esc_html them anyway so a typo'd or third-party CPT
     * registration can't introduce an XSS path.
     */
    private function render_field(string $name, mixed $type, mixed $value): void
    {
        $label = ucwords(str_replace('_', ' ', $name));
        $field_id = "ntdst_field_{$name}";
        $field_name = "ntdst_fields[{$name}]";
        $field_id_attr = esc_attr($field_id);
        $field_name_attr = esc_attr($field_name);

        // Ensure value is never null for string contexts
        $safe_value = $value ?? '';

        // Handle array types (could be extended)
        $options = [];
        $readonly = false;
        if (is_array($type)) {
            // For relation, gallery, repeater, and callback fields, use the entire $type as options
            // For other fields, extract from 'options' key
            $field_type = $type['type'] ?? 'text';
            if (in_array($field_type, ['relation', 'gallery', 'repeater', 'callback'], true)) {
                $options = $type;  // Pass entire config
            } else {
                $options = $type['options'] ?? [];
            }
            $readonly = $type['readonly'] ?? false;
            $type = $field_type;
        }

        // Callback fields handle their own rendering entirely
        if ($type === 'callback') {
            if (isset($options['callback']) && is_callable($options['callback'])) {
                global $post;
                call_user_func($options['callback'], $post, $name, $value);
            }
            return;
        }

        echo '<div class="ntdst-field">';
        echo '<label for="' . $field_id_attr . '">' . esc_html($label) . '</label>';

        // If readonly and not a select, just display as text
        if ($readonly && $type !== 'select' && $type !== 'array' && $type !== 'json') {
            echo '<div class="ntdst-readonly-value" style="padding: 8px 0; font-size: 14px;">';
            if ($type === 'float' || $type === 'decimal') {
                echo '<strong>' . esc_html(number_format((float) $safe_value, 2)) . '</strong>';
            } elseif ($type === 'integer' || $type === 'int') {
                echo '<strong>' . esc_html($safe_value) . '</strong>';
            } else {
                echo '<strong>' . esc_html($safe_value) . '</strong>';
            }
            echo '<input type="hidden" name="' . $field_name_attr . '" value="' . esc_attr($safe_value) . '">';
            echo '<p class="description">This value is automatically calculated and cannot be edited.</p>';
            echo '</div>';
            echo '</div>';
            return;
        }

        switch ($type) {
            case 'select':
                echo '<select id="' . $field_id_attr . '" name="' . $field_name_attr . '" class="regular-text"' . ($readonly ? ' disabled' : '') . '>';
                foreach ($options as $opt_value => $opt_label) {
                    $selected = ($safe_value == $opt_value) ? ' selected' : '';
                    echo '<option value="' . esc_attr($opt_value) . '"' . $selected . '>' . esc_html($opt_label) . '</option>';
                }
                echo '</select>';
                // If readonly, add hidden input to preserve value
                if ($readonly) {
                    echo '<input type="hidden" name="' . $field_name_attr . '" value="' . esc_attr($safe_value) . '">';
                }
                break;

            case 'text':
            case 'string':
                echo '<input type="text" id="' . $field_id_attr . '" name="' . $field_name_attr . '" value="' . esc_attr($safe_value) . '" class="regular-text">';
                break;

            case 'email':
                echo '<input type="email" id="' . $field_id_attr . '" name="' . $field_name_attr . '" value="' . esc_attr($safe_value) . '" class="regular-text">';
                break;

            case 'integer':
            case 'int':
                $int_value = $value !== null && $value !== '' ? esc_attr($value) : '';
                echo '<input type="number" id="' . $field_id_attr . '" name="' . $field_name_attr . '" value="' . $int_value . '" step="1" class="small-text">';
                break;

            case 'float':
            case 'decimal':
                $float_value = $value !== null && $value !== '' ? esc_attr($value) : '';
                echo '<input type="number" id="' . $field_id_attr . '" name="' . $field_name_attr . '" value="' . $float_value . '" step="0.01" class="small-text">';
                break;

            case 'boolean':
            case 'bool':
                $checked = $value ? ' checked' : '';
                echo '<label><input type="checkbox" id="' . $field_id_attr . '" name="' . $field_name_attr . '" value="1"' . $checked . '> Yes</label>';
                break;

            case 'textarea':
            case 'longtext':
                echo '<textarea id="' . $field_id_attr . '" name="' . $field_name_attr . '" rows="5" class="large-text">' . esc_textarea($safe_value) . '</textarea>';
                break;

            case 'array':
            case 'json':
                $json_value = is_array($value) ? json_encode($value, JSON_PRETTY_PRINT) : ($value ?? '');
                echo '<div class="ntdst-field-array">';
                echo '<textarea id="' . $field_id_attr . '" name="' . $field_name_attr . '" rows="8" class="large-text code">' . esc_textarea($json_value) . '</textarea>';
                echo '<p class="description">Enter valid JSON array. Example: ["value1", "value2"]</p>';
                echo '</div>';
                break;

            case 'date':
                echo '<input type="date" id="' . $field_id_attr . '" name="' . $field_name_attr . '" value="' . esc_attr($safe_value) . '">';
                break;

            case 'datetime':
                echo '<input type="datetime-local" id="' . $field_id_attr . '" name="' . $field_name_attr . '" value="' . esc_attr($safe_value) . '">';
                break;

            case 'url':
                echo '<input type="url" id="' . $field_id_attr . '" name="' . $field_name_attr . '" value="' . esc_attr($safe_value) . '" class="regular-text">';
                break;

            case 'relation':
                // Relationship field (autocomplete post selector)
                $this->render_relation_field($field_id, $field_name, $name, $value, $options);
                break;

            case 'gallery':
                // Gallery field (image selector with reordering)
                $this->render_gallery_field($field_id, $field_name, $name, $value, $options);
                break;

            case 'repeater':
                // Repeater field (multi-row data with sub-fields)
                $this->render_repeater_field($field_id, $field_name, $name, $value, $options);
                break;

            default:
                // Default to text input
                echo '<input type="text" id="' . $field_id_attr . '" name="' . $field_name_attr . '" value="' . esc_attr($safe_value) . '" class="regular-text">';
                break;
        }

        echo '</div>';
    }

    /**
     * Render relation field (autocomplete post/user selector)
     */
    private function render_relation_field(string $field_id, string $field_name, string $name, mixed $value, array $options): void
    {
        $post_type = $options['post_type'] ?? 'post';
        $multiple = $options['multiple'] ?? true;
        $description = $options['description'] ?? '';
        $is_user_field = ($post_type === 'user');

        // Set appropriate placeholder
        if ($is_user_field) {
            $user_role = $options['user_role'] ?? '';
            $placeholder = $options['placeholder'] ?? "Search " . ($user_role ? $user_role . 's' : 'users') . "...";
        } else {
            $placeholder = $options['placeholder'] ?? "Search {$post_type}...";
        }

        // Normalize value to array
        $selected_ids = [];
        if (!empty($value)) {
            $selected_ids = is_array($value) ? array_map('intval', $value) : [intval($value)];
        }

        // Get selected items data (posts or users)
        $selected_items = [];
        if (!empty($selected_ids)) {
            if ($is_user_field) {
                // Get users
                $user_args = [
                    'include' => $selected_ids,
                ];
                if (!empty($options['user_role'])) {
                    $user_args['role'] = $options['user_role'];
                }
                $selected_items = get_users($user_args);
            } else {
                // Get posts
                $selected_items = get_posts([
                    'post_type' => $post_type,
                    'post__in' => $selected_ids,
                    'posts_per_page' => -1,
                    'orderby' => 'post__in',
                ]);
            }
        }

        // Build data attributes
        $data_attrs = [
            'data-field-name="' . esc_attr($name) . '"',
            'data-post-type="' . esc_attr($post_type) . '"',
            'data-multiple="' . ($multiple ? '1' : '0') . '"',
        ];

        if ($is_user_field && !empty($options['user_role'])) {
            $data_attrs[] = 'data-user-role="' . esc_attr($options['user_role']) . '"';
        }

        echo '<div class="ntdst-relation-field" ' . implode(' ', $data_attrs) . '>';

        // Selected items display
        echo '<div class="ntdst-relation-selected" id="' . esc_attr($field_id) . '_selected">';
        foreach ($selected_items as $item) {
            $item_id = $is_user_field ? $item->ID : $item->ID;
            $item_title = $is_user_field ? $item->display_name : $item->post_title;

            echo '<span class="ntdst-relation-tag" data-id="' . esc_attr($item_id) . '">';
            echo esc_html($item_title);
            echo '<button type="button" class="ntdst-relation-remove" aria-label="Remove">&times;</button>';
            echo '<input type="hidden" name="' . esc_attr($field_name) . '[]" value="' . esc_attr($item_id) . '">';
            echo '</span>';
        }
        echo '</div>';

        // Search input
        echo '<div class="ntdst-relation-search">';
        echo '<input type="text" class="ntdst-relation-input regular-text" placeholder="' . esc_attr($placeholder) . '" autocomplete="off">';
        echo '<div class="ntdst-relation-results" style="display: none;"></div>';
        echo '</div>';

        if ($description) {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }

        echo '</div>';
    }

    /**
     * Render gallery field (image selector with drag & drop reordering)
     */
    private function render_gallery_field(string $field_id, string $field_name, string $name, mixed $value, array $options): void
    {
        static $gallery_js_loaded = false;

        // Enqueue WordPress media library
        wp_enqueue_media();

        $description = $options['description'] ?? '';
        $button_text = $options['button_text'] ?? 'Add Images';

        // Normalize value to array of attachment IDs
        $attachment_ids = [];
        if (!empty($value)) {
            $attachment_ids = is_array($value) ? array_map('intval', $value) : [intval($value)];
        }

        echo '<div class="ntdst-gallery-field" data-field-name="' . esc_attr($name) . '">';

        // Gallery preview container
        echo '<div class="ntdst-gallery-preview" id="' . esc_attr($field_id) . '_preview">';

        foreach ($attachment_ids as $attachment_id) {
            $image_url = wp_get_attachment_image_url($attachment_id, 'thumbnail');
            $image_title = get_the_title($attachment_id);
            $alt_text = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
            $edit_url = admin_url('post.php?post=' . $attachment_id . '&action=edit');

            if ($image_url) {
                $has_alt = !empty($alt_text);
                $item_class = 'ntdst-gallery-item' . (!$has_alt ? ' no-alt-text' : '');

                echo '<div class="' . esc_attr($item_class) . '" data-id="' . esc_attr($attachment_id) . '">';
                echo '<img src="' . esc_url($image_url) . '" alt="' . esc_attr($image_title) . '">';
                echo '<button type="button" class="ntdst-gallery-remove" aria-label="Remove">&times;</button>';

                // Alt text indicator
                if ($has_alt) {
                    echo '<span class="alt-indicator" title="' . esc_attr($alt_text) . '">✓ Alt</span>';
                } else {
                    echo '<span class="alt-indicator missing" title="No alt text">⚠ No Alt</span>';
                }

                // Edit link
                echo '<a href="' . esc_url($edit_url) . '" class="edit-link" target="_blank" title="Edit image">✎</a>';

                echo '<input type="hidden" name="' . esc_attr($field_name) . '[]" value="' . esc_attr($attachment_id) . '">';
                echo '</div>';
            }
        }

        echo '</div>';

        // Add images button
        echo '<button type="button" class="button ntdst-gallery-add" data-field-id="' . esc_attr($field_id) . '">' . esc_html($button_text) . '</button>';

        if ($description) {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }

        echo '</div>';

        // Inline CSS (only once)
        if (!$gallery_js_loaded) {
            echo '<style>
                .ntdst-gallery-field {
                    margin-top: 8px;
                }
                .ntdst-gallery-preview {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 10px;
                    margin-bottom: 12px;
                    min-height: 40px;
                    padding: 10px;
                    background: #f9f9f9;
                    border: 1px dashed #ccc;
                    border-radius: 4px;
                }
                .ntdst-gallery-preview:empty::before {
                    content: "No images selected. Click the button below to add images.";
                    color: #999;
                    font-size: 13px;
                    font-style: italic;
                    display: block;
                    padding: 20px;
                    text-align: center;
                    width: 100%;
                }
                .ntdst-gallery-item {
                    position: relative;
                    width: 100px;
                    height: 100px;
                    background: #fff;
                    border: 2px solid #ddd;
                    border-radius: 4px;
                    overflow: hidden;
                    cursor: move;
                    transition: border-color 0.2s, box-shadow 0.2s;
                }
                .ntdst-gallery-item:hover {
                    border-color: #2271b1;
                    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
                }
                .ntdst-gallery-item.ui-sortable-helper {
                    opacity: 0.7;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
                }
                .ntdst-gallery-item.ui-sortable-placeholder {
                    border: 2px dashed #2271b1;
                    background: #e5f3ff;
                    visibility: visible !important;
                }
                .ntdst-gallery-item img {
                    width: 100%;
                    height: 100%;
                    object-fit: cover;
                }
                .ntdst-gallery-remove {
                    position: absolute;
                    top: 4px;
                    right: 4px;
                    background: rgba(0,0,0,0.7);
                    color: #fff;
                    border: none;
                    border-radius: 2px;
                    width: 20px;
                    height: 20px;
                    font-size: 16px;
                    line-height: 1;
                    cursor: pointer;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 0;
                    opacity: 0;
                    transition: opacity 0.2s, background 0.2s;
                }
                .ntdst-gallery-item:hover .ntdst-gallery-remove {
                    opacity: 1;
                }
                .ntdst-gallery-remove:hover {
                    background: #d63638;
                }
                .ntdst-gallery-add {
                    margin-bottom: 8px;
                }
                .ntdst-gallery-item.no-alt-text {
                    border-color: #d63638;
                }
                .alt-indicator {
                    position: absolute;
                    bottom: 4px;
                    left: 4px;
                    background: rgba(22, 163, 74, 0.9);
                    color: #fff;
                    font-size: 10px;
                    font-weight: 600;
                    padding: 2px 6px;
                    border-radius: 3px;
                    line-height: 1.4;
                    white-space: nowrap;
                    pointer-events: none;
                    transition: opacity 0.2s;
                }
                .alt-indicator.missing {
                    background: rgba(234, 88, 12, 0.9);
                }
                .edit-link {
                    position: absolute;
                    top: 4px;
                    left: 4px;
                    background: rgba(0,0,0,0.7);
                    color: #fff;
                    text-decoration: none;
                    font-size: 14px;
                    width: 20px;
                    height: 20px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    border-radius: 2px;
                    opacity: 0;
                    transition: opacity 0.2s, background 0.2s;
                }
                .ntdst-gallery-item:hover .edit-link {
                    opacity: 1;
                }
                .edit-link:hover {
                    background: #2271b1;
                    color: #fff;
                }
            </style>';

            $gallery_js_loaded = true;
        }
    }

    /**
     * Render repeater field (multi-row data with sub-fields)
     */
    private function render_repeater_field(string $field_id, string $field_name, string $name, mixed $value, array $options): void
    {
        static $repeater_js_loaded = false;

        $description = $options['description'] ?? '';
        $sub_fields = $options['sub_fields'] ?? [];
        $button_text = $options['button_text'] ?? 'Add Row';
        $min_rows = $options['min_rows'] ?? 0;
        $max_rows = $options['max_rows'] ?? null;

        // Normalize value to array of rows
        $rows = [];
        if (!empty($value) && is_array($value)) {
            $rows = $value;
        }

        // Ensure minimum rows
        while (count($rows) < $min_rows) {
            $rows[] = [];
        }

        echo '<div class="ntdst-repeater-field" data-field-name="' . esc_attr($name) . '" data-field-id="' . esc_attr($field_id) . '" data-max-rows="' . esc_attr($max_rows ?? '') . '">';

        if ($description) {
            echo '<p class="description" style="margin-top: 0;">' . esc_html($description) . '</p>';
        }

        // Table with header
        echo '<table class="ntdst-repeater-table">';
        echo '<thead>';
        echo '<tr>';
        echo '<th class="ntdst-repeater-handle-header"></th>'; // Drag handle column

        // Sub-field headers
        foreach ($sub_fields as $sub_field_name => $sub_field_type) {
            $label = is_array($sub_field_type) ? ($sub_field_type['label'] ?? ucwords(str_replace('_', ' ', $sub_field_name))) : ucwords(str_replace('_', ' ', $sub_field_name));
            echo '<th>' . esc_html($label) . '</th>';
        }

        echo '<th class="ntdst-repeater-actions-header"></th>'; // Remove button column
        echo '</tr>';
        echo '</thead>';

        // Rows container (tbody)
        echo '<tbody class="ntdst-repeater-rows" id="' . esc_attr($field_id) . '_rows">';

        foreach ($rows as $row_index => $row_data) {
            $this->render_repeater_row($field_name, $name, $row_index, $row_data, $sub_fields);
        }

        echo '</tbody>';
        echo '</table>';

        // Add row button
        echo '<button type="button" class="button ntdst-repeater-add" data-field-name="' . esc_attr($name) . '">' . esc_html($button_text) . '</button>';

        // Row template (hidden, used by JavaScript)
        echo '<script type="text/html" id="' . esc_attr($field_id) . '_template">';
        $this->render_repeater_row($field_name, $name, '__INDEX__', [], $sub_fields);
        echo '</script>';

        echo '</div>';

        // Inline CSS and JavaScript (only once)
        if (!$repeater_js_loaded) {
            echo '<style>
                .ntdst-repeater-field {
                    margin-top: 8px;
                }
                .ntdst-repeater-table {
                    width: 100%;
                    max-width: 100%;
                    border-collapse: collapse;
                    margin-bottom: 12px;
                    background: #fff;
                    border: 1px solid #ddd;
                }
                .ntdst-repeater-table thead th {
                    background: #f9f9f9;
                    padding: 10px;
                    text-align: left;
                    font-weight: 600;
                    font-size: 12px;
                    border-bottom: 2px solid #ddd;
                    white-space: nowrap;
                }
                .ntdst-repeater-handle-header {
                    width: 30px !important;
                }
                .ntdst-repeater-actions-header {
                    width: 40px !important;
                }
                .ntdst-repeater-table tbody tr {
                    border-bottom: 1px solid #eee;
                }
                .ntdst-repeater-table tbody tr:hover {
                    background: #fafafa;
                }
                .ntdst-repeater-table td {
                    padding: 8px 10px;
                    vertical-align: middle;
                }
                .ntdst-repeater-handle {
                    width: 30px;
                    text-align: center;
                    cursor: move;
                }
                .ntdst-repeater-drag-handle {
                    color: #999;
                    font-size: 18px;
                    line-height: 1;
                    cursor: move;
                    user-select: none;
                    display: inline-block;
                }
                .ntdst-repeater-drag-handle:hover {
                    color: #2271b1;
                }
                .ntdst-repeater-actions {
                    width: 40px;
                    text-align: center;
                }
                .ntdst-repeater-remove {
                    background: transparent;
                    color: #dc3232;
                    border: none;
                    padding: 4px 8px;
                    cursor: pointer;
                    font-size: 20px;
                    line-height: 1;
                    border-radius: 3px;
                }
                .ntdst-repeater-remove:hover {
                    background: #dc3232;
                    color: #fff;
                }
                .ntdst-repeater-input,
                .ntdst-repeater-textarea,
                .ntdst-repeater-select,
                .ntdst-repeater-number,
                .ntdst-repeater-date {
                    width: 100%;
                    padding: 6px 8px;
                    border: 1px solid #ddd;
                    border-radius: 3px;
                    font-size: 13px;
                }
                .ntdst-repeater-textarea {
                    resize: vertical;
                    font-family: inherit;
                }
                .ntdst-repeater-number {
                    max-width: 100px;
                }
                .ntdst-repeater-date {
                    max-width: 150px;
                }
                .ntdst-repeater-rows:empty::after {
                    content: "No rows added yet. Click the button below to add a row.";
                    display: block;
                    padding: 20px;
                    text-align: center;
                    color: #999;
                    font-style: italic;
                    width: max-content;
                }
                .ntdst-repeater-row.ui-sortable-helper {
                    display: table;
                    width: 100%;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
                    background: #fff;
                }
                .ntdst-repeater-row.ui-sortable-placeholder {
                    background: #f0f6fc;
                    border: 2px dashed #2271b1;
                    visibility: visible !important;
                    height: 50px;
                }
            </style>';

            // JavaScript for repeater add/remove functionality
            echo '<script>
            jQuery(document).ready(function($) {
                // Add row button click handler
                $(document).on("click", ".ntdst-repeater-add", function(e) {
                    e.preventDefault();

                    var $field = $(this).closest(".ntdst-repeater-field");
                    var fieldId = $field.data("field-id");
                    var maxRows = $field.data("max-rows");
                    var $tbody = $field.find(".ntdst-repeater-rows");
                    var $template = $("#" + fieldId + "_template");

                    // Check max rows limit
                    if (maxRows && $tbody.find("tr").length >= maxRows) {
                        alert("Maximum number of rows reached (" + maxRows + ")");
                        return;
                    }

                    // Get next index
                    var nextIndex = 0;
                    $tbody.find("tr").each(function() {
                        var idx = parseInt($(this).data("index"), 10);
                        if (!isNaN(idx) && idx >= nextIndex) {
                            nextIndex = idx + 1;
                        }
                    });

                    // Clone template and replace __INDEX__ placeholder
                    var templateHtml = $template.html();
                    var newRow = templateHtml.replace(/__INDEX__/g, nextIndex);
                    $tbody.append(newRow);

                    // Trigger change event for any listeners
                    $tbody.trigger("repeater:row-added");
                });

                // Remove row button click handler
                $(document).on("click", ".ntdst-repeater-remove", function(e) {
                    e.preventDefault();

                    var $row = $(this).closest("tr");
                    var $tbody = $row.closest("tbody");

                    $row.fadeOut(200, function() {
                        $(this).remove();
                        $tbody.trigger("repeater:row-removed");
                    });
                });

                // Enable drag-and-drop sorting if jQuery UI sortable is available
                if ($.fn.sortable) {
                    $(".ntdst-repeater-rows").sortable({
                        handle: ".ntdst-repeater-drag-handle",
                        placeholder: "ntdst-repeater-row ui-sortable-placeholder",
                        axis: "y",
                        update: function(event, ui) {
                            $(this).trigger("repeater:row-reordered");
                        }
                    });
                }
            });
            </script>';

            $repeater_js_loaded = true;
        }
    }

    /**
     * Render a single repeater row (table row format)
     */
    private function render_repeater_row(string $field_name, string $name, mixed $row_index, array $row_data, array $sub_fields): void
    {
        echo '<tr class="ntdst-repeater-row" data-index="' . esc_attr($row_index) . '">';

        // Drag handle column
        echo '<td class="ntdst-repeater-handle">';
        echo '<span class="ntdst-repeater-drag-handle" title="Drag to reorder">⋮⋮</span>';
        echo '</td>';

        // Sub-field columns
        foreach ($sub_fields as $sub_field_name => $sub_field_type) {
            $sub_field_value = $row_data[$sub_field_name] ?? '';
            $sub_field_id = "ntdst_field_{$name}_{$row_index}_{$sub_field_name}";
            $sub_field_full_name = "{$field_name}[{$row_index}][{$sub_field_name}]";

            // Extract type and options
            $type = is_array($sub_field_type) ? ($sub_field_type['type'] ?? 'text') : $sub_field_type;
            $options = is_array($sub_field_type) ? ($sub_field_type['options'] ?? []) : [];

            echo '<td>';

            // Render sub-field input (no labels in table cells)
            switch ($type) {
                case 'text':
                case 'string':
                    echo '<input type="text" id="' . esc_attr($sub_field_id) . '" name="' . esc_attr($sub_field_full_name) . '" value="' . esc_attr($sub_field_value) . '" class="ntdst-repeater-input">';
                    break;

                case 'textarea':
                    echo '<textarea id="' . esc_attr($sub_field_id) . '" name="' . esc_attr($sub_field_full_name) . '" rows="2" class="ntdst-repeater-textarea">' . esc_textarea($sub_field_value) . '</textarea>';
                    break;

                case 'select':
                    echo '<select id="' . esc_attr($sub_field_id) . '" name="' . esc_attr($sub_field_full_name) . '" class="ntdst-repeater-select">';
                    foreach ($options as $opt_value => $opt_label) {
                        $selected = ($sub_field_value == $opt_value) ? 'selected' : '';
                        echo '<option value="' . esc_attr($opt_value) . '" ' . $selected . '>' . esc_html($opt_label) . '</option>';
                    }
                    echo '</select>';
                    break;

                case 'number':
                case 'integer':
                    echo '<input type="number" id="' . esc_attr($sub_field_id) . '" name="' . esc_attr($sub_field_full_name) . '" value="' . esc_attr($sub_field_value) . '" step="1" class="ntdst-repeater-number">';
                    break;

                case 'float':
                case 'decimal':
                    echo '<input type="number" id="' . esc_attr($sub_field_id) . '" name="' . esc_attr($sub_field_full_name) . '" value="' . esc_attr($sub_field_value) . '" step="0.01" class="ntdst-repeater-number">';
                    break;

                case 'date':
                    echo '<input type="date" id="' . esc_attr($sub_field_id) . '" name="' . esc_attr($sub_field_full_name) . '" value="' . esc_attr($sub_field_value) . '" class="ntdst-repeater-date">';
                    break;

                case 'url':
                    echo '<input type="url" id="' . esc_attr($sub_field_id) . '" name="' . esc_attr($sub_field_full_name) . '" value="' . esc_attr($sub_field_value) . '" class="ntdst-repeater-input">';
                    break;

                default:
                    echo '<input type="text" id="' . esc_attr($sub_field_id) . '" name="' . esc_attr($sub_field_full_name) . '" value="' . esc_attr($sub_field_value) . '" class="ntdst-repeater-input">';
                    break;
            }

            echo '</td>';
        }

        // Remove button column
        echo '<td class="ntdst-repeater-actions">';
        echo '<button type="button" class="ntdst-repeater-remove" title="Remove row">×</button>';
        echo '</td>';

        echo '</tr>';
    }

    /**
     * Save metabox data using Data.php ORM or WordPress native functions
     */
    public function save_metabox_data(int $post_id, \WP_Post $post): void
    {
        $model_name = $post->post_type;

        // Check if this model is registered
        if (!isset($this->registered_models[$model_name])) {
            return;
        }

        // Security checks
        $nonce_name = "ntdst_{$model_name}_nonce";
        $nonce_action = "ntdst_save_{$model_name}";

        if (!isset($_POST[$nonce_name])) {
            return;
        }

        if (!wp_verify_nonce(wp_unslash($_POST[$nonce_name]), $nonce_action)) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Prevent infinite loops - remove this hook temporarily
        remove_action('save_post', [$this, 'save_metabox_data'], 10);

        // Get submitted fields
        $fields_data = $_POST['ntdst_fields'] ?? [];

        if (empty($fields_data)) {
            add_action('save_post', [$this, 'save_metabox_data'], 10, 2);
            return;
        }

        // Sanitize and prepare data based on field types
        $fields_config = $this->registered_models[$model_name]['fields'];
        $sanitized_data = [];

        foreach ($fields_data as $field_name => $field_value) {
            $field_config = $fields_config[$field_name] ?? 'text';
            $field_type = is_array($field_config) ? ($field_config['type'] ?? 'text') : $field_config;

            // Remove WordPress slashes before sanitizing
            $field_value = wp_unslash($field_value);

            // Pass full config for repeater fields (needs sub_fields info)
            $sanitized = $this->sanitize_field($field_value, $field_type, $field_config);
            $sanitized_data[$field_name] = $sanitized;
        }

        // Handle relation/gallery fields that weren't submitted (treat as empty)
        // This is critical for when users remove all items from a relation field
        foreach ($fields_config as $field_name => $field_config) {
            if (isset($sanitized_data[$field_name])) {
                continue; // Already processed
            }

            $type = is_array($field_config) ? ($field_config['type'] ?? 'text') : $field_config;

            // For relation and gallery fields, missing POST data means user cleared all items
            if (in_array($type, ['relation', 'gallery'])) {
                $sanitized_data[$field_name] = [];
            }
        }

        // Check if this is a registered Data model or native post type
        $is_data_model = $this->isDataModel($model_name);

        if ($is_data_model) {
            // Save using Data.php ORM for registered models
            try {
                $model = ntdst_data()->get($model_name);
                $existing = $model->find($post_id);

                if ($existing && !is_wp_error($existing)) {
                    // Update existing
                    $result = $model->update($post_id, $sanitized_data);
                } else {
                    // Create new
                    $sanitized_data['post_id'] = $post_id;
                    $result = $model->create($sanitized_data);
                }

                // Fire hook for extensibility
                do_action("ntdst/metabox_saved/{$model_name}", $post_id, $sanitized_data);
            } catch (\Throwable $e) {
                if (function_exists('ntdst_log')) {
                    ntdst_log('metabox')->error("Save failed for {$model_name}: " . $e->getMessage());
                }
            }
        } else {
            // Use WordPress native functions for unregistered/native post types
            foreach ($sanitized_data as $field_name => $value) {
                // Delete meta if value is empty array (cleaner than storing serialized empty array)
                if (is_array($value) && empty($value)) {
                    delete_post_meta($post_id, $field_name);
                } else {
                    update_post_meta($post_id, $field_name, $value);
                }
            }

            // Fire hook for extensibility
            do_action("ntdst/metabox_saved/{$model_name}", $post_id, $sanitized_data);
        }

        // Re-add the hook after saving is complete
        add_action('save_post', [$this, 'save_metabox_data'], 10, 2);
    }

    /**
     * Check if this model has a Data-layer schema (ORM-backed).
     *
     * We check our OWN registry rather than calling NTDST_Data_Manager::get(),
     * which auto-creates an empty model entry as a side effect. Auto-creating
     * a phantom model would persist across the request and shadow later
     * registrations.
     */
    private function isDataModel(string $model_name): bool
    {
        return !empty($this->registered_models[$model_name]['fields'] ?? []);
    }

    /**
     * Sanitize field value based on type
     *
     * @param mixed $value The field value to sanitize
     * @param string $type The field type
     * @param array|string $field_config Full field config (for repeater sub_fields)
     */
    private function sanitize_field(mixed $value, string $type, mixed $field_config = []): mixed
    {
        switch ($type) {
            case 'email':
                return sanitize_email($value);

            case 'integer':
            case 'int':
                return absint($value);

            case 'float':
            case 'decimal':
                return floatval($value);

            case 'boolean':
            case 'bool':
                return (bool) $value;

            case 'textarea':
            case 'longtext':
                return sanitize_textarea_field($value);

            case 'array':
            case 'json':
                // If already an array, return as-is
                if (is_array($value)) {
                    return $value;
                }

                // If empty string, return empty array
                if (empty($value) || trim($value) === '') {
                    return [];
                }

                // Try to decode JSON
                $decoded = json_decode(trim($value), true);

                // Check for JSON errors. Log the failure but NOT the value
                // itself — users may paste PII into form fields and we don't
                // want it ending up in plaintext error logs.
                if (json_last_error() !== JSON_ERROR_NONE) {
                    if (function_exists('ntdst_log')) {
                        ntdst_log('metabox')->error('JSON decode error: ' . json_last_error_msg());
                    }
                    return [];
                }

                return is_array($decoded) ? $decoded : [];

            case 'relation':
                // Relation field - array of post IDs
                if (is_array($value)) {
                    return array_map('absint', array_filter($value));
                }
                return !empty($value) ? [absint($value)] : [];

            case 'gallery':
                // Gallery field - array of attachment IDs
                if (is_array($value)) {
                    return array_map('absint', array_filter($value));
                }
                return !empty($value) ? [absint($value)] : [];

            case 'repeater':
                // Repeater field - array of rows, each row is an associative array
                if (!is_array($value)) {
                    return [];
                }

                // Get sub_fields config for type-aware sanitization
                $sub_fields = is_array($field_config) ? ($field_config['sub_fields'] ?? []) : [];

                $sanitized_rows = [];
                foreach ($value as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $sanitized_row = [];
                    foreach ($row as $sub_field => $sub_value) {
                        // Get sub-field type from config
                        $sub_field_config = $sub_fields[$sub_field] ?? 'text';
                        $sub_field_type = is_array($sub_field_config) ? ($sub_field_config['type'] ?? 'text') : $sub_field_config;

                        // Type-aware sanitization for sub-fields
                        switch ($sub_field_type) {
                            case 'float':
                            case 'decimal':
                                $sanitized_row[$sub_field] = floatval($sub_value);
                                break;
                            case 'number':
                            case 'integer':
                                $sanitized_row[$sub_field] = intval($sub_value);
                                break;
                            case 'url':
                                $sanitized_row[$sub_field] = esc_url_raw($sub_value);
                                break;
                            case 'textarea':
                                $sanitized_row[$sub_field] = sanitize_textarea_field($sub_value);
                                break;
                            default:
                                $sanitized_row[$sub_field] = sanitize_text_field($sub_value);
                                break;
                        }
                    }

                    // Only add row if it has data (check for non-empty, non-zero values)
                    $has_data = false;
                    foreach ($sanitized_row as $v) {
                        if ($v !== '' && $v !== null) {
                            $has_data = true;
                            break;
                        }
                    }
                    if ($has_data) {
                        $sanitized_rows[] = $sanitized_row;
                    }
                }

                return $sanitized_rows;

            case 'url':
                return esc_url_raw($value);

            case 'text':
            case 'string':
            default:
                return sanitize_text_field($value);
        }
    }
}

/**
 * Global helper - get metabox generator instance
 */
if (!function_exists('ntdst_metabox')) {
    function ntdst_metabox(): NTDST_MetaboxGenerator
    {
        return NTDST_MetaboxGenerator::instance();
    }
}
