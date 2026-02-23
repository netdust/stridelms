<?php
/**
 * Fast REST API Endpoints for Database Access
 *
 * Super-fast alternative to WordPress AJAX using REST API + direct DB access.
 *
 * Requirements:
 * - Define `ALLOW_RESTAPI_AJAX` as true in wp-config.php
 * - Use with ntdstAPI client (endpoints-client.js)
 *
 * Endpoints:
 * - POST /wp-json/ntdst/v1/get_nonce
 * - POST /wp-json/ntdst/v1/action
 */

if (!defined('ABSPATH')) exit;

class Endpoints {

	private const NAMESPACE = 'ntdst/v1';
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
		'search_users',
		'send_magic_link',
	];

	public function __construct() {
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

	public function register_routes() {
		$this->register_nonce_endpoint();
		$this->register_action_endpoint();
	}

	private function register_nonce_endpoint() {
		register_rest_route(self::NAMESPACE, '/get_nonce', [
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

	private function register_action_endpoint() {
		register_rest_route(self::NAMESPACE, '/action', [
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
	public function check_nonce_permission(WP_REST_Request $request): bool {
		// Rate limiting check first
		if (!$this->checkRateLimit()) {
			return false;
		}

		// Get the requested action
		$params = $request->get_json_params();
		$action = sanitize_text_field($params['action'] ?? $request->get_param('action') ?? '');

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
	public function check_action_permission(WP_REST_Request $request): bool {
		// Rate limiting check
		if (!$this->checkRateLimit()) {
			return false;
		}

		// CSRF: Verify request origin
		if (!$this->verifyOrigin()) {
			return false;
		}

		return true;
	}

	/**
	 * Rate limiting to prevent API abuse
	 * Returns false if rate limit exceeded
	 */
	private function checkRateLimit(): bool {
		$ip = $this->getClientIp();
		$key = 'ntdst_rate_' . md5($ip);

		$count = (int) get_transient($key);

		if ($count >= self::RATE_LIMIT) {
			// Rate limit exceeded
			return false;
		}

		// Increment counter
		set_transient($key, $count + 1, self::RATE_WINDOW);

		return true;
	}

	/**
	 * Verify request origin for CSRF protection
	 * Only allows requests from same origin or with valid referer
	 */
	private function verifyOrigin(): bool {
		// Get origin header
		$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
		$referer = $_SERVER['HTTP_REFERER'] ?? '';

		// If no origin/referer, this is likely a same-origin request
		if (empty($origin) && empty($referer)) {
			return true;
		}

		$home_url = home_url();
		$site_url = site_url();

		// Check if origin matches our site
		if (!empty($origin)) {
			if (strpos($origin, parse_url($home_url, PHP_URL_HOST)) !== false) {
				return true;
			}
			if (strpos($origin, parse_url($site_url, PHP_URL_HOST)) !== false) {
				return true;
			}
		}

		// Check if referer matches our site
		if (!empty($referer)) {
			if (strpos($referer, $home_url) === 0) {
				return true;
			}
			if (strpos($referer, $site_url) === 0) {
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
	 * Get client IP for rate limiting (secure implementation)
	 */
	private function getClientIp(): string {
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

	public function handle_get_nonce(WP_REST_Request $request): array {
		// Get action from JSON body or query param
		$params = $request->get_json_params();
		$action = $params['action'] ?? $request->get_param('action');

		if (empty($action)) {
			return $this->error('No action specified', 'missing_action');
		}

		return $this->success([
			'nonce' => wp_create_nonce($action),
		]);
	}

	public function handle_action(WP_REST_Request $request): array {
		$params = $request->get_json_params();
		$action = sanitize_text_field($params['action'] ?? '');
		$nonce  = sanitize_text_field($params['nonce'] ?? '');

		if (empty($action) || empty($nonce)) {
			return $this->error('Missing action or nonce', 'missing_params');
		}

		if (!wp_verify_nonce($nonce, $action)) {
			return $this->error('Invalid or expired nonce', 'invalid_nonce');
		}

		$data = apply_filters("ntdst/api_data/{$action}", [], $params);

		// Handle WP_Error responses from filters
		if (is_wp_error($data)) {
			return $this->error($data->get_error_message(), $data->get_error_code());
		}

		if (empty($data)) {
			return $this->error('Unknown action request', 'unknown_action');
		}

		return $this->success($data);
	}


	// =========================================================================
	// CACHE CLEARING
	// =========================================================================

	public function clear_post_cache(int $post_id): void {
		wp_cache_delete("post_meta_{$post_id}", self::CACHE_GROUP);
		wp_cache_delete("post_terms_{$post_id}", self::CACHE_GROUP);
		wp_cache_flush_group(self::CACHE_GROUP); // Optional: full flush
	}

	// =========================================================================
	// EXAMPLE ACTIONS (register via filter)
	// =========================================================================

	public function register_example_actions() {
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
			$results = array_map(function($user) {
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

	private function success(array $data): array {
		return [
			'success' => true,
			'data'    => $data,
		];
	}

	private function error(string $message, string $code = 'error'): array {
		return [
			'success' => false,
			'data'    => [
				'message' => $message,
				'code'    => $code,
			],
		];
	}
}

/**
 * Global helper - get endpoints instance
 */
function ntdst_endpoints(): Endpoints
{
	static $manager = null;
	return $manager ??= new Endpoints();
}
