<?php

namespace ntdst\Stride\sync;

defined('ABSPATH') || exit;

use ntdst\Stride\FieldRegistry;

/**
 * WordPress User Storage Backend
 *
 * Stores user data in WordPress user meta and core user fields.
 * Lowest priority - acts as fallback storage.
 *
 * @package stride
 */
class WordPressUserStorage extends AbstractStorageBackend
{
    /**
     * Core WP_User fields (not meta)
     */
    protected array $coreFields = [
        'user_email',
        'display_name',
    ];

    /**
     * Field mapping: FieldRegistry constant => WordPress meta key
     * Built dynamically in constructor using FieldRegistry
     */
    protected array $fieldMap = [];

    public function __construct()
    {
        $this->fieldContext = 'subscriber';
        $this->buildFieldMap();
    }

    /**
     * Build field map using FieldRegistry constants
     */
    private function buildFieldMap(): void
    {
        // Standard fields - direct mapping to WP user meta/fields
        $this->fieldMap = [
            // Core WP_User fields
            FieldRegistry::FIELD_EMAIL => 'user_email',
            FieldRegistry::FIELD_DISPLAY_NAME => 'display_name',

            // Standard user meta
            FieldRegistry::FIELD_FIRST_NAME => 'first_name',
            FieldRegistry::FIELD_LAST_NAME => 'last_name',
            FieldRegistry::FIELD_PHONE => 'stride_phone',

            // Address fields (prefixed for Stride)
            FieldRegistry::FIELD_ADDRESS => 'stride_address',
            FieldRegistry::FIELD_ADDRESS_2 => 'stride_address_2',
            FieldRegistry::FIELD_CITY => 'stride_city',
            FieldRegistry::FIELD_POSTAL_CODE => 'stride_postal_code',
            FieldRegistry::FIELD_COUNTRY => 'stride_country',
            FieldRegistry::FIELD_STATE => 'stride_state',

            // Invoice/billing fields
            FieldRegistry::SUBSCRIBER_INVOICE_ORG_NAME => 'stride_invoice_org',
            FieldRegistry::SUBSCRIBER_INVOICE_EMAIL => 'stride_invoice_email',
            FieldRegistry::SUBSCRIBER_INVOICE_ADDRESS => 'stride_invoice_address',
            FieldRegistry::SUBSCRIBER_INVOICE_CITY => 'stride_invoice_city',
            FieldRegistry::SUBSCRIBER_INVOICE_POSTAL_CODE => 'stride_invoice_postal',
            FieldRegistry::SUBSCRIBER_VAT_NUMBER => 'stride_vat_number',
            FieldRegistry::SUBSCRIBER_GLN_NUMBER => 'stride_gln_number',
            FieldRegistry::SUBSCRIBER_DEPARTMENT => 'stride_department',
            FieldRegistry::SUBSCRIBER_EXPORT_ID => 'stride_export_id',
            FieldRegistry::SUBSCRIBER_PROFILE_TYPE => 'stride_profile_type',

            // Management
            FieldRegistry::FIELD_MANAGED_BY => 'stride_managed_by',
        ];
    }

    /**
     * @inheritDoc
     */
    public function getId(): string
    {
        return 'wordpress';
    }

    /**
     * @inheritDoc
     */
    public function getPriority(): int
    {
        return 0; // Lowest priority - fallback
    }

    /**
     * @inheritDoc
     */
    protected function readFromStorage(int $userId, string $storageKey): mixed
    {
        // Core WP_User field
        if (in_array($storageKey, $this->coreFields, true)) {
            $user = get_userdata($userId);
            return $user ? ($user->$storageKey ?? null) : null;
        }

        // User meta
        $value = get_user_meta($userId, $storageKey, true);

        return $value !== '' ? $value : null;
    }

    /**
     * @inheritDoc
     */
    protected function writeToStorage(int $userId, string $storageKey, mixed $value): bool
    {
        // Core WP_User field
        if (in_array($storageKey, $this->coreFields, true)) {
            $result = wp_update_user([
                'ID' => $userId,
                $storageKey => $value,
            ]);
            return !is_wp_error($result);
        }

        // User meta
        return update_user_meta($userId, $storageKey, $value) !== false;
    }

    /**
     * @inheritDoc
     */
    public function isAvailable(): bool
    {
        return function_exists('get_user_meta');
    }

    /**
     * Get all user data including meta (efficient batch read)
     *
     * @param int $userId
     * @return array
     */
    public function getAllUserData(int $userId): array
    {
        $user = get_userdata($userId);
        if (!$user) {
            return [];
        }

        $data = [
            FieldRegistry::FIELD_EMAIL => $user->user_email,
            FieldRegistry::FIELD_FIRST_NAME => $user->first_name,
            FieldRegistry::FIELD_LAST_NAME => $user->last_name,
            FieldRegistry::FIELD_DISPLAY_NAME => $user->display_name,
        ];

        // Get all meta in one query
        $meta = get_user_meta($userId);

        foreach ($this->fieldMap as $field => $key) {
            if (!in_array($key, $this->coreFields, true) && isset($meta[$key][0])) {
                $data[$field] = $meta[$key][0];
            }
        }

        return $data;
    }
}
