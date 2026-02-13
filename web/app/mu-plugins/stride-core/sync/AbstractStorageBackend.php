<?php

namespace ntdst\Stride\sync;

defined('ABSPATH') || exit;

use ntdst\Stride\contracts\StorageBackendInterface;
use ntdst\Stride\FieldRegistry;

/**
 * Abstract Storage Backend
 *
 * Base class for storage backends with built-in:
 * - Request-level caching
 * - Field mapping via FieldRegistry
 * - Decorator pattern support
 *
 * @package stride
 */
abstract class AbstractStorageBackend implements StorageBackendInterface
{
    /**
     * Request-level cache: [userId => [field => value]]
     */
    protected static array $cache = [];

    /**
     * Field context for FieldRegistry ('subscriber', 'company', etc.)
     */
    protected string $fieldContext = 'subscriber';

    /**
     * Field definitions: [logical_name => storage_key]
     * Override in child classes
     */
    protected array $fieldMap = [];

    /**
     * @inheritDoc
     */
    abstract public function getId(): string;

    /**
     * @inheritDoc
     */
    public function getPriority(): int
    {
        return 10;
    }

    /**
     * @inheritDoc
     */
    public function getSupportedFields(): array
    {
        return array_keys($this->fieldMap);
    }

    /**
     * @inheritDoc
     */
    public function hasField(string $field): bool
    {
        return isset($this->fieldMap[$field]) || in_array($field, $this->fieldMap, true);
    }

    /**
     * @inheritDoc
     */
    public function getField(int $userId, string $field): mixed
    {
        // Check cache first
        $cacheKey = $this->getCacheKey($userId, $field);
        if (isset(static::$cache[$cacheKey])) {
            return static::$cache[$cacheKey];
        }

        // Skip if we don't handle this field
        if (!$this->hasField($field)) {
            return null;
        }

        // Get from storage
        $storageKey = $this->getStorageKey($field);
        $value = $this->readFromStorage($userId, $storageKey);

        // Cache the result
        static::$cache[$cacheKey] = $value;

        return $value;
    }

    /**
     * @inheritDoc
     */
    public function getFields(int $userId, array $fields = []): array
    {
        $fields = empty($fields) ? $this->getSupportedFields() : $fields;
        $result = [];

        foreach ($fields as $field) {
            if ($this->hasField($field)) {
                $value = $this->getField($userId, $field);
                if ($value !== null) {
                    $result[$field] = $value;
                }
            }
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function setField(int $userId, string $field, mixed $value): bool
    {
        if (!$this->hasField($field)) {
            return false;
        }

        $storageKey = $this->getStorageKey($field);
        $success = $this->writeToStorage($userId, $storageKey, $value);

        if ($success) {
            // Update cache
            $cacheKey = $this->getCacheKey($userId, $field);
            static::$cache[$cacheKey] = $value;
        }

        return $success;
    }

    /**
     * @inheritDoc
     */
    public function setFields(int $userId, array $data): bool
    {
        $success = true;

        foreach ($data as $field => $value) {
            if (!$this->setField($userId, $field, $value)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * @inheritDoc
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function clearCache(?int $userId = null): void
    {
        if ($userId === null) {
            static::$cache = [];
        } else {
            $prefix = $this->getId() . '_' . $userId . '_';
            foreach (array_keys(static::$cache) as $key) {
                if (str_starts_with($key, $prefix)) {
                    unset(static::$cache[$key]);
                }
            }
        }
    }

    /**
     * Get storage key for a logical field name
     * Uses FieldRegistry for legacy mapping if applicable
     *
     * @param string $field Logical field name
     * @return string Storage key
     */
    protected function getStorageKey(string $field): string
    {
        // Direct mapping takes precedence
        if (isset($this->fieldMap[$field])) {
            return $this->fieldMap[$field];
        }

        // Check if field is already a storage key
        if (in_array($field, $this->fieldMap, true)) {
            return $field;
        }

        // Use FieldRegistry for legacy mapping
        if (FieldRegistry::useLegacyFieldNames()) {
            return FieldRegistry::newToLegacy($field, $this->fieldContext);
        }

        return $field;
    }

    /**
     * Get logical field name from storage key
     *
     * @param string $storageKey
     * @return string
     */
    protected function getLogicalField(string $storageKey): string
    {
        $flipped = array_flip($this->fieldMap);
        if (isset($flipped[$storageKey])) {
            return $flipped[$storageKey];
        }

        return FieldRegistry::legacyToNew($storageKey, $this->fieldContext);
    }

    /**
     * Generate cache key
     */
    protected function getCacheKey(int $userId, string $field): string
    {
        return $this->getId() . '_' . $userId . '_' . $field;
    }

    /**
     * Read value from actual storage
     * Override in child classes
     *
     * @param int $userId
     * @param string $storageKey The actual key in storage
     * @return mixed
     */
    abstract protected function readFromStorage(int $userId, string $storageKey): mixed;

    /**
     * Write value to actual storage
     * Override in child classes
     *
     * @param int $userId
     * @param string $storageKey
     * @param mixed $value
     * @return bool
     */
    abstract protected function writeToStorage(int $userId, string $storageKey, mixed $value): bool;
}
