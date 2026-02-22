<?php

declare(strict_types=1);

namespace NTDST\Auth\Handlers;

use NTDST\Auth\AuthService;
use NTDST\Auth\RegistrationService;
use NTDST\Auth\SettingsService;

defined('ABSPATH') || exit;

/**
 * AJAX handler for authentication actions.
 *
 * Thin handler - validates input, delegates to services, returns JSON.
 */
final class AuthHandler implements \NTDST_Service_Meta
{
    public static function metadata(): array
    {
        return [
            'name' => 'Auth Handler',
            'description' => 'AJAX endpoints for authentication',
            'priority' => 6,
        ];
    }

    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        // Public actions (no login required)
        add_action('wp_ajax_nopriv_ntdst_auth_request_magic_link', [$this, 'ajaxRequestMagicLink']);
        add_action('wp_ajax_nopriv_ntdst_auth_login_password', [$this, 'ajaxLoginPassword']);
        add_action('wp_ajax_nopriv_ntdst_auth_register', [$this, 'ajaxRegister']);

        // Logged-in actions
        add_action('wp_ajax_ntdst_auth_request_magic_link', [$this, 'ajaxRequestMagicLink']);
    }

    /**
     * AJAX: Request magic link.
     */
    public function ajaxRequestMagicLink(): void
    {
        if (!$this->verifyNonce('ntdst_auth_login')) {
            wp_send_json_error(['message' => __('Invalid security token.', 'ntdst-auth')]);
        }

        $email = sanitize_email($_POST['email'] ?? '');

        if (empty($email)) {
            wp_send_json_error(['message' => __('Please enter your email address.', 'ntdst-auth')]);
        }

        $authService = ntdst_get(AuthService::class);
        $result = $authService->requestMagicLink($email);

        if ($result['success']) {
            wp_send_json_success(['message' => $result['message']]);
        } else {
            wp_send_json_error(['message' => $result['message']]);
        }
    }

    /**
     * AJAX: Login with password.
     */
    public function ajaxLoginPassword(): void
    {
        if (!$this->verifyNonce('ntdst_auth_login')) {
            wp_send_json_error(['message' => __('Invalid security token.', 'ntdst-auth')]);
        }

        $email = sanitize_email($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            wp_send_json_error(['message' => __('Please enter both email and password.', 'ntdst-auth')]);
        }

        $authService = ntdst_get(AuthService::class);
        $result = $authService->loginWithPassword($email, $password);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => __('Login successful!', 'ntdst-auth'),
            'redirect' => $result['redirect'],
        ]);
    }

    /**
     * AJAX: Register new user.
     */
    public function ajaxRegister(): void
    {
        if (!$this->verifyNonce('ntdst_auth_register')) {
            wp_send_json_error(['message' => __('Invalid security token.', 'ntdst-auth')]);
        }

        $data = [
            'email' => sanitize_email($_POST['email'] ?? ''),
            'first_name' => sanitize_text_field($_POST['first_name'] ?? ''),
            'last_name' => sanitize_text_field($_POST['last_name'] ?? ''),
            'consent_terms' => !empty($_POST['consent_terms']),
            'consent_privacy' => !empty($_POST['consent_privacy']),
        ];

        if (empty($data['email'])) {
            wp_send_json_error(['message' => __('Please enter your email address.', 'ntdst-auth')]);
        }

        $registration = ntdst_get(RegistrationService::class);
        $result = $registration->register($data);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => $result['message']]);
    }

    /**
     * Verify nonce from request.
     */
    private function verifyNonce(string $action): bool
    {
        $nonce = $_POST['nonce'] ?? $_POST['_wpnonce'] ?? '';
        return wp_verify_nonce($nonce, $action) !== false;
    }
}
