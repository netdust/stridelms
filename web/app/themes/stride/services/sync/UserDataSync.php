<?php

namespace stride\services\sync;

defined('ABSPATH') || exit;

use stride\services\contracts\StorageBackendInterface;
use WP_Error;

/**
 * User Data Sync Service
 *
 * Orchestrates user data across multiple storage backends.
 * Ensures data stays in sync between WordPress, FluentCRM, and future systems.
 *
 * Read strategy: Check backends in priority order, return first non-null value
 * Write strategy: Write-through to ALL backends
 *
 * SECURITY - AUTHORIZATION RESPONSIBILITY:
 *
 * This service does NOT perform authorization checks internally. It is a
 * low-level data synchronization service used by higher-level services
 * (SmartCode providers, profile services, admin tools) that ARE responsible
 * for authorization.
 *
 * CALLER RESPONSIBILITIES:
 * 1. Verify the current user has permission to access the target user's data
 * 2. For admin operations: check current_user_can('edit_users')
 * 3. For user self-service: verify $userId === get_current_user_id()
 * 4. For automation/system: ensure context is trusted (FluentCRM automation, cron)
 *
 * Example usage with authorization:
 * ```php
 * // Admin editing another user
 * if (!current_user_can('edit_users')) {
 *     return new WP_Error('forbidden', 'Permission denied');
 * }
 * $dataSync->setField($userId, 'field', $value);
 *
 * // User self-service
 * if ($userId !== get_current_user_id()) {
 *     return new WP_Error('forbidden', 'Can only edit own profile');
 * }
 * $dataSync->setField($userId, 'field', $value);
 * ```
 *
 * For convenience, use setFieldsWithAuth() and getFieldWithAuth() methods
 * which perform standard capability checks.
 *
 * Available filters:
 * - stride/user_data_sync/backends - Modify registered backends
 * - stride/user_data_sync/before_write - Intercept/modify data before write
 * - stride/user_data_sync/after_write - Action after successful write
 * - stride/user_data_sync/can_read_user - Custom authorization for reads
 * - stride/user_data_sync/can_write_user - Custom authorization for writes
 *
 * @package stride
 */
class UserDataSync implements \NTDST_Service_Meta
{
    /**
     * @var StorageBackendInterface[]
     */
    private array $backends = [];

    /**
     * Backends sorted by priority (cached)
     * @var StorageBackendInterface[]|null
     */
    private ?array $sortedBackends = null;

    /**
     * Service metadata for NTDST Bootstrap
     */
    public static function metadata(): array
    {
        return [
            'name' => 'User Data Sync',
            'description' => 'Synchronizes user data across storage backends',
            'admin_only' => false,
            'enabled' => true,
            'priority' => 5, // Load early
        ];
    }

    /**
     * Constructor
     *
     * @param StorageBackendInterface[] $backends Optional backends to register
     */
    public function __construct(array $backends = [])
    {
        // Register default backends if none provided
        if (empty($backends)) {
            $this->registerBackend(new FluentCRMStorage());
            $this->registerBackend(new WordPressUserStorage());
        } else {
            foreach ($backends as $backend) {
                $this->registerBackend($backend);
            }
        }

        $this->init();
    }

    /**
     * Initialize hooks
     */
    private function init(): void
    {
        add_action('init', [$this, 'registerHooks'], 20);
    }

    /**
     * Register WordPress hooks for auto-sync
     */
    public function registerHooks(): void
    {
        // Allow modification of backends
        $this->backends = apply_filters('stride/user_data_sync/backends', $this->backends);
        $this->sortedBackends = null; // Reset sorted cache

        do_action('stride/user_data_sync_ready', $this);
    }

    /**
     * Register a storage backend
     */
    public function registerBackend(StorageBackendInterface $backend): self
    {
        $this->backends[$backend->getId()] = $backend;
        $this->sortedBackends = null; // Reset sorted cache
        return $this;
    }

    /**
     * Get a specific backend
     */
    public function getBackend(string $id): ?StorageBackendInterface
    {
        return $this->backends[$id] ?? null;
    }

    /**
     * Get backends sorted by priority (highest first)
     *
     * @return StorageBackendInterface[]
     */
    private function getSortedBackends(): array
    {
        if ($this->sortedBackends === null) {
            $this->sortedBackends = $this->backends;
            usort($this->sortedBackends, fn($a, $b) => $b->getPriority() - $a->getPriority());
        }
        return $this->sortedBackends;
    }

