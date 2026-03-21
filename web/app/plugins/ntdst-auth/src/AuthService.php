<?php

declare(strict_types=1);

namespace NTDST\Auth;

use NTDST\Auth\Helpers\Config;
use NTDST\Auth\Helpers\TokenHelper;
use NTDST\Auth\Helpers\ConsentHelper;

defined('ABSPATH') || exit;

/**
 * Main authentication service.
 *
 * Handles magic link and password authentication, URL routes,
 * admin settings, and privacy tools integration.
 */
final class AuthService implements \NTDST_Service_Meta
{
    private TokenHelper $tokens;
    private ConsentHelper $consent;

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
        $config = Config::all();
        $this->tokens = new TokenHelper($config);
        $this->consent = new ConsentHelper($config);
        $this->init();
    }

    private function init(): void
    {
        // Routes
        add_action('init', [$this, 'registerRoutes']);
        add_action('template_redirect', [$this, 'handleEarlyAuthRoutes'], 5);
        add_action('template_redirect', [$this, 'preventCanonicalLoginRedirect'], 999);

        // Redirect wp-login.php if enabled
        if (Config::get('redirect_wp_login', true)) {
            add_action('login_init', [$this, 'redirectWpLogin']);
        }

        // Let WordPress know about our custom login URL
        add_filter('login_url', [$this, 'filterLoginUrl'], 10, 3);

        // Admin settings
        add_action('admin_menu', [$this, 'addSettingsPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);

        // Privacy tools
        add_filter('wp_privacy_personal_data_exporters', [$this, 'registerPrivacyExporter']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'registerPrivacyEraser']);
    }

    // -------------------------------------------------------------------------
    // Helpers access (for RegistrationService)
    // -------------------------------------------------------------------------

    public function tokens(): TokenHelper
    {
        return $this->tokens;
    }

    public function consent(): ConsentHelper
    {
        return $this->consent;
    }

    // -------------------------------------------------------------------------
    // Routes
    // -------------------------------------------------------------------------

    public function registerRoutes(): void
    {
        $loginUrl = ltrim(Config::get('login_url', '/login'), '/');
        $registerUrl = ltrim(Config::get('register_url', '/register'), '/');

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
            if (!Config::get('enable_registration', true)) {
                wp_redirect(home_url(Config::get('login_url', '/login')));
                exit;
            }
            return $this->renderPage('register');
        });

        // Logout (no cookie-setting, safe at template_include time)
        ntdst_router()->get('auth/logout', function () {
            return $this->handleLogout();
        });

        // Note: auth/verify and auth/activate are handled at template_redirect
        // (handleEarlyAuthRoutes) to ensure headers haven't been sent yet
        // when we set auth cookies.
    }

    /**
     * Handle auth routes that set cookies BEFORE any output.
     *
     * Magic link verification and activation must set cookies via
     * wp_set_auth_cookie(), which requires headers not yet sent.
     * The router's template_include hook fires too late.
     */
    public function handleEarlyAuthRoutes(): void
    {
        $path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');

        if (preg_match('#^auth/verify/([a-zA-Z0-9_-]+)$#', $path, $matches)) {
            $this->handleMagicLinkVerify($matches[1]);
            // handleMagicLinkVerify calls exit
        }

        if (preg_match('#^auth/activate/([a-zA-Z0-9_-]+)$#', $path, $matches)) {
            $this->handleActivation($matches[1]);
            // handleActivation calls exit
        }
    }

    /**
     * Filter WordPress login_url so core knows about our custom login page.
     */
    public function filterLoginUrl(string $login_url, string $redirect = '', bool $force_reauth = false): string
    {
        $customLogin = home_url(Config::get('login_url', '/login'));
        if ($redirect) {
            $customLogin = add_query_arg('redirect_to', urlencode($redirect), $customLogin);
        }
        return $customLogin;
    }

    public function preventCanonicalLoginRedirect(): void
    {
        $loginUrl = ltrim(Config::get('login_url', '/login'), '/');
        $registerUrl = ltrim(Config::get('register_url', '/register'), '/');
        $currentPath = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');

        $authPaths = [$loginUrl, $registerUrl, 'auth/logout'];

        if (in_array($currentPath, $authPaths, true)) {
            remove_action('template_redirect', 'wp_redirect_admin_locations', 1000);

            global $wp_query;
            $wp_query->is_404 = false;
            status_header(200);
        }
    }

    // -------------------------------------------------------------------------
    // Authentication
    // -------------------------------------------------------------------------

    /**
     * Request magic link for email.
     *
     * @return array{success: bool, message: string}
     */
    public function requestMagicLink(string $email): array
    {
        $email = sanitize_email($email);
        $successMessage = __('If an account exists with this email, you will receive a login link shortly.', 'ntdst-auth');

        if (!is_email($email)) {
            return ['success' => true, 'message' => $successMessage];
        }

        if ($this->tokens->isRateLimited('magic_email_' . $email) || $this->tokens->isRateLimited('magic_ip_' . $this->getClientIp())) {
            return [
                'success' => false,
                'message' => __('Please wait before requesting another login link.', 'ntdst-auth'),
            ];
        }

        $user = get_user_by('email', $email);

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
        if (!Config::get('enable_password', false)) {
            return new \WP_Error('password_disabled', __('Password login is not enabled.', 'ntdst-auth'));
        }

        if ($this->tokens->isRateLimited('login_ip_' . $this->getClientIp())) {
            return new \WP_Error('rate_limited', __('Too many login attempts. Please try again later.', 'ntdst-auth'));
        }

        // Increment rate limit counter for every attempt (success or failure)
        $this->tokens->incrementRateLimit('login_ip_' . $this->getClientIp());

        $email = sanitize_email($email);
        $user = get_user_by('email', $email);
        $genericError = new \WP_Error('invalid_credentials', __('Invalid email or password.', 'ntdst-auth'));

        if (!$user) {
            return $genericError;
        }

        if (!$this->consent->isActivated($user->ID)) {
            return new \WP_Error('not_activated', __('Please activate your account first. Check your email for the activation link.', 'ntdst-auth'));
        }

        if (!wp_check_password($password, $user->user_pass, $user->ID)) {
            return $genericError;
        }

        $this->setAuthCookie($user->ID);

        do_action('ntdst_auth_login_success', $user->ID);

        return [
            'success' => true,
            'redirect' => $this->getRedirectAfterLogin(),
        ];
    }

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

        if (!$this->consent->isActivated($result['user_id'])) {
            $this->renderPage('error', [
                'title' => __('Account Not Activated', 'ntdst-auth'),
                'message' => __('Please activate your account first.', 'ntdst-auth'),
            ]);
            exit;
        }

        $this->setAuthCookie($result['user_id']);

        do_action('ntdst_auth_login_success', $result['user_id']);

        wp_safe_redirect($this->getRedirectAfterLogin());
        exit;
    }

    private function handleActivation(string $token): void
    {
        $registration = ntdst_get(RegistrationService::class);
        $result = $registration->activate($token);

        if (is_wp_error($result)) {
            // Error pages don't set cookies, safe to render at any time
            $this->renderPage('error', [
                'title' => __('Activation Failed', 'ntdst-auth'),
                'message' => $result->get_error_message(),
            ]);
            exit;
        }

        $this->setAuthCookie($result['user_id']);

        // Redirect immediately (like magic link) instead of rendering inline.
        // The cookie must be sent before any output.
        $redirect = add_query_arg('activated', '1', $this->getRedirectAfterLogin());
        wp_safe_redirect($redirect);
        exit;
    }

    private function handleLogout(): void
    {
        if (is_user_logged_in()) {
            wp_logout();
        }

        $redirectUrl = home_url(Config::get('redirect_after_logout', '/login'));
        wp_safe_redirect($redirectUrl);
        exit;
    }

    public function redirectWpLogin(): void
    {
        // Never redirect POST requests (cookie-setting, reauth flows)
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            return;
        }

        // Allow WordPress internal actions that need wp-login.php
        $action = $_GET['action'] ?? '';
        $allowedActions = ['lostpassword', 'rp', 'resetpass', 'logout', 'postpass', 'reauth'];
        if (in_array($action, $allowedActions, true)) {
            return;
        }

        // Allow reauth parameter (WordPress cookie verification)
        if (isset($_GET['reauth'])) {
            return;
        }

        $loginUrl = home_url(Config::get('login_url', '/login'));

        // Preserve redirect_to parameter
        if (!empty($_GET['redirect_to'])) {
            $loginUrl = add_query_arg('redirect_to', urlencode($_GET['redirect_to']), $loginUrl);
        }

        wp_safe_redirect($loginUrl);
        exit;
    }

    // -------------------------------------------------------------------------
    // Admin Settings
    // -------------------------------------------------------------------------

    public function addSettingsPage(): void
    {
        add_options_page(
            __('Authentication', 'ntdst-auth'),
            __('Authentication', 'ntdst-auth'),
            'manage_options',
            'ntdst-auth',
            [$this, 'renderSettingsPage']
        );
    }

    public function registerSettings(): void
    {
        register_setting('ntdst_auth', Config::optionKey(), [
            'type' => 'array',
            'sanitize_callback' => [Config::class, 'sanitize'],
        ]);
    }

    public function enqueueAdminAssets(string $hook): void
    {
        if ($hook !== 'settings_page_ntdst-auth') {
            return;
        }

        ntdst_enqueue_admin_toolkit();
    }

    public function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $template = NTDST_AUTH_PATH . 'admin/settings.php';
        if (file_exists($template)) {
            $settings = Config::all();
            include $template;
        }
    }

    // -------------------------------------------------------------------------
    // Privacy Tools
    // -------------------------------------------------------------------------

    /**
     * @param array<string, array> $exporters
     * @return array<string, array>
     */
    public function registerPrivacyExporter(array $exporters): array
    {
        $exporters['ntdst-auth'] = [
            'exporter_friendly_name' => __('Authentication Data', 'ntdst-auth'),
            'callback' => [$this->consent, 'exportUserData'],
        ];
        return $exporters;
    }

    /**
     * @param array<string, array> $erasers
     * @return array<string, array>
     */
    public function registerPrivacyEraser(array $erasers): array
    {
        $erasers['ntdst-auth'] = [
            'eraser_friendly_name' => __('Authentication Data', 'ntdst-auth'),
            'callback' => [$this->consent, 'eraseUserData'],
        ];
        return $erasers;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function setAuthCookie(int $userId): void
    {
        wp_clear_auth_cookie();
        wp_set_current_user($userId);
        wp_set_auth_cookie($userId, true);
    }

    private function getRedirectAfterLogin(): string
    {
        $redirectTo = $_GET['redirect_to'] ?? '';
        if ($redirectTo) {
            $validated = wp_validate_redirect($redirectTo, home_url('/'));
            if ($validated !== home_url('/') || $redirectTo === home_url('/')) {
                return $validated;
            }
        }

        return home_url(Config::get('redirect_after_login', '/'));
    }

    private function sendMagicLinkEmail(string $email, string $token): void
    {
        $verifyUrl = home_url('/auth/verify/' . $token);
        $expiry = (int) Config::get('magic_link_expiry', 15);

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
     * @param array<string, mixed> $data
     */
    private function renderPage(string $template, array $data = []): void
    {
        status_header(200);
        nocache_headers();

        $paths = [
            get_stylesheet_directory() . '/ntdst-auth/pages/' . $template . '.php',
            get_template_directory() . '/ntdst-auth/pages/' . $template . '.php',
            NTDST_AUTH_PATH . 'templates/pages/' . $template . '.php',
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                extract($data);
                $settings = Config::all();
                include $path;
                exit;
            }
        }

        wp_die(__('Template not found.', 'ntdst-auth'));
    }

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
