<?php
declare(strict_types=1);

namespace NetdustLTI\Repositories;

use WP_Error;
use WP_Post;

/**
 * Repository for LTI Tool CPT records
 *
 * Manages external LTI tools that this site (as Platform) can launch.
 * Uses NTDST Data Manager for all CRUD operations.
 * Returns WP_Post from find()/findBySlug(), arrays from all().
 */
final class ToolRepository
{
    private const POST_TYPE = 'lti_tool';

    /**
     * Find a tool by ID
     *
     * @param int $id Tool post ID
     * @return WP_Post|WP_Error Tool post or error if not found
     */
    public function find(int $id): WP_Post|WP_Error
    {
        $model = ntdst_data()->get(self::POST_TYPE);
        $post = $model->find($id);

        if (!$post || is_wp_error($post)) {
            return new WP_Error('not_found', 'Tool not found');
        }

        if ($post->post_type !== self::POST_TYPE) {
            return new WP_Error('not_found', 'Tool not found');
        }

        return $post;
    }

    /**
     * Find a tool by slug (post_name)
     *
     * Used for shortcode lookups like [lti_launch tool="articulate-tool"]
     *
     * @param string $slug Tool post slug
     * @return WP_Post|WP_Error Tool post or error if not found
     */
    public function findBySlug(string $slug): WP_Post|WP_Error
    {
        $model = ntdst_data()->get(self::POST_TYPE);

        $results = $model
            ->where('post_name', $slug)
            ->withMeta()
            ->limit(1)
            ->get();

        if (empty($results)) {
            return new WP_Error('not_found', 'Tool not found');
        }

        // get() returns arrays, need to retrieve the actual post
        return $model->find((int) $results[0]['ID']);
    }

    /**
     * Get all tools
     *
     * @return array Array of tool data arrays
     */
    public function all(): array
    {
        $model = ntdst_data()->get(self::POST_TYPE);
        return $model->withMeta()->orderBy('post_title', 'ASC')->get();
    }

    /**
     * Create a new tool
     *
     * @param array $data Tool data
     * @return int|WP_Error Post ID on success, WP_Error on failure
     */
    public function create(array $data): int|WP_Error
    {
        $model = ntdst_data()->get(self::POST_TYPE);

        $postData = [
            'title' => $data['name'] ?? 'Untitled Tool',
            'post_status' => 'publish',
            'launch_url' => $data['launch_url'] ?? '',
            'oidc_url' => $data['oidc_url'] ?? '',
            'jwks_url' => $data['jwks_url'] ?? '',
            'client_id' => $data['client_id'] ?? '',
            'deployment_id' => $data['deployment_id'] ?? '',
        ];

        $result = $model->create($postData);

        if (is_wp_error($result)) {
            return $result;
        }

        return $result->ID;
    }

    /**
     * Update an existing tool
     *
     * @param int $id Tool post ID
     * @param array $data Tool data to update
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function update(int $id, array $data): bool|WP_Error
    {
        $model = ntdst_data()->get(self::POST_TYPE);

        $updateData = [];
        if (isset($data['name'])) {
            $updateData['title'] = $data['name'];
        }

        $metaFields = [
            'launch_url',
            'oidc_url',
            'jwks_url',
            'client_id',
            'deployment_id',
        ];

        foreach ($metaFields as $field) {
            if (array_key_exists($field, $data)) {
                $updateData[$field] = $data[$field];
            }
        }

        $result = $model->update($id, $updateData);

        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }

    /**
     * Delete a tool
     *
     * @param int $id Tool post ID
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function delete(int $id): bool|WP_Error
    {
        $model = ntdst_data()->get(self::POST_TYPE);
        $result = $model->delete($id, true); // Force delete

        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }
}
