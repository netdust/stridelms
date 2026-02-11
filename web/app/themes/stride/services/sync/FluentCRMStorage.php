<?php

namespace stride\services\sync;

defined('ABSPATH') || exit;

use stride\services\contracts\FluentCRMAdapterInterface;
use stride\services\adapters\FluentCRMAdapter;
use stride\services\FieldRegistry;

/**
 * FluentCRM Storage Backend
 *
 * Stores user data in FluentCRM subscriber records.
 * High priority - FluentCRM is source of truth for CRM data.
 *
 * @package stride
 */
class FluentCRMStorage extends AbstractStorageBackend
{
    private FluentCRMAdapterInterface $adapter;

    /**
     * Subscriber ID cache: [userId => subscriberId]
     */
    private static array $subscriberIdCache = [];

    /**
     * Standard FluentCRM contact fields (not custom)
     */
    protected array $standardFields = [];

    /**
     * Field mapping: FieldRegistry constant => FluentCRM field
     */
    protected array $fieldMap = [];

    public function __construct(?FluentCRMAdapterInterface $adapter = null)
    {
        $this->adapter = $adapter ?? new FluentCRMAdapter();
        $this->fieldContext = 'subscriber';
        $this->buildFieldMap();
    }

    /**
     * Build field map using FieldRegistry constants
     */
    private function buildFieldMap(): void
    {
        // Standard FluentCRM contact fields
        $this->standardFields = [
            FieldRegistry::FIELD_EMAIL,
            FieldRegistry::FIELD_FIRST_NAME,
            FieldRegistry::FIELD_LAST_NAME,
            FieldRegistry::FIELD_PHONE,
            FieldRegistry::FIELD_ADDRESS,      // maps to address_line_1
            FieldRegistry::FIELD_ADDRESS_2,    // maps to address_line_2
            FieldRegistry::FIELD_CITY,
            FieldRegistry::FIELD_STATE,
            FieldRegistry::FIELD_POSTAL_CODE,
            FieldRegistry::FIELD_COUNTRY,
        ];

        // Field map: logical field => FluentCRM field
        $this->fieldMap = [
            // Standard contact fields
            FieldRegistry::FIELD_EMAIL => 'email',
            FieldRegistry::FIELD_FIRST_NAME => 'first_name',
            FieldRegistry::FIELD_LAST_NAME => 'last_name',
            FieldRegistry::FIELD_PHONE => 'phone',
            FieldRegistry::FIELD_ADDRESS => 'address_line_1',
            FieldRegistry::FIELD_ADDRESS_2 => 'address_line_2',
            FieldRegistry::FIELD_CITY => 'city',
            FieldRegistry::FIELD_STATE => 'state',
            FieldRegistry::FIELD_POSTAL_CODE => 'postal_code',
            FieldRegistry::FIELD_COUNTRY => 'country',

            // Custom fields - stored in FluentCRM custom values
            FieldRegistry::SUBSCRIBER_INVOICE_ORG_NAME => FieldRegistry::SUBSCRIBER_INVOICE_ORG_NAME,
            FieldRegistry::SUBSCRIBER_INVOICE_EMAIL => FieldRegistry::SUBSCRIBER_INVOICE_EMAIL,
            FieldRegistry::SUBSCRIBER_INVOICE_ADDRESS => FieldRegistry::SUBSCRIBER_INVOICE_ADDRESS,
            FieldRegistry::SUBSCRIBER_INVOICE_CITY => FieldRegistry::SUBSCRIBER_INVOICE_CITY,
            FieldRegistry::SUBSCRIBER_INVOICE_POSTAL_CODE => FieldRegistry::SUBSCRIBER_INVOICE_POSTAL_CODE,
            FieldRegistry::SUBSCRIBER_VAT_NUMBER => FieldRegistry::SUBSCRIBER_VAT_NUMBER,
            FieldRegistry::SUBSCRIBER_GLN_NUMBER => FieldRegistry::SUBSCRIBER_GLN_NUMBER,
            FieldRegistry::SUBSCRIBER_DEPARTMENT => FieldRegistry::SUBSCRIBER_DEPARTMENT,
            FieldRegistry::SUBSCRIBER_EXPORT_ID => FieldRegistry::SUBSCRIBER_EXPORT_ID,
            FieldRegistry::SUBSCRIBER_PROFILE_TYPE => FieldRegistry::SUBSCRIBER_PROFILE_TYPE,

            // Management field
            FieldRegistry::FIELD_MANAGED_BY => FieldRegistry::FIELD_MANAGED_BY,
        ];
    }

    /**
     * Check if field is a standard FluentCRM contact field
     */
    private function isStandardField(string $field): bool
    {
        return in_array($field, $this->standardFields, true);
    }

    /**
     * @inheritDoc
     */
    public function getId(): string
    {
        return 'fluentcrm';
    }

