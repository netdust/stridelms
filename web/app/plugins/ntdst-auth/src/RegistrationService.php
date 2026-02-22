<?php

declare(strict_types=1);

namespace NTDST\Auth;

defined('ABSPATH') || exit;

/**
 * Handles user registration with activation flow.
 *
 * Creates users in pending state, sends activation emails,
 * and activates accounts when users click the link.
 */
final class RegistrationService implements \NTDST_Service_Meta
{
    private SettingsService $settings;
    private TokenService $tokens;
    private ConsentService $consent;

    public static function metadata(): array
    {
        return [
            'name' => 'Registration Service',
            'description' => 'User registration and activation',
            'priority' => 4,
        ];
    }

    public function __construct()
    {
        $this->settings = ntdst_get(SettingsService::class);
        $this->tokens = ntdst_get(TokenService::class);
        $this->consent = ntdst_get(ConsentService::class);
    }

    /**
     * Register a new user.
     *
     * Always returns success message to prevent email enumeration.
     * Internally handles existing users vs new registrations.
     *
     * @param array{email: string, first_name?: string, last_name?: string, consent_terms?: bool, consent_privacy?: bool} $data
     * @return array{success: bool, message: string}|\WP_Error
     */
    public function register(array $data): array|\WP_Error
    {
        // Check if registration is enabled
        if (!$this->settings->get('enable_registration', true)) {
            return new \WP_Error('registration_disabled', __('Registration is currently disabled.', 'ntdst-auth'));
        }

        // Check rate limit
        if ($this->tokens->isRateLimited('register_ip_' . $this->getClientIp())) {
            return new \WP_Error('rate_limited', __('Too many registration attempts. Please try again later.', 'ntdst-auth'));
        }

        // Validate email
        $email = sanitize_email($data['email'] ?? '');
        if (!is_email($email)) {
            return new \WP_Error('invalid_email', __('Please enter a valid email address.', 'ntdst-auth'));
        }

        // Validate consent
        if (empty($data['consent_terms']) || empty($data['consent_privacy'])) {
            return new \WP_Error('consent_required', __('You must accept the terms and privacy policy.', 'ntdst-auth'));
        }

        // Check if user exists
        $existingUser = get_user_by('email', $email);

        if ($existingUser) {
            // Send "already registered" email (no enumeration)
            $this->sendAlreadyRegisteredEmail($email);
        } else {
            // Create new user
            $result = $this->createUser($data);
            if (is_wp_error($result)) {
                // Log error but return generic message
                ntdst_log('auth')->error('Registration failed', [
                    'email' => $email,
                    'error' => $result->get_error_message(),
                ]);
            }
        }

        // Always return same message to prevent enumeration
        return [
            'success' => true,
            'message' => __('Check your inbox for instructions to complete your registration.', 'ntdst-auth'),
        ];
    }

    /**
     * Create a new user account.
     *
     * @param array{email: string, first_name?: string, last_name?: string, consent_terms?: bool, consent_privacy?: bool} $data
     * @return int|\WP_Error User ID or error
     */
    private function createUser(array $data): int|\WP_Error
    {
        $email = sanitize_email($data['email']);
        $firstName = sanitize_text_field($data['first_name'] ?? '');
        $lastName = sanitize_text_field($data['last_name'] ?? '');

        // Generate username from email
        $username = $this->generateUsername($email);

        // Generate random password (user won't use it with magic links)
        $password = wp_generate_password(24, true, true);

        // Create user
        $userId = wp_insert_user([
            'user_login' => $username,
            'user_email' => $email,
            'user_pass' => $password,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'display_name' => trim($firstName . ' ' . $lastName) ?: $username,
            'role' => 'subscriber',
        ]);

        if (is_wp_error($userId)) {
            return $userId;
        }

        // Record consent
        $this->consent->recordConsent($userId, [
            'terms' => !empty($data['consent_terms']),
            'privacy' => !empty($data['consent_privacy']),
        ]);

        // Send activation email
        $this->sendActivationEmail($email, $userId);

        /**
         * Fires after user registration is complete.
         *
         * @param int $userId User ID
         * @param array $data Registration data
         */
        do_action('ntdst_auth_registration_complete', $userId, $data);

        return $userId;
    }

    /**
     * Activate a user account.
     *
     * @return array{success: bool, user_id: int}|\WP_Error
     */
    public function activate(string $token): array|\WP_Error
    {
        $result = $this->tokens->verify($token, 'activation');

        if (is_wp_error($result)) {
            return $result;
        }

        $userId = $result['user_id'];

        // Check if already activated
        if ($this->consent->isActivated($userId)) {
            return new \WP_Error('already_activated', __('Your account is already activated. Please log in.', 'ntdst-auth'));
        }

        // Activate user
        $this->consent->activateUser($userId);

        // Send welcome email
        $user = get_user_by('ID', $userId);
        if ($user) {
            $this->sendWelcomeEmail($user->user_email);
        }

        return [
            'success' => true,
            'user_id' => $userId,
        ];
    }

    /**
     * Generate unique username from email.
     */
    private function generateUsername(string $email): string
    {
        $base = sanitize_user(explode('@', $email)[0], true);
        $username = $base;
        $suffix = 1;

        while (username_exists($username)) {
            $username = $base . $suffix;
            $suffix++;
        }

        return $username;
    }

    /**
     * Send activation email.
     */
    private function sendActivationEmail(string $email, int $userId): void
    {
        $token = $this->tokens->createActivationToken($email, $userId);
        $activateUrl = home_url('/auth/activate/' . $token);
        $expiry = (int) $this->settings->get('activation_link_expiry', 48);

        ntdst_mail()
            ->to($email)
            ->subject(__('Activate your account', 'ntdst-auth'))
            ->template('activation', [
                'activate_url' => $activateUrl,
                'expiry_hours' => $expiry,
                'site_name' => get_bloginfo('name'),
            ])
            ->send();
    }

    /**
     * Send "already registered" email.
     */
    private function sendAlreadyRegisteredEmail(string $email): void
    {
        $loginUrl = home_url($this->settings->get('login_url', '/login'));

        ntdst_mail()
            ->to($email)
            ->subject(__('Account already exists', 'ntdst-auth'))
            ->template('already-registered', [
                'login_url' => $loginUrl,
                'site_name' => get_bloginfo('name'),
            ])
            ->send();
    }

    /**
     * Send welcome email.
     */
    private function sendWelcomeEmail(string $email): void
    {
        $loginUrl = home_url($this->settings->get('login_url', '/login'));

        ntdst_mail()
            ->to($email)
            ->subject(__('Welcome!', 'ntdst-auth'))
            ->template('welcome', [
                'login_url' => $loginUrl,
                'site_name' => get_bloginfo('name'),
            ])
            ->send();
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
