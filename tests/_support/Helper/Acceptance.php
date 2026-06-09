<?php

declare(strict_types=1);

namespace Tests\Support\Helper;

use Codeception\Module;

/**
 * Acceptance Test Helper
 *
 * Provides custom methods for acceptance testing, including login
 * workarounds for custom auth systems.
 */
class Acceptance extends Module
{
    /**
     * Shared secret for test login - must match test-login-helper.php
     */
    private const TEST_LOGIN_SECRET = 'stride_codeception_test_secret_2024';

    /**
     * Login a user by ID using the test login helper.
     *
     * This bypasses the login page entirely by using a test-only
     * endpoint that sets the WordPress auth cookie directly.
     *
     * Requires the test-login-helper.php mu-plugin to be active.
     *
     * @param int $userId The user ID to log in as
     * @param string $redirect Optional URL to redirect to after login
     */
    public function loginAsUserId(int $userId, string $redirect = '/'): void
    {
        $webDriver = $this->getModule('WPWebDriver');

        // Generate the test key using shared secret
        $testKey = md5('stride_test_' . $userId . '_' . self::TEST_LOGIN_SECRET);

        // Build the test login URL
        $testLoginUrl = '/?stride_test_login=1&user_id=' . $userId . '&test_key=' . $testKey;

        if ($redirect) {
            $testLoginUrl .= '&redirect=' . urlencode($redirect);
        }

        codecept_debug("Test login for user ID: " . $userId);

        // Visit the test login URL - this will set the auth cookie and redirect
        $webDriver->amOnPage($testLoginUrl);
    }

    /**
     * Find a published vad_edition that has at least N sessions linked via
     * `_ntdst_edition_id` postmeta. Returns 0 if none found.
     *
     * Avoids hardcoded edition IDs in tests — seed data changes and IDs drift.
     */
    public function grabEditionWithMinSessions(int $minSessions = 2): int
    {
        $db = $this->getModule('WPDb');
        $rows = $db->grabColumnFromDatabase(
            $db->grabPrefixedTableNameFor('postmeta'),
            'meta_value',
            ['meta_key' => '_ntdst_edition_id']
        );

        $counts = array_count_values(array_map('intval', $rows));
        arsort($counts);

        foreach ($counts as $editionId => $count) {
            if ($count >= $minSessions) {
                // Verify the edition is published
                $exists = (int) $db->grabFromDatabase($db->grabPrefixedTableNameFor('posts'), 'ID', [
                    'ID'          => $editionId,
                    'post_type'   => 'vad_edition',
                    'post_status' => 'publish',
                ]);
                if ($exists) {
                    return $exists;
                }
            }
        }

        return 0;
    }

    /**
     * Find a published vad_edition that has at least one registration whose
     * user still exists. Seed data can contain orphaned registrations
     * (e.g. user_id 2220323 → no matching wp_users row) — those would hit
     * the user_unavailable guard in RegistrationModalController and break
     * tests that don't care about that branch.
     */
    public function grabEditionWithRegistrations(): int
    {
        $db = $this->getModule('WPDb');

        $userIds = array_flip(array_map('intval', $db->grabColumnFromDatabase($db->grabPrefixedTableNameFor('users'), 'ID')));

        // Load (edition_id, user_id) pairs together so we can correlate per-row.
        $regs = $db->grabAllFromDatabase($db->grabPrefixedTableNameFor('vad_registrations'), 'edition_id, user_id', []);

        $counts = [];
        foreach ($regs as $r) {
            $userId = (int) $r['user_id'];
            $editionId = (int) $r['edition_id'];
            if (isset($userIds[$userId])) {
                $counts[$editionId] = ($counts[$editionId] ?? 0) + 1;
            }
        }
        arsort($counts);

        foreach (array_keys($counts) as $editionId) {
            $exists = (int) $db->grabFromDatabase($db->grabPrefixedTableNameFor('posts'), 'ID', [
                'ID'          => $editionId,
                'post_type'   => 'vad_edition',
                'post_status' => 'publish',
            ]);
            if ($exists) {
                return $exists;
            }
        }

        return 0;
    }

    /**
     * Resolve a usable administrator account without assuming a login name.
     *
     * Prefers the seeded `seed_admin` (scripts/seed.php); falls back to the
     * lowest-ID user holding the administrator capability. Returns 0 when the
     * database has no administrator at all.
     */
    public function grabAdminUserId(): int
    {
        $db = $this->getModule('WPDb');

        $seedAdmin = $db->grabFromDatabase(
            $db->grabPrefixedTableNameFor('users'),
            'ID',
            ['user_login' => 'seed_admin']
        );
        if ((int) $seedAdmin > 0) {
            return (int) $seedAdmin;
        }

        $userId = $db->grabFromDatabase(
            $db->grabPrefixedTableNameFor('usermeta'),
            'user_id',
            [
                'meta_key' => $db->grabTablePrefix() . 'capabilities',
                'meta_value like' => '%administrator%',
            ]
        );

        return (int) $userId;
    }

    /**
     * Activate a user account using the test activation helper.
     *
     * This bypasses email verification by directly setting the activation
     * user meta via a test-only endpoint.
     *
     * Requires the test-login-helper.php mu-plugin to be active.
     *
     * @param int $userId The user ID to activate
     * @param string $redirect Optional URL to redirect to after activation
     */
    public function activateUserById(int $userId, string $redirect = '/aanmelden/'): void
    {
        $webDriver = $this->getModule('WPWebDriver');

        // Generate the test key using shared secret
        $testKey = md5('stride_test_activate_' . $userId . '_' . self::TEST_LOGIN_SECRET);

        // Build the test activation URL
        $testActivateUrl = '/?stride_test_activate=1&user_id=' . $userId . '&test_key=' . $testKey;

        if ($redirect) {
            $testActivateUrl .= '&redirect=' . urlencode($redirect);
        }

        codecept_debug("Test activation for user ID: " . $userId);

        // Visit the test activation URL
        $webDriver->amOnPage($testActivateUrl);
    }
}
