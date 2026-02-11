<?php

namespace stride\services\sync;

defined('ABSPATH') || exit;

use stride\services\FieldRegistry;

/**
 * User Data Sync Hooks
 *
 * Automatically synchronizes user data when updates occur in any backend.
 * Listens to WordPress profile updates and FluentCRM subscriber changes.
 *
 * @package stride
 */
class UserDataSyncHooks implements \NTDST_Service_Meta
{
    private UserDataSync $sync;

    /**
     * Fields to auto-sync on WordPress profile update
     */
    private array $wpSyncFields = [
        FieldRegistry::FIELD_FIRST_NAME,
        FieldRegistry::FIELD_LAST_NAME,
        FieldRegistry::FIELD_EMAIL,
    ];

    /**
     * Service metadata for NTDST Bootstrap
     */
    public static function metadata(): array
    {
        return [
            'name' => 'User Data Sync Hooks',
            'description' => 'Auto-sync hooks for user data changes',
            'admin_only' => false,
            'enabled' => true,
            'priority' => 15,
        ];
    }

    public function __construct(?UserDataSync $sync = null)
    {
        $this->sync = $sync ?? new UserDataSync();
        $this->init();
    }

    private function init(): void
    {
        add_action('init', [$this, 'registerHooks'], 25);
    }

    /**
     * Register all sync hooks
     */
    public function registerHooks(): void
    {
        // WordPress profile updates
        add_action('profile_update', [$this, 'onWordPressProfileUpdate'], 10, 2);
        add_action('user_register', [$this, 'onUserRegistration'], 10, 1);

        // FluentCRM hooks (if available)
        if (defined('FLUENTCRM')) {
            add_action('fluentcrm_subscriber_created', [$this, 'onSubscriberCreated'], 10, 1);
            add_action('fluentcrm_subscriber_updated', [$this, 'onSubscriberUpdated'], 10, 1);
            add_action('fluent_crm/subscriber_custom_data_updated', [$this, 'onSubscriberCustomUpdated'], 10, 2);
        }

        do_action('stride/user_data_sync_hooks_ready', $this);
    }

    /**
     * Handle WordPress profile update
     */
    public function onWordPressProfileUpdate(int $userId, ?\WP_User $oldUserData = null): void
    {
        // Prevent infinite loops
        if ($this->isProcessing($userId)) {
            return;
        }
        $this->setProcessing($userId, true);

        try {
            $user = get_userdata($userId);
            if (!$user) {
                return;
            }

            $data = [];
            foreach ($this->wpSyncFields as $field) {
                $value = match ($field) {
                    FieldRegistry::FIELD_EMAIL => $user->user_email,
                    FieldRegistry::FIELD_FIRST_NAME => $user->first_name,
                    FieldRegistry::FIELD_LAST_NAME => $user->last_name,
                    default => $user->$field ?? null,
                };

                if ($value !== null) {
                    $data[$field] = $value;
                }
            }

            if (!empty($data)) {
                $this->sync->setFields($userId, $data);
            }
        } finally {
            $this->setProcessing($userId, false);
        }
    }

    /**
     * Handle new user registration
     */
    public function onUserRegistration(int $userId): void
    {
        // Small delay to ensure user data is fully saved
        $this->onWordPressProfileUpdate($userId);

        do_action('stride/user_registered_synced', $userId);
    }

    /**
     * Handle FluentCRM subscriber creation
     *
     * @param object $subscriber FluentCRM Subscriber model
     */
    public function onSubscriberCreated($subscriber): void
    {
        $userId = $subscriber->user_id ?? null;

        if (!$userId) {
            return;
        }

        // Clear cache to ensure fresh data
        $this->sync->clearCache($userId);

        do_action('stride/subscriber_created_synced', $userId, $subscriber);
    }

    /**
     * Handle FluentCRM subscriber update
     *
     * @param object $subscriber FluentCRM Subscriber model
     */
    public function onSubscriberUpdated($subscriber): void
    {
        $userId = $subscriber->user_id ?? null;

        if (!$userId || $this->isProcessing($userId)) {
            return;
        }
        $this->setProcessing($userId, true);

        try {
            // Sync from FluentCRM to WordPress
            $data = [
                FieldRegistry::FIELD_FIRST_NAME => $subscriber->first_name,
                FieldRegistry::FIELD_LAST_NAME => $subscriber->last_name,
                FieldRegistry::FIELD_PHONE => $subscriber->phone,
            ];

            // Only update WordPress (not back to FluentCRM to avoid loop)
            $wpBackend = $this->sync->getBackend('wordpress');
            if ($wpBackend && $wpBackend->isAvailable()) {
                $wpBackend->setFields($userId, array_filter($data));
            }
        } finally {
            $this->setProcessing($userId, false);
        }
    }

    /**
     * Handle FluentCRM custom field update
     *
     * @param object $subscriber
     * @param array $customData
     */
    public function onSubscriberCustomUpdated($subscriber, array $customData): void
    {
        // Custom fields are CRM-specific, no need to sync to WordPress
        // Just clear cache to ensure fresh reads
        $userId = $subscriber->user_id ?? null;

        if ($userId) {
            $this->sync->clearCache($userId);
        }
    }

    /**
     * Prevent infinite sync loops
     */
    private static array $processing = [];

    private function isProcessing(int $userId): bool
    {
        return self::$processing[$userId] ?? false;
    }

    private function setProcessing(int $userId, bool $value): void
    {
        self::$processing[$userId] = $value;
    }

    /**
     * Get the sync service
     */
    public function getSyncService(): UserDataSync
    {
        return $this->sync;
    }
}
