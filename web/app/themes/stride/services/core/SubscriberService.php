<?php

namespace stride\services\core;

defined('ABSPATH') || exit;

use stride\services\contracts\FluentCRMAdapterInterface;
use stride\services\adapters\FluentCRMAdapter;
use stride\services\sync\UserDataSync;
use stride\services\FieldRegistry;
use WP_Error;

/**
 * Subscriber Service
 *
 * FluentCRM wrapper providing a clean API for subscriber/contact operations.
 * Handles subscriber management, custom fields, tags, notes, and company links.
 *
 * Available filters:
 * - netdust_subscriber_config - Customize service configuration
 * - stride/subscriber/member_company_type - Override company type for member check
 *
 * @package stride
 */
class SubscriberService implements \NTDST_Service_Meta
{
    private array $config;
    private FluentCRMAdapterInterface $fluentcrm;
    private UserDataSync $dataSync;

    /**
     * Service metadata for NTDST Bootstrap
     */
    public static function metadata(): array
    {
        return [
            'name' => 'Subscriber Service',
            'description' => 'FluentCRM contact management and operations',
            'admin_only' => false,
            'enabled' => true,
            'priority' => 10,
        ];
    }

    /**
     * Constructor - dependencies injected by DI container
     */
    public function __construct(
        ?FluentCRMAdapterInterface $fluentcrm = null,
        ?UserDataSync $dataSync = null
    ) {
        $this->fluentcrm = $fluentcrm ?? new FluentCRMAdapter();
        $this->dataSync = $dataSync ?? new UserDataSync();
        $this->config = $this->getDefaultConfig();
        $this->init();
    }

    /**
     * Get configuration with filter for customization
     */
    private function getDefaultConfig(): array
    {
        return apply_filters('netdust_subscriber_config', [
            'member_company_type' => FieldRegistry::COMPANY_TYPE_PARTNER,
            'cache_ttl' => 300, // 5 minutes
        ]);
    }

    /**
     * Initialize service hooks
     */
    private function init(): void
    {
        add_action('init', [$this, 'registerHooks'], 20);
    }

    /**
     * Register WordPress hooks
     */
    public function registerHooks(): void
    {
        do_action('stride/subscriber_service_ready', $this);
    }

    /**
     * Check if FluentCRM is available
     */
    public function isAvailable(): bool
    {
        return $this->fluentcrm->isAvailable();
    }

    // ========================================
    // CORE OPERATIONS
    // ========================================

    /**
     * Get subscriber by WordPress user ID
     *
     * @param int $userId
     * @return array|WP_Error Subscriber data or error
     */
    public function getSubscriber(int $userId): array|WP_Error
    {
        if (!$this->isAvailable()) {
            return new WP_Error('fluentcrm_unavailable', 'FluentCRM is not available');
        }

        $subscriber = $this->fluentcrm->getSubscriberByUserId($userId);

        if (!$subscriber) {
            return new WP_Error('subscriber_not_found', 'Subscriber not found', ['user_id' => $userId]);
        }

        return $subscriber;
    }

    /**
     * Get subscriber by email address
     *
     * @param string $email
     * @return array|WP_Error Subscriber data or error
     */
    public function getSubscriberByEmail(string $email): array|WP_Error
    {
        if (!$this->isAvailable()) {
            return new WP_Error('fluentcrm_unavailable', 'FluentCRM is not available');
        }

        $email = sanitize_email($email);

        if (!is_email($email)) {
            return new WP_Error('invalid_email', 'Invalid email address');
        }

        $subscriber = $this->fluentcrm->getSubscriberByEmail($email);

        if (!$subscriber) {
            return new WP_Error('subscriber_not_found', 'Subscriber not found', ['email' => $email]);
        }

        return $subscriber;
    }

