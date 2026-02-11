<?php

namespace stride\services\core;

defined('ABSPATH') || exit;

use stride\services\contracts\FluentCRMAdapterInterface;
use stride\services\adapters\FluentCRMAdapter;
use stride\services\FieldRegistry;
use WP_Error;

/**
 * Organization Service
 *
 * Manages FluentCRM companies (organizations) for the Stride LMS.
 * Handles company creation, updates, user linking, and invoice data.
 *
 * Available filters:
 * - netdust_organization_config - Customize service configuration
 * - stride/organization/custom_fields - Modify custom field definitions
 *
 * @package stride
 */
class OrganizationService implements \NTDST_Service_Meta
{
    private array $config;
    private FluentCRMAdapterInterface $fluentcrm;

    /**
     * Service metadata for NTDST Bootstrap
     */
    public static function metadata(): array
    {
        return [
            'name' => 'Organization Service',
            'description' => 'FluentCRM company/organization management',
            'admin_only' => false,
            'enabled' => true,
            'priority' => 10,
        ];
    }

    /**
     * Constructor - dependencies injected by DI container
     */
    public function __construct(?FluentCRMAdapterInterface $fluentcrm = null)
    {
        $this->fluentcrm = $fluentcrm ?? new FluentCRMAdapter();
        $this->config = $this->getDefaultConfig();
        $this->init();
    }

