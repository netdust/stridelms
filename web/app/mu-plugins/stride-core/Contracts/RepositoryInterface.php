<?php

declare(strict_types=1);

namespace Stride\Contracts;

use WP_Error;
use WP_Post;

/**
 * Base repository contract for all data access.
 *
 * All repositories return WP_Error on failure, never null/false.
 */
interface RepositoryInterface
{
    /**
     * Find a single record by ID.
     *
     * @return WP_Post|WP_Error
     */
    public function find(int $id): WP_Post|WP_Error;

    /**
     * Create a new record.
     *
     * @param array<string, mixed> $data
     * @return WP_Post|WP_Error
     */
    public function create(array $data): WP_Post|WP_Error;

    /**
     * Update an existing record.
     *
     * @param array<string, mixed> $data
     * @return WP_Post|WP_Error
     */
    public function update(int $id, array $data): WP_Post|WP_Error;

    /**
     * Delete a record.
     *
     * @return bool|WP_Error
     */
    public function delete(int $id, bool $force = false): bool|WP_Error;
}