    /**
     * Find or create subscriber for user
     *
     * @param int $userId
     * @return array|WP_Error Subscriber data or error
     */
    public function findOrCreate(int $userId): array|WP_Error
    {
        if (!$this->isAvailable()) {
            return new WP_Error('fluentcrm_unavailable', 'FluentCRM is not available');
        }

        // Try to find existing
        $subscriber = $this->fluentcrm->getSubscriberByUserId($userId);

        if ($subscriber) {
            return $subscriber;
        }

        // Get user data
        $user = get_user_by('ID', $userId);

        if (!$user) {
            return new WP_Error('user_not_found', 'WordPress user not found', ['user_id' => $userId]);
        }

        // Create new subscriber
        $subscriberId = $this->fluentcrm->createSubscriber([
            'email' => $user->user_email,
            'first_name' => $user->first_name ?: '',
            'last_name' => $user->last_name ?: '',
            'user_id' => $userId,
            'status' => 'subscribed',
        ]);

        if (!$subscriberId) {
            return new WP_Error('creation_failed', 'Failed to create subscriber');
        }

        // Fetch and return the created subscriber
        $subscriber = $this->fluentcrm->getSubscriberByUserId($userId);

        if (!$subscriber) {
            return new WP_Error('subscriber_not_found', 'Could not retrieve created subscriber');
        }

        do_action('stride/subscriber_created', $userId, $subscriberId);

        return $subscriber;
    }

    /**
     * Get subscriber ID for a user
     *
     * @param int $userId
     * @return int|null Subscriber ID or null if not found.
     *                  Note: Returns null if FluentCRM unavailable - check isAvailable() first.
     */
    public function getSubscriberId(int $userId): ?int
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $subscriber = $this->fluentcrm->getSubscriberByUserId($userId);

