<?php
/**
 * Test Login Helper
 *
 * Backdoor login/activation endpoints for the acceptance suite
 * (tests/_support/Helper/Acceptance.php is the only intended caller).
 *
 * Inert unless ALL of these hold:
 *  1. WP_ENV is not 'production'
 *  2. a test environment is detected (CODECEPTION_TEST or DDEV)
 *  3. STRIDE_TEST_LOGIN_SECRET is provided via the environment (.env) —
 *     there is NO hardcoded fallback; without the secret the file does nothing.
 *
 * @package Stride\Test
 */

if (!defined('ABSPATH')) {
    exit;
}

// Hard production gate — regardless of any other signal.
if (defined('WP_ENV') && WP_ENV === 'production') {
    return;
}

// Only in a recognised test environment.
$isTestEnv = (
    getenv('CODECEPTION_TEST') === 'true' ||
    defined('CODECEPTION_TEST') ||
    getenv('DDEV_PROJECT') === 'stride'
);
if (!$isTestEnv) {
    return;
}

// Secret comes from the environment only (Bedrock loads .env into $_ENV).
$strideTestLoginSecret = $_ENV['STRIDE_TEST_LOGIN_SECRET'] ?? getenv('STRIDE_TEST_LOGIN_SECRET') ?: '';
if (!is_string($strideTestLoginSecret) || $strideTestLoginSecret === '') {
    return;
}

define('STRIDE_TEST_LOGIN_SECRET', $strideTestLoginSecret);

add_action('init', function () {
    // Handle test login request
    if (
        isset($_GET['stride_test_login']) &&
        isset($_GET['user_id']) &&
        isset($_GET['test_key'])
    ) {
        $userId = absint($_GET['user_id']);
        $testKey = sanitize_text_field($_GET['test_key']);
        $expectedKey = hash_hmac('sha256', 'login:' . $userId, STRIDE_TEST_LOGIN_SECRET);

        if ($userId > 0 && hash_equals($expectedKey, $testKey)) {
            // Verify user exists
            $user = get_user_by('id', $userId);
            if (!$user) {
                wp_die('Test login failed: User not found');
            }

            // Clear any existing login
            if (is_user_logged_in()) {
                wp_logout();
            }

            // Set auth cookie for the user
            wp_set_current_user($userId);
            wp_set_auth_cookie($userId, true);

            // Redirect to specified URL or home
            $redirect = isset($_GET['redirect']) ? esc_url_raw($_GET['redirect']) : home_url('/');
            wp_safe_redirect($redirect);
            exit;
        }

        wp_die('Test login failed: Invalid test key');
    }

    // Handle test activation request (activates user without email verification)
    if (
        isset($_GET['stride_test_activate']) &&
        isset($_GET['user_id']) &&
        isset($_GET['test_key'])
    ) {
        $userId = absint($_GET['user_id']);
        $testKey = sanitize_text_field($_GET['test_key']);
        $expectedKey = hash_hmac('sha256', 'activate:' . $userId, STRIDE_TEST_LOGIN_SECRET);

        if ($userId > 0 && hash_equals($expectedKey, $testKey)) {
            // Activate the user directly
            update_user_meta($userId, 'ntdst_auth_activated', true);
            update_user_meta($userId, 'ntdst_auth_activated_at', time());

            // Return JSON response for AJAX calls
            if (wp_doing_ajax() || isset($_GET['json'])) {
                wp_send_json_success(['user_id' => $userId, 'activated' => true]);
            }

            // Redirect to login or specified URL
            $redirect = isset($_GET['redirect']) ? esc_url_raw($_GET['redirect']) : home_url('/login/');
            wp_safe_redirect($redirect);
            exit;
        }

        wp_die('Test activation failed: Invalid test key');
    }
}, 1);
