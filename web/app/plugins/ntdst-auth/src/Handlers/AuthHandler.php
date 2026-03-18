<?php

declare(strict_types=1);

namespace NTDST\Auth\Handlers;

use NTDST\Auth\AuthService;
use NTDST\Auth\Helpers\Config;
use NTDST\Auth\RegistrationService;

defined('ABSPATH') || exit;

/**
 * AJAX handler for authentication actions.
 *
 * Thin handler - validates input, delegates to services, returns response.
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
            ntdst_response()->error(__('Invalid security token.', 'ntdst-auth'))->json();
        }

        $email = sanitize_email($_POST['email'] ?? '');

        if (empty($email)) {
            ntdst_response()->error(__('Please enter your email address.', 'ntdst-auth'))->json();
        }

        $authService = ntdst_get(AuthService::class);
        $result = $authService->requestMagicLink($email);

        if ($result['success']) {
            ntdst_response()->with('message', $result['message'])->json();
        } else {
            ntdst_response()->error($result['message'])->json();
        }
    }

    /**
     * AJAX: Login with password.
     *
     * Supports two modes:
     * - AJAX (default): returns JSON response
     * - Redirect (_redirect=1): server-side 302 redirect after login
     */
    public function ajaxLoginPassword(): void
    {
        $isRedirect = !empty($_POST['_redirect']);
        $loginUrl = home_url(Config::get('login_url', '/login'));

        if (!$this->verifyNonce('ntdst_auth_login')) {
            $msg = __('Invalid security token. Please try again.', 'ntdst-auth');
            if ($isRedirect) {
                ntdst_response()->error($msg)->redirect($loginUrl);
            }
            ntdst_response()->error($msg)->json();
        }

        $email = sanitize_email($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $msg = __('Please enter both email and password.', 'ntdst-auth');
            if ($isRedirect) {
                ntdst_response()->error($msg)->redirect($loginUrl);
            }
            ntdst_response()->error($msg)->json();
        }

        $authService = ntdst_get(AuthService::class);
        $result = $authService->loginWithPassword($email, $password);

        if (is_wp_error($result)) {
            $msg = $result->get_error_message();
            if ($isRedirect) {
                ntdst_response()->error($msg)->redirect($loginUrl);
            }
            ntdst_response()->error($msg)->json();
        }

        if ($isRedirect) {
            ntdst_redirect($result['redirect']);
        }

        ntdst_response()
            ->with('message', __('Login successful!', 'ntdst-auth'))
            ->with('redirect', $result['redirect'])
            ->json();
    }

    /**
     * AJAX: Register new user.
     */
    public function ajaxRegister(): void
    {
        if (!$this->verifyNonce('ntdst_auth_register')) {
            ntdst_response()->error(__('Invalid security token.', 'ntdst-auth'))->json();
        }

        $data = [
            'email' => sanitize_email($_POST['email'] ?? ''),
            'first_name' => sanitize_text_field($_POST['first_name'] ?? ''),
            'last_name' => sanitize_text_field($_POST['last_name'] ?? ''),
            'profile_type' => sanitize_text_field($_POST['profile_type'] ?? ''),
            'consent_terms' => !empty($_POST['consent_terms']),
            'consent_privacy' => !empty($_POST['consent_privacy']),
        ];

        if (empty($data['email'])) {
            ntdst_response()->error(__('Please enter your email address.', 'ntdst-auth'))->json();
        }

        $registration = ntdst_get(RegistrationService::class);
        $result = $registration->register($data);

        if (is_wp_error($result)) {
            ntdst_response()->error($result->get_error_message())->json();
        }

        ntdst_response()->with('message', $result['message'])->json();
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
