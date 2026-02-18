<?php

namespace Stride\Tests\Mocks;

use stride\services\contracts\FluentCRMAdapterInterface;

/**
 * Mock FluentCRM Adapter for Testing
 *
 * Provides in-memory storage for all FluentCRM operations.
 * Tracks all calls for assertion in tests.
 */
class MockFluentCRMAdapter implements FluentCRMAdapterInterface
{
    private bool $available = true;

    /** @var array<int, array> Subscribers indexed by ID */
    private array $subscribers = [];

    /** @var array<int, int> Map of user_id => subscriber_id */
    private array $userIdMap = [];

    /** @var array<string, int> Map of email => subscriber_id */
    private array $emailMap = [];

    /** @var array<int, array> Custom fields per subscriber */
    private array $customFields = [];

    /** @var array<int, array> Tags per subscriber */
    private array $tags = [];

    /** @var array<int, array> Notes per subscriber */
    private array $notes = [];

    /** @var array<int, array> Companies indexed by ID */
    private array $companies = [];

    /** @var array<int, array<int>> Company links per subscriber */
    private array $companyLinks = [];

    /** @var array<int, array> Company custom fields */
    private array $companyCustomFields = [];

    /** @var array All method calls for inspection */
    public array $calls = [];

    private int $nextSubscriberId = 1;
    private int $nextNoteId = 1;
    private int $nextCompanyId = 1;

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
     * Seed a subscriber for testing
     */
    public function seedSubscriber(array $data): int
    {
        $id = $data['id'] ?? $this->nextSubscriberId++;

        $this->subscribers[$id] = array_merge([
            'id' => $id,
            'email' => "user{$id}@test.com",
            'first_name' => 'Test',
            'last_name' => 'User',
            'full_name' => 'Test User',
            'phone' => '',
            'status' => 'subscribed',
        ], $data);

        if (isset($data['user_id'])) {
            $this->userIdMap[$data['user_id']] = $id;
        }

        $this->emailMap[$this->subscribers[$id]['email']] = $id;
        $this->customFields[$id] = $data['custom_fields'] ?? [];

        return $id;
    }

    /**
     * Seed a company for testing
     */
    public function seedCompany(array $data): int
    {
        $id = $data['id'] ?? $this->nextCompanyId++;

        $this->companies[$id] = array_merge([
            'id' => $id,
            'name' => "Company {$id}",
            'email' => '',
            'phone' => '',
            'type' => 'client',
        ], $data);

        $this->companyCustomFields[$id] = $data['custom_fields'] ?? [];

        return $id;
    }

    public function getSubscriberByUserId(int $userId): ?array
    {
        $this->calls[] = ['method' => 'getSubscriberByUserId', 'args' => [$userId]];

        $subscriberId = $this->userIdMap[$userId] ?? null;

        return $subscriberId ? $this->subscribers[$subscriberId] : null;
    }

    public function getSubscriberByEmail(string $email): ?array
    {
        $this->calls[] = ['method' => 'getSubscriberByEmail', 'args' => [$email]];

        $subscriberId = $this->emailMap[$email] ?? null;

        return $subscriberId ? $this->subscribers[$subscriberId] : null;
    }

    public function createSubscriber(array $data): ?int
    {
        $this->calls[] = ['method' => 'createSubscriber', 'args' => [$data]];

        $id = $this->nextSubscriberId++;

        $this->subscribers[$id] = array_merge([
            'id' => $id,
            'status' => 'subscribed',
            'full_name' => trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')),
        ], $data);

        if (isset($data['user_id'])) {
            $this->userIdMap[$data['user_id']] = $id;
        }

        if (isset($data['email'])) {
            $this->emailMap[$data['email']] = $id;
        }

        $this->customFields[$id] = [];

        return $id;
    }

    public function updateSubscriber(int $subscriberId, array $data): bool
    {
        $this->calls[] = ['method' => 'updateSubscriber', 'args' => [$subscriberId, $data]];

        if (!isset($this->subscribers[$subscriberId])) {
            return false;
        }

        $this->subscribers[$subscriberId] = array_merge($this->subscribers[$subscriberId], $data);

        return true;
    }

    public function getCustomField(int $subscriberId, string $fieldKey): mixed
    {
        $this->calls[] = ['method' => 'getCustomField', 'args' => [$subscriberId, $fieldKey]];

        return $this->customFields[$subscriberId][$fieldKey] ?? null;
    }

    public function updateCustomField(int $subscriberId, string $fieldKey, mixed $value): bool
    {
        $this->calls[] = ['method' => 'updateCustomField', 'args' => [$subscriberId, $fieldKey, $value]];

        if (!isset($this->customFields[$subscriberId])) {
            $this->customFields[$subscriberId] = [];
        }

        $this->customFields[$subscriberId][$fieldKey] = $value;

        return true;
    }