    /**
     * @inheritDoc
     */
    public function getPriority(): int
    {
        return 100; // High priority - source of truth
    }

    /**
     * @inheritDoc
     */
    public function isAvailable(): bool
    {
        return $this->adapter->isAvailable();
    }

    /**
     * @inheritDoc
     */
    protected function readFromStorage(int $userId, string $storageKey): mixed
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $subscriberId = $this->getSubscriberId($userId);
        if (!$subscriberId) {
            return null;
        }

        // Get subscriber data (cached in adapter)
        $subscriber = $this->adapter->getSubscriberByUserId($userId);
        if (!$subscriber) {
            return null;
        }

        // Check standard fields first (use FluentCRM field name)
        if (isset($subscriber[$storageKey])) {
            return $subscriber[$storageKey];
        }

        // Get from custom fields - use database field name
        $dbKey = FieldRegistry::getDbFieldName($storageKey, 'subscriber');
        return $this->adapter->getCustomField($subscriberId, $dbKey);
    }

    /**
     * @inheritDoc
     */
    protected function writeToStorage(int $userId, string $storageKey, mixed $value): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        $subscriberId = $this->getSubscriberId($userId);
        if (!$subscriberId) {
            // Try to create subscriber
            $subscriberId = $this->ensureSubscriber($userId);
            if (!$subscriberId) {
                return false;
            }
        }

        // Find the original field to check if it's standard
        $originalField = $this->getLogicalFieldFromStorage($storageKey);

        // Standard field - update subscriber directly
        if ($this->isStandardField($originalField)) {
            return $this->adapter->updateSubscriber($subscriberId, [$storageKey => $value]);
        }

        // Custom field - use database field name
        $dbKey = FieldRegistry::getDbFieldName($storageKey, 'subscriber');
        return $this->adapter->updateCustomField($subscriberId, $dbKey, $value);
    }

    /**
     * Get logical field name from storage key
     */
    private function getLogicalFieldFromStorage(string $storageKey): string
    {
        $flipped = array_flip($this->fieldMap);
        return $flipped[$storageKey] ?? $storageKey;
    }

    /**
     * @inheritDoc
     */
    public function setFields(int $userId, array $data): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        $subscriberId = $this->getSubscriberId($userId);
        if (!$subscriberId) {
            $subscriberId = $this->ensureSubscriber($userId);
            if (!$subscriberId) {
                return false;
            }
        }

        // Separate standard and custom fields for efficient updates
        $standardData = [];
        $customData = [];

        foreach ($data as $field => $value) {
            $storageKey = $this->getStorageKey($field);

            if ($this->isStandardField($field)) {
                $standardData[$storageKey] = $value;
            } else {
                $dbKey = FieldRegistry::getDbFieldName($storageKey, 'subscriber');
                $customData[$dbKey] = $value;
            }
        }

        $success = true;

        // Batch update standard fields
        if (!empty($standardData)) {
            if (!$this->adapter->updateSubscriber($subscriberId, $standardData)) {
                $success = false;
            }
        }

        // Update custom fields
        foreach ($customData as $key => $value) {
            if (!$this->adapter->updateCustomField($subscriberId, $key, $value)) {
                $success = false;
            }
        }

        // Clear cache on write
        $this->clearCache($userId);

        return $success;
    }

    /**
     * @inheritDoc
     */
    public function clearCache(?int $userId = null): void
    {
        parent::clearCache($userId);

        if ($userId === null) {
            self::$subscriberIdCache = [];
        } else {
            unset(self::$subscriberIdCache[$userId]);
        }

        // Also clear adapter cache
        FluentCRMAdapter::clearCache();
    }

    /**
     * Get FluentCRM subscriber ID for WordPress user
     */
    private function getSubscriberId(int $userId): ?int
    {
        if (isset(self::$subscriberIdCache[$userId])) {
            return self::$subscriberIdCache[$userId];
        }

        $subscriber = $this->adapter->getSubscriberByUserId($userId);

        if ($subscriber) {
            self::$subscriberIdCache[$userId] = (int) $subscriber['id'];
            return self::$subscriberIdCache[$userId];
        }

        return null;
    }

    /**
     * Ensure subscriber exists, create if needed
     */
    private function ensureSubscriber(int $userId): ?int
    {
        $user = get_userdata($userId);
        if (!$user) {
            return null;
        }

        $subscriberId = $this->adapter->createSubscriber([
            'email' => $user->user_email,
            'first_name' => $user->first_name ?: '',
            'last_name' => $user->last_name ?: '',
            'user_id' => $userId,
            'status' => 'subscribed',
        ]);

        if ($subscriberId) {
            self::$subscriberIdCache[$userId] = $subscriberId;
        }

        return $subscriberId;
    }
}
