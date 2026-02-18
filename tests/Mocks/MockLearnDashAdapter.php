<?php

namespace Stride\Tests\Mocks;

use stride\services\contracts\LearnDashAdapterInterface;

/**
 * Mock LearnDash Adapter for Testing
 *
 * Provides in-memory storage for LearnDash operations.
 * Tracks all calls for assertion in tests.
 */
class MockLearnDashAdapter implements LearnDashAdapterInterface
{
    private bool $available = true;

    /** @var array<int, array> Courses indexed by ID */
    private array $courses = [];

    /** @var array<int, array> Course settings indexed by course ID */
    private array $courseSettings = [];

    /** @var array<int, array<int>> Enrolled user IDs per course */
    private array $enrollments = [];

    /** @var array<string, int|null> Access timestamps: "{userId}_{courseId}" => timestamp */
    private array $accessTimestamps = [];

    /** @var array<string, bool> Completions: "{userId}_{courseId}" => completed */
    private array $completions = [];

    /** @var array All method calls for inspection */
    public array $calls = [];

    public function setAvailable(bool $available): self
    {
        $this->available = $available;
        return $this;
    }

    public function isAvailable(): bool
    {
        $this->calls[] = ['method' => 'isAvailable'];
        return $this->available;
    }

    /**
     * Seed a course for testing
     */
    public function seedCourse(array $data): int
    {
        $id = $data['ID'] ?? $data['id'] ?? count($this->courses) + 1000;

        // Create WP_Post object
        $this->courses[$id] = new \WP_Post([
            'ID' => $id,
            'post_type' => $data['post_type'] ?? 'sfwd-courses',
            'post_title' => $data['post_title'] ?? "Course {$id}",
            'post_status' => $data['post_status'] ?? 'publish',
        ]);

        $this->courseSettings[$id] = $data['settings'] ?? [
            'course_price' => 0,
            'course_price_type' => 'free',
        ];

        $this->enrollments[$id] = $data['enrolled_users'] ?? [];

        return $id;
    }

    public function getCourse(int $courseId): ?\WP_Post
    {
        $this->calls[] = ['method' => 'getCourse', 'args' => [$courseId]];

        // Return mock post object if exists
        if (isset($this->courses[$courseId])) {
            return $this->courses[$courseId];
        }

        return null;
    }

    public function getCourseSetting(int $courseId, string $key): mixed
    {
        $this->calls[] = ['method' => 'getCourseSetting', 'args' => [$courseId, $key]];

        return $this->courseSettings[$courseId][$key] ?? null;
    }

    public function getCourseSettings(int $courseId): array
    {
        $this->calls[] = ['method' => 'getCourseSettings', 'args' => [$courseId]];

        return $this->courseSettings[$courseId] ?? [];
    }

    public function hasAccess(int $courseId, int $userId): bool
    {
        $this->calls[] = ['method' => 'hasAccess', 'args' => [$courseId, $userId]];

        return in_array($userId, $this->enrollments[$courseId] ?? []);
    }

    public function getAccessFrom(int $userId, int $courseId): ?int
    {
        $this->calls[] = ['method' => 'getAccessFrom', 'args' => [$userId, $courseId]];

        $key = "{$userId}_{$courseId}";
        return $this->accessTimestamps[$key] ?? null;
    }

    public function getEnrolledUsers(int $courseId): array
    {
        $this->calls[] = ['method' => 'getEnrolledUsers', 'args' => [$courseId]];

        return $this->enrollments[$courseId] ?? [];
    }

    public function enrollUser(int $userId, int $courseId): bool
    {
        $this->calls[] = ['method' => 'enrollUser', 'args' => [$userId, $courseId]];

        if (!isset($this->enrollments[$courseId])) {
            $this->enrollments[$courseId] = [];
        }

        if (!in_array($userId, $this->enrollments[$courseId])) {
            $this->enrollments[$courseId][] = $userId;
        }

        $key = "{$userId}_{$courseId}";
        $this->accessTimestamps[$key] = time();

        return true;
    }

    public function unenrollUser(int $userId, int $courseId): bool
    {
        $this->calls[] = ['method' => 'unenrollUser', 'args' => [$userId, $courseId]];

        if (isset($this->enrollments[$courseId])) {
            $this->enrollments[$courseId] = array_values(array_filter(
                $this->enrollments[$courseId],
                fn($id) => $id !== $userId
            ));
        }

        $key = "{$userId}_{$courseId}";
        unset($this->accessTimestamps[$key]);
        unset($this->completions[$key]);

        return true;
    }

    public function hasCategory(int $courseId, string $categoryName): bool
    {
        $this->calls[] = ['method' => 'hasCategory', 'args' => [$courseId, $categoryName]];

        $settings = $this->courseSettings[$courseId] ?? [];
        $categories = $settings['categories'] ?? [];

        return in_array($categoryName, $categories);
    }

    public function isCompleted(int $userId, int $courseId): bool
    {
        $this->calls[] = ['method' => 'isCompleted', 'args' => [$userId, $courseId]];

        $key = "{$userId}_{$courseId}";
        return $this->completions[$key] ?? false;
    }

    public function getCertificateLink(int $courseId, int $userId): ?string
    {
        $this->calls[] = ['method' => 'getCertificateLink', 'args' => [$courseId, $userId]];

        $key = "{$userId}_{$courseId}";

        if ($this->completions[$key] ?? false) {
            return "https://example.com/certificate/{$courseId}/{$userId}";
        }

        return null;
    }

    // ========================================
    // TEST HELPERS
    // ========================================

    /**
     * Mark a user as having completed a course
     */
    public function markCompleted(int $userId, int $courseId): void
    {
        $key = "{$userId}_{$courseId}";
        $this->completions[$key] = true;
    }

    /**
     * Set course settings
     */
    public function setCourseSettings(int $courseId, array $settings): void
    {
        $this->courseSettings[$courseId] = array_merge(
            $this->courseSettings[$courseId] ?? [],
            $settings
        );
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
        $this->courses = [];
        $this->courseSettings = [];
        $this->enrollments = [];
        $this->accessTimestamps = [];
        $this->completions = [];
        $this->calls = [];
    }

    /**
     * Check if user is enrolled
     */
    public function isEnrolled(int $userId, int $courseId): bool
    {
        return in_array($userId, $this->enrollments[$courseId] ?? []);
    }
}