        return $subscriber['id'] ?? null;
    }

    // ========================================
    // USER DATA HELPERS
    // ========================================

    /**
     * Get user email address
     *
     * Tries FluentCRM subscriber first, falls back to WordPress user.
     *
     * @param int $userId
     * @return string|null Email or null if user not found
     */
    public function getUserEmail(int $userId): ?string
    {
        // Try FluentCRM subscriber first (may have updated email)
        if ($this->isAvailable()) {
            $subscriber = $this->fluentcrm->getSubscriberByUserId($userId);
            if ($subscriber && !empty($subscriber['email'])) {
                return $subscriber['email'];
            }
        }

        // Fallback to WordPress user
        $user = get_user_by('ID', $userId);
        return $user ? $user->user_email : null;
    }

    /**
     * Get user email domain
     *
     * @param int $userId
     * @return string|null Domain or null if user not found
     */
    public function getUserEmailDomain(int $userId): ?string
    {
        $email = $this->getUserEmail($userId);
        if (!$email) {
            return null;
        }

        $domain = substr(strrchr($email, '@'), 1);
        return $domain ?: null;
    }

    /**
     * Check if user exists
     *
     * @param int $userId
     * @return bool
     */
    public function userExists(int $userId): bool
    {
        return (bool) get_user_by('ID', $userId);
    }

    // ========================================
    // FIELD OPERATIONS
    // ========================================

    /**
     * Get field value (synced across all storage backends)
     *
     * @param int $userId
     * @param string $fieldName Use FieldRegistry constants
     * @return mixed|WP_Error
     */
    public function getField(int $userId, string $fieldName): mixed
    {
        if (!$this->isAvailable()) {
            return new WP_Error('fluentcrm_unavailable', 'FluentCRM is not available');
        }

        // UserDataSync checks all backends in priority order
        $value = $this->dataSync->getField($userId, $fieldName);

        return $value;
    }

    /**
     * Update field value (synced to all storage backends)
     *
     * SECURITY: Requires admin capability OR current user updating their own field.
     *
     * @param int $userId
     * @param string $fieldName Use FieldRegistry constants
     * @param mixed $value
     * @return true|WP_Error
     */
    public function updateField(int $userId, string $fieldName, mixed $value): true|WP_Error
    {
        // Authorization check
        $authCheck = $this->canModifyUser($userId);
        if (is_wp_error($authCheck)) {
            return $authCheck;
        }

        // UserDataSync writes to all backends
        return $this->dataSync->setField($userId, $fieldName, $value);
    }

    /**
     * Update multiple profile fields (synced to all storage backends)
     *
     * SECURITY: Requires admin capability OR current user updating their own profile.
     *
     * @param int $userId
     * @param array $data Use FieldRegistry constants as keys
     * @return true|WP_Error
     */
    public function updateProfile(int $userId, array $data): true|WP_Error
    {
        // Authorization check
        $authCheck = $this->canModifyUser($userId);
        if (is_wp_error($authCheck)) {
            return $authCheck;
        }

        // Sanitize input
        $sanitizedData = [];
        foreach ($data as $key => $value) {
            $sanitizedData[$key] = match ($key) {
                FieldRegistry::FIELD_EMAIL,
                FieldRegistry::SUBSCRIBER_INVOICE_EMAIL => sanitize_email($value),
                default => is_string($value) ? sanitize_text_field($value) : $value,
            };
        }

        // UserDataSync handles writing to all backends
        $result = $this->dataSync->setFields($userId, $sanitizedData);

        if (is_wp_error($result)) {
            return $result;
        }

        do_action('stride/subscriber_profile_updated', $userId, $sanitizedData);

        return true;
    }

    // ========================================
    // ADDRESS/INVOICE DATA
    // ========================================

    /**
     * Get invoice address for user
     * Merges data from: subscriber < subscriber custom fields < company
     *
     * PERFORMANCE: Optimized to use single query with eager loading when possible.
     * Uses adapter's request-level cache to avoid duplicate queries.
     *
     * @param int $userId
     * @return array|WP_Error
     */
    public function getInvoiceAddress(int $userId): array|WP_Error
    {
        if (!$this->isAvailable()) {
            return new WP_Error('fluentcrm_unavailable', 'FluentCRM is not available');
        }

        // Single lookup - adapter caches this for subsequent calls
        $subscriber = $this->fluentcrm->getSubscriberByUserId($userId);

        if (!$subscriber) {
            return new WP_Error('subscriber_not_found', 'Subscriber not found');
        }

        $subscriberId = $subscriber['id'];
        $customFields = $this->fluentcrm->getCustomFields($subscriberId);

        // Convert legacy field names to new names for internal use
        $fields = FieldRegistry::convertLegacyData($customFields, 'subscriber');

        // Start with subscriber base data
        $address = [
            'name' => $subscriber['full_name'] ?? '',
            'email' => $subscriber['email'] ?? '',
            'phone' => $subscriber['phone'] ?? '',
            'address' => $subscriber['address_line_1'] ?? '',
            'address_2' => $subscriber['address_line_2'] ?? '',
            'city' => $subscriber['city'] ?? '',
            'postal_code' => $subscriber['postal_code'] ?? '',
            'country' => $subscriber['country'] ?? 'BE',
            'vat_number' => '',
            'gln_number' => '',
            'organisation' => '',
            'department' => '',
        ];

        // Overlay subscriber custom fields using new field names
        if (!empty($fields[FieldRegistry::SUBSCRIBER_INVOICE_ORG_NAME])) {
            $address['organisation'] = $fields[FieldRegistry::SUBSCRIBER_INVOICE_ORG_NAME];
        }
        if (!empty($fields[FieldRegistry::SUBSCRIBER_INVOICE_ADDRESS])) {
            $address['address'] = $fields[FieldRegistry::SUBSCRIBER_INVOICE_ADDRESS];
        }
        if (!empty($fields[FieldRegistry::SUBSCRIBER_INVOICE_CITY])) {
            $address['city'] = $fields[FieldRegistry::SUBSCRIBER_INVOICE_CITY];
        }
        if (!empty($fields[FieldRegistry::SUBSCRIBER_INVOICE_POSTAL_CODE])) {
            $address['postal_code'] = $fields[FieldRegistry::SUBSCRIBER_INVOICE_POSTAL_CODE];
        }
        if (!empty($fields[FieldRegistry::SUBSCRIBER_INVOICE_EMAIL])) {
            $address['email'] = $fields[FieldRegistry::SUBSCRIBER_INVOICE_EMAIL];
        }
        if (!empty($fields[FieldRegistry::SUBSCRIBER_VAT_NUMBER])) {
            $address['vat_number'] = $fields[FieldRegistry::SUBSCRIBER_VAT_NUMBER];
        }
        if (!empty($fields[FieldRegistry::SUBSCRIBER_GLN_NUMBER])) {
            $address['gln_number'] = $fields[FieldRegistry::SUBSCRIBER_GLN_NUMBER];
        }
        if (!empty($fields[FieldRegistry::SUBSCRIBER_DEPARTMENT])) {
            $address['department'] = $fields[FieldRegistry::SUBSCRIBER_DEPARTMENT];
        }

        // Get companies - uses subscriber ID directly to avoid extra lookup
        $companies = $this->fluentcrm->getCompanies($subscriberId);

        if (!empty($companies)) {
            // Use first (primary) company
            $company = $companies[0];
            $companyFields = FieldRegistry::convertLegacyData($company['custom_fields'] ?? [], 'company');

            // Map company fields to address keys
            $companyToAddressMap = [
                FieldRegistry::COMPANY_INVOICE_NAME => 'organisation',
                FieldRegistry::COMPANY_INVOICE_ADDRESS => 'address',
                FieldRegistry::COMPANY_INVOICE_CITY => 'city',
                FieldRegistry::COMPANY_INVOICE_POSTAL_CODE => 'postal_code',
                FieldRegistry::COMPANY_INVOICE_EMAIL => 'email',
                FieldRegistry::COMPANY_VAT_NUMBER => 'vat_number',
                FieldRegistry::COMPANY_GLN_NUMBER => 'gln_number',
            ];

            foreach ($companyToAddressMap as $companyField => $addressKey) {
                if (!empty($companyFields[$companyField])) {
                    $address[$addressKey] = $companyFields[$companyField];
                }
            }

            // Company name takes precedence
            if (!empty($company['name'])) {
                $address['organisation'] = $company['name'];
            }
        }

        return $address;
    }

    /**
     * Get billing data for user
     *
     * @param int $userId
     * @return array|WP_Error
     */
    public function getBillingData(int $userId): array|WP_Error
    {
        return $this->getInvoiceAddress($userId);
    }

    // ========================================
    // NOTES
    // ========================================

    /**
     * Create a note for subscriber
     *
     * @param int $userId
     * @param string $content
     * @param string|null $type
     * @return int|WP_Error Note ID or error
     */
    public function createNote(int $userId, string $content, ?string $type = null): int|WP_Error
    {
        $subscriberId = $this->getSubscriberId($userId);

        if (!$subscriberId) {
            return new WP_Error('subscriber_not_found', 'Subscriber not found');
        }

        $noteId = $this->fluentcrm->createNote($subscriberId, $content, $type);

        if (!$noteId) {
            return new WP_Error('note_creation_failed', 'Failed to create note');
        }

        return $noteId;
    }

    /**
     * Get subscriber notes
     *
     * @param int $userId
     * @param int $limit
     * @return array|WP_Error
     */
    public function getNotes(int $userId, int $limit = 10): array|WP_Error
    {
        $subscriberId = $this->getSubscriberId($userId);

        if (!$subscriberId) {
            return new WP_Error('subscriber_not_found', 'Subscriber not found');
        }

        return $this->fluentcrm->getNotes($subscriberId, $limit);
    }

    // ========================================
    // TAGS
    // ========================================

    /**
     * Add tag to subscriber
     *
     * SECURITY: Requires admin capability. Tags affect automation and segmentation.
     *
     * @param int $userId
     * @param int|string $tagIdOrName
     * @return true|WP_Error
     */
    public function addTag(int $userId, int|string $tagIdOrName): true|WP_Error
    {
        // Authorization check - admin only for tag management
        if (!$this->currentUserCanManage()) {
            return new WP_Error(
                'unauthorized',
                __('You do not have permission to manage subscriber tags.', 'stride'),
                ['status' => 403]
            );
        }

        $subscriberId = $this->getSubscriberId($userId);

        if (!$subscriberId) {
            return new WP_Error('subscriber_not_found', 'Subscriber not found');
        }

        $result = $this->fluentcrm->addTag($subscriberId, $tagIdOrName);

        if (!$result) {
            return new WP_Error('tag_add_failed', 'Failed to add tag');
        }

        do_action('stride/subscriber_tag_added', $userId, $tagIdOrName);

        return true;
    }

    /**
     * Remove tag from subscriber
     *
     * SECURITY: Requires admin capability. Tags affect automation and segmentation.
     *
     * @param int $userId
     * @param int|string $tagIdOrName
     * @return true|WP_Error
     */
    public function removeTag(int $userId, int|string $tagIdOrName): true|WP_Error
    {
        // Authorization check - admin only for tag management
        if (!$this->currentUserCanManage()) {
            return new WP_Error(
                'unauthorized',
                __('You do not have permission to manage subscriber tags.', 'stride'),
                ['status' => 403]
            );
        }

        $subscriberId = $this->getSubscriberId($userId);

        if (!$subscriberId) {
            return new WP_Error('subscriber_not_found', 'Subscriber not found');
        }

        $result = $this->fluentcrm->removeTag($subscriberId, $tagIdOrName);

        if (!$result) {
            return new WP_Error('tag_remove_failed', 'Failed to remove tag');
        }

        do_action('stride/subscriber_tag_removed', $userId, $tagIdOrName);

        return true;
    }

    /**
     * Get all tags for subscriber
     *
     * @param int $userId
     * @return array|WP_Error
     */
    public function getTags(int $userId): array|WP_Error
    {
        $subscriberId = $this->getSubscriberId($userId);

        if (!$subscriberId) {
            return new WP_Error('subscriber_not_found', 'Subscriber not found');
        }

        return $this->fluentcrm->getTags($subscriberId);
    }

    /**
     * Check if subscriber has tag
     *
     * @param int $userId
     * @param int|string $tagIdOrName
     * @return bool
     */
    public function hasTag(int $userId, int|string $tagIdOrName): bool
    {
        $subscriberId = $this->getSubscriberId($userId);

        if (!$subscriberId) {
            return false;
        }

        return $this->fluentcrm->hasTag($subscriberId, $tagIdOrName);
    }

    // ========================================
    // COMPANY/ORGANIZATION
    // ========================================

    /**
     * Get user's primary company
     *
     * @param int $userId
     * @return array|WP_Error|null Company data, error, or null if none
     */
    public function getCompany(int $userId): array|WP_Error|null
    {
        $subscriberId = $this->getSubscriberId($userId);

        if (!$subscriberId) {
            return new WP_Error('subscriber_not_found', 'Subscriber not found');
        }

        $companies = $this->fluentcrm->getCompanies($subscriberId);

        if (empty($companies)) {
            return null;
        }

        // Return first (primary) company
        return $companies[0];
    }

    /**
     * Get all companies linked to user
     *
     * @param int $userId
     * @return array|WP_Error
     */
    public function getCompanies(int $userId): array|WP_Error
    {
        $subscriberId = $this->getSubscriberId($userId);

        if (!$subscriberId) {
            return new WP_Error('subscriber_not_found', 'Subscriber not found');
        }

        return $this->fluentcrm->getCompanies($subscriberId);
    }

    /**
     * Link user to company
     *
     * SECURITY: Requires admin capability OR current user linking themselves.
     *
     * @param int $userId
     * @param int $companyId
     * @return true|WP_Error
     */
    public function linkToCompany(int $userId, int $companyId): true|WP_Error
    {
        // Authorization check
        $authCheck = $this->canModifyUser($userId);
        if (is_wp_error($authCheck)) {
            return $authCheck;
        }

        $subscriberId = $this->getSubscriberId($userId);

        if (!$subscriberId) {
            return new WP_Error('subscriber_not_found', 'Subscriber not found');
        }

        $result = $this->fluentcrm->linkToCompany($subscriberId, $companyId);

        if (!$result) {
            return new WP_Error('link_failed', 'Failed to link to company');
        }

        do_action('stride/subscriber_company_linked', $userId, $companyId);

        return true;
    }

    /**
     * Unlink user from company
     *
     * SECURITY: Requires admin capability OR current user unlinking themselves.
     *
     * @param int $userId
     * @param int $companyId
     * @return true|WP_Error
     */
    public function unlinkFromCompany(int $userId, int $companyId): true|WP_Error
    {
        // Authorization check
        $authCheck = $this->canModifyUser($userId);
        if (is_wp_error($authCheck)) {
            return $authCheck;
        }

        $subscriberId = $this->getSubscriberId($userId);

        if (!$subscriberId) {
            return new WP_Error('subscriber_not_found', 'Subscriber not found');
        }

        $result = $this->fluentcrm->unlinkFromCompany($subscriberId, $companyId);

        if (!$result) {
            return new WP_Error('unlink_failed', 'Failed to unlink from company');
        }

        do_action('stride/subscriber_company_unlinked', $userId, $companyId);

        return true;
    }

    /**
     * Sync company data to user's invoice fields
     *
     * @param int $userId
     * @return true|WP_Error
     */
    public function syncCompanyData(int $userId): true|WP_Error
    {
        $company = $this->getCompany($userId);

        if (is_wp_error($company)) {
            return $company;
        }

        if (!$company) {
            return new WP_Error('no_company', 'User has no linked company');
        }

        // Convert company fields from legacy names
        $companyFields = FieldRegistry::convertLegacyData($company['custom_fields'] ?? [], 'company');

        // Map company fields to subscriber fields
        $fieldMapping = [
            FieldRegistry::COMPANY_INVOICE_NAME => FieldRegistry::SUBSCRIBER_INVOICE_ORG_NAME,
            FieldRegistry::COMPANY_INVOICE_ADDRESS => FieldRegistry::SUBSCRIBER_INVOICE_ADDRESS,
            FieldRegistry::COMPANY_INVOICE_CITY => FieldRegistry::SUBSCRIBER_INVOICE_CITY,
            FieldRegistry::COMPANY_INVOICE_POSTAL_CODE => FieldRegistry::SUBSCRIBER_INVOICE_POSTAL_CODE,
            FieldRegistry::COMPANY_INVOICE_EMAIL => FieldRegistry::SUBSCRIBER_INVOICE_EMAIL,
            FieldRegistry::COMPANY_VAT_NUMBER => FieldRegistry::SUBSCRIBER_VAT_NUMBER,
            FieldRegistry::COMPANY_GLN_NUMBER => FieldRegistry::SUBSCRIBER_GLN_NUMBER,
            FieldRegistry::COMPANY_EXPORT_ID => FieldRegistry::SUBSCRIBER_EXPORT_ID,
        ];

        $updates = [];
        foreach ($fieldMapping as $companyField => $subscriberField) {
            if (!empty($companyFields[$companyField])) {
                // Use logical field names - UserDataSync handles storage mapping
                $updates[$subscriberField] = $companyFields[$companyField];
            }
        }

        if (empty($updates)) {
            return true; // Nothing to sync
        }

        return $this->updateProfile($userId, $updates);
    }

    /**
     * Find company by export ID (accounting system reference)
     *
     * @param string $exportId
     * @return array|null
     */
    public function getCompanyByExportId(string $exportId): ?array
    {
        return $this->fluentcrm->findCompanyByExportId($exportId);
    }

    // ========================================
    // MEMBER STATUS
    // ========================================

    /**
     * Check if user is a member (linked to partner company)
     *
     * @param int $userId
     * @return bool
     */
    public function isMember(int $userId): bool
    {
        $memberType = apply_filters(
            'stride/subscriber/member_company_type',
            $this->config['member_company_type']
        );

        $companies = $this->getCompanies($userId);

        if (is_wp_error($companies) || empty($companies)) {
            return false;
        }

        foreach ($companies as $company) {
            if (($company['type'] ?? '') === $memberType) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get member type (profile type)
     *
     * @param int $userId
     * @return string|null
     */
    public function getMemberType(int $userId): ?string
    {
        // Use logical field name - UserDataSync handles storage mapping
        $profileType = $this->getField($userId, FieldRegistry::SUBSCRIBER_PROFILE_TYPE);

        if (is_wp_error($profileType)) {
            return null;
        }

        return $profileType ?: null;
    }

    /**
     * Get partner company for user (if member)
     *
     * @param int $userId
     * @return array|null
     */
    public function getPartnerCompany(int $userId): ?array
    {
        $memberType = apply_filters(
            'stride/subscriber/member_company_type',
            $this->config['member_company_type']
        );

        $companies = $this->getCompanies($userId);

        if (is_wp_error($companies) || empty($companies)) {
            return null;
        }

        foreach ($companies as $company) {
            if (($company['type'] ?? '') === $memberType) {
                return $company;
            }
        }

        return null;
    }

    // ========================================
    // BATCH OPERATIONS
    // ========================================

    /**
     * Get subscribers for multiple users
     * PERFORMANCE: Uses single WHERE IN query instead of N+1
     *
     * @param array $userIds
     * @param int $limit Maximum number of users to process (default 500)
     * @return array Map of user_id => subscriber data
     */
    public function getSubscribersBatch(array $userIds, int $limit = 500): array
    {
        if (!$this->isAvailable() || empty($userIds)) {
            return [];
        }

        // Enforce batch size limit
        $userIds = array_slice($userIds, 0, min($limit, 500));

        return $this->fluentcrm->getSubscribersByUserIds($userIds);
    }

    /**
     * Check member status for multiple users
     * PERFORMANCE: Uses eager loading to fetch subscribers+companies in one query
     *
     * @param array $userIds
     * @param int $limit Maximum number of users to process (default 500)
     * @return array Map of user_id => bool
     */
    public function getMembersBatch(array $userIds, int $limit = 500): array
    {
        if (!$this->isAvailable() || empty($userIds)) {
            return [];
        }

        // Enforce batch size limit
        $userIds = array_slice($userIds, 0, min($limit, 500));

        $memberType = apply_filters(
            'stride/subscriber/member_company_type',
            $this->config['member_company_type']
        );

        // Fetch all subscribers with their companies in one query
        $subscribersWithCompanies = $this->fluentcrm->getSubscribersWithCompanies($userIds);

        $result = [];

        foreach ($userIds as $userId) {
            $userId = (int) $userId;

            if (!isset($subscribersWithCompanies[$userId])) {
                $result[$userId] = false;
                continue;
            }

            $companies = $subscribersWithCompanies[$userId]['companies'] ?? [];
            $isMember = false;

            foreach ($companies as $company) {
                if (($company['type'] ?? '') === $memberType) {
                    $isMember = true;
                    break;
                }
            }

            $result[$userId] = $isMember;
        }

        return $result;
    }

    // ========================================
    // AUTHORIZATION HELPERS
    // ========================================

    /**
     * Check if current user can manage subscribers
     *
     * @return bool
     */
    private function currentUserCanManage(): bool
    {
        return current_user_can('manage_options') || current_user_can('fluentcrm_admin');
    }

    /**
     * Check if current user can modify another user's subscriber data
     *
     * Allows operation if:
     * - Current user has admin/management capability, OR
     * - Current user is the target user (self-modification)
     *
     * @param int $targetUserId The user being modified
     * @return true|WP_Error
     */
    private function canModifyUser(int $targetUserId): true|WP_Error
    {
        $currentUserId = get_current_user_id();

        // Allow admins
        if ($this->currentUserCanManage()) {
            return true;
        }

        // Allow self-modification
        if ($currentUserId > 0 && $currentUserId === $targetUserId) {
            return true;
        }

        // Allow hook to grant access (for custom workflows)
        $allowed = apply_filters('stride/subscriber/can_modify', false, $currentUserId, $targetUserId);
        if ($allowed === true) {
            return true;
        }

        return new WP_Error(
            'unauthorized',
            __('You do not have permission to modify this subscriber.', 'stride'),
            ['status' => 403]
        );
    }
}