    // ========================================
    // AUTHORIZATION HELPERS
    // ========================================

    /**
     * Check if current user can access another user's data
     *
     * Authorization rules:
     * 1. User can always access their own data
     * 2. Admins (edit_users capability) can access any user
     * 3. Custom filter for application-specific rules
     *
     * @param int $userId Target user ID
     * @param string $operation 'read' or 'write'
     * @return bool
     */
    public function canAccessUser(int $userId, string $operation = 'read'): bool
    {
        // Users can access their own data
        $currentUserId = get_current_user_id();
        if ($currentUserId > 0 && $currentUserId === $userId) {
            return true;
        }

        // Admins can access any user
        if (current_user_can('edit_users')) {
            return true;
        }

        // Allow filter for custom authorization (managed accounts, team access, etc.)
        $filterHook = $operation === 'write'
            ? 'stride/user_data_sync/can_write_user'
            : 'stride/user_data_sync/can_read_user';

        return apply_filters($filterHook, false, $userId, $currentUserId);
    }

    /**
     * Get a field value with authorization check
     *
     * SECURITY: Use this method for user-facing operations.
     * Returns WP_Error if current user cannot access target user's data.
     *
     * @param int $userId
     * @param string $field
     * @return mixed|WP_Error Field value or error
     */
    public function getFieldWithAuth(int $userId, string $field): mixed
    {
        if (!$this->canAccessUser($userId, 'read')) {
            return new WP_Error(
                'unauthorized',
                __('You do not have permission to access this user\'s data.', 'stride'),
                ['status' => 403]
            );
        }

        return $this->getField($userId, $field);
    }

    /**
     * Get multiple fields with authorization check
     *
     * SECURITY: Use this method for user-facing operations.
     * Returns WP_Error if current user cannot access target user's data.
     *
     * @param int $userId
     * @param array $fields Specific fields or empty for all
     * @return array|WP_Error Field values or error
     */
    public function getFieldsWithAuth(int $userId, array $fields = []): array|WP_Error
    {
        if (!$this->canAccessUser($userId, 'read')) {
            return new WP_Error(
                'unauthorized',
                __('You do not have permission to access this user\'s data.', 'stride'),
                ['status' => 403]
            );
        }

        return $this->getFields($userId, $fields);
    }

    /**
     * Set fields with authorization check
     *
     * SECURITY: Use this method for user-facing operations.
     * Returns WP_Error if current user cannot modify target user's data.
     *
     * @param int $userId
     * @param array $data Field => value map
     * @return true|WP_Error
     */
    public function setFieldsWithAuth(int $userId, array $data): true|WP_Error
    {
        if (!$this->canAccessUser($userId, 'write')) {
            return new WP_Error(
                'unauthorized',
                __('You do not have permission to modify this user\'s data.', 'stride'),
                ['status' => 403]
            );
        }

        return $this->setFields($userId, $data);
    }

    /**
     * Set a single field with authorization check
     *
     * SECURITY: Use this method for user-facing operations.
     * Returns WP_Error if current user cannot modify target user's data.
     *
     * @param int $userId
     * @param string $field
     * @param mixed $value
     * @return true|WP_Error
     */
    public function setFieldWithAuth(int $userId, string $field, mixed $value): true|WP_Error
    {
        return $this->setFieldsWithAuth($userId, [$field => $value]);
    }

    // ========================================
    // READ OPERATIONS
    // ========================================

