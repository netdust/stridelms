<?php

declare(strict_types=1);

namespace Stride\Modules\Edition;

use Stride\Admin\StrideSettingsService;

/**
 * Edition CPT Registration.
 *
 * Scheduled course offerings with dates, capacity, pricing.
 */
final class EditionCPT
{
    public const POST_TYPE = 'vad_edition';

    public static function register(): void
    {
        // Register CPT hooks
        add_action('save_post_' . self::POST_TYPE, [self::class, 'generateSlugFromCourse'], 10, 3);
        add_filter('wp_insert_post_data', [self::class, 'filterPostData'], 10, 2);

        ntdst_data()->register(self::POST_TYPE, [
            'meta_prefix' => '_ntdst_',
            'label' => 'Edities',
            'labels' => [
                'name' => 'Edities',
                'singular_name' => 'Editie',
                'add_new' => 'Nieuwe editie',
                'add_new_item' => 'Nieuwe editie toevoegen',
                'edit_item' => 'Editie bewerken',
            ],
            'public' => true,
            'publicly_queryable' => true,
            'has_archive' => true,
            'show_ui' => true,
            'show_in_menu' => 'stride-dashboard',
            'menu_icon' => 'dashicons-calendar-alt',
            'supports' => ['title'],
            'rewrite' => [
                'slug' => StrideSettingsService::getEditionSlug(),
                'with_front' => false,
            ],
            'fields' => self::getFields(),
            // Disable auto-generated metabox - custom UI handled by EditionAdminController
            'auto_metabox' => false,
        ]);
    }

    private static function getFields(): array
    {
        return [
            'course_id' => [
                'type' => 'int',
                'label' => 'Cursus',
                'required' => true,
            ],
            'start_date' => [
                'type' => 'text',
                'label' => 'Startdatum',
                'required' => true,
            ],
            'end_date' => [
                'type' => 'text',
                'label' => 'Einddatum',
            ],
            'capacity' => [
                'type' => 'int',
                'label' => 'Capaciteit',
                'required' => true,
            ],
            'price' => [
                'type' => 'float',
                'label' => 'Prijs (leden)',
            ],
            'price_non_member' => [
                'type' => 'float',
                'label' => 'Prijs (niet-leden)',
            ],
            'venue' => [
                'type' => 'text',
                'label' => 'Locatie',
            ],
            'status' => [
                'type' => 'text',
                'label' => 'Status',
            ],
            'speakers' => [
                'type' => 'text',
                'label' => 'Sprekers',
            ],
            'selection_deadline' => [
                'type' => 'text',
                'label' => 'Selectie deadline',
                'description' => 'Deadline for session selection (YYYY-MM-DD)',
            ],
            'session_slots' => [
                'type' => 'json',
                'label' => 'Sessie slots',
                'description' => 'JSON array of slot configurations',
            ],
            'completion_mode' => [
                'type' => 'text',
                'label' => 'Completion Mode',
                'description' => 'automatic or manual',
            ],
            'completion_threshold' => [
                'type' => 'int',
                'label' => 'Completion Threshold',
                'description' => 'Percentage threshold for automatic completion',
            ],
            'notes' => [
                'type' => 'json',
                'label' => 'Notities',
                'description' => 'Array of edition notes',
            ],
        ];
    }

    /**
     * Filter post data before insert/update to set title from course.
     *
     * @param array<string, mixed> $data Post data.
     * @param array<string, mixed> $postarr Raw post array.
     * @return array<string, mixed>
     */
    public static function filterPostData(array $data, array $postarr): array
    {
        if ($data['post_type'] !== self::POST_TYPE) {
            return $data;
        }

        // Get course ID from meta being saved
        $courseId = 0;
        if (!empty($postarr['_ntdst_course_id'])) {
            $courseId = (int) $postarr['_ntdst_course_id'];
        } elseif (!empty($postarr['ID'])) {
            $courseId = (int) get_post_meta($postarr['ID'], '_ntdst_course_id', true);
        }

        if ($courseId > 0) {
            $course = get_post($courseId);
            if ($course instanceof \WP_Post) {
                // Set title from course
                $data['post_title'] = $course->post_title;

                // Generate slug from course slug if current slug is numeric
                $currentSlug = $data['post_name'] ?? '';
                if (empty($currentSlug) || is_numeric($currentSlug)) {
                    $baseSlug = $course->post_name;
                    $data['post_name'] = self::generateUniqueSlug($baseSlug, (int) ($postarr['ID'] ?? 0));
                }
            }
        }

        return $data;
    }

    /**
     * Generate slug from course on save.
     *
     * @param int      $postId Post ID.
     * @param \WP_Post $post   Post object.
     * @param bool     $update Whether this is an update.
     */
    public static function generateSlugFromCourse(int $postId, \WP_Post $post, bool $update): void
    {
        // Skip revisions and autosaves
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }

        // Skip if slug is already set to non-numeric value
        if (!is_numeric($post->post_name)) {
            return;
        }

        // Get course ID
        $courseId = (int) get_post_meta($postId, '_ntdst_course_id', true);
        if ($courseId <= 0) {
            return;
        }

        $course = get_post($courseId);
        if (!$course instanceof \WP_Post) {
            return;
        }

        // Generate unique slug
        $newSlug = self::generateUniqueSlug($course->post_name, $postId);

        // Update post slug without triggering infinite loop
        remove_action('save_post_' . self::POST_TYPE, [self::class, 'generateSlugFromCourse'], 10);
        wp_update_post([
            'ID' => $postId,
            'post_name' => $newSlug,
            'post_title' => $course->post_title,
        ]);
        add_action('save_post_' . self::POST_TYPE, [self::class, 'generateSlugFromCourse'], 10, 3);
    }

    /**
     * Generate unique slug for edition.
     *
     * If multiple editions exist for the same course, appends a suffix.
     *
     * @param string $baseSlug Base slug from course.
     * @param int    $excludeId Post ID to exclude from uniqueness check.
     * @return string Unique slug.
     */
    private static function generateUniqueSlug(string $baseSlug, int $excludeId = 0): string
    {
        global $wpdb;

        $slug = $baseSlug;
        $suffix = 2;

        while (true) {
            $query = $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s AND ID != %d LIMIT 1",
                $slug,
                self::POST_TYPE,
                $excludeId
            );

            $exists = $wpdb->get_var($query);

            if (!$exists) {
                break;
            }

            $slug = $baseSlug . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }
}
