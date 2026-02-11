<?php

namespace stride\services\smartcode\providers;

defined('ABSPATH') || exit;

use stride\services\smartcode\contracts\SmartCodeProviderInterface;
use stride\services\smartcode\contracts\SmartCodeContextInterface;
use stride\services\sync\UserDataSync;
use stride\services\core\SubscriberService;
use stride\services\FieldRegistry;

/**
 * Contact SmartCode Provider
 *
 * Provides subscriber/contact data SmartCodes for FluentCRM and FluentForms.
 * Uses FieldRegistry constants and UserDataSync for data retrieval.
 *
 * SECURITY: Output is escaped with esc_html() by default to prevent XSS.
 * Use the 'stride/smartcode/escape_output' filter to customize escaping behavior.
 *
 * PERFORMANCE: Supports batch prefetch via prefetchUsers() method.
 * Call this before processing multiple users to warm the cache.
 *
 * Available SmartCodes:
 * - stride_contact.first_name
 * - stride_contact.last_name
 * - stride_contact.full_name
 * - stride_contact.phone
 * - stride_contact.profile_type
 * - stride_contact.is_member
 * - stride_contact.invoice_org
 * - stride_contact.invoice_address
 * - stride_contact.invoice_city
 * - stride_contact.invoice_postal
 * - stride_contact.invoice_email
 * - stride_contact.vat_number
 * - stride_contact.gln_number
 * - stride_contact.department
 * - stride_contact.profile_link
 *
 * @package stride\services\smartcode\providers
 */
class ContactSmartCodeProvider implements SmartCodeProviderInterface
{
    private UserDataSync $dataSync;
    private SubscriberService $subscriberService;

    /**
     * Prefetched user data cache: [userId => [field => value]]
     */
    private static array $prefetchedData = [];

    /**
     * Fields to prefetch for performance
     */
    private const PREFETCH_FIELDS = [
        FieldRegistry::FIELD_FIRST_NAME,
        FieldRegistry::FIELD_LAST_NAME,
        FieldRegistry::FIELD_PHONE,
        FieldRegistry::SUBSCRIBER_PROFILE_TYPE,
        FieldRegistry::SUBSCRIBER_INVOICE_ORG_NAME,
        FieldRegistry::SUBSCRIBER_INVOICE_ADDRESS,
        FieldRegistry::SUBSCRIBER_INVOICE_CITY,
        FieldRegistry::SUBSCRIBER_INVOICE_POSTAL_CODE,
        FieldRegistry::SUBSCRIBER_INVOICE_EMAIL,
        FieldRegistry::SUBSCRIBER_VAT_NUMBER,
        FieldRegistry::SUBSCRIBER_GLN_NUMBER,
        FieldRegistry::SUBSCRIBER_DEPARTMENT,
    ];

    /**
     * Constructor
     *
     * @param UserDataSync|null $dataSync
     * @param SubscriberService|null $subscriberService
     */
    public function __construct(
        ?UserDataSync $dataSync = null,
        ?SubscriberService $subscriberService = null
    ) {
        $this->dataSync = $dataSync ?? $this->getOrCreateDataSync();
        $this->subscriberService = $subscriberService ?? $this->getOrCreateSubscriberService();
    }

    /**
     * Get or create UserDataSync using DI container if available
     */
    private function getOrCreateDataSync(): UserDataSync
    {
        if (function_exists('ntdst_get')) {
            try {
                $service = ntdst_get(UserDataSync::class);
                if ($service instanceof UserDataSync) {
                    return $service;
                }
            } catch (\Exception $e) {
                // Fall through
            }
        }
        return new UserDataSync();
    }

    /**
     * Get or create SubscriberService using DI container if available
     */
    private function getOrCreateSubscriberService(): SubscriberService
    {
        if (function_exists('ntdst_get')) {
            try {
                $service = ntdst_get(SubscriberService::class);
                if ($service instanceof SubscriberService) {
                    return $service;
                }
            } catch (\Exception $e) {
                // Fall through
            }
        }
        return new SubscriberService();
    }

    /**
     * Get the unique key for this provider
     *
     * @return string
     */
    public function getKey(): string
    {
        return 'stride_contact';
    }

