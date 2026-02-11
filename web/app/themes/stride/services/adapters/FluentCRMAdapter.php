<?php

namespace stride\services\adapters;

defined('ABSPATH') || exit;

use stride\services\contracts\FluentCRMAdapterInterface;

/**
 * FluentCRM Adapter - Production Implementation
 *
 * Wraps actual FluentCRM API and models. This adapter is injected into
 * SubscriberService and can be replaced with a mock for testing.
 *
 * @package stride
 */
class FluentCRMAdapter implements FluentCRMAdapterInterface
{
    /**
     * Request-level cache for subscribers (avoids repeated DB queries)
     */
    private static array $subscriberCache = [];

    /**
     * Request-level cache for tag ID lookups
     */
    private static array $tagIdCache = [];

    /**
     * Check if FluentCRM is available
     */
    public function isAvailable(): bool
    {
        return defined('FLUENTCRM') && function_exists('FluentCrmApi');
    }

    /**
     * Safely log error with fallback if ntdst_log() unavailable
     * SECURITY: Only logs non-sensitive identifiers, never PII
     */
    private function logError(string $message, array $context = []): void
    {
        // Sanitize context to remove PII
        $safeContext = $this->sanitizeLogContext($context);

        if (function_exists('ntdst_log')) {
            ntdst_log($message, $safeContext);
        } else {
            error_log(sprintf('%s - %s', $message, wp_json_encode($safeContext)));
        }
    }

    /**
     * Remove PII from log context
     */
    private function sanitizeLogContext(array $context): array
    {
        $sensitiveKeys = ['email', 'first_name', 'last_name', 'phone', 'address', 'data'];

        foreach ($sensitiveKeys as $key) {
            if (isset($context[$key])) {
                if ($key === 'email' && is_string($context[$key])) {
                    // Hash email for correlation without exposing it
                    $context[$key . '_hash'] = substr(hash('sha256', $context[$key]), 0, 12);
                    unset($context[$key]);
                } elseif ($key === 'data' && is_array($context[$key])) {
                    // Recursively sanitize nested data
                    $context[$key] = $this->sanitizeLogContext($context[$key]);
                } else {
                    unset($context[$key]);
                }
            }
        }

        return $context;
    }

    /**
     * Get subscriber by WordPress user ID
     * Uses request-level caching to avoid repeated queries
     */
    public function getSubscriberByUserId(int $userId): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        // Check request-level cache first
        $cacheKey = 'user_' . $userId;
        if (isset(self::$subscriberCache[$cacheKey])) {
            return self::$subscriberCache[$cacheKey];
        }