    /**
     * Get a single field value
     * Checks backends in priority order, returns first non-null value
     *
     * SECURITY: This method does NOT check authorization.
     * Use getFieldWithAuth() for user-facing operations, or verify
     * authorization before calling this method.
     *
     * @param int $userId
     * @param string $field
     * @return mixed
     */
    public function getField(int $userId, string $field): mixed
    {
        foreach ($this->getSortedBackends() as $backend) {
            if (!$backend->isAvailable()) {
                continue;
            }

            $value = $backend->getField($userId, $field);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Get multiple fields
     * Merges from all backends, higher priority wins
     *
     * SECURITY: This method does NOT check authorization.
     * Use getFieldsWithAuth() for user-facing operations, or verify
     * authorization before calling this method.
     *
     * @param int $userId
     * @param array $fields Specific fields or empty for all
     * @return array
     */
    public function getFields(int $userId, array $fields = []): array
    {
        $result = [];

        // Go through backends in reverse priority order
        // so higher priority backends overwrite lower ones
        $backends = array_reverse($this->getSortedBackends());

        foreach ($backends as $backend) {
            if (!$backend->isAvailable()) {
                continue;
            }

            $data = $backend->getFields($userId, $fields);
            $result = array_merge($result, $data);
        }

        return $result;
    }

    /**
     * Get all user data from all backends
     *
     * SECURITY: This method does NOT check authorization.
     * Verify authorization before calling this method.
     *
     * @param int $userId
     * @return array
     */
    public function getAllData(int $userId): array
    {
        return $this->getFields($userId);
    }

    // ========================================
    // WRITE OPERATIONS
    // ========================================

    /**
     * Set a single field value
     * Writes to ALL available backends (write-through)
     *
     * SECURITY: This method does NOT check authorization.
     * Use setFieldWithAuth() for user-facing operations, or verify
     * authorization before calling this method.
     *
     * @param int $userId
     * @param string $field
     * @param mixed $value
     * @return true|WP_Error
     */
    public function setField(int $userId, string $field, mixed $value): true|WP_Error
    {
        return $this->setFields($userId, [$field => $value]);
    }

    /**
     * Set multiple field values
     * Writes to ALL available backends (write-through)
     *
     * SECURITY: This method does NOT check authorization.
     * Use setFieldsWithAuth() for user-facing operations, or verify
     * authorization before calling this method.
     *
     * @param int $userId
     * @param array $data Field => value map
     * @return true|WP_Error
     */
    public function setFields(int $userId, array $data): true|WP_Error
    {
        // Allow interception
        $data = apply_filters('stride/user_data_sync/before_write', $data, $userId);

        if (empty($data)) {
            return true;
        }

        $errors = [];

        foreach ($this->getSortedBackends() as $backend) {
            if (!$backend->isAvailable()) {
                continue;
            }

            // Filter data to only fields this backend handles
            $backendData = array_filter(
                $data,
                fn($field) => $backend->hasField($field),
                ARRAY_FILTER_USE_KEY
            );

            if (empty($backendData)) {
                continue;
            }

            if (!$backend->setFields($userId, $backendData)) {
                $errors[] = $backend->getId();
            }
        }

        if (!empty($errors)) {
            return new WP_Error(
                'sync_partial_failure',
                sprintf('Failed to sync to: %s', implode(', ', $errors)),
                ['backends' => $errors]
            );
        }

        do_action('stride/user_data_sync/after_write', $userId, $data);

        return true;
    }

    /**
     * Update user profile (alias for setFields)
     *
     * SECURITY: This method does NOT check authorization.
     * Verify authorization before calling this method.
     *
     * @param int $userId
     * @param array $data
     * @return true|WP_Error
     */
    public function updateProfile(int $userId, array $data): true|WP_Error
    {
        return $this->setFields($userId, $data);
    }

    // ========================================
    // SYNC OPERATIONS
    // ========================================

    /**
     * Sync data from one user to another
     * Useful for managed accounts / team enrollments
     *
     * SECURITY: This method requires admin privileges.
     * Syncing data between users should only be done by administrators
     * or during trusted system operations (like team enrollment).
     *
     * @param int $targetUserId User to receive data
     * @param int $sourceUserId User to copy from
     * @param array $fields Specific fields to sync (empty = all)
     * @return true|WP_Error
     */
    public function syncFromUser(int $targetUserId, int $sourceUserId, array $fields = []): true|WP_Error
    {
        $sourceData = $this->getFields($sourceUserId, $fields);

        if (empty($sourceData)) {
            return new WP_Error('no_source_data', 'No data found for source user');
        }

        return $this->setFields($targetUserId, $sourceData);
    }

    /**
     * Force sync between all backends for a user
     * Reads from highest priority, writes to all others
     *
     * SECURITY: This method should only be called by administrators
     * or during trusted system operations.
     *
     * @param int $userId
     * @param array $fields Specific fields or empty for all
     * @return true|WP_Error
     */
    public function resync(int $userId, array $fields = []): true|WP_Error
    {
        // Clear all caches first
        $this->clearCache($userId);

        // Get data from highest priority backend
        $backends = $this->getSortedBackends();
        $primaryBackend = $backends[0] ?? null;

        if (!$primaryBackend || !$primaryBackend->isAvailable()) {
            return new WP_Error('no_primary_backend', 'Primary storage backend not available');
        }

        $data = $primaryBackend->getFields($userId, $fields);

        if (empty($data)) {
            return true; // Nothing to sync
        }

        // Write to all other backends
        $errors = [];
        foreach (array_slice($backends, 1) as $backend) {
            if (!$backend->isAvailable()) {
                continue;
            }

            $backendData = array_filter(
                $data,
                fn($field) => $backend->hasField($field),
                ARRAY_FILTER_USE_KEY
            );

            if (!empty($backendData) && !$backend->setFields($userId, $backendData)) {
                $errors[] = $backend->getId();
            }
        }

        if (!empty($errors)) {
            return new WP_Error(
                'resync_partial_failure',
                sprintf('Failed to resync to: %s', implode(', ', $errors)),
                ['backends' => $errors]
            );
        }

        return true;
    }

    // ========================================
    // USER MANAGEMENT
    // ========================================

    /**
     * Find or create a WordPress user
     * Also ensures FluentCRM subscriber exists
     *
     * SECURITY: Creating users is a privileged operation.
     * This method should only be called by:
     * - Administrators (enrollment management)
     * - Trusted system processes (form submission handlers, automations)
     * - Self-registration flows with proper validation
     *
     * @param string $email
     * @param string $firstName
     * @param string $lastName
     * @param array $extraData Additional profile data
     * @return int|WP_Error User ID or error
     */
    public function findOrCreateUser(
        string $email,
        string $firstName = '',
        string $lastName = '',
        array $extraData = []
    ): int|WP_Error {
        $email = sanitize_email($email);

        if (!is_email($email)) {
            return new WP_Error('invalid_email', 'Invalid email address');
        }

        // Check for existing user
        $user = get_user_by('email', $email);

        if ($user) {
            $userId = $user->ID;
        } else {
            // Create new user
            $password = wp_generate_password(12, false);
            $userId = wp_create_user($email, $password, $email);

            if (is_wp_error($userId)) {
                return $userId;
            }

            // Set basic info
            wp_update_user([
                'ID' => $userId,
                'first_name' => sanitize_text_field($firstName),
                'last_name' => sanitize_text_field($lastName),
                'display_name' => trim($firstName . ' ' . $lastName) ?: $email,
            ]);

            do_action('stride/user_created', $userId, $email);
        }

        // Ensure data is synced to all backends
        $data = array_merge([
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ], $extraData);

        $this->setFields($userId, array_filter($data));

        return $userId;
    }

    /**
     * Mark a user as managed by current user
     * Used when an admin enrolls someone else
     *
     * SECURITY: This method should only be called by administrators
     * or during the enrollment flow when an admin enrolls on behalf
     * of another person. The calling code must verify admin privileges.
     *
     * @param int $userId The managed user
     * @param string|null $managerEmail Manager's email (null = current user)
     * @return true|WP_Error
     */
    public function markAsManagedUser(int $userId, ?string $managerEmail = null): true|WP_Error
    {
        if ($managerEmail === null) {
            $currentUser = wp_get_current_user();
            $managerEmail = $currentUser->user_email ?? '';
        }

        if (empty($managerEmail)) {
            return new WP_Error('no_manager', 'No manager email provided');
        }

        // Store managed_by field
        $result = $this->setField($userId, 'managed_by', $managerEmail);

        if (is_wp_error($result)) {
            return $result;
        }

        // Add managed tag in FluentCRM if available
        $fluentcrm = $this->getBackend('fluentcrm');
        if ($fluentcrm && $fluentcrm->isAvailable()) {
            $adapter = new \stride\services\adapters\FluentCRMAdapter();
            $subscriber = $adapter->getSubscriberByUserId($userId);

            if ($subscriber) {
                $adapter->addTag($subscriber['id'], 'Managed');
                $adapter->createNote(
                    $subscriber['id'],
                    sprintf('Account created/updated during enrollment by: %s', $managerEmail)
                );
            }
        }

        do_action('stride/user_marked_as_managed', $userId, $managerEmail);

        return true;
    }

    // ========================================
    // CACHE MANAGEMENT
    // ========================================

    /**
     * Clear cache for user across all backends
     *
     * @param int|null $userId Specific user or null for all
     */
    public function clearCache(?int $userId = null): void
    {
        foreach ($this->backends as $backend) {
            $backend->clearCache($userId);
        }
    }
}
