<?php

declare(strict_types=1);

namespace Stride\Modules\User;

/**
 * Manages profile types stored in wp_options and user profile type in usermeta.
 *
 * Profile types are admin-defined categories (e.g., "Apotheker", "Arts").
 * Used for content differentiation, pricing rules, and reporting.
 */
class ProfileTypeService implements \NTDST_Service_Meta
{
    private const OPTION_KEY = 'stride_profile_types';
    private const USER_META_KEY = '_stride_profile_type';

    /** @var array<int, array{slug: string, label: string, description: string, color: string, icon: string, order: int}>|null */
    private ?array $cachedTypes = null;

    public static function metadata(): array
    {
        return [
            'name' => 'Profile Type Service',
            'description' => 'Manages user profile types',
            'priority' => 3,
        ];
    }

    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        add_action('ntdst_auth_registration_complete', [$this, 'onRegistrationComplete'], 10, 2);
    }

    /**
     * Get all defined profile types, ordered.
     *
     * @return array<int, array{slug: string, label: string, description: string, color: string, icon: string, order: int}>
     */
    public function getTypes(): array
    {
        if ($this->cachedTypes === null) {
            $raw = get_option(self::OPTION_KEY, []);
            $this->cachedTypes = is_array($raw) ? $raw : [];
        }

        return $this->cachedTypes;
    }

    /**
     * Get a single profile type by slug.
     */
    public function getType(string $slug): ?array
    {
        foreach ($this->getTypes() as $type) {
            if ($type['slug'] === $slug) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Get user's primary profile type (first in stored array).
     * Returns null if no type set or type no longer exists.
     */
    public function getUserType(int $userId): ?array
    {
        $slugs = get_user_meta($userId, self::USER_META_KEY, true);

        if (!is_array($slugs) || empty($slugs)) {
            return null;
        }

        return $this->getType($slugs[0]);
    }

    /**
     * Get all of user's profile types (for future multi-select).
     *
     * @return array<int, array{slug: string, label: string, description: string, color: string, icon: string, order: int}>
     */
    public function getUserTypes(int $userId): array
    {
        $slugs = get_user_meta($userId, self::USER_META_KEY, true);

        if (!is_array($slugs) || empty($slugs)) {
            return [];
        }

        $types = [];
        foreach ($slugs as $slug) {
            $type = $this->getType($slug);
            if ($type !== null) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * Set user's profile type (replaces current value).
     * Returns false if slug is not a known type.
     */
    public function setUserType(int $userId, string $slug): bool
    {
        if ($this->getType($slug) === null) {
            return false;
        }

        update_user_meta($userId, self::USER_META_KEY, [$slug]);

        return true;
    }

    /**
     * Check if user has a specific profile type.
     */
    public function userHasType(int $userId, string $slug): bool
    {
        $slugs = get_user_meta($userId, self::USER_META_KEY, true);

        return is_array($slugs) && in_array($slug, $slugs, true);
    }

    /**
     * Count users with a specific profile type.
     * Uses direct DB query for performance.
     */
    public function countUsersWithType(string $slug): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta}
             WHERE meta_key = %s AND meta_value LIKE %s",
            self::USER_META_KEY,
            '%"' . $wpdb->esc_like($slug) . '"%',
        ));
    }

    /**
     * Hook: set profile type after registration.
     */
    public function onRegistrationComplete(int $userId, array $data): void
    {
        $slug = sanitize_text_field($data['profile_type'] ?? '');

        if (!empty($slug)) {
            $this->setUserType($userId, $slug);
        }
    }
}
