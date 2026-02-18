<?php

namespace Stride\Tests\Mocks;

use stride\services\contracts\StorageBackendInterface;

/**
 * Mock Storage Backend for Testing
 *
 * Simple in-memory storage backend that implements StorageBackendInterface.
 * Used to construct a testable UserDataSync instance.
 */
class MockStorageBackend implements StorageBackendInterface
{
    private string $id;
    private int $priority;
    private array $data = [];
    public array $calls = [];

    public function __construct(string $id = 'mock', int $priority = 100)
    {
        $this->id = $id;
        $this->priority = $priority;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function getSupportedFields(): array
    {
        // Support all fields
        return ['*'];
    }

    public function hasField(string $field): bool
    {
        return true; // Support all fields
    }

    public function getField(int $userId, string $field): mixed
    {
        $this->calls[] = ['method' => 'getField', 'args' => [$userId, $field]];
        return $this->data[$userId][$field] ?? null;
    }

    public function getFields(int $userId, array $fields = []): array
    {
        $this->calls[] = ['method' => 'getFields', 'args' => [$userId, $fields]];

        $userData = $this->data[$userId] ?? [];

        if (empty($fields)) {
            return $userData;
        }

        $result = [];
        foreach ($fields as $field) {
            $result[$field] = $userData[$field] ?? null;
        }

        return $result;
    }

    public function setField(int $userId, string $field, mixed $value): bool
    {
        $this->calls[] = ['method' => 'setField', 'args' => [$userId, $field, $value]];

        if (!isset($this->data[$userId])) {
            $this->data[$userId] = [];
        }

        $this->data[$userId][$field] = $value;
        return true;
    }

    public function setFields(int $userId, array $data): bool
    {
        $this->calls[] = ['method' => 'setFields', 'args' => [$userId, $data]];

        if (!isset($this->data[$userId])) {
            $this->data[$userId] = [];
        }

        foreach ($data as $field => $value) {
            $this->data[$userId][$field] = $value;
        }

        return true;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function clearCache(?int $userId = null): void
    {
        // No-op for mock
    }

    // Test helpers
    public function seedData(int $userId, array $data): void
    {
        $this->data[$userId] = array_merge($this->data[$userId] ?? [], $data);
    }

    public function getData(int $userId): array
    {
        return $this->data[$userId] ?? [];
    }

    public function reset(): void
    {
        $this->data = [];
        $this->calls = [];
    }
}