    /**
     * Get the display title for this provider
     *
     * @return string
     */
    public function getTitle(): string
    {
        return __('Stride Contact', 'stride');
    }

    /**
     * Get available SmartCodes with labels
     *
     * @return array<string, string>
     */
    public function getShortCodes(): array
    {
        return [
            'first_name' => __('First Name', 'stride'),
            'last_name' => __('Last Name', 'stride'),
            'full_name' => __('Full Name', 'stride'),
            'phone' => __('Phone', 'stride'),
            'profile_type' => __('Profile Type', 'stride'),
            'is_member' => __('Is Member (yes/no)', 'stride'),
            'invoice_org' => __('Invoice Organization', 'stride'),
            'invoice_address' => __('Invoice Address', 'stride'),
            'invoice_city' => __('Invoice City', 'stride'),
            'invoice_postal' => __('Invoice Postal Code', 'stride'),
            'invoice_email' => __('Invoice Email', 'stride'),
            'vat_number' => __('VAT Number', 'stride'),
            'gln_number' => __('GLN/Peppol Number', 'stride'),
            'department' => __('Department', 'stride'),
            'profile_link' => __('Profile Link', 'stride'),
        ];
    }

    /**
     * Prefetch user data for multiple users
     *
     * PERFORMANCE: Call this before processing batch emails to warm the cache.
     * Reduces N+1 queries to a single batch fetch.
     *
     * @param array $userIds Array of user IDs to prefetch
     */
    public function prefetchUsers(array $userIds): void
    {
        if (empty($userIds)) {
            return;
        }

        // Limit batch size to prevent memory issues
        $userIds = array_slice(array_unique(array_filter($userIds)), 0, 500);

        foreach ($userIds as $userId) {
            $userId = (int) $userId;

            // Skip if already prefetched
            if (isset(self::$prefetchedData[$userId])) {
                continue;
            }

            // Fetch all fields at once
            $data = $this->dataSync->getFields($userId, self::PREFETCH_FIELDS);
            self::$prefetchedData[$userId] = $data;
        }
    }

    /**
     * Clear prefetch cache
     *
     * @param int|null $userId Specific user or null for all
     */
    public function clearPrefetchCache(?int $userId = null): void
    {
        if ($userId === null) {
            self::$prefetchedData = [];
        } else {
            unset(self::$prefetchedData[$userId]);
        }
    }

    /**
     * Get the value for a specific SmartCode
     *
     * SECURITY: Output is escaped by default. Use filter to customize.
     *
     * @param string $valueKey
     * @param mixed $subscriber FluentCRM subscriber object or array
     * @param SmartCodeContextInterface $context
     * @return string|null
     */
    public function getValue(string $valueKey, mixed $subscriber, SmartCodeContextInterface $context): ?string
    {
        $userId = $this->getUserIdFromSubscriber($subscriber);

        if (!$userId) {
            return null;
        }

        $rawValue = match ($valueKey) {
            'first_name' => $this->getField($userId, FieldRegistry::FIELD_FIRST_NAME),
            'last_name' => $this->getField($userId, FieldRegistry::FIELD_LAST_NAME),
            'full_name' => $this->getFullName($userId),
            'phone' => $this->getField($userId, FieldRegistry::FIELD_PHONE),
            'profile_type' => $this->getField($userId, FieldRegistry::SUBSCRIBER_PROFILE_TYPE),
            'is_member' => $this->getIsMember($userId),
            'invoice_org' => $this->getField($userId, FieldRegistry::SUBSCRIBER_INVOICE_ORG_NAME),
            'invoice_address' => $this->getField($userId, FieldRegistry::SUBSCRIBER_INVOICE_ADDRESS),
            'invoice_city' => $this->getField($userId, FieldRegistry::SUBSCRIBER_INVOICE_CITY),
            'invoice_postal' => $this->getField($userId, FieldRegistry::SUBSCRIBER_INVOICE_POSTAL_CODE),
            'invoice_email' => $this->getField($userId, FieldRegistry::SUBSCRIBER_INVOICE_EMAIL),
            'vat_number' => $this->getField($userId, FieldRegistry::SUBSCRIBER_VAT_NUMBER),
            'gln_number' => $this->getField($userId, FieldRegistry::SUBSCRIBER_GLN_NUMBER),
            'department' => $this->getField($userId, FieldRegistry::SUBSCRIBER_DEPARTMENT),
            'profile_link' => $this->getProfileLink($userId),
            default => null,
        };

        // Apply output escaping
        return $this->escapeOutput($rawValue, $valueKey);
    }

