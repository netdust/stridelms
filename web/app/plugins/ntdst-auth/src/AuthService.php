<?php

declare(strict_types=1);

namespace NTDST\Auth;

defined('ABSPATH') || exit;

/**
 * Handles authentication via magic link and optional password.
 *
 * Registers URL routes, processes login/logout, and manages sessions.
 */
final class AuthService implements \NTDST_Service_Meta
{
    private SettingsService $settings;
    private TokenService $tokens;
    private ConsentService $consent;

    public static function metadata(): array
    {
        return [
            'name' => 'Auth Service',
            'description' => 'Magic link and password authentication',
            'priority' => 5,
        ];
    }

    public function __construct()
    {
        $this->settings = ntdst_get(SettingsService::class);
        $this->tokens = ntdst_get(TokenService::class);
        $this->consent = ntdst_get(ConsentService::class);
        $this->init();
    }

    private function init(): void
    {
        add_action('init', [$this, 'registerRoutes']);

        // Redirect wp-login.php if enabled
        if ($this->settings->get('redirect_wp_login', true)) {
            add_action('login_init', [$this, 'redirectWpLogin']);
        }
    }

    /**
     * Register URL routes.
     */
    public function registerRoutes(): void
    {
        $loginUrl = ltrim($this->settings->get('login_url', '/login'), '/');
        $registerUrl = ltrim($this->settings->get('register_url', '/register'), '/');

        // Login page
        ntdst_router()->get($loginUrl, function () {
            if (is_user_logged_in()) {
                wp_redirect($this->getRedirectAfterLogin());
                exit;
            }
            return $this->renderPage('login');
        });

        // Register page
        ntdst_router()->get($registerUrl, function () {
            if (is_user_logged_in()) {
                wp_redirect($this->getRedirectAfterLogin());
                exit;
            }
            if (!$this->settings->get('enable_registration', true)) {
                wp_redirect(home_url($this->settings->get('login_url', '/login')));
                exit;
            }
            return $this->renderPage('register');
        });

        // Magic link verification
        ntdst_router()->get('auth/verify/:token', function (array $params) {
            return $this->handleMagicLinkVerify($params['token']);
        });

        // Activation link
        ntdst_router()->get('auth/activate/:token', function (array $params) {
            return $this->handleActivation($params['token']);
        });

        // Logout
        ntdst_router()->get('auth/logout', function () {
            return $this->handleLogout();
        });
    }

    /**
     * Request magic link for email.
     *
     * @return array{success: bool, message: string}
     */
    public function requestMagicLink(string $email): array
    {
        $email = sanitize_email($email);

        // Always return same message to prevent enumeration
        $successMessage = __('If an account exists with this email, you will receive a login link shortly.', 'ntdst-auth');

        if (!is_email($email)) {
            // Still return success message (no enumeration)
            return ['success' => true, 'message' => $successMessage];
        }

        // Check rate limit
        if ($this->tokens->isRateLimited('magic_email_' . $email) || $this->tokens->isRateLimited('magic_ip_' . $this->getClientIp())) {
            return [
                'success' => false,
                'message' => __('Please wait before requesting another login link.', 'ntdst-auth'),
            ];
        }

        $user = get_user_by('email', $email);

        // Only send if user exists and is activated
        if ($user && $this->consent->isActivated($user->ID)) {
            $token = $this->tokens->createMagicLinkToken($email, $user->ID);

            if ($token) {
                $this->sendMagicLinkEmail($email, $token);
            }
        }

        return ['success' => true, 'message' => $successMessage];
    }

    /**
     * Login with password.
     *
     * @return array{success: bool, message?: string, redirect?: string}|\WP_Error
     */
    public function loginWithPassword(string $email, string $password): array|\WP_Error
    {
        if (!$this->settings->get('enable_password', false)) {
            return new \WP_Error('password_disabled', __('Password login is not enabled.', 'ntdst-auth'));
        }

        // Check rate limit
        if ($this->tokens->isRateLimited('login_ip_' . $this->getClientIp())) {
            return new \WP_Error('rate_limited', __('Too many login attempts. Please try again later.', 'ntdst-auth'));
        }

        $email = sanitize_email($email);
        $user = get_user_by('email', $email);

        // Generic error for security
        $genericError = new \WP_Error('invalid_credentials', __('Invalid email or password.', 'ntdst-auth'));

        if (!$user) {
            return $genericError;
        }

        // Check if activated
        if (!$this->consent->isActivated($user->ID)) {
            return new \WP_Error('not_activated', __('Please activate your account first. Check your email for the activation link.', 'ntdst-auth'));
        }

        // Verify password
        if (!wp_check_password($password, $user->user_pass, $user->ID)) {
            return $genericError;
        }

        // Log user in
        $this->setAuthCookie($user->ID);

        /**
         * Fires on successful login.
         *
         * @param int $userId User ID
         */
        do_action('ntdst_auth_login_success', $user->ID);

        return [
            'success' => true,
            'redirect' => $this->getRedirectAfterLogin(),
        ];
    }