    /**
     * Get configuration with filter for customization
     */
    private function getDefaultConfig(): array
    {
        return apply_filters('netdust_organization_config', [
            'member_company_type' => FieldRegistry::COMPANY_TYPE_PARTNER,
            'default_country' => 'BE',
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
        do_action('stride/organization_service_ready', $this);
    }

    /**
     * Check if FluentCRM is available
     */
    public function isAvailable(): bool
    {
        return $this->fluentcrm->isAvailable();
    }

    /**
     * Get custom field definitions
     * Returns mapping of field keys to human-readable labels
     */
    public function getCustomFieldDefinitions(): array
    {
        $definitions = [];
        foreach (FieldRegistry::getCompanyFields() as $name => $key) {
            $definitions[$key] = FieldRegistry::getFieldLabel($key, 'company');
        }

        return apply_filters('stride/organization/custom_fields', $definitions);
    }

    // ========================================
    // COMPANY MANAGEMENT
    // ========================================

    /**
     * Create a new company/organization
     *
     * SECURITY: Requires admin capability.
     *
     * @param array $data Company data including:
     *   - name (required)
     *   - email
     *   - phone
     *   - address_line_1, address_line_2, city, postal_code, country
     *   - type (defaults to 'company')
     *   - custom_fields: array of custom field values
     * @return int|WP_Error Company ID or error
     */
    public function createCompany(array $data): int|WP_Error
    {
        // Authorization check - admin only
        if (!$this->currentUserCanManage()) {
            return new WP_Error(
                'unauthorized',
                __('You do not have permission to create companies.', 'stride'),
                ['status' => 403]
            );
        }

        if (!$this->isAvailable()) {
            return new WP_Error(
                'fluentcrm_unavailable',
                'FluentCRM is not available',
                ['status' => 503]
            );
        }

        // Validate required fields
        if (empty($data['name'])) {
            return new WP_Error(
                'missing_name',
                'Company name is required',
                ['field' => 'name']
            );
        }

        // Sanitize input
        $sanitizedData = $this->sanitizeCompanyData($data);

        // Set defaults
        if (empty($sanitizedData['country'])) {
            $sanitizedData['country'] = $this->config['default_country'];
        }

        if (empty($sanitizedData['type'])) {
            $sanitizedData['type'] = 'company';
        }

        // Create company
        $companyId = $this->fluentcrm->createCompany($sanitizedData);

        if (!$companyId) {
            return new WP_Error(
                'creation_failed',
                'Failed to create company',
                ['data' => $sanitizedData]
            );
        }

        do_action('stride/organization_created', $companyId, $sanitizedData);

        return $companyId;
    }

    /**
     * Update an existing company
     *
     * SECURITY: Requires admin capability.
     *
     * @param int $companyId
     * @param array $data Fields to update
     * @return true|WP_Error
     */
    public function updateCompany(int $companyId, array $data): true|WP_Error
    {
        // Authorization check - admin only
        if (!$this->currentUserCanManage()) {
            return new WP_Error(
                'unauthorized',
                __('You do not have permission to update companies.', 'stride'),
                ['status' => 403]
            );
        }

        if (!$this->isAvailable()) {
            return new WP_Error(
                'fluentcrm_unavailable',
                'FluentCRM is not available',
                ['status' => 503]
            );
        }

        // Verify company exists
        $company = $this->fluentcrm->getCompany($companyId);

        if (!$company) {
            return new WP_Error(
                'company_not_found',
                'Company not found',
                ['company_id' => $companyId]
            );
        }

        // Sanitize input
        $sanitizedData = $this->sanitizeCompanyData($data);

        $result = $this->fluentcrm->updateCompany($companyId, $sanitizedData);

        if (!$result) {
            return new WP_Error(
                'update_failed',
                'Failed to update company',
                ['company_id' => $companyId]
            );
        }

        do_action('stride/organization_updated', $companyId, $sanitizedData);

        return true;
    }

    /**
     * Get company by ID
     *
     * @param int $companyId
     * @return array|WP_Error Company data or error
     */
    public function getCompany(int $companyId): array|WP_Error
    {
        if (!$this->isAvailable()) {
            return new WP_Error(
                'fluentcrm_unavailable',
                'FluentCRM is not available',
                ['status' => 503]
            );
        }

        $company = $this->fluentcrm->getCompany($companyId);

        if (!$company) {
            return new WP_Error(
                'company_not_found',
                'Company not found',
                ['company_id' => $companyId]
            );
        }

        return $company;
    }

    /**
     * Get all users linked to a company
     *
     * @param int $companyId
     * @return array|WP_Error Array of user data or error
     */
    public function getCompanyUsers(int $companyId): array|WP_Error
    {
        if (!$this->isAvailable()) {
            return new WP_Error(
                'fluentcrm_unavailable',
                'FluentCRM is not available',
                ['status' => 503]
            );
        }

        // Verify company exists
        $company = $this->fluentcrm->getCompany($companyId);

        if (!$company) {
            return new WP_Error(
                'company_not_found',
                'Company not found',
                ['company_id' => $companyId]
            );
        }

        $subscribers = $this->fluentcrm->getCompanySubscribers($companyId);

        // Map subscribers to user-friendly format with WordPress user IDs
        return array_map(function ($subscriber) {
            return [
                'subscriber_id' => $subscriber['id'],
                'user_id' => $subscriber['user_id'],
                'email' => $subscriber['email'],
                'first_name' => $subscriber['first_name'],
                'last_name' => $subscriber['last_name'],
                'full_name' => $subscriber['full_name'],
            ];
        }, $subscribers);
    }

    /**
     * Link a WordPress user to a company
     *
     * SECURITY: Requires admin capability OR current user linking themselves.
     *
     * @param int $userId WordPress user ID
     * @param int $companyId FluentCRM company ID
     * @return true|WP_Error
     */
    public function linkUserToCompany(int $userId, int $companyId): true|WP_Error
    {
        // Authorization check
        $authCheck = $this->canModifyUser($userId);
        if (is_wp_error($authCheck)) {
            return $authCheck;
        }

        if (!$this->isAvailable()) {
            return new WP_Error(
                'fluentcrm_unavailable',
                'FluentCRM is not available',
                ['status' => 503]
            );
        }

        // Get subscriber for user
        $subscriber = $this->fluentcrm->getSubscriberByUserId($userId);

        if (!$subscriber) {
            return new WP_Error(
                'subscriber_not_found',
                'No FluentCRM subscriber found for user',
                ['user_id' => $userId]
            );
        }

        // Verify company exists
        $company = $this->fluentcrm->getCompany($companyId);

        if (!$company) {
            return new WP_Error(
                'company_not_found',
                'Company not found',
                ['company_id' => $companyId]
            );
        }

        $result = $this->fluentcrm->linkToCompany($subscriber['id'], $companyId);

        if (!$result) {
            return new WP_Error(
                'link_failed',
                'Failed to link user to company',
                ['user_id' => $userId, 'company_id' => $companyId]
            );
        }

        do_action('stride/user_linked_to_organization', $userId, $companyId);

        return true;
    }

    /**
     * Unlink a WordPress user from a company
     *
     * SECURITY: Requires admin capability. Unlinking affects billing and membership.
     *
     * @param int $userId WordPress user ID
     * @param int $companyId FluentCRM company ID
     * @return true|WP_Error
     */
    public function unlinkUserFromCompany(int $userId, int $companyId): true|WP_Error
    {
        // Authorization check - admin only for unlinking
        if (!$this->currentUserCanManage()) {
            return new WP_Error(
                'unauthorized',
                __('You do not have permission to unlink users from companies.', 'stride'),
                ['status' => 403]
            );
        }

        if (!$this->isAvailable()) {
            return new WP_Error(
                'fluentcrm_unavailable',
                'FluentCRM is not available',
                ['status' => 503]
            );
        }

        // Get subscriber for user
        $subscriber = $this->fluentcrm->getSubscriberByUserId($userId);

        if (!$subscriber) {
            return new WP_Error(
                'subscriber_not_found',
                'No FluentCRM subscriber found for user',
                ['user_id' => $userId]
            );
        }

        $result = $this->fluentcrm->unlinkFromCompany($subscriber['id'], $companyId);

        if (!$result) {
            return new WP_Error(
                'unlink_failed',
                'Failed to unlink user from company',
                ['user_id' => $userId, 'company_id' => $companyId]
            );
        }

        do_action('stride/user_unlinked_from_organization', $userId, $companyId);

        return true;
    }

    // ========================================
    // CUSTOM FIELDS
    // ========================================

    /**
     * Get company custom field value
     *
     * @param int $companyId
     * @param string $fieldKey
     * @return mixed|WP_Error
     */
    public function getCustomField(int $companyId, string $fieldKey): mixed
    {
        if (!$this->isAvailable()) {
            return new WP_Error(
                'fluentcrm_unavailable',
                'FluentCRM is not available',
                ['status' => 503]
            );
        }

        $customFields = $this->fluentcrm->getCompanyCustomFields($companyId);

        return $customFields[$fieldKey] ?? null;
    }

    /**
     * Update company custom fields
     *
     * SECURITY: Requires admin capability.
     *
     * @param int $companyId
     * @param array $fields Key-value pairs
     * @return true|WP_Error
     */
    public function updateCustomFields(int $companyId, array $fields): true|WP_Error
    {
        // Authorization check - admin only
        if (!$this->currentUserCanManage()) {
            return new WP_Error(
                'unauthorized',
                __('You do not have permission to update company fields.', 'stride'),
                ['status' => 403]
            );
        }

        if (!$this->isAvailable()) {
            return new WP_Error(
                'fluentcrm_unavailable',
                'FluentCRM is not available',
                ['status' => 503]
            );
        }

        // Verify company exists
        $company = $this->fluentcrm->getCompany($companyId);

        if (!$company) {
            return new WP_Error(
                'company_not_found',
                'Company not found',
                ['company_id' => $companyId]
            );
        }

        // Sanitize custom field values
        $sanitizedFields = [];
        foreach ($fields as $key => $value) {
            $sanitizedFields[sanitize_key($key)] = is_string($value) ? sanitize_text_field($value) : $value;
        }

        $result = $this->fluentcrm->updateCompanyCustomFields($companyId, $sanitizedFields);

        if (!$result) {
            return new WP_Error(
                'update_failed',
                'Failed to update custom fields',
                ['company_id' => $companyId]
            );
        }

        return true;
    }

    /**
     * Get invoice data for a company
     *
     * @param int $companyId
     * @return array|WP_Error
     */
    public function getInvoiceData(int $companyId): array|WP_Error
    {
        if (!$this->isAvailable()) {
            return new WP_Error(
                'fluentcrm_unavailable',
                'FluentCRM is not available',
                ['status' => 503]
            );
        }

        $company = $this->fluentcrm->getCompany($companyId);

        if (!$company) {
            return new WP_Error(
                'company_not_found',
                'Company not found',
                ['company_id' => $companyId]
            );
        }

        // Convert legacy field names to new names
        $fields = FieldRegistry::convertLegacyData($company['custom_fields'] ?? [], 'company');

        return [
            'company_id' => $companyId,
            'name' => $fields[FieldRegistry::COMPANY_INVOICE_NAME] ?? $company['name'],
            'email' => $fields[FieldRegistry::COMPANY_INVOICE_EMAIL] ?? $company['email'],
            'address' => $fields[FieldRegistry::COMPANY_INVOICE_ADDRESS] ?? $company['address_line_1'],
            'city' => $fields[FieldRegistry::COMPANY_INVOICE_CITY] ?? $company['city'],
            'postal_code' => $fields[FieldRegistry::COMPANY_INVOICE_POSTAL_CODE] ?? $company['postal_code'],
            'country' => $company['country'] ?? 'BE',
            'vat_number' => $fields[FieldRegistry::COMPANY_VAT_NUMBER] ?? '',
            'gln_number' => $fields[FieldRegistry::COMPANY_GLN_NUMBER] ?? '',
            'department' => $fields[FieldRegistry::COMPANY_DEPARTMENT] ?? '',
            'export_id' => $fields[FieldRegistry::COMPANY_EXPORT_ID] ?? '',
        ];
    }

    /**
     * Set invoice data for a company
     *
     * @param int $companyId
     * @param array $invoiceData
     * @return true|WP_Error
     */
    public function setInvoiceData(int $companyId, array $invoiceData): true|WP_Error
    {
        // Map incoming API keys to FieldRegistry constants
        $inputToFieldMap = [
            'name' => FieldRegistry::COMPANY_INVOICE_NAME,
            'email' => FieldRegistry::COMPANY_INVOICE_EMAIL,
            'address' => FieldRegistry::COMPANY_INVOICE_ADDRESS,
            'city' => FieldRegistry::COMPANY_INVOICE_CITY,
            'postal_code' => FieldRegistry::COMPANY_INVOICE_POSTAL_CODE,
            'vat_number' => FieldRegistry::COMPANY_VAT_NUMBER,
            'gln_number' => FieldRegistry::COMPANY_GLN_NUMBER,
            'department' => FieldRegistry::COMPANY_DEPARTMENT,
            'export_id' => FieldRegistry::COMPANY_EXPORT_ID,
        ];

        $customFields = [];
        foreach ($invoiceData as $key => $value) {
            if (isset($inputToFieldMap[$key]) && $value !== null && $value !== '') {
                // Get database field name (legacy if in legacy mode)
                $dbField = FieldRegistry::getDbFieldName($inputToFieldMap[$key], 'company');
                $customFields[$dbField] = $value;
            }
        }

        if (empty($customFields)) {
            return true; // Nothing to update
        }

        return $this->updateCustomFields($companyId, $customFields);
    }

    // ========================================
    // LOOKUP METHODS
    // ========================================

    /**
     * Find company by export ID (Winbooks/accounting reference)
     *
     * @param string $exportId
     * @return array|null Company data or null
     */
    public function findByExportId(string $exportId): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        return $this->fluentcrm->findCompanyByExportId($exportId);
    }

    /**
     * Find company by name (exact match)
     *
     * @param string $name
     * @return array|null Company data or null
     */
    public function findByName(string $name): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        return $this->fluentcrm->findCompanyByName($name);
    }