    public function getCustomFields(int $subscriberId): array
    {
        $this->calls[] = ['method' => 'getCustomFields', 'args' => [$subscriberId]];

        return $this->customFields[$subscriberId] ?? [];
    }

    public function addTag(int $subscriberId, int|string $tagIdOrName): bool
    {
        $this->calls[] = ['method' => 'addTag', 'args' => [$subscriberId, $tagIdOrName]];

        if (!isset($this->tags[$subscriberId])) {
            $this->tags[$subscriberId] = [];
        }

        $this->tags[$subscriberId][] = [
            'id' => is_int($tagIdOrName) ? $tagIdOrName : abs(crc32($tagIdOrName)),
            'name' => is_string($tagIdOrName) ? $tagIdOrName : "Tag {$tagIdOrName}",
        ];

        return true;
    }

    public function removeTag(int $subscriberId, int|string $tagIdOrName): bool
    {
        $this->calls[] = ['method' => 'removeTag', 'args' => [$subscriberId, $tagIdOrName]];

        if (!isset($this->tags[$subscriberId])) {
            return true;
        }

        $this->tags[$subscriberId] = array_filter(
            $this->tags[$subscriberId],
            fn($tag) => $tag['id'] !== $tagIdOrName && $tag['name'] !== $tagIdOrName
        );

        return true;
    }

    public function getTags(int $subscriberId): array
    {
        $this->calls[] = ['method' => 'getTags', 'args' => [$subscriberId]];

        return $this->tags[$subscriberId] ?? [];
    }

    public function hasTag(int $subscriberId, int|string $tagIdOrName): bool
    {
        $this->calls[] = ['method' => 'hasTag', 'args' => [$subscriberId, $tagIdOrName]];

        foreach ($this->tags[$subscriberId] ?? [] as $tag) {
            if ($tag['id'] === $tagIdOrName || $tag['name'] === $tagIdOrName) {
                return true;
            }
        }

        return false;
    }

    public function createNote(int $subscriberId, string $content, ?string $type = null): ?int
    {
        $this->calls[] = ['method' => 'createNote', 'args' => [$subscriberId, $content, $type]];

        if (!isset($this->notes[$subscriberId])) {
            $this->notes[$subscriberId] = [];
        }

        $noteId = $this->nextNoteId++;

        $this->notes[$subscriberId][] = [
            'id' => $noteId,
            'content' => $content,
            'type' => $type ?? 'note',
            'created_at' => date('Y-m-d H:i:s'),
        ];

        return $noteId;
    }

    public function getNotes(int $subscriberId, int $limit = 10): array
    {
        $this->calls[] = ['method' => 'getNotes', 'args' => [$subscriberId, $limit]];

        $notes = $this->notes[$subscriberId] ?? [];

        return array_slice(array_reverse($notes), 0, $limit);
    }

    public function getCompanies(int $subscriberId): array
    {
        $this->calls[] = ['method' => 'getCompanies', 'args' => [$subscriberId]];

        $companyIds = $this->companyLinks[$subscriberId] ?? [];
        $companies = [];

        foreach ($companyIds as $companyId) {
            if (isset($this->companies[$companyId])) {
                $companies[] = $this->companies[$companyId];
            }
        }

        return $companies;
    }

    public function getCompany(int $companyId): ?array
    {
        $this->calls[] = ['method' => 'getCompany', 'args' => [$companyId]];

        return $this->companies[$companyId] ?? null;
    }

    public function getCompanyCustomFields(int $companyId): array
    {
        $this->calls[] = ['method' => 'getCompanyCustomFields', 'args' => [$companyId]];

        return $this->companyCustomFields[$companyId] ?? [];
    }

    public function linkToCompany(int $subscriberId, int $companyId): bool
    {
        $this->calls[] = ['method' => 'linkToCompany', 'args' => [$subscriberId, $companyId]];

        if (!isset($this->companyLinks[$subscriberId])) {
            $this->companyLinks[$subscriberId] = [];
        }

        if (!in_array($companyId, $this->companyLinks[$subscriberId])) {
            $this->companyLinks[$subscriberId][] = $companyId;
        }

        return true;
    }

    public function unlinkFromCompany(int $subscriberId, int $companyId): bool
    {
        $this->calls[] = ['method' => 'unlinkFromCompany', 'args' => [$subscriberId, $companyId]];

        if (isset($this->companyLinks[$subscriberId])) {
            $this->companyLinks[$subscriberId] = array_values(array_filter(
                $this->companyLinks[$subscriberId],
                fn($id) => $id !== $companyId
            ));
        }

        return true;
    }