    /**
     * Handle magic link verification.
     */
    private function handleMagicLinkVerify(string $token): void
    {
        $result = $this->tokens->verify($token, 'magic_link');

        if (is_wp_error($result)) {
            $this->renderPage('error', [
                'title' => __('Link Invalid', 'ntdst-auth'),
                'message' => $result->get_error_message(),
                'show_request_new' => true,
            ]);
            exit;
        }

        // Check if user is activated
        if (!$this->consent->isActivated($result['user_id'])) {
            $this->renderPage('error', [
                'title' => __('Account Not Activated', 'ntdst-auth'),
                'message' => __('Please activate your account first.', 'ntdst-auth'),
            ]);
            exit;
        }

        // Log user in
        $this->setAuthCookie($result['user_id']);

        /**
         * Fires on successful login.
         *
         * @param int $userId User ID
         */
        do_action('ntdst_auth_login_success', $result['user_id']);

        // Redirect
        wp_redirect($this->getRedirectAfterLogin());
        exit;
    }

    /**
     * Handle activation link.
     */
    private function handleActivation(string $token): void
    {
        $registration = ntdst_get(RegistrationService::class);
        $result = $registration->activate($token);

        if (is_wp_error($result)) {
            $this->renderPage('error', [
                'title' => __('Activation Failed', 'ntdst-auth'),
                'message' => $result->get_error_message(),
            ]);
            exit;
        }

        // Log user in
        $this->setAuthCookie($result['user_id']);

        // Show success page
        $this->renderPage('activate', [
            'title' => __('Account Activated', 'ntdst-auth'),
            'message' => __('Your account has been activated successfully!', 'ntdst-auth'),
            'redirect' => $this->getRedirectAfterLogin(),
        ]);
        exit;
    }

    /**
     * Handle logout.
     */
    private function handleLogout(): void
    {
        if (is_user_logged_in()) {
            wp_logout();
        }

        $redirectUrl = home_url($this->settings->get('redirect_after_logout', '/login'));
        wp_safe_redirect($redirectUrl);
        exit;
    }

    /**
     * Redirect wp-login.php to custom login.
     */
    public function redirectWpLogin(): void
    {
        // Allow password reset flow
        $action = $_GET['action'] ?? '';
        if (in_array($action, ['lostpassword', 'rp', 'resetpass'], true)) {
            return;
        }

        // Allow logout
        if ($action === 'logout') {
            return;
        }

        $loginUrl = home_url($this->settings->get('login_url', '/login'));
        wp_safe_redirect($loginUrl);
        exit;
    }

    /**
     * Set authentication cookie for user.
     */
    private function setAuthCookie(int $userId): void
    {
        wp_clear_auth_cookie();
        wp_set_current_user($userId);
        wp_set_auth_cookie($userId, true);
    }

    /**
     * Get redirect URL after login.
     */
    private function getRedirectAfterLogin(): string
    {
        // Check for redirect_to parameter
        $redirectTo = $_GET['redirect_to'] ?? '';
        if ($redirectTo) {
            $validated = wp_validate_redirect($redirectTo, home_url('/'));
            if ($validated !== home_url('/') || $redirectTo === home_url('/')) {
                return $validated;
            }
        }

        return home_url($this->settings->get('redirect_after_login', '/'));
    }

    /**
     * Send magic link email.
     */
    private function sendMagicLinkEmail(string $email, string $token): void
    {
        $verifyUrl = home_url('/auth/verify/' . $token);
        $expiry = (int) $this->settings->get('magic_link_expiry', 15);

        ntdst_mail()
            ->to($email)
            ->subject(__('Your login link', 'ntdst-auth'))
            ->template('magic-link', [
                'login_url' => $verifyUrl,
                'expiry_minutes' => $expiry,
                'site_name' => get_bloginfo('name'),
            ])
            ->send();
    }

    /**
     * Render a page template.
     *
     * @param array<string, mixed> $data
     */
    private function renderPage(string $template, array $data = []): void
    {
        // Check for theme override
        $paths = [
            get_stylesheet_directory() . '/ntdst-auth/pages/' . $template . '.php',
            get_template_directory() . '/ntdst-auth/pages/' . $template . '.php',
            NTDST_AUTH_PATH . 'templates/pages/' . $template . '.php',
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                // Extract data to variables
                extract($data);
                $settings = $this->settings->getSettings();

                include $path;
                exit;
            }
        }

        // Fallback
        wp_die(__('Template not found.', 'ntdst-auth'));
    }

    /**
     * Get client IP address.
     */
    private function getClientIp(): string
    {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (str_contains($ip, ',')) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }
}