    /**
     * Search companies by name
     *
     * @param string $query
     * @param int $limit
     * @return array Array of company data
     */
    public function search(string $query, int $limit = 10): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $query = sanitize_text_field($query);

        if (strlen($query) < 2) {
            return [];
        }

        return $this->fluentcrm->searchCompanies($query, $limit);
    }

    /**
     * Check if company is a member organization (partner)
     *
     * @param int $companyId
     * @return bool
     */
    public function isMemberOrganization(int $companyId): bool
    {
        $company = $this->fluentcrm->getCompany($companyId);

        if (!$company) {
            return false;
        }

        $memberType = $this->config['member_company_type'];

        return ($company['type'] ?? '') === $memberType;
    }

    /**
     * Get all member organizations (partners)
     *
     * @return array Array of company data
     */
    public function getMemberOrganizations(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        // Search for companies with partner type
        // Note: This is a workaround since FluentCRM doesn't have direct type filtering
        try {
            $companies = \FluentCrm\App\Models\Company::where('type', $this->config['member_company_type'])
                ->get();

            return $companies->map(function ($company) {
                $customValues = $company->getCustomValues() ?? [];
                return [
                    'id' => (int) $company->id,
                    'name' => $company->name,
                    'type' => $company->type,
                    'email' => $company->email,
                    'custom_fields' => $customValues,
                ];
            })->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    // ========================================
    // HELPER METHODS
    // ========================================

    /**
     * Sanitize company data
     */
    private function sanitizeCompanyData(array $data): array
    {
        $sanitized = [];

        // Standard text fields
        $textFields = ['name', 'email', 'phone', 'address_line_1', 'address_line_2',
                       'city', 'state', 'postal_code', 'country', 'type', 'description'];

        foreach ($textFields as $field) {
            if (isset($data[$field])) {
                $sanitized[$field] = $field === 'email'
                    ? sanitize_email($data[$field])
                    : sanitize_text_field($data[$field]);
            }
        }

        // Handle custom fields
        if (isset($data['custom_fields']) && is_array($data['custom_fields'])) {
            $sanitized['custom_fields'] = [];
            foreach ($data['custom_fields'] as $key => $value) {
                $sanitized['custom_fields'][sanitize_key($key)] = is_string($value)
                    ? sanitize_text_field($value)
                    : $value;
            }
        }

        return $sanitized;
    }

    /**
     * Create or find company by name
     * If company exists, returns existing. Otherwise creates new.
     *
     * @param string $name
     * @param array $data Additional data for new company
     * @return int|WP_Error Company ID or error
     */
    public function findOrCreate(string $name, array $data = []): int|WP_Error
    {
        $existing = $this->findByName($name);

        if ($existing) {
            return (int) $existing['id'];
        }

        $data['name'] = $name;

        return $this->createCompany($data);
    }

    // ========================================
    // AUTHORIZATION HELPERS
    // ========================================

    /**
     * Check if current user can manage organizations
     *
     * @return bool
     */
    private function currentUserCanManage(): bool
    {
        return current_user_can('manage_options') || current_user_can('fluentcrm_admin');
    }

    /**
     * Check if current user can modify another user's company links
     *
     * Allows operation if:
     * - Current user has admin/management capability, OR
     * - Current user is the target user (self-linking)
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
        $allowed = apply_filters('stride/organization/can_modify_link', false, $currentUserId, $targetUserId);
        if ($allowed === true) {
            return true;
        }

        return new WP_Error(
            'unauthorized',
            __('You do not have permission to modify company links.', 'stride'),
            ['status' => 403]
        );
    }
}