    /**
     * Escape output value for safe display
     *
     * SECURITY: Applies esc_html() by default. URLs get esc_url().
     * Use 'stride/smartcode/escape_output' filter to customize.
     *
     * @param string|null $value Raw value
     * @param string $valueKey SmartCode key (for context-specific escaping)
     * @return string|null Escaped value
     */
    private function escapeOutput(?string $value, string $valueKey): ?string
    {
        if ($value === null) {
            return null;
        }

        // Allow filter to bypass or customize escaping
        $escapeEnabled = apply_filters('stride/smartcode/escape_output', true, $valueKey, 'contact');

        if (!$escapeEnabled) {
            return $value;
        }

        // URL fields use esc_url()
        if (in_array($valueKey, ['profile_link'], true)) {
            return esc_url($value);
        }

        // Email fields - keep as-is but validate
        if (in_array($valueKey, ['invoice_email'], true)) {
            return is_email($value) ? $value : esc_html($value);
        }

        // Default: escape for HTML context
        return esc_html($value);
    }

    /**
     * Get user ID from subscriber object/array
     *
     * @param mixed $subscriber
     * @return int|null
     */
    private function getUserIdFromSubscriber(mixed $subscriber): ?int
    {
        if (is_object($subscriber)) {
            // FluentCRM Subscriber model
            $userId = $subscriber->user_id ?? null;

            // Try getWpUserId() method if property not available
            if ($userId === null && method_exists($subscriber, 'getWpUserId')) {
                $userId = $subscriber->getWpUserId();
            }
        } elseif (is_array($subscriber)) {
            $userId = $subscriber['user_id'] ?? null;
        } else {
            return null;
        }

        return $userId ? absint($userId) : null;
    }

    /**
     * Get a field value (from prefetch cache or UserDataSync)
     *
     * PERFORMANCE: Checks prefetch cache first, falls back to UserDataSync.
     *
     * @param int $userId
     * @param string $field
     * @return string|null
     */
    private function getField(int $userId, string $field): ?string
    {
        // Check prefetch cache first
        if (isset(self::$prefetchedData[$userId][$field])) {
            $value = self::$prefetchedData[$userId][$field];
            return $value !== null && $value !== '' ? (string) $value : null;
        }

        // Fall back to direct lookup (and cache for subsequent calls)
        $value = $this->dataSync->getField($userId, $field);

        // Cache for subsequent calls within same request
        if (!isset(self::$prefetchedData[$userId])) {
            self::$prefetchedData[$userId] = [];
        }
        self::$prefetchedData[$userId][$field] = $value;

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    /**
     * Get full name
     *
     * @param int $userId
     * @return string|null
     */
    private function getFullName(int $userId): ?string
    {
        $firstName = $this->getField($userId, FieldRegistry::FIELD_FIRST_NAME) ?? '';
        $lastName = $this->getField($userId, FieldRegistry::FIELD_LAST_NAME) ?? '';

        $fullName = trim($firstName . ' ' . $lastName);

        return $fullName !== '' ? $fullName : null;
    }

    /**
     * Get member status as string
     *
     * @param int $userId
     * @return string
     */
    private function getIsMember(int $userId): string
    {
        $isMember = $this->subscriberService->isMember($userId);

        return $isMember ? __('yes', 'stride') : __('no', 'stride');
    }

    /**
     * Get profile link URL
     *
     * @param int $userId
     * @return string|null
     */
    private function getProfileLink(int $userId): ?string
    {
        // Get profile page from settings or use default
        $profilePageId = get_option('stride_profile_page_id');

        if ($profilePageId) {
            return get_permalink($profilePageId);
        }

        // Fallback to author page
        $user = get_user_by('ID', $userId);
        if ($user) {
            return get_author_posts_url($userId);
        }

        return null;
    }
}
