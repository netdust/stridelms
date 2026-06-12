<?php

declare(strict_types=1);

namespace Stride\Infrastructure;

use Stride\Contracts\RepositoryInterface;
use WP_Error;
use WP_Post;

/**
 * Base repository for CPT-based entities.
 *
 * Uses ntdst_data() for all database operations.
 * Child classes must define $postType.
 */
abstract class AbstractRepository implements RepositoryInterface
{
    /**
     * Post type slug - must be set by child class.
     */
    protected string $postType;

    /**
     * Get the Data Manager model.
     */
    protected function model(): mixed
    {
        return ntdst_data()->get($this->postType);
    }

    public function find(int $id, bool $skipCache = false): WP_Post|WP_Error
    {
        $post = $this->model()->find($id, $skipCache);

        if ($post === null || is_wp_error($post)) {
            return new WP_Error(
                'not_found',
                sprintf('%s with ID %d not found', $this->postType, $id),
            );
        }

        return $post;
    }

    public function create(array $data): WP_Post|WP_Error
    {
        $result = $this->model()->create($data);

        if (is_wp_error($result)) {
            return $result;
        }

        return $result;
    }

    public function update(int $id, array $data): WP_Post|WP_Error
    {
        $result = $this->model()->update($id, $data);

        if (is_wp_error($result)) {
            return $result;
        }

        return $this->find($id);
    }

    public function delete(int $id, bool $force = false): bool|WP_Error
    {
        $result = $this->model()->delete($id, $force);

        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }

    /**
     * Read a single registered field value (typed per schema).
     */
    public function getField(int $id, string $field, mixed $default = null): mixed
    {
        return $this->model()->getMeta($id, $field, $default);
    }

    /**
     * Read all registered field values for a post (typed per schema).
     *
     * @return array<string, mixed>
     */
    public function findFields(int $id): array
    {
        return $this->model()->getMeta($id);
    }

    /**
     * The model's meta prefix (e.g. '_ntdst_').
     *
     * Needed when callers read batch query results from getPostsFast() / withMeta()
     * — those return meta nested under a 'meta' key, with raw prefixed keys.
     * Pull the prefix from here rather than hardcoding the string at the call site.
     */
    public function getMetaPrefix(): string
    {
        return $this->model()->getMetaPrefix();
    }

    /**
     * Get all records with optional filters.
     *
     * @param array<string, mixed> $filters
     * @return array<array<string, mixed>>
     */
    public function all(array $filters = [], int $limit = -1): array
    {
        $query = $this->model();

        foreach ($filters as $field => $value) {
            $query = $query->where($field, $value);
        }

        return $query->limit($limit)->withMeta()->get();
    }

    /**
     * Count records matching filters.
     *
     * @param array<string, mixed> $filters
     */
    public function count(array $filters = []): int
    {
        $query = $this->model();

        foreach ($filters as $field => $value) {
            $query = $query->where($field, $value);
        }

        return $query->count();
    }
}
