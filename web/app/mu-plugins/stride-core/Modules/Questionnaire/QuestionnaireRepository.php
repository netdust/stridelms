<?php

declare(strict_types=1);

namespace Stride\Modules\Questionnaire;

/**
 * QuestionnaireRepository
 *
 * Manages field group definitions stored in wp_options.
 * Plain class — not a service (no hooks). Resolved via DI autowiring when injected.
 */
class QuestionnaireRepository
{
    public const OPTION_KEY = 'stride_questionnaire_field_groups';

    public const STAGES = [
        'interest',
        'waitlist',
        'enrollment_personal',
        'enrollment_billing',
        'intake',
        'evaluation',
    ];

    private const WILDCARD_EDITIONS     = '_all_editions';
    private const WILDCARD_TRAJECTORIES = '_all_trajectories';

    /**
     * Returns all field groups from wp_options.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllGroups(): array
    {
        $raw = get_option(self::OPTION_KEY, []);

        if (!is_array($raw)) {
            return [];
        }

        return $raw;
    }

    /**
     * Saves all field groups to wp_options.
     *
     * @param array<int, array<string, mixed>> $groups
     */
    public function saveGroups(array $groups): void
    {
        update_option(self::OPTION_KEY, $groups);
    }

    /**
     * Returns field groups assigned to a given post.
     *
     * Matches on:
     * - Direct post ID in assignments array
     * - Wildcard `_all_editions` when post type is `vad_edition`
     * - Wildcard `_all_trajectories` when post type is `vad_trajectory`
     *
     * @return array<int, array<string, mixed>>
     */
    public function getGroupsForPost(int $postId, string $postType = ''): array
    {
        if ($postType === '') {
            $postType = (string) get_post_type($postId);
        }

        $groups = $this->getAllGroups();
        $matched = [];

        foreach ($groups as $group) {
            $assignments = $group['assignments'] ?? [];

            if ($this->matchesAssignment($postId, $postType, $assignments)) {
                $matched[] = $group;
            }
        }

        return $matched;
    }

    /**
     * Returns field groups for a post filtered by stage.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getGroupsForStage(int $postId, string $stage, string $postType = 'vad_edition'): array
    {
        $groups = $this->getGroupsForPost($postId, $postType);

        return array_values(
            array_filter($groups, static fn(array $g) => ($g['stage'] ?? '') === $stage)
        );
    }

    /**
     * Returns a flat list of all fields across groups for a given post and stage.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getFlatFieldsForStage(int $postId, string $stage, string $postType = 'vad_edition'): array
    {
        $groups = $this->getGroupsForStage($postId, $stage, $postType);
        $fields = [];

        foreach ($groups as $group) {
            foreach ($group['fields'] ?? [] as $field) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<int, mixed> $assignments
     */
    private function matchesAssignment(int $postId, string $postType, array $assignments): bool
    {
        // Direct post ID match
        if (in_array($postId, $assignments, strict: true)) {
            return true;
        }

        // Wildcard match for editions
        if (
            $postType === 'vad_edition'
            && in_array(self::WILDCARD_EDITIONS, $assignments, strict: true)
        ) {
            return true;
        }

        // Wildcard match for trajectories
        if (
            $postType === 'vad_trajectory'
            && in_array(self::WILDCARD_TRAJECTORIES, $assignments, strict: true)
        ) {
            return true;
        }

        return false;
    }
}
