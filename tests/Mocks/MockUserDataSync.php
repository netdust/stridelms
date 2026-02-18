<?php

namespace Stride\Tests\Mocks;

/**
 * Mock UserDataSync for Testing
 *
 * Provides in-memory storage for user data sync operations.
 * Simulates the multi-backend write-through behavior.
 */
class MockUserDataSync
{
    /** @var array<int, array> Fields per user */
    private array $userData = [];

    /** @var array All method calls for inspection */
    public array $calls = [];

    /**
     * Get a single field for a user
     */
    public function getField(int $userId, string $fieldName): mixed
    {
        $this->calls[] = ['method' => 'getField', 'args' => [$userId, $fieldName]];

        return $this->userData[$userId][$fieldName] ?? null;
    }

    /**
     * Get multiple fields for a user
     */
    public function getFields(int $userId, array $fieldNames): array
    {
        $this->calls[] = ['method' => 'getFields', 'args' => [$userId, $fieldNames]];

        $result = [];

        foreach ($fieldNames as $fieldName) {
            $result[$fieldName] = $this->userData[$userId][$fieldName] ?? null;
        }

        return $result;
    }

    /**
     * Set a single field for a user
     */
    public function setField(int $userId, string $fieldName, mixed $value): bool
    {
        $this->calls[] = ['method' => 'setField', 'args' => [$userId, $fieldName, $value]];

        if (!isset($this->userData[$userId])) {
            $this->userData[$userId] = [];
        }

        $this->userData[$userId][$fieldName] = $value;

        return true;
    }

    /**
     * Set multiple fields for a user
     */
    public function setFields(int $userId, array $fields): bool
    {
        $this->calls[] = ['method' => 'setFields', 'args' => [$userId, $fields]];

        if (!isset($this->userData[$userId])) {
            $this->userData[$userId] = [];
        }

        foreach ($fields as $fieldName => $value) {
            $this->userData[$userId][$fieldName] = $value;
        }

        return true;
    }

    /**
     * Find or create a user by email
     */
    public function findOrCreateUser(string $email, array $data = []): int|false
    {
        $this->calls[] = ['method' => 'findOrCreateUser', 'args' => [$email, $data]];

        // Look for existing user by email
        foreach ($this->userData as $userId => $fields) {
            if (($fields['email'] ?? '') === $email) {
                return $userId;
            }
        }

        // Create new user (mock ID generation)
        static $nextUserId = 100;
        $userId = $nextUserId++;

        $this->userData[$userId] = array_merge(['email' => $email], $data);

        return $userId;
    }

    /**
     * Check if user exists
     */
    public function userExists(int $userId): bool
    {
        $this->calls[] = ['method' => 'userExists', 'args' => [$userId]];

        return isset($this->userData[$userId]);
    }

    /**
     * Get all data for a user
     */
    public function getAllFields(int $userId): array
    {
        $this->calls[] = ['method' => 'getAllFields', 'args' => [$userId]];

        return $this->userData[$userId] ?? [];
    }

    // ========================================
    // TEST HELPERS
    // ========================================

    /**
     * Seed user data for testing
     */
    public function seedUser(int $userId, array $data): void
    {
        $this->userData[$userId] = $data;
    }

    /**
     * Get all recorded method calls
     */
    public function getCalls(): array
    {
        return $this->calls;
    }

    /**
     * Get calls for a specific method
     */
    public function getCallsFor(string $method): array
    {
        return array_filter($this->calls, fn($call) => $call['method'] === $method);
    }

    /**
     * Check if method was called
     */
    public function wasCalled(string $method): bool
    {
        return count($this->getCallsFor($method)) > 0;
    }

    /**
     * Reset all data
     */
    public function reset(): void
    {
        $this->userData = [];
        $this->calls = [];
    }

    /**
     * Get raw user data (for debugging)
     */
    public function getUserData(int $userId): array
    {
        return $this->userData[$userId] ?? [];
    }
}
