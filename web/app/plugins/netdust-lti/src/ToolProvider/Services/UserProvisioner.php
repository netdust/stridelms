<?php
declare(strict_types=1);

namespace NetdustLTI\ToolProvider\Services;

use NetdustLTI\Shared\Domain\LtiClaims;
use WP_User;
use WP_Error;

final class UserProvisioner
{
    private const META_LTI_SUB = '_netdust_lti_sub';
    private const META_LTI_PROVISIONED = '_netdust_lti_provisioned';

    public function provision(LtiClaims $claims, int $platformId): WP_User|WP_Error
    {
        // 1. Look up by platform-scoped LTI sub
        $scopedSub = $platformId . ':' . $claims->sub;
        $userId = $this->findByLtiSub($scopedSub);

        // 2. Look up by bare sub (backwards compat)
        if (!$userId) {
            $userId = $this->findByLtiSub($claims->sub);
        }

        // 3. Look up by email
        if (!$userId && $claims->email) {
            $existing = get_user_by('email', $claims->email);
            $userId = $existing instanceof WP_User ? $existing->ID : null;
        }

        // 4. Create new user with race condition protection
        if (!$userId) {
            $lockKey = 'lti_provision_' . md5($claims->email ?? $claims->sub);

            if (get_transient($lockKey)) {
                usleep(500000);
                $userId = $this->findByLtiSub($scopedSub);
                if (!$userId && $claims->email) {
                    $existing = get_user_by('email', $claims->email);
                    $userId = $existing instanceof WP_User ? $existing->ID : null;
                }
            }

            if (!$userId) {
                set_transient($lockKey, true, 30);
                $userId = $this->createUser($claims, $platformId);
                delete_transient($lockKey);

                if (is_wp_error($userId)) {
                    return $userId;
                }

                update_user_meta($userId, self::META_LTI_PROVISIONED, 1);
            }
        }

        // Always store/update scoped sub
        update_user_meta($userId, self::META_LTI_SUB, $scopedSub);
        update_user_meta($userId, '_netdust_lti_last_login', current_time('mysql'));

        $user = get_user_by('id', $userId);

        if (!$user) {
            return new WP_Error('user_not_found', 'User could not be retrieved');
        }

        // Ensure user has at least a role
        if (empty($user->roles)) {
            $role = $this->resolveRole($claims, $platformId);
            $user->set_role($role);
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

    private function createUser(LtiClaims $claims, int $platformId): int|WP_Error
    {
        $username = $this->generateUsername($claims);
        $role = $this->resolveRole($claims, $platformId);

        $userData = [
            'user_login' => $username,
            'user_email' => $claims->email ?? $username . '@lti.local',
            'user_pass' => wp_generate_password(24),
            'display_name' => $claims->name ?? $username,
            'first_name' => $claims->givenName ?? '',
            'last_name' => $claims->familyName ?? '',
            'role' => $role,
        ];

        $userData = apply_filters('netdust_lti_provision_user_data', $userData, $claims);

        return wp_insert_user($userData);
    }

    private function generateUsername(LtiClaims $claims): string
    {
        // 1. Try given.family
        if ($claims->givenName && $claims->familyName) {
            $base = sanitize_user(
                strtolower($claims->givenName . '.' . $claims->familyName),
                true
            );
        // 2. Fall back to email prefix
        } elseif ($claims->email) {
            $base = sanitize_user(explode('@', $claims->email)[0], true);
        // 3. Fall back to hash of sub
        } else {
            $base = 'lti_' . substr(md5($claims->sub), 0, 8);
        }

        if (empty($base)) {
            $base = 'lti_' . substr(md5($claims->sub), 0, 8);
        }

        $username = $base;
        $counter = 1;

        while (username_exists($username)) {
            $username = $base . '_' . $counter;
            $counter++;
        }

        return $username;
    }

    private function resolveRole(LtiClaims $claims, int $platformId): string
    {
        $defaultInstructor = 'instructor';
        $defaultLearner = 'subscriber';

        if ($platformId > 0) {
            $model = ntdst_data()->get('lti_platform');
            $instructorRole = $model->getMeta($platformId, 'role_instructor');
            $learnerRole = $model->getMeta($platformId, 'role_learner');

            if ($instructorRole) {
                $defaultInstructor = $instructorRole;
            }
            if ($learnerRole) {
                $defaultLearner = $learnerRole;
            }
        }

        return $claims->isInstructor() ? $defaultInstructor : $defaultLearner;
    }

    public function isLtiUser(int $userId): bool
    {
        return (bool) get_user_meta($userId, self::META_LTI_PROVISIONED, true);
    }
}
