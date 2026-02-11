<?php

namespace stride\services\contracts;

defined('ABSPATH') || exit;

/**
 * Storage Backend Interface
 *
 * Contract for user data storage backends. Backends can be chained
 * using the decorator pattern to sync data across multiple systems.
 *
 * Example chain: FluentCRM → WordPress → (future: Invoice system)
 *
 * @package stride
 */
interface StorageBackendInterface
{
    /**
     * Get the backend identifier
     *
     * @return string Unique identifier (e.g., 'wordpress', 'fluentcrm')
     */
    public function getId(): string;

    /**
     * Get priority for read operations (higher = checked first)
     *
     * @return int
     */
    public function getPriority(): int;

    /**
     * Get list of fields this backend handles
     *
     * @return array Field names this backend can store
     */
    public function getSupportedFields(): array;

    /**
     * Check if this backend handles a specific field
     *
     * @param string $field
     * @return bool
     */
    public function hasField(string $field): bool;

    /**
     * Get field value for user
     *
     * @param int $userId WordPress user ID
     * @param string $field Field name
     * @return mixed|null Value or null if not found
     */
    public function getField(int $userId, string $field): mixed;

    /**
     * Get multiple fields for user
     *
     * @param int $userId
     * @param array $fields Field names (empty = all fields)
     * @return array Field => value map
     */
    public function getFields(int $userId, array $fields = []): array;

    /**
     * Set field value for user
     *
     * @param int $userId
     * @param string $field
     * @param mixed $value
     * @return bool Success
     */
    public function setField(int $userId, string $field, mixed $value): bool;

    /**
     * Set multiple fields for user
     *
     * @param int $userId
     * @param array $data Field => value map
     * @return bool Success
     */
    public function setFields(int $userId, array $data): bool;

    /**
     * Check if backend is available
     *
     * @return bool
     */
    public function isAvailable(): bool;

    /**
     * Clear any cached data for user
     *
     * @param int|null $userId Specific user or null for all
     */
    public function clearCache(?int $userId = null): void;
}
