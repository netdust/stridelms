<?php

declare(strict_types=1);

namespace NetdustLTI\Shared;

class TokenAuthMiddleware
{
    private const SLIDING_TTL = 8 * HOUR_IN_SECONDS;
    private const ABSOLUTE_MAX_TTL = 24 * HOUR_IN_SECONDS;

    /**
     * Register the token auth check.
     * Uses send_headers (fires before init) for X-Frame-Options,
     * and init priority 1 for user authentication.
     */
    public function register(): void
    {
        add_action('send_headers', [$this, 'handleFrameHeaders'], PHP_INT_MAX);
        add_action('init', [$this, 'authenticate'], 1);
    }

    /**
     * Remove X-Frame-Options for token-authenticated requests.
     * Fires at send_headers (before init), so we check the token
     * directly without relying on wp_set_current_user().
     */
    public function handleFrameHeaders(): void
    {
        $token = $this->extractToken();
        if (empty($token)) {
            return;
        }

        $data = get_transient('lti_nav_' . $token);
        if (!empty($data) && !empty($data['user_id'])) {
            header_remove('X-Frame-Options');
            header('Content-Security-Policy: frame-ancestors *');
        }
    }

    /**
     * Authenticate via _lti nav token.
     * Sets wp_set_current_user() without cookies.
     */
    public function authenticate(): void
    {
        $token = $this->extractToken();
        if (empty($token)) {
            return;
        }

        $data = get_transient('lti_nav_' . $token);
        if (empty($data) || empty($data['user_id'])) {
            return;
        }

        // Absolute max lifetime check
        if (!empty($data['created_at']) && (time() - $data['created_at']) > self::ABSOLUTE_MAX_TTL) {
            delete_transient('lti_nav_' . $token);
            return;
        }

        // Set current user without cookies
        wp_set_current_user((int) $data['user_id']);

        // Sliding expiration — reset TTL on each valid use
        $data['last_used'] = time();
        set_transient('lti_nav_' . $token, $data, self::SLIDING_TTL);
    }

    /**
     * Extract token from request (GET, POST, or header).
     */
    private function extractToken(): string
    {
        if (!empty($_GET['_lti'])) {
            return sanitize_text_field(wp_unslash($_GET['_lti']));
        }
        if (!empty($_POST['_lti'])) {
            return sanitize_text_field(wp_unslash($_POST['_lti']));
        }

        // Check header fallback
        $header = $_SERVER['HTTP_X_LTI_TOKEN'] ?? '';
        if (!empty($header)) {
            return sanitize_text_field($header);
        }

        return '';
    }
}
