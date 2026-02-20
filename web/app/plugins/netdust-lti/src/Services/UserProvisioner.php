<?php
declare(strict_types=1);

namespace NetdustLTI\Services;

use NetdustLTI\Domain\LtiClaims;
use WP_User;
use WP_Error;

final class UserProvisioner
{
    private const META_LTI_SUB = '_netdust_lti_sub';
    private const META_LTI_PROVISIONED = '_netdust_lti_provisioned';

    public function provision(LtiClaims $claims): WP_User|WP_Error
    {
        // 1. Look up by LTI sub (most reliable for repeat launches)
        $userId = $this->findByLtiSub($claims->sub);

        // 2. Look up by email
        if (!$userId && $claims->email) {
            $existing = get_user_by('email', $claims->email);
            $userId = $existing?->ID;
        }

        // 3. Create new user with race condition protection
        if (!$userId) {
            // Use transient lock to prevent duplicate creation during concurrent launches
            $lockKey = 'lti_provision_' . md5($claims->email ?? $claims->sub);

            if (get_transient($lockKey)) {
                // Another process is creating this user - wait and retry lookup
                usleep(500000); // 500ms
                $userId = $this->findByLtiSub($claims->sub);
                if (!$userId && $claims->email) {
                    $existing = get_user_by('email', $claims->email);
                    $userId = $existing?->ID;
                }
            }

            if (!$userId) {
                // Set lock before creating user (30 second TTL)
                set_transient($lockKey, true, 30);

                $userId = $this->createUser($claims);

                delete_transient($lockKey);

                if (is_wp_error($userId)) {
                    return $userId;
                }

                // Mark as LTI-provisioned
                update_user_meta($userId, self::META_LTI_PROVISIONED, 1);
            }
        }

        // Store/update LTI sub
        update_user_meta($userId, self::META_LTI_SUB, $claims->sub);

        // Update last LTI login
        update_user_meta($userId, '_netdust_lti_last_login', current_time('mysql'));

        $user = get_user_by('id', $userId);

        if (!$user) {
            return new WP_Error('user_not_found', 'User could not be retrieved');
        }

        return $user;
    }

    private function findByLtiSub(string $sub): ?int
    {
        global $wpdb;

        $userId = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT user_id FROM {$wpdb->usermeta}
                 WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                self::META_LTI_SUB,
                $sub
            )
        );

        return $userId ? (int) $userId : null;
    }

    private function createUser(LtiClaims $claims): int|WP_Error
    {
        // Generate username
        $username = $this->generateUsername($claims);

        // Create user
        $userId = wp_insert_user([
            'user_login' => $username,
            'user_email' => $claims->email ?? $username . '@lti.local',
            'user_pass' => wp_generate_password(24),
            'display_name' => $claims->name ?? $username,
            'first_name' => $claims->givenName ?? '',
            'last_name' => $claims->familyName ?? '',
            'role' => $claims->isInstructor() ? 'instructor' : 'subscriber',
        ]);

        return $userId;
    }

    private function generateUsername(LtiClaims $claims): string
    {
        $base = '';

        if ($claims->email) {
            $base = sanitize_user(explode('@', $claims->email)[0], true);
        } elseif ($claims->name) {
            $base = sanitize_user(str_replace(' ', '_', strtolower($claims->name)), true);
        } else {
            $base = 'lti_user';
        }

        // Ensure unique
        $username = $base;
        $counter = 1;

        while (username_exists($username)) {
            $username = $base . '_' . $counter;
            $counter++;
        }

        return $username;
    }

    public function isLtiUser(int $userId): bool
    {
        return (bool) get_user_meta($userId, self::META_LTI_PROVISIONED, true);
    }
}
