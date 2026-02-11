<?php

namespace stride\services\contracts;

defined('ABSPATH') || exit;

/**
 * FluentCRM Adapter Interface
 *
 * Abstraction layer for FluentCRM operations to enable testing.
 * The production implementation wraps actual FluentCRM API/models.
 * Tests can provide mock implementations.
 *
 * @package stride
 */
interface FluentCRMAdapterInterface
{
    /**
     * Check if FluentCRM is available
     */
    public function isAvailable(): bool;

    /**
     * Get subscriber by WordPress user ID
     *
     * @param int $userId
     * @return array|null Subscriber data or null
     */
    public function getSubscriberByUserId(int $userId): ?array;

    /**
     * Get subscriber by email
     *
     * @param string $email
     * @return array|null Subscriber data or null
     */
    public function getSubscriberByEmail(string $email): ?array;

    /**
     * Create a new subscriber
     *
     * @param array $data Subscriber data
     * @return int|null Subscriber ID or null on failure
     */
    public function createSubscriber(array $data): ?int;

    /**
     * Update subscriber
     *
     * @param int $subscriberId
     * @param array $data
     * @return bool
     */
    public function updateSubscriber(int $subscriberId, array $data): bool;

    /**
     * Get subscriber custom field value
     *
     * @param int $subscriberId
     * @param string $fieldKey
     * @return mixed
     */
    public function getCustomField(int $subscriberId, string $fieldKey): mixed;

    /**
     * Update subscriber custom field
     *
     * @param int $subscriberId
     * @param string $fieldKey
     * @param mixed $value
     * @return bool
     */
    public function updateCustomField(int $subscriberId, string $fieldKey, mixed $value): bool;

    /**
     * Get all custom fields for subscriber
     *
     * @param int $subscriberId
     * @return array
     */
    public function getCustomFields(int $subscriberId): array;

    /**
     * Add tag to subscriber
     *
     * @param int $subscriberId
     * @param int|string $tagIdOrName
     * @return bool
     */
    public function addTag(int $subscriberId, int|string $tagIdOrName): bool;

    /**
     * Remove tag from subscriber
     *
     * @param int $subscriberId
     * @param int|string $tagIdOrName
     * @return bool
     */
    public function removeTag(int $subscriberId, int|string $tagIdOrName): bool;

    /**
     * Get subscriber tags
     *
     * @param int $subscriberId
     * @return array Array of tag data
     */
    public function getTags(int $subscriberId): array;

    /**
     * Check if subscriber has tag
     *
     * @param int $subscriberId
     * @param int|string $tagIdOrName
     * @return bool
     */
    public function hasTag(int $subscriberId, int|string $tagIdOrName): bool;

    /**
     * Create subscriber note
     *
     * @param int $subscriberId
     * @param string $content
     * @param string|null $type
     * @return int|null Note ID or null
     */
    public function createNote(int $subscriberId, string $content, ?string $type = null): ?int;

    /**
     * Get subscriber notes
     *
     * @param int $subscriberId
     * @param int $limit
     * @return array
     */
    public function getNotes(int $subscriberId, int $limit = 10): array;

    /**
     * Get subscriber's linked companies
     *
     * @param int $subscriberId
     * @return array Array of company data
     */
    public function getCompanies(int $subscriberId): array;

    /**
     * Get company by ID
     *
     * @param int $companyId
     * @return array|null Company data or null
     */
    public function getCompany(int $companyId): ?array;

    /**
     * Get company custom fields
     *
     * @param int $companyId
     * @return array
     */
    public function getCompanyCustomFields(int $companyId): array;

    /**
     * Link subscriber to company
     *
     * @param int $subscriberId
     * @param int $companyId
     * @return bool
     */
    public function linkToCompany(int $subscriberId, int $companyId): bool;

    /**
     * Unlink subscriber from company
     *
     * @param int $subscriberId
     * @param int $companyId
     * @return bool
     */
    public function unlinkFromCompany(int $subscriberId, int $companyId): bool;

    /**
     * Find company by export ID (accounting system reference)
     *
     * @param string $exportId
     * @return array|null Company data or null
     */
    public function findCompanyByExportId(string $exportId): ?array;

    /**
     * Get tag ID by name
     *
     * @param string $tagName
     * @return int|null
     */
    public function getTagIdByName(string $tagName): ?int;

    // ========================================
    // COMPANY MANAGEMENT
    // ========================================

    /**
     * Create a new company
     *
     * @param array $data Company data (name, email, phone, address fields, type)
     * @return int|null Company ID or null on failure
     */
    public function createCompany(array $data): ?int;

    /**
     * Update company
     *
     * @param int $companyId
     * @param array $data
     * @return bool
     */
    public function updateCompany(int $companyId, array $data): bool;

    /**
     * Update company custom fields
     *
     * @param int $companyId
     * @param array $fields Key-value pairs of custom fields
     * @return bool
     */
    public function updateCompanyCustomFields(int $companyId, array $fields): bool;

    /**
     * Get all subscribers linked to a company
     *
     * @param int $companyId
     * @return array Array of subscriber data
     */
    public function getCompanySubscribers(int $companyId): array;

    /**
     * Find company by name (exact match)
     *
     * @param string $name
     * @return array|null Company data or null
     */
    public function findCompanyByName(string $name): ?array;

    /**
     * Search companies by name (partial match)
     *
     * @param string $query
     * @param int $limit
     * @return array Array of company data
     */
    public function searchCompanies(string $query, int $limit = 10): array;

    // ========================================
    // BATCH OPERATIONS (Performance Optimized)
    // ========================================

    /**
     * Get multiple subscribers by user IDs in a single query
     * PERFORMANCE: Uses WHERE IN instead of N+1 queries
     *
     * @param array $userIds Array of WordPress user IDs
     * @return array Map of user_id => subscriber data
     */
    public function getSubscribersByUserIds(array $userIds): array;

    /**
     * Get multiple subscribers with their companies (eager loaded)
     * PERFORMANCE: Single query with relationship eager loading
     *
     * @param array $userIds Array of WordPress user IDs
     * @return array Map of user_id => ['subscriber' => data, 'companies' => data]
     */
    public function getSubscribersWithCompanies(array $userIds): array;
}
