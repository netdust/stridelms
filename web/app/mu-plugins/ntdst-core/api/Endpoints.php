<?php

declare(strict_types=1);

/**
 * Fast REST API Endpoints for Database Access
 *
 * Super-fast alternative to WordPress AJAX using REST API + direct DB access.
 *
 * SCOPE: the same-origin, nonce-gated generic-action dispatcher
 * (`ntdst/api_data/{action}`). NOT a general REST route registrar and NOT for
 * cross-origin callers — an anonymous WP nonce is a shared, non-origin-bound
 * token that authenticates nothing for a cookie-less cross-origin caller. For
 * new REST routes (incl. cross-origin/CORS) use `ntdst_router()->rest()` +
 * `NTDST_Cors_Policy`.
 *
 * Requirements:
 * - Use with ntdstAPI client (endpoints-client.js)
 *
 * Endpoints:
 * - POST /wp-json/ntdst/v1/get_nonce
 * - POST /wp-json/ntdst/v1/action
 *
 * Conventions:
 *  - Filter prefixes: `ntdst/api/*` for new code. `netdust_trusted_proxies`
 *    is historical — do not propagate that naming.
 *  - Public-action nonces are issued without auth. Handlers for public
 *    actions MUST NOT assume the caller is authenticated; treat all input
 *    as untrusted.
 *  - The `_files` key in request params is reserved for uploaded files
 *    (overwritten by get_request_params). Do not pass `_files` as data.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class NTDST_Endpoints
{
    private const REST_NAMESPACE = 'ntdst/v1';
    private const CACHE_GROUP = 'ntdst_posts';

    /**
     * Rate limiting settings
     */
    private const RATE_LIMIT = 30; // Max requests per window
    private const RATE_WINDOW = 60; // Window in seconds

    /**
     * Public actions that don't require authentication for nonce generation
     * All other actions require user to be logged in
     */
    private array $public_actions = [
        'get_recent_posts',
        'search_posts',
        'send_magic_link',
    ];

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('rest_api_init', [$this, 'register_example_actions']);

        // Auto-clear cache on post changes
        add_action('save_post', [$this, 'clear_post_cache'], 10, 1);
        add_action('deleted_post', [$this, 'clear_post_cache'], 10, 1);
        add_action('trashed_post', [$this, 'clear_post_cache'], 10, 1);
    }

    // =========================================================================
    // REST ROUTE REGISTRATION
    // =========================================================================

    public function register_routes(): void
    {
        $this->register_nonce_endpoint();
        $this->register_action_endpoint();
    }

    private function register_nonce_endpoint(): void
    {
        register_rest_route(self::REST_NAMESPACE, '/get_nonce', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_get_nonce'],
            'permission_callback' => [$this, 'check_nonce_permission'],
            'args'                => [
                'action' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    private function register_action_endpoint(): void
    {
        register_rest_route(self::REST_NAMESPACE, '/action', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_action'],
            'permission_callback' => [$this, 'check_action_permission'],
            'args'                => [
                'action' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'nonce' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    // =========================================================================
    // PERMISSION & SECURITY CALLBACKS
    // =========================================================================

    /**
     * Check permission for nonce endpoint
     * Only allows nonce generation for public actions or logged-in users
     */
    public function check_nonce_permission(WP_REST_Request $request): bool
    {
        // Resolve action first so the rate limit can be per-action.
        $params = $this->get_request_params($request);
        $action = sanitize_text_field($params['action'] ?? $request->get_param('action') ?? '');

        if (!$this->checkRateLimit($action)) {
            return false;
        }

        // Get public actions dynamically (allows late registration)
        $public_actions = apply_filters('ntdst/api/public_actions', $this->public_actions);

        // Allow public actions without authentication
        if (in_array($action, $public_actions, true)) {
            return true;
        }

        // For non-public actions, require authentication
        return is_user_logged_in();
    }

    /**
     * Check permission for action endpoint
     * Verifies origin and applies rate limiting
     */
    public function check_action_permission(WP_REST_Request $request): bool
    {
        $params = $this->get_request_params($request);
        $action = sanitize_text_field($params['action'] ?? '');

        // Rate limiting check (per-action so sensitive actions can be tighter)
        if (!$this->checkRateLimit($action)) {
            return false;
        }

        // CSRF: Verify request origin
        if (!$this->verifyOrigin()) {
            return false;
        }

        // Auth gate, symmetric with check_nonce_permission: anonymous
        // requests may only dispatch PUBLIC actions. Previously this relied
        // indirectly on "anon can't mint a nonce for a non-public action" +
        // per-handler login checks — a handler that forgot its own check,
        // combined with any nonce leak, became an exposed surface.
        $public_actions = apply_filters('ntdst/api/public_actions', $this->public_actions);
        if (!in_array($action, $public_actions, true) && !is_user_logged_in()) {
            return false;
        }

        return true;
    }

    /**
     * Rate limiting to prevent API abuse.
     *
     * Keying strategy:
     *  - Logged-in: bucket per (user_id, action) — fair to NAT'd users
     *  - Anonymous: bucket per (ip, action)
     *
     * Limits and windows are filterable per-action so sensitive operations
     * (e.g. magic-link send) can be much stricter than the default 30/min.
     *
     * @return bool false when limit exceeded
     */
    private function checkRateLimit(string $action = ''): bool
    {
        $limit = (int) apply_filters("ntdst/api/rate_limit/{$action}", self::RATE_LIMIT, $action);
        $window = (int) apply_filters("ntdst/api/rate_window/{$action}", self::RATE_WINDOW, $action);

        if ($limit <= 0) {
            // A filter explicitly disabled the limit.
            return true;
        }

        $userId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        $bucket = $userId > 0
            ? "u{$userId}"
            : 'ip' . md5($this->getClientIp());
        $key = 'ntdst_rate_' . md5($bucket . '|' . $action);

        $count = (int) get_transient($key);

        if ($count >= $limit) {
            return false;
        }

        set_transient($key, $count + 1, $window);

        return true;
    }

    /**
     * Verify request origin for CSRF protection.
     * Only allows requests from same origin or with valid referer.
     * Rejects missing Origin+Referer when auth cookies are present.
     */
    private function verifyOrigin(): bool
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $referer = $_SERVER['HTTP_REFERER'] ?? '';

        // If no origin/referer, only allow if no auth cookie present.
        // Browsers always send Origin on cross-origin requests with credentials.
        if (empty($origin) && empty($referer)) {
            return !$this->hasCookieAuth();
        }

        $homeHost = parse_url(home_url(), PHP_URL_HOST);
        $siteHost = parse_url(site_url(), PHP_URL_HOST);

        // Exact hostname match on Origin header
        if (!empty($origin)) {
            $originHost = parse_url($origin, PHP_URL_HOST);
            if ($originHost === $homeHost || $originHost === $siteHost) {
                return true;
            }
        }

        // Referer must start with our full URL — use trailing slash so
        // `https://example.com.evil.com/x` does NOT match
        // `https://example.com` via simple prefix.
        if (!empty($referer)) {
            $homeUrl = home_url('/');
            $siteUrl = site_url('/');
            if (str_starts_with($referer, $homeUrl) || str_starts_with($referer, $siteUrl)) {
                return true;
            }
        }

        // Allow custom origins via filter
        $allowed_origins = apply_filters('ntdst/api/allowed_origins', []);
        if (!empty($origin) && in_array($origin, $allowed_origins, true)) {
            return true;
        }

        return false;
    }

    /**
     * Check if the request contains WordPress authentication cookies.
     */
    private function hasCookieAuth(): bool
    {
        foreach ($_COOKIE as $name => $value) {
            if (str_starts_with($name, 'wordpress_logged_in_')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get client IP for rate limiting (secure implementation)
     */
    private function getClientIp(): string
    {
        $remote_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Define trusted proxies
        $trusted_proxies = apply_filters('netdust_trusted_proxies', ['127.0.0.1', '::1']);

        // Only trust X-Forwarded-For if behind trusted proxy
        if (in_array($remote_ip, $trusted_proxies, true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $forwarded_ips = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
            $client_ip = $forwarded_ips[0];
            if (filter_var($client_ip, FILTER_VALIDATE_IP)) {
                return $client_ip;
            }
        }

        return $remote_ip;
    }

    // =========================================================================
    // ENDPOINT HANDLERS
    // =========================================================================

    /**
     * Extract request params from JSON body or form-data.
     *
     * Supports both application/json and multipart/form-data content types,
     * allowing the same ntdst/api_data filters to handle file uploads.
     * File params are available as $params['_files'].
     */
    private function get_request_params(WP_REST_Request $request): array
    {
        $params = $request->get_json_params();

        if (empty($params)) {
            $params = $request->get_body_params();
        }

        // Include uploaded files when present
        $files = $request->get_file_params();
        if (!empty($files)) {
            $params['_files'] = $files;
        }

        return $params;
    }

    public function handle_get_nonce(WP_REST_Request $request): array
    {
        $params = $this->get_request_params($request);
        $action = $params['action'] ?? $request->get_param('action');

        if (empty($action)) {
            return $this->error('No action specified', 'missing_action');
        }

        return $this->success([
            'nonce' => wp_create_nonce($action),
        ]);
    }

    public function handle_action(WP_REST_Request $request): array
    {
        $params = $this->get_request_params($request);
        $action = sanitize_text_field($params['action'] ?? '');
        $nonce  = sanitize_text_field($params['nonce'] ?? '');

        if (empty($action) || empty($nonce)) {
            return $this->error('Missing action or nonce', 'missing_params');
        }

        if (!wp_verify_nonce($nonce, $action)) {
            return $this->error('Invalid or expired nonce', 'invalid_nonce');
        }

        // Distinguish "no handler registered" from "handler returned nothing"
        // so a legitimate empty result (e.g. zero search hits) isn't a 404.
        if (!has_filter("ntdst/api_data/{$action}")) {
            return $this->error('Unknown action request', 'unknown_action');
        }

        $data = apply_filters("ntdst/api_data/{$action}", [], $params);

        if (is_wp_error($data)) {
            return $this->error($data->get_error_message(), $data->get_error_code());
        }

        return $this->success(is_array($data) ? $data : []);
    }


    // =========================================================================
    // CACHE CLEARING
    // =========================================================================

    public function clear_post_cache(int $post_id): void
    {
        // Clear per-post entries only. NTDST_Query_Cache's onPostSave hook
        // already bumps the per-post-type version, which invalidates query
        // caches granularly — flushing the whole group here defeats that.
        wp_cache_delete("post_meta_{$post_id}", self::CACHE_GROUP);
        wp_cache_delete("post_terms_{$post_id}", self::CACHE_GROUP);
    }

    // =========================================================================
    // EXAMPLE ACTIONS (register via filter)
    // =========================================================================

    public function register_example_actions(): void
    {
        // Get recent posts
        add_filter('ntdst/api_data/get_recent_posts', function ($data, $params) {
            $post_type = sanitize_text_field($params['post_type'] ?? 'post');
            $per_page  = max(1, intval($params['per_page'] ?? 10));
            $use_cache = ($params['use_cache'] ?? true) !== false;

            $args = [
                'post_type'      => $post_type,
                'posts_per_page' => $per_page,
                'cache_time'     => $use_cache ? 3600 : 0,
            ];

            return ['posts' => ntdst_get_posts_fast($args)];
        }, 10, 2);

        // Search posts
        add_filter('ntdst/api_data/search_posts', function ($data, $params) {
            $search = trim(sanitize_text_field($params['search'] ?? ''));
            if (empty($search)) {
                return $this->error('Search term required', 'empty_search');
            }

            $post_types = is_array($params['post_types']) ? array_map('sanitize_text_field', $params['post_types']) : ['post', 'page'];

            $args = [
                's'               => $search,
                'post_type'       => $post_types,
                'posts_per_page'  => 20,
                'cache_time'      => 300,
            ];

            return ['results' => ntdst_get_posts_fast($args)];
        }, 10, 2);

        // Search users
        add_filter('ntdst/api_data/search_users', function ($data, $params) {
            // Listing users by email/login leaks PII — require list_users.
            if (!current_user_can('list_users')) {
                return $this->error('Insufficient permissions', 'forbidden');
            }

            $search = trim(sanitize_text_field($params['search'] ?? ''));
            if (empty($search)) {
                return $this->error('Search term required', 'empty_search');
            }

            $role = sanitize_text_field($params['role'] ?? '');
            $per_page = absint($params['per_page'] ?? 20);

            $args = [
                'search'         => '*' . $search . '*',
                'search_columns' => ['user_login', 'user_email', 'display_name'],
                'number'         => $per_page,
                'orderby'        => 'display_name',
                'order'          => 'ASC',
            ];

            if (!empty($role)) {
                $args['role'] = $role;
            }

            $users = get_users($args);

            // Transform to match expected format
            $results = array_map(function ($user) {
                return [
                    'ID' => $user->ID,
                    'id' => $user->ID,
                    'post_title' => $user->display_name,
                    'title' => $user->display_name,
                    'user_email' => $user->user_email,
                    'user_login' => $user->user_login,
                ];
            }, $users);

            return ['results' => $results];
        }, 10, 2);
    }

    // =========================================================================
    // RESPONSE HELPERS
    // =========================================================================

    private function success(array $data): array
    {
        return NTDST_Response::apiSuccess($data);
    }

    private function error(string $message, string $code = 'error'): array
    {
        return NTDST_Response::apiError($message, $code);
    }
}

/**
 * Global helper - get endpoints instance
 */
if (!function_exists('ntdst_endpoints')) {
    function ntdst_endpoints(): NTDST_Endpoints
    {
        static $manager = null;
        return $manager ??= new NTDST_Endpoints();
    }
}

// Back-compat: keep the old unprefixed class name working for callers
// outside this codebase. New code should use NTDST_Endpoints.
if (!class_exists('Endpoints', false)) {
    class_alias(NTDST_Endpoints::class, 'Endpoints');
}