        try {
            $subscriber = FluentCrmApi('contacts')->getContactByUserRef($userId);

            if (!$subscriber) {
                self::$subscriberCache[$cacheKey] = null;
                return null;
            }

            $formatted = $this->formatSubscriber($subscriber);
            self::$subscriberCache[$cacheKey] = $formatted;

            return $formatted;
        } catch (\Exception $e) {
            $this->logError('FluentCRM: Failed to get subscriber by user ID', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get subscriber by email
     * Uses request-level caching to avoid repeated queries
     */
    public function getSubscriberByEmail(string $email): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        // Check request-level cache first
        $cacheKey = 'email_' . md5($email);
        if (isset(self::$subscriberCache[$cacheKey])) {
            return self::$subscriberCache[$cacheKey];
        }

        try {
            $subscriber = FluentCrmApi('contacts')->getContactByEmail($email);

            if (!$subscriber) {
                self::$subscriberCache[$cacheKey] = null;
                return null;
            }

            $formatted = $this->formatSubscriber($subscriber);
            self::$subscriberCache[$cacheKey] = $formatted;

            // Also cache by user_id if available
            if ($subscriber->user_id) {
                self::$subscriberCache['user_' . $subscriber->user_id] = $formatted;
            }

            return $formatted;
        } catch (\Exception $e) {
            $this->logError('FluentCRM: Failed to get subscriber by email', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Create a new subscriber
     */
    public function createSubscriber(array $data): ?int
    {
        if (!$this->isAvailable()) {
            return null;
        }

        try {
            $subscriber = FluentCrmApi('contacts')->createOrUpdate($data);

            return $subscriber ? (int) $subscriber->id : null;
        } catch (\Exception $e) {
            $this->logError('FluentCRM: Failed to create subscriber', [
                'data' => $data,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Update subscriber
     */
    public function updateSubscriber(int $subscriberId, array $data): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            $subscriber = \FluentCrm\App\Models\Subscriber::find($subscriberId);

            if (!$subscriber) {
                return false;
            }

            $subscriber->fill($data);
            $subscriber->save();

            return true;
        } catch (\Exception $e) {
            $this->logError('FluentCRM: Failed to update subscriber', [
                'subscriber_id' => $subscriberId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get subscriber custom field value
     */
    public function getCustomField(int $subscriberId, string $fieldKey): mixed
    {
        if (!$this->isAvailable()) {
            return null;
        }

        try {
            $subscriber = \FluentCrm\App\Models\Subscriber::find($subscriberId);

            if (!$subscriber) {
                return null;
            }

            $customFields = $subscriber->custom_fields();

            return $customFields[$fieldKey] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Update subscriber custom field
     */
    public function updateCustomField(int $subscriberId, string $fieldKey, mixed $value): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            $subscriber = \FluentCrm\App\Models\Subscriber::find($subscriberId);

            if (!$subscriber) {
                return false;
            }

            $customFields = $subscriber->custom_fields();
            $customFields[$fieldKey] = $value;

            $subscriber->updateCustomFieldsData($customFields);

            return true;
        } catch (\Exception $e) {
            $this->logError('FluentCRM: Failed to update custom field', [
                'subscriber_id' => $subscriberId,
                'field' => $fieldKey,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get all custom fields for subscriber
     */
    public function getCustomFields(int $subscriberId): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        try {
            $subscriber = \FluentCrm\App\Models\Subscriber::find($subscriberId);

            if (!$subscriber) {
                return [];
            }

            return $subscriber->custom_fields() ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Add tag to subscriber
     */
    public function addTag(int $subscriberId, int|string $tagIdOrName): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            $tagId = is_string($tagIdOrName) ? $this->getTagIdByName($tagIdOrName) : $tagIdOrName;

            if (!$tagId) {
                return false;
            }

            $subscriber = \FluentCrm\App\Models\Subscriber::find($subscriberId);

            if (!$subscriber) {
                return false;
            }

            $subscriber->attachTags([$tagId]);

            return true;
        } catch (\Exception $e) {
            $this->logError('FluentCRM: Failed to add tag', [
                'subscriber_id' => $subscriberId,
                'tag' => $tagIdOrName,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Remove tag from subscriber
     */
    public function removeTag(int $subscriberId, int|string $tagIdOrName): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            $tagId = is_string($tagIdOrName) ? $this->getTagIdByName($tagIdOrName) : $tagIdOrName;

            if (!$tagId) {
                return false;
            }

            $subscriber = \FluentCrm\App\Models\Subscriber::find($subscriberId);

            if (!$subscriber) {
                return false;
            }

            $subscriber->detachTags([$tagId]);

            return true;
        } catch (\Exception $e) {
            $this->logError('FluentCRM: Failed to remove tag', [
                'subscriber_id' => $subscriberId,
                'tag' => $tagIdOrName,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get subscriber tags
     */
    public function getTags(int $subscriberId): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        try {
            $subscriber = \FluentCrm\App\Models\Subscriber::find($subscriberId);

            if (!$subscriber) {
                return [];
            }

            $tags = $subscriber->tags;

            if (!$tags) {
                return [];
            }

            return $tags->map(function ($tag) {
                return [
                    'id' => (int) $tag->id,
                    'title' => $tag->title,
                    'slug' => $tag->slug,
                ];
            })->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Check if subscriber has tag
     */
    public function hasTag(int $subscriberId, int|string $tagIdOrName): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            $tagId = is_string($tagIdOrName) ? $this->getTagIdByName($tagIdOrName) : $tagIdOrName;

            if (!$tagId) {
                return false;
            }

            $subscriber = \FluentCrm\App\Models\Subscriber::find($subscriberId);

            if (!$subscriber) {
                return false;
            }

            return $subscriber->hasAnyTagId([$tagId]);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Create subscriber note
     */
    public function createNote(int $subscriberId, string $content, ?string $type = null): ?int
    {
        if (!$this->isAvailable()) {
            return null;
        }

        try {
            $noteData = [
                'subscriber_id' => $subscriberId,
                'title' => $type ?? 'note',
                'description' => $content,
                'type' => 'note',
                'created_by' => get_current_user_id() ?: 0,
            ];

            $note = FluentCrmApi('contacts')->createNote($subscriberId, $noteData);

            return $note ? (int) $note->id : null;
        } catch (\Exception $e) {
            $this->logError('FluentCRM: Failed to create note', [
                'subscriber_id' => $subscriberId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get subscriber notes
     */
    public function getNotes(int $subscriberId, int $limit = 10): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        try {
            $notes = \FluentCrm\App\Models\SubscriberNote::where('subscriber_id', $subscriberId)
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();

            return $notes->map(function ($note) {
                return [
                    'id' => (int) $note->id,
                    'title' => $note->title,
                    'description' => $note->description,
                    'type' => $note->type,
                    'created_at' => $note->created_at,
                    'created_by' => $note->created_by,
                ];
            })->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get subscriber's linked companies
     */
    public function getCompanies(int $subscriberId): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        try {
            $subscriber = \FluentCrm\App\Models\Subscriber::find($subscriberId);

            if (!$subscriber) {
                return [];
            }

            $companies = $subscriber->companies;

            if (!$companies) {
                return [];
            }

            return $companies->map(function ($company) {
                return $this->formatCompany($company);
            })->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get company by ID
     */
    public function getCompany(int $companyId): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        try {
            $company = \FluentCrm\App\Models\Company::find($companyId);

            if (!$company) {
                return null;
            }

            return $this->formatCompany($company);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get company custom fields
     */
    public function getCompanyCustomFields(int $companyId): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        try {
            $company = \FluentCrm\App\Models\Company::find($companyId);

            if (!$company) {
                return [];
            }

            return $company->getCustomValues() ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Link subscriber to company
     */
    public function linkToCompany(int $subscriberId, int $companyId): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            $subscriber = \FluentCrm\App\Models\Subscriber::find($subscriberId);

            if (!$subscriber) {
                return false;
            }

            $subscriber->companies()->syncWithoutDetaching([$companyId]);

            return true;
        } catch (\Exception $e) {
            $this->logError('FluentCRM: Failed to link subscriber to company', [
                'subscriber_id' => $subscriberId,
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Unlink subscriber from company
     */
    public function unlinkFromCompany(int $subscriberId, int $companyId): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            $subscriber = \FluentCrm\App\Models\Subscriber::find($subscriberId);

            if (!$subscriber) {
                return false;
            }

            $subscriber->companies()->detach([$companyId]);

            return true;
        } catch (\Exception $e) {
            $this->logError('FluentCRM: Failed to unlink subscriber from company', [
                'subscriber_id' => $subscriberId,
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Find company by export ID (accounting system reference)
     *
     * PERFORMANCE: Uses chunked iteration and caching to avoid memory issues.
     * FluentCRM doesn't support direct custom field queries, so we use
     * a reverse-lookup cache stored in WordPress options for O(1) lookups.
     */
    public function findCompanyByExportId(string $exportId): ?array
    {
        if (!$this->isAvailable() || empty($exportId)) {
            return null;
        }

        try {
            // Try transient cache first (fast path)
            $cacheKey = 'stride_company_export_' . md5($exportId);
            $cachedId = get_transient($cacheKey);

            if ($cachedId !== false) {
                if ($cachedId === 0) {
                    return null; // Cached as not found
                }
                $company = \FluentCrm\App\Models\Company::find($cachedId);
                return $company ? $this->formatCompany($company) : null;
            }

            // Try reverse lookup index (medium path)
            $indexKey = 'stride_export_id_index';
            $index = get_option($indexKey, []);

            if (isset($index[$exportId])) {
                $company = \FluentCrm\App\Models\Company::find($index[$exportId]);
                if ($company) {
                    set_transient($cacheKey, $company->id, HOUR_IN_SECONDS);
                    return $this->formatCompany($company);
                }
                // Index was stale, remove entry
                unset($index[$exportId]);
                update_option($indexKey, $index, false);
            }

            // Fallback to chunked scan (slow path, but memory-safe)
            $found = null;
            $chunkSize = 100;

            \FluentCrm\App\Models\Company::chunk($chunkSize, function ($companies) use ($exportId, &$found, &$index, $indexKey) {
                foreach ($companies as $company) {
                    $customValues = $company->getCustomValues() ?? [];
                    $companyExportId = $customValues['export_id'] ?? null;

                    // Build index as we scan
                    if ($companyExportId) {
                        $index[$companyExportId] = $company->id;
                    }

                    if ($companyExportId === $exportId) {
                        $found = $company;
                        return false; // Stop chunking
                    }
                }
            });

            // Save updated index for future lookups
            update_option($indexKey, $index, false);

            if ($found) {
                set_transient($cacheKey, $found->id, HOUR_IN_SECONDS);
                return $this->formatCompany($found);
            }

            // Cache negative result to avoid repeated scans
            set_transient($cacheKey, 0, 15 * MINUTE_IN_SECONDS);

            return null;
        } catch (\Exception $e) {
            $this->logError('FluentCRM: Failed to find company by export ID', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get tag ID by name
     * Uses request-level caching since tags rarely change
     */
    public function getTagIdByName(string $tagName): ?int
    {
        if (!$this->isAvailable()) {
            return null;
        }

        // Check cache first
        if (isset(self::$tagIdCache[$tagName])) {
            return self::$tagIdCache[$tagName];
        }

        try {
            $tag = \FluentCrm\App\Models\Tag::where('title', $tagName)->first();

            $tagId = $tag ? (int) $tag->id : null;
            self::$tagIdCache[$tagName] = $tagId;

            return $tagId;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Format subscriber model to array
     */
    private function formatSubscriber(object $subscriber): array
    {
        return [
            'id' => (int) $subscriber->id,
            'user_id' => $subscriber->user_id ? (int) $subscriber->user_id : null,
            'email' => $subscriber->email,
            'first_name' => $subscriber->first_name,
            'last_name' => $subscriber->last_name,
            'full_name' => $subscriber->full_name,
            'status' => $subscriber->status,
            'phone' => $subscriber->phone,
            'address_line_1' => $subscriber->address_line_1,
            'address_line_2' => $subscriber->address_line_2,
            'city' => $subscriber->city,
            'state' => $subscriber->state,
            'postal_code' => $subscriber->postal_code,
            'country' => $subscriber->country,
            'created_at' => $subscriber->created_at,
        ];
    }

    /**
     * Format company model to array
     */
    private function formatCompany(object $company): array
    {
        $customValues = $company->getCustomValues() ?? [];

        return [
            'id' => (int) $company->id,
            'name' => $company->name,
            'type' => $company->type,
            'email' => $company->email,
            'phone' => $company->phone,
            'address_line_1' => $company->address_line_1,
            'address_line_2' => $company->address_line_2,
            'city' => $company->city,
            'state' => $company->state,
            'postal_code' => $company->postal_code,
            'country' => $company->country,
            'custom_fields' => $customValues,
        ];
    }

    // ========================================
    // COMPANY MANAGEMENT
    // ========================================

    /**
     * Create a new company
     */
    public function createCompany(array $data): ?int
    {
        if (!$this->isAvailable()) {
            return null;
        }

        try {
            $company = new \FluentCrm\App\Models\Company();

            // Set standard fields
            $standardFields = ['name', 'email', 'phone', 'address_line_1', 'address_line_2',
                              'city', 'state', 'postal_code', 'country', 'type', 'description'];

            foreach ($standardFields as $field) {
                if (isset($data[$field])) {
                    $company->{$field} = $data[$field];
                }
            }

            $company->save();

            // Handle custom fields separately
            if (isset($data['custom_fields']) && is_array($data['custom_fields'])) {
                $this->updateCompanyCustomFields($company->id, $data['custom_fields']);
            }

            return (int) $company->id;
        } catch (\Exception $e) {
            $this->logError('FluentCRM: Failed to create company', [
                'data' => $data,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Update company
     */
    public function updateCompany(int $companyId, array $data): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            $company = \FluentCrm\App\Models\Company::find($companyId);

            if (!$company) {
                return false;
            }

            // Update standard fields
            $standardFields = ['name', 'email', 'phone', 'address_line_1', 'address_line_2',
                              'city', 'state', 'postal_code', 'country', 'type', 'description'];

            foreach ($standardFields as $field) {
                if (isset($data[$field])) {
                    $company->{$field} = $data[$field];
                }
            }

            $company->save();

            // Handle custom fields separately
            if (isset($data['custom_fields']) && is_array($data['custom_fields'])) {
                $this->updateCompanyCustomFields($companyId, $data['custom_fields']);
            }

            return true;
        } catch (\Exception $e) {
            $this->logError('FluentCRM: Failed to update company', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Update company custom fields
     */
    public function updateCompanyCustomFields(int $companyId, array $fields): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            $company = \FluentCrm\App\Models\Company::find($companyId);

            if (!$company) {
                return false;
            }

            // Get existing custom values and merge
            $existingValues = $company->getCustomValues() ?? [];
            $mergedValues = array_merge($existingValues, $fields);

            // FluentCRM stores custom values in meta
            $company->updateCustomValues($mergedValues);

            // Clear company export_id cache if that field was updated
            if (isset($fields['export_id'])) {
                $oldExportId = $existingValues['export_id'] ?? null;
                if ($oldExportId) {
                    delete_transient('stride_company_export_' . md5($oldExportId));
                }
            }

            return true;
        } catch (\Exception $e) {
            $this->logError('FluentCRM: Failed to update company custom fields', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get all subscribers linked to a company
     */
    public function getCompanySubscribers(int $companyId): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        try {
            $company = \FluentCrm\App\Models\Company::find($companyId);

            if (!$company) {
                return [];
            }

            $subscribers = $company->subscribers;

            if (!$subscribers) {
                return [];
            }

            return $subscribers->map(function ($subscriber) {
                return $this->formatSubscriber($subscriber);
            })->toArray();
        } catch (\Exception $e) {
            $this->logError('FluentCRM: Failed to get company subscribers', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Find company by name (exact match)
     */
    public function findCompanyByName(string $name): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        try {
            $company = \FluentCrm\App\Models\Company::where('name', $name)->first();

            return $company ? $this->formatCompany($company) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Search companies by name (partial match)
     * SECURITY: Escapes SQL LIKE wildcards in user input
     */
    public function searchCompanies(string $query, int $limit = 10): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        try {
            // Escape SQL LIKE wildcards to prevent pattern manipulation
            $escapedQuery = str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $query);

            $companies = \FluentCrm\App\Models\Company::where('name', 'LIKE', '%' . $escapedQuery . '%')
                ->limit(min($limit, 100)) // Cap at 100 to prevent abuse
                ->get();

            return $companies->map(function ($company) {
                return $this->formatCompany($company);
            })->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

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
    public function getSubscribersByUserIds(array $userIds): array
    {
        if (!$this->isAvailable() || empty($userIds)) {
            return [];
        }

        // Sanitize and limit input
        $userIds = array_map('absint', array_slice($userIds, 0, 500));
        $userIds = array_filter($userIds);

        if (empty($userIds)) {
            return [];
        }

        // Check cache for already-loaded subscribers
        $result = [];
        $uncachedIds = [];

        foreach ($userIds as $userId) {
            $cacheKey = 'user_' . $userId;
            if (isset(self::$subscriberCache[$cacheKey])) {
                if (self::$subscriberCache[$cacheKey] !== null) {
                    $result[$userId] = self::$subscriberCache[$cacheKey];
                }
            } else {
                $uncachedIds[] = $userId;
            }
        }

        // Fetch uncached subscribers in a single query
        if (!empty($uncachedIds)) {
            try {
                $subscribers = \FluentCrm\App\Models\Subscriber::whereIn('user_id', $uncachedIds)->get();

                foreach ($subscribers as $subscriber) {
                    $formatted = $this->formatSubscriber($subscriber);
                    $userId = (int) $subscriber->user_id;

                    $result[$userId] = $formatted;
                    self::$subscriberCache['user_' . $userId] = $formatted;
                }

                // Mark missing subscribers as null in cache
                foreach ($uncachedIds as $userId) {
                    if (!isset($result[$userId])) {
                        self::$subscriberCache['user_' . $userId] = null;
                    }
                }
            } catch (\Exception $e) {
                $this->logError('FluentCRM: Batch subscriber lookup failed', [
                    'count' => count($uncachedIds),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /**
     * Get multiple subscribers with their companies (eager loaded)
     * PERFORMANCE: Single query with relationship eager loading
     *
     * @param array $userIds Array of WordPress user IDs
     * @return array Map of user_id => ['subscriber' => data, 'companies' => data]
     */
    public function getSubscribersWithCompanies(array $userIds): array
    {
        if (!$this->isAvailable() || empty($userIds)) {
            return [];
        }

        // Sanitize and limit input
        $userIds = array_map('absint', array_slice($userIds, 0, 500));
        $userIds = array_filter($userIds);

        if (empty($userIds)) {
            return [];
        }

        try {
            // Eager load companies relationship to avoid N+1
            $subscribers = \FluentCrm\App\Models\Subscriber::with('companies')
                ->whereIn('user_id', $userIds)
                ->get();

            $result = [];

            foreach ($subscribers as $subscriber) {
                $userId = (int) $subscriber->user_id;
                $formatted = $this->formatSubscriber($subscriber);

                // Cache the subscriber
                self::$subscriberCache['user_' . $userId] = $formatted;

                // Format companies
                $companies = [];
                if ($subscriber->companies) {
                    $companies = $subscriber->companies->map(function ($company) {
                        return $this->formatCompany($company);
                    })->toArray();
                }

                $result[$userId] = [
                    'subscriber' => $formatted,
                    'companies' => $companies,
                ];
            }

            return $result;
        } catch (\Exception $e) {
            $this->logError('FluentCRM: Batch subscriber+company lookup failed', [
                'count' => count($userIds),
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Clear request-level cache (useful for testing or long-running processes)
     */
    public static function clearCache(): void
    {
        self::$subscriberCache = [];
        self::$tagIdCache = [];
    }
}
