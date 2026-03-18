<?php

declare(strict_types=1);

namespace Netdust\Mail;

defined('ABSPATH') || exit;

/**
 * Repository for email templates.
 *
 * Handles CRUD operations for ndmail_template CPT using the NTDST Data Manager.
 */
class MailTemplateRepository
{
    private const POST_TYPE = MailTemplateCPT::POST_TYPE;

    /**
     * Get the post type name.
     */
    public function getPostType(): string
    {
        return self::POST_TYPE;
    }

    /**
     * Find a template by its slug (post_name).
     *
     * @param string $slug The template post_name.
     * @return \WP_Post|null Template with ->fields attached, or null if not found.
     */
    public function findBySlug(string $slug): ?\WP_Post
    {
        $model = ntdst_data()->get(self::POST_TYPE);
        $results = $model->where('post_name', $slug)
            ->where('post_status', 'publish')
            ->withMeta()
            ->limit(1)
            ->get();

        $posts = $this->convertResultsToPosts($results);

        return $posts[0] ?? null;
    }

    /**
     * Find a template by ID.
     *
     * @param int $id The template post ID.
     * @return \WP_Post|null Template with ->fields and ->meta attached, or null if not found.
     */
    public function findById(int $id): ?\WP_Post
    {
        $model = ntdst_data()->get(self::POST_TYPE);
        $result = $model->find($id);

        if (is_wp_error($result)) {
            return null;
        }

        return $result;
    }

    /**
     * Find all templates that have triggers configured.
     *
     * @return array<\WP_Post> List of template posts with ->fields attached.
     */
    public function findWithTriggers(): array
    {
        $model = ntdst_data()->get(self::POST_TYPE);
        $results = $model->where('post_status', 'publish')
            ->whereNot('trigger', '')
            ->withMeta()
            ->get();

        return $this->convertResultsToPosts($results);
    }

    /**
     * Find templates by category.
     *
     * @param string $category The category value (e.g., 'auth', 'notification').
     * @return array<\WP_Post> List of template posts with ->fields attached.
     */
    public function findByCategory(string $category): array
    {
        $model = ntdst_data()->get(self::POST_TYPE);
        $results = $model->where('post_status', 'publish')
            ->where('category', $category)
            ->withMeta()
            ->get();

        return $this->convertResultsToPosts($results);
    }

    /**
     * Find all published templates.
     *
     * @return array<\WP_Post> List of all published template posts with ->fields attached.
     */
    public function findAll(): array
    {
        $model = ntdst_data()->get(self::POST_TYPE);
        $results = $model->where('post_status', 'publish')
            ->withMeta()
            ->get();

        return $this->convertResultsToPosts($results);
    }

    /**
     * Meta prefix used by this CPT.
     */
    private const META_PREFIX = '_ndmail_';

    /**
     * Convert query builder results to WP_Post array with fields attached.
     *
     * Data Manager returns lowercase 'id' and 'meta' keys with full prefixed names.
     * We strip the prefix for easier access (e.g., 'status' instead of '_ndmail_status').
     *
     * @param array $results Query builder results (associative arrays).
     * @return array<\WP_Post> Posts with ->fields attached.
     */
    private function convertResultsToPosts(array $results): array
    {
        $posts = [];
        foreach ($results as $result) {
            // Data Manager uses lowercase 'id'
            $postId = $result['id'] ?? $result['ID'] ?? null;
            if (!$postId) {
                continue;
            }

            $post = get_post($postId);
            if ($post) {
                // Data Manager uses 'meta' key with prefixed field names
                $rawMeta = $result['meta'] ?? $result['fields'] ?? [];

                // Strip meta prefix for cleaner field access
                $fields = [];
                foreach ($rawMeta as $key => $value) {
                    $cleanKey = str_starts_with($key, self::META_PREFIX)
                        ? substr($key, strlen(self::META_PREFIX))
                        : $key;
                    $fields[$cleanKey] = $value;
                }

                $post->fields = $fields;
                $posts[] = $post;
            }
        }
        return $posts;
    }

    /**
     * Create a new email template.
     *
     * @param array $data Template data including title, subject, body, category, status, trigger.
     * @return \WP_Post|\WP_Error The created post or error.
     */
    public function create(array $data): \WP_Post|\WP_Error
    {
        $model = ntdst_data()->get(self::POST_TYPE);

        // Ensure post_status defaults to draft if not provided
        if (!isset($data['post_status'])) {
            $data['post_status'] = 'draft';
        }

        return $model->create($data);
    }

    /**
     * Update an existing email template.
     *
     * @param int $id The template post ID.
     * @param array $data Updated template data.
     * @return \WP_Post|\WP_Error The updated post or error.
     */
    public function update(int $id, array $data): \WP_Post|\WP_Error
    {
        $model = ntdst_data()->get(self::POST_TYPE);
        return $model->update($id, $data);
    }

    /**
     * Delete an email template.
     *
     * @param int $id The template post ID.
     * @param bool $force Whether to permanently delete (true) or trash (false).
     * @return bool|\WP_Error True on success, WP_Error on failure.
     */
    public function delete(int $id, bool $force = false): bool|\WP_Error
    {
        $model = ntdst_data()->get(self::POST_TYPE);
        return $model->delete($id, $force);
    }
}
