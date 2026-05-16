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
            'stride_postmeta',
            'meta_value',
            ['meta_key' => '_ntdst_edition_id']
        );

        $counts = array_count_values(array_map('intval', $rows));
        arsort($counts);

        foreach ($counts as $editionId => $count) {
            if ($count >= $minSessions) {
                // Verify the edition is published
                $exists = (int) $db->grabFromDatabase('stride_posts', 'ID', [
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
    public function activateUserById(int $userId, string $redirect = '/login/'): void
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
