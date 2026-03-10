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
                    ucwords(str_replace('_', ' ', $source_post_type)) . 's'
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
                    ]
                );
            }
        }
    }

    /**
     * Get all registered models with relation fields
     */
    private function getModelsWithRelations(): array
    {
        $models = [];

        // Get all registered post types
        $post_types = get_post_types(['public' => true], 'names');

        foreach ($post_types as $post_type) {
            // Try to get the model config from Data Manager
            try {
                $data_manager = ntdst_data();
                $model = $data_manager->get($post_type);

                if (!$model) {
                    continue;
                }

                // Get schema using public method
                $schema = $model->getSchema();

                if (empty($schema)) {
                    continue;
                }

                // Find relation fields
                foreach ($schema as $field_name => $field_config) {
                    if (is_array($field_config) && ($field_config['type'] ?? '') === 'relation') {
                        $models[$post_type][$field_name] = $field_config;
                    }
                }
            } catch (Exception $e) {
                // Model not registered, skip
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
     * Find posts that reference the given post ID in a relation field
     */
    private function findReferringPosts(int $post_id, string $post_type, string $field_name): array
    {
        global $wpdb;

        // Query posts that have this post_id in their relation field meta
        // WordPress stores arrays as PHP serialized: a:1:{i:0;i:6;}
        // We search for the pattern 'i:{$post_id};' which represents the integer value in serialized format
        $sql = $wpdb->prepare(
            "SELECT DISTINCT p.* FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = %s
            AND p.post_status = 'publish'
            AND pm.meta_key = %s
            AND pm.meta_value LIKE %s
            ORDER BY p.post_title ASC",
            $post_type,
            $field_name,
            '%i:' . $post_id . ';%'  // Serialized integer pattern
        );

        return $wpdb->get_results($sql);
    }
}

// Auto-initialize when theme is ready
add_action('after_setup_theme', function() {
    // Only initialize if Theme is available
    if (class_exists('NTDST_Theme')) {
        $theme = ntdst_get(NTDST_Theme::class);
        if ($theme) {
            new NTDST_RelationField($theme);
        }
    }
}, 20); // Priority 20 to ensure theme is fully initialized