    public function findCompanyByExportId(string $exportId): ?array
    {
        $this->calls[] = ['method' => 'findCompanyByExportId', 'args' => [$exportId]];

        foreach ($this->companies as $company) {
            $customFields = $this->companyCustomFields[$company['id']] ?? [];
            if (($customFields['export_id'] ?? null) === $exportId) {
                return $company;
            }
        }

        return null;
    }

    public function getTagIdByName(string $tagName): ?int
    {
        $this->calls[] = ['method' => 'getTagIdByName', 'args' => [$tagName]];

        // Generate consistent tag ID from name
        return abs(crc32($tagName));
    }

    public function createCompany(array $data): ?int
    {
        $this->calls[] = ['method' => 'createCompany', 'args' => [$data]];

        $id = $this->nextCompanyId++;

        $this->companies[$id] = array_merge([
            'id' => $id,
            'type' => 'client',
        ], $data);

        $this->companyCustomFields[$id] = $data['custom_fields'] ?? [];

        return $id;
    }

    public function updateCompany(int $companyId, array $data): bool
    {
        $this->calls[] = ['method' => 'updateCompany', 'args' => [$companyId, $data]];

        if (!isset($this->companies[$companyId])) {
            return false;
        }

        $this->companies[$companyId] = array_merge($this->companies[$companyId], $data);

        return true;
    }

    public function updateCompanyCustomFields(int $companyId, array $fields): bool
    {
        $this->calls[] = ['method' => 'updateCompanyCustomFields', 'args' => [$companyId, $fields]];

        if (!isset($this->companyCustomFields[$companyId])) {
            $this->companyCustomFields[$companyId] = [];
        }

        $this->companyCustomFields[$companyId] = array_merge($this->companyCustomFields[$companyId], $fields);

        return true;
    }

    public function getCompanySubscribers(int $companyId): array
    {
        $this->calls[] = ['method' => 'getCompanySubscribers', 'args' => [$companyId]];

        $subscribers = [];

        foreach ($this->companyLinks as $subscriberId => $linkedCompanies) {
            if (in_array($companyId, $linkedCompanies)) {
                if (isset($this->subscribers[$subscriberId])) {
                    $subscribers[] = $this->subscribers[$subscriberId];
                }
            }
        }

        return $subscribers;
    }

    public function findCompanyByName(string $name): ?array
    {
        $this->calls[] = ['method' => 'findCompanyByName', 'args' => [$name]];

        foreach ($this->companies as $company) {
            if ($company['name'] === $name) {
                return $company;
            }
        }

        return null;
    }

    public function searchCompanies(string $query, int $limit = 10): array
    {
        $this->calls[] = ['method' => 'searchCompanies', 'args' => [$query, $limit]];

        $results = [];
        $query = strtolower($query);

        foreach ($this->companies as $company) {
            if (str_contains(strtolower($company['name']), $query)) {
                $results[] = $company;
                if (count($results) >= $limit) {
                    break;
                }
            }
        }

        return $results;
    }

    public function getSubscribersByUserIds(array $userIds): array
    {
        $this->calls[] = ['method' => 'getSubscribersByUserIds', 'args' => [$userIds]];

        $result = [];

        foreach ($userIds as $userId) {
            $subscriber = $this->getSubscriberByUserId($userId);
            if ($subscriber) {
                $result[$userId] = $subscriber;
            }
        }

        return $result;
    }

    public function getSubscribersWithCompanies(array $userIds): array
    {
        $this->calls[] = ['method' => 'getSubscribersWithCompanies', 'args' => [$userIds]];

        $result = [];

        foreach ($userIds as $userId) {
            $subscriber = $this->getSubscriberByUserId($userId);
            if ($subscriber) {
                $result[$userId] = [
                    'subscriber' => $subscriber,
                    'companies' => $this->getCompanies($subscriber['id']),
                ];
            }
        }

        return $result;
    }

    // ========================================
    // TEST HELPERS
    // ========================================

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
        $this->subscribers = [];
        $this->userIdMap = [];
        $this->emailMap = [];
        $this->customFields = [];
        $this->tags = [];
        $this->notes = [];
        $this->companies = [];
        $this->companyLinks = [];
        $this->companyCustomFields = [];
        $this->calls = [];
        $this->nextSubscriberId = 1;
        $this->nextNoteId = 1;
        $this->nextCompanyId = 1;
    }

    /**
     * Get all subscribers (for debugging)
     */
    public function getSubscribers(): array
    {
        return $this->subscribers;
    }

    /**
     * Get all notes for a subscriber
     */
    public function getAllNotes(int $subscriberId): array
    {
        return $this->notes[$subscriberId] ?? [];
    }
}
