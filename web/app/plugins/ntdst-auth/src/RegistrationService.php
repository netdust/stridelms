<?php

declare(strict_types=1);

namespace NTDST\Auth;

use NTDST\Auth\Helpers\Config;
use NTDST\Auth\Helpers\TokenHelper;
use NTDST\Auth\Helpers\ConsentHelper;

defined('ABSPATH') || exit;

/**
 * Handles user registration with activation flow.
 *
 * Creates users in pending state, sends activation emails,
 * and activates accounts when users click the link.
 */
final class RegistrationService implements \NTDST_Service_Meta
{
    private TokenHelper $tokens;
    private ConsentHelper $consent;

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
        $config = Config::all();
        $this->tokens = new TokenHelper($config);
        $this->consent = new ConsentHelper($config);
    }

    /**
     * Register a new user.
     *
     * Always returns success message to prevent email enumeration.
     *
     * @param array{email: string, first_name?: string, last_name?: string, consent_terms?: bool, consent_privacy?: bool} $data
     * @return array{success: bool, message: string}|\WP_Error
     */
    public function register(array $data): array|\WP_Error
    {
        if (!Config::get('enable_registration', true)) {
            return new \WP_Error('registration_disabled', __('Registration is currently disabled.', 'ntdst-auth'));
        }

        if ($this->tokens->isRateLimited('register_ip_' . $this->getClientIp())) {
            return new \WP_Error('rate_limited', __('Too many registration attempts. Please try again later.', 'ntdst-auth'));
        }

        $email = sanitize_email($data['email'] ?? '');
        if (!is_email($email)) {
            return new \WP_Error('invalid_email', __('Please enter a valid email address.', 'ntdst-auth'));
        }

        if (empty($data['consent_terms']) || empty($data['consent_privacy'])) {
            return new \WP_Error('consent_required', __('You must accept the terms and privacy policy.', 'ntdst-auth'));
        }

        $existingUser = get_user_by('email', $email);

        if ($existingUser) {
            $this->sendAlreadyRegisteredEmail($email);
        } else {
            $result = $this->createUser($data);
            if (is_wp_error($result)) {
                ntdst_log('auth')->error('Registration failed', [
                    'email' => $email,
                    'error' => $result->get_error_message(),
                ]);
            }
        }

        return [
            'success' => true,
            'message' => __('Check your inbox for instructions to complete your registration.', 'ntdst-auth'),
        ];
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

        if ($this->consent->isActivated($userId)) {
            return new \WP_Error('already_activated', __('Your account is already activated. Please log in.', 'ntdst-auth'));
        }

        $this->consent->activateUser($userId);

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
     * @param array{email: string, first_name?: string, last_name?: string, consent_terms?: bool, consent_privacy?: bool} $data
     * @return int|\WP_Error
     */
    private function createUser(array $data): int|\WP_Error
    {
        $email = sanitize_email($data['email']);
        $firstName = sanitize_text_field($data['first_name'] ?? '');
        $lastName = sanitize_text_field($data['last_name'] ?? '');

        $username = $this->generateUsername($email);
        $password = wp_generate_password(24, true, true);

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

        $this->consent->recordConsent($userId, [
            'terms' => !empty($data['consent_terms']),
            'privacy' => !empty($data['consent_privacy']),
        ]);

        $this->sendActivationEmail($email, $userId);

        do_action('ntdst_auth_registration_complete', $userId, $data);

        return $userId;
    }

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

    private function sendActivationEmail(string $email, int $userId): void
    {
        $token = $this->tokens->createActivationToken($email, $userId);
        $activateUrl = home_url('/auth/activate/' . $token);
        $expiry = (int) Config::get('activation_link_expiry', 48);

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

    private function sendAlreadyRegisteredEmail(string $email): void
    {
        $loginUrl = home_url(Config::get('login_url', '/login'));

        ntdst_mail()
            ->to($email)
            ->subject(__('Account already exists', 'ntdst-auth'))
            ->template('already-registered', [
                'login_url' => $loginUrl,
                'site_name' => get_bloginfo('name'),
            ])
            ->send();
    }

    private function sendWelcomeEmail(string $email): void
    {
        $loginUrl = home_url(Config::get('login_url', '/login'));

        ntdst_mail()
            ->to($email)
            ->subject(__('Welcome!', 'ntdst-auth'))
            ->template('welcome', [
                'login_url' => $loginUrl,
                'site_name' => get_bloginfo('name'),
            ])
            ->send();
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
