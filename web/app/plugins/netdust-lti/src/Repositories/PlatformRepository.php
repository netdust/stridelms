<?php
declare(strict_types=1);

namespace NetdustLTI\Repositories;

use WP_Error;
use WP_Post;

/**
 * Repository for LTI Platform CPT records
 *
 * Uses NTDST Data Manager for all CRUD operations.
 * Returns WP_Post from find(), arrays from all()/allEnabled().
 */
final class PlatformRepository
{
    private const POST_TYPE = 'lti_platform';

    /**
     * Find a platform by ID
     *
     * @param int $id Platform post ID
     * @return WP_Post|WP_Error Platform post or error if not found
     */
    public function find(int $id): WP_Post|WP_Error
    {
        $model = ntdst_data()->get(self::POST_TYPE);
        $post = $model->find($id);

        if (!$post || is_wp_error($post)) {
            return new WP_Error('not_found', 'Platform not found');
        }

        if ($post->post_type !== self::POST_TYPE) {
            return new WP_Error('not_found', 'Platform not found');
        }

        return $post;
    }

    /**
     * Find a platform by issuer (platform_id) and client_id
     *
     * @param string $platformId The issuer/platform identifier URL
     * @param string $clientId The OAuth2 client ID
     * @return WP_Post|WP_Error Platform post or error if not found
     */
    public function findByIssuerAndClient(string $platformId, string $clientId): WP_Post|WP_Error
    {
        $model = ntdst_data()->get(self::POST_TYPE);

        $results = $model
            ->where('platform_id', $platformId)
            ->where('client_id', $clientId)
            ->withMeta()
            ->limit(1)
            ->get();

        if (empty($results)) {
            return new WP_Error('not_found', 'Platform not found');
        }

        // get() returns arrays, need to retrieve the actual post
        return $model->find((int) $results[0]['ID']);
    }

    /**
     * Get all platforms
     *
     * @return array Array of platform data arrays
     */
    public function all(): array
    {
        $model = ntdst_data()->get(self::POST_TYPE);
        return $model->withMeta()->orderBy('post_title', 'ASC')->get();
    }

    /**
     * Get all enabled platforms
     *
     * @return array Array of enabled platform data arrays
     */
    public function allEnabled(): array
    {
        $model = ntdst_data()->get(self::POST_TYPE);
        return $model
            ->where('enabled', true)
            ->withMeta()
            ->orderBy('post_title', 'ASC')
            ->get();
    }

    /**
     * Create a new platform
     *
     * @param array $data Platform data
     * @return int|WP_Error Post ID on success, WP_Error on failure
     */
    public function create(array $data): int|WP_Error
    {
        $model = ntdst_data()->get(self::POST_TYPE);

        $postData = [
            'title' => $data['name'] ?? 'Untitled Platform',
            'post_status' => 'publish',
            'platform_id' => $data['platform_id'] ?? '',
            'client_id' => $data['client_id'] ?? '',
            'deployment_id' => $data['deployment_id'] ?? '',
            'auth_endpoint' => $data['auth_endpoint'] ?? '',
            'token_endpoint' => $data['token_endpoint'] ?? '',
            'jwks_endpoint' => $data['jwks_endpoint'] ?? '',
            'enabled' => $data['enabled'] ?? true,
        ];

        $result = $model->create($postData);

        if (is_wp_error($result)) {
            return $result;
        }

        return $result->ID;
    }

    /**
     * Update an existing platform
     *
     * @param int $id Platform post ID
     * @param array $data Platform data to update
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
            'platform_id',
            'client_id',
            'deployment_id',
            'auth_endpoint',
            'token_endpoint',
            'jwks_endpoint',
            'enabled',
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
     * Delete a platform
     *
     * @param int $id Platform post ID
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
