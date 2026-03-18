<?php
declare(strict_types=1);

namespace NetdustLTI\ToolProvider;

use WP_Error;

/**
 * Repository for LTI Context records.
 *
 * Stores contexts as a JSON field on lti_platform CPT via Data Manager.
 * Each platform has a 'contexts' field containing an array of contexts.
 */
final class ContextRepository
{
    /**
     * Get all contexts for a platform via Data Manager.
     */
    private function getContexts(int $platformId): array
    {
        $model = ntdst_data()->get('lti_platform');
        $platform = $model->find($platformId);

        if (!$platform || is_wp_error($platform)) {
            return [];
        }

        $contextJson = $platform->fields['contexts'] ?? '';
        if (empty($contextJson)) {
            return [];
        }

        $contexts = json_decode($contextJson, true);
        return is_array($contexts) ? $contexts : [];
    }

    /**
     * Save all contexts for a platform via Data Manager.
     */
    private function saveContexts(int $platformId, array $contexts): void
    {
        $model = ntdst_data()->get('lti_platform');
        $model->updateMeta($platformId, 'contexts', wp_json_encode($contexts));
    }

    /**
     * Generate a unique context key.
     */
    private function generateContextKey(string $ltiContextId, ?string $resourceLinkId): string
    {
        if ($resourceLinkId) {
            return "{$ltiContextId}_{$resourceLinkId}";
        }
        return $ltiContextId;
    }

    /**
     * Generate a deterministic ID for a context.
     */
    private function generateContextId(int $platformId, string $contextKey): int
    {
        return abs(crc32("{$platformId}_{$contextKey}"));
    }

    /**
     * Find context by ID.
     */
    public function find(int $id): array|WP_Error
    {
        // With field-based storage, we need to scan all platforms
        // This is less efficient but contexts are rarely accessed by ID directly
        $model = ntdst_data()->get('lti_platform');
        $platforms = $model->where('post_status', 'publish')->get();

        foreach ($platforms as $platform) {
            $platformId = (int) $platform['ID'];
            $contexts = $this->getContexts($platformId);
            foreach ($contexts as $key => $context) {
                $contextId = $this->generateContextId($platformId, $key);
                if ($contextId === $id) {
                    return array_merge($context, [
                        'id' => $contextId,
                        'platform_id' => $platformId,
                    ]);
                }
            }
        }

        return new WP_Error('not_found', 'Context not found');
    }

    /**
     * Find context by LTI context identifiers.
     */
    public function findByLtiContext(int $platformId, string $ltiContextId, ?string $resourceLinkId = null): array|null
    {
        $contexts = $this->getContexts($platformId);

        // Try exact match with resource link first
        if ($resourceLinkId) {
            $key = $this->generateContextKey($ltiContextId, $resourceLinkId);
            if (isset($contexts[$key])) {
                return array_merge($contexts[$key], [
                    'id' => $this->generateContextId($platformId, $key),
                    'platform_id' => $platformId,
                ]);
            }
        }

        // Try match without resource link
        $key = $ltiContextId;
        if (isset($contexts[$key])) {
            return array_merge($contexts[$key], [
                'id' => $this->generateContextId($platformId, $key),
                'platform_id' => $platformId,
            ]);
        }

        return null;
    }

    /**
     * Find all contexts for a LearnDash course.
     */
    public function findByCourseId(int $courseId): array
    {
        $results = [];

        $model = ntdst_data()->get('lti_platform');
        $platforms = $model->where('post_status', 'publish')->get();

        foreach ($platforms as $platform) {
            $platformId = (int) $platform['ID'];
            $contexts = $this->getContexts($platformId);
            foreach ($contexts as $key => $context) {
                if ((int) ($context['ld_course_id'] ?? 0) === $courseId) {
                    $results[] = array_merge($context, [
                        'id' => $this->generateContextId($platformId, $key),
                        'platform_id' => $platformId,
                    ]);
                }
            }
        }

        return $results;
    }

    /**
     * Create a new context.
     */
    public function create(array $data): int|WP_Error
    {
        $platformId = (int) ($data['platform_id'] ?? 0);
        if (!$platformId) {
            return new WP_Error('invalid_platform', 'Platform ID is required');
        }

        $ltiContextId = $data['lti_context_id'] ?? '';
        if (empty($ltiContextId)) {
            return new WP_Error('invalid_context', 'LTI Context ID is required');
        }

        $contexts = $this->getContexts($platformId);
        $key = $this->generateContextKey($ltiContextId, $data['resource_link_id'] ?? null);

        if (isset($contexts[$key])) {
            return new WP_Error('duplicate', 'Context already exists');
        }

        $now = current_time('mysql');
        $contexts[$key] = [
            'lti_context_id' => $ltiContextId,
            'ld_course_id' => (int) ($data['ld_course_id'] ?? 0),
            'resource_link_id' => $data['resource_link_id'] ?? null,
            'line_item_url' => $data['line_item_url'] ?? null,
            'settings' => $data['settings'] ?? [],
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $this->saveContexts($platformId, $contexts);

        return $this->generateContextId($platformId, $key);
    }

    /**
     * Update an existing context.
     */
    public function update(int $id, array $data): bool|WP_Error
    {
        // Find the context first
        $model = ntdst_data()->get('lti_platform');
        $platforms = $model->where('post_status', 'publish')->get();

        foreach ($platforms as $platform) {
            $platformId = (int) $platform['ID'];
            $contexts = $this->getContexts($platformId);
            foreach ($contexts as $key => $context) {
                $contextId = $this->generateContextId($platformId, $key);
                if ($contextId === $id) {
                    // Found it, update
                    if (array_key_exists('line_item_url', $data)) {
                        $contexts[$key]['line_item_url'] = $data['line_item_url'];
                    }
                    if (array_key_exists('settings', $data)) {
                        $contexts[$key]['settings'] = $data['settings'];
                    }
                    $contexts[$key]['updated_at'] = current_time('mysql');

                    $this->saveContexts($platformId, $contexts);
                    return true;
                }
            }
        }

        return new WP_Error('not_found', 'Context not found');
    }

    /**
     * Delete a context.
     */
    public function delete(int $id): bool|WP_Error
    {
        $model = ntdst_data()->get('lti_platform');
        $platforms = $model->where('post_status', 'publish')->get();

        foreach ($platforms as $platform) {
            $platformId = (int) $platform['ID'];
            $contexts = $this->getContexts($platformId);
            foreach ($contexts as $key => $context) {
                $contextId = $this->generateContextId($platformId, $key);
                if ($contextId === $id) {
                    unset($contexts[$key]);
                    $this->saveContexts($platformId, $contexts);
                    return true;
                }
            }
        }

        return new WP_Error('not_found', 'Context not found');
    }
}
