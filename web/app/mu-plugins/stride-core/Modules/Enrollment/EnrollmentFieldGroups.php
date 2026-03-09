<?php

declare(strict_types=1);

namespace Stride\Modules\Enrollment;

/**
 * Enrollment field group data access.
 *
 * Plain class — manages reusable field group templates stored in wp_options.
 * Groups can be assigned to specific editions/trajectories or to all of a type.
 * Registered as singleton in the DI container by EnrollmentService.
 */
final class EnrollmentFieldGroups
{
    public const OPTION_KEY = 'stride_enrollment_field_groups';

    private const LEGACY_META_KEY = '_stride_enrollment_field_groups';
    private const LEGACY_FLAT_META_KEY = '_stride_enrollment_fields';

    /**
     * Get all field groups.
     *
     * @return array<array{id: string, label: string, step: string, assignments: array, fields: array}>
     */
    public function getAllGroups(): array
    {
        $groups = get_option(self::OPTION_KEY, []);

        return is_array($groups) ? $groups : [];
    }

    /**
     * Save all field groups.
     */
    public function saveGroups(array $groups): void
    {
        update_option(self::OPTION_KEY, $groups, false);
    }

    /**
     * Get field groups assigned to a specific post (edition or trajectory).
     *
     * Matches on:
     * - Direct post ID assignment
     * - '_all_editions' wildcard (for editions)
     * - '_all_trajectories' wildcard (for trajectories)
     *
     * @return array<array{label: string, step: string, fields: array}>
     */
    public function getFieldGroupsForPost(int $postId, string $postType = ''): array
    {
        if (!$postType) {
            $postType = get_post_type($postId) ?: '';
        }

        $wildcard = match ($postType) {
            'vad_edition' => '_all_editions',
            'vad_trajectory' => '_all_trajectories',
            default => '',
        };

        $allGroups = $this->getAllGroups();
        $matched = [];

        foreach ($allGroups as $group) {
            $assignments = $group['assignments'] ?? [];

            if (in_array($postId, $assignments, true) || ($wildcard && in_array($wildcard, $assignments, true))) {
                $matched[] = $group;
            }
        }

        return $matched;
    }

    /**
     * Get field groups for a specific step, assigned to a post.
     *
     * @param string $step 'personal' or 'billing'
     * @return array<array{label: string, step: string, fields: array}>
     */
    public function getFieldGroupsForStep(int $postId, string $postType, string $step): array
    {
        return array_values(array_filter(
            $this->getFieldGroupsForPost($postId, $postType),
            fn(array $group) => ($group['step'] ?? 'personal') === $step,
        ));
    }

    /**
     * Backward-compatible: flat field list for an edition.
     *
     * @return array<array{label: string, name: string, type: string, options: string, required: bool}>
     */
    public function getEnrollmentFieldsForEdition(int $editionId): array
    {
        return $this->getEnrollmentFieldsForPost($editionId, 'vad_edition');
    }

    /**
     * Flat field list for any post.
     *
     * @return array<array{label: string, name: string, type: string, options: string, required: bool}>
     */
    public function getEnrollmentFieldsForPost(int $postId, string $postType = ''): array
    {
        $groups = $this->getFieldGroupsForPost($postId, $postType);
        $fields = [];

        foreach ($groups as $group) {
            foreach ($group['fields'] ?? [] as $field) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * Migrate legacy per-course field groups to the central option.
     *
     * Called once from the settings page when legacy data is detected.
     *
     * @return int Number of groups migrated
     */
    public function migrateLegacyData(): int
    {
        $existing = $this->getAllGroups();
        if (!empty($existing)) {
            return 0;
        }

        $migrated = [];
        $nextId = 1;

        $courses = get_posts([
            'post_type' => 'sfwd-courses',
            'posts_per_page' => 500,
            'fields' => 'ids',
            'meta_query' => [
                'relation' => 'OR',
                ['key' => self::LEGACY_META_KEY, 'compare' => 'EXISTS'],
                ['key' => self::LEGACY_FLAT_META_KEY, 'compare' => 'EXISTS'],
            ],
        ]);

        foreach ($courses as $courseId) {
            $groups = get_post_meta($courseId, self::LEGACY_META_KEY, true);

            if (!is_array($groups) || empty($groups)) {
                $flatFields = get_post_meta($courseId, self::LEGACY_FLAT_META_KEY, true);
                if (is_array($flatFields) && !empty($flatFields)) {
                    $groups = [
                        [
                            'label' => 'Aanvullende informatie',
                            'step' => 'personal',
                            'fields' => $flatFields,
                        ],
                    ];
                }
            }

            if (!is_array($groups) || empty($groups)) {
                continue;
            }

            $editionIds = $this->getEditionIdsForCourse($courseId);

            foreach ($groups as $group) {
                $migrated[] = [
                    'id' => 'fg_' . $nextId++,
                    'label' => $group['label'] ?? 'Aanvullende informatie',
                    'step' => $group['step'] ?? 'personal',
                    'assignments' => $editionIds,
                    'fields' => $group['fields'] ?? [],
                ];
            }

            delete_post_meta($courseId, self::LEGACY_META_KEY);
            delete_post_meta($courseId, self::LEGACY_FLAT_META_KEY);
        }

        if (!empty($migrated)) {
            $this->saveGroups($migrated);
        }

        return count($migrated);
    }

    private function getEditionIdsForCourse(int $courseId): array
    {
        $editions = get_posts([
            'post_type' => 'vad_edition',
            'posts_per_page' => 500,
            'fields' => 'ids',
            'meta_query' => [
                ['key' => '_ntdst_course_id', 'value' => $courseId, 'type' => 'NUMERIC'],
            ],
        ]);

        return array_map('intval', $editions);
    }
}
