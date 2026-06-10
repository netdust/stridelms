<?php

declare(strict_types=1);

/**
 * Relation Field Service
 * Provides API endpoints for post searching and reverse relationship metaboxes
 *
 * @package NTDST Core
 * @version 1.0.0
 */

defined('ABSPATH') || exit;

final class NTDST_RelationField implements NTDST_Service_Meta
{
    public static function metadata(): array
    {
        return [
            'name' => 'Relation Fields',
            'description' => 'API endpoints for relation field autocomplete and reverse relationships',
            'admin_only' => true,
            'enabled' => true,
            'priority' => 5,
        ];
    }

    public function __construct(private readonly NTDST_Theme $theme)
    {
        $this->init();
    }

    private function init(): void
    {
        // Note: Using existing search_posts endpoint from Endpoints.php
        // No need to register duplicate API endpoint

        // Register reverse relationship metaboxes
        add_action('add_meta_boxes', [$this, 'registerReverseRelationshipMetaboxes'], 20);
    }

    /**
     * Register reverse relationship metaboxes
     * Shows "Featured in Exhibitions" on Artist/Artwork pages, etc.
     */
    public function registerReverseRelationshipMetaboxes(): void
    {
        // Get all registered models with relation fields
        $models_with_relations = $this->getModelsWithRelations();

        foreach ($models_with_relations as $source_post_type => $relation_fields) {
            foreach ($relation_fields as $field_name => $field_config) {
                $target_post_type = $field_config['post_type'] ?? null;

                if (!$target_post_type || !post_type_exists($target_post_type)) {
                    continue;
                }

                // Add metabox to target post type showing where it's referenced
                $metabox_title = $field_config['reverse_label'] ?? sprintf(
                    'Featured in %s',
                    ucwords(str_replace('_', ' ', $source_post_type)) . 's',
                );

                add_meta_box(
                    "ntdst_reverse_{$source_post_type}_{$field_name}",
                    $metabox_title,
                    [$this, 'renderReverseRelationshipMetabox'],
                    $target_post_type,
                    'side',
                    'default',
                    [
                        'source_post_type' => $source_post_type,
                        'field_name' => $field_name,
                        'target_post_type' => $target_post_type,
                    ],
                );
            }
        }
    }

    /**
     * Get all registered models with relation fields.
     *
     * Uses NTDST_Data_Manager::isRegistered() so iterating over public
     * post types doesn't auto-create phantom model entries for built-in
     * types (post, page, sfwd-courses, etc.) that have no NTDST schema.
     */
    private function getModelsWithRelations(): array
    {
        $models = [];
        $data_manager = ntdst_data();

        // Get all registered post types
        $post_types = get_post_types(['public' => true], 'names');

        foreach ($post_types as $post_type) {
            if (!$data_manager->isRegistered($post_type)) {
                continue;
            }

            try {
                $model = $data_manager->get($post_type);
                $schema = $model->getSchema();
                if (empty($schema)) {
                    continue;
                }

                foreach ($schema as $field_name => $field_config) {
                    if (is_array($field_config) && ($field_config['type'] ?? '') === 'relation') {
                        $models[$post_type][$field_name] = $field_config;
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $models;
    }

    /**
     * Render reverse relationship metabox
     */
    public function renderReverseRelationshipMetabox(\WP_Post $post, array $metabox): void
    {
        $source_post_type = $metabox['args']['source_post_type'];
        $field_name = $metabox['args']['field_name'];

        // Find all posts of source_post_type that reference this post
        $referring_posts = $this->findReferringPosts($post->ID, $source_post_type, $field_name);

        if (empty($referring_posts)) {
            echo '<p class="ntdst-reverse-relations-empty" style="padding: 12px; color: #646970; font-style: italic; margin: 0;">Not featured in any ' . esc_html($source_post_type) . 's yet.</p>';
            return;
        }

        echo '<ul class="ntdst-reverse-relations-list" style="margin: 0; padding: 0;">';
        foreach ($referring_posts as $referring_post) {
            $edit_url = get_edit_post_link($referring_post->ID);
            echo '<li style="margin: 0; padding: 8px 12px; border-bottom: 1px solid #f0f0f1;">';
            echo '<a href="' . esc_url($edit_url) . '">' . esc_html($referring_post->post_title) . '</a>';
            echo '</li>';
        }
        echo '</ul>';

        echo '<style>
            .ntdst-reverse-relations-list li:last-child { border-bottom: none; }
        </style>';
    }

    /**
     * Find posts that reference the given post ID in a relation field.
     *
     * Data.php stores relation values as JSON arrays ([6, 7]), not PHP
     * serialized. The old implementation searched for serialized patterns
     * which never matched. We now narrow the candidate set with a JSON-
     * shape LIKE (matches `:6,`, `:6]`, `[6,`, `[6]`) and then verify each
     * candidate by JSON-decoding and looking for the ID, avoiding false
     * positives like ID 60 matching when searching for ID 6.
     */
    private function findReferringPosts(int $post_id, string $post_type, string $field_name): array
    {
        global $wpdb;

        // Narrow candidate set: rows whose JSON meta_value contains the ID
        // bounded by JSON delimiters (start-of-array, comma, end-of-array).
        $sql = $wpdb->prepare(
            "SELECT DISTINCT p.* FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = %s
            AND p.post_status = 'publish'
            AND pm.meta_key = %s
            AND (
                pm.meta_value LIKE %s
                OR pm.meta_value LIKE %s
                OR pm.meta_value LIKE %s
                OR pm.meta_value LIKE %s
            )
            ORDER BY p.post_title ASC",
            $post_type,
            $field_name,
            '[' . $post_id . ']',            // single-element exact
            '[' . $post_id . ',%',           // first element
            '%,' . $post_id . ',%',          // middle element
            '%,' . $post_id . ']',            // last element
        );
        $candidates = $wpdb->get_results($sql) ?: [];

        // Verify each candidate by decoding the meta value — guards against
        // false positives in unexpected meta shapes.
        $matches = [];
        foreach ($candidates as $candidate) {
            $raw = get_post_meta($candidate->ID, $field_name, true);
            $ids = is_array($raw) ? $raw : (is_string($raw) ? (json_decode($raw, true) ?: []) : []);
            if (in_array($post_id, array_map('intval', $ids), true)) {
                $matches[] = $candidate;
            }
        }
        return $matches;
    }
}

// Auto-initialize when theme is ready
add_action('after_setup_theme', function () {
    // Only initialize if Theme is available
    if (class_exists('NTDST_Theme')) {
        $theme = ntdst_get(NTDST_Theme::class);
        if ($theme) {
            new NTDST_RelationField($theme);
        }
    }
}, 20); // Priority 20 to ensure theme is fully initialized
