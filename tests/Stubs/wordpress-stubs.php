<?php

/**
 * WordPress Function Stubs
 *
 * Minimal WordPress function implementations for testing.
 * These provide basic functionality without requiring WP core.
 */

if (!class_exists('WP_Post')) {
    /**
     * WordPress Post Class Stub
     */
    class WP_Post
    {
        public int $ID = 0;
        public string $post_type = 'post';
        public string $post_title = '';
        public string $post_status = 'publish';
        public string $post_content = '';
        public string $post_excerpt = '';
        public string $post_date = '';
        public string $post_date_gmt = '';
        public string $post_modified = '';
        public string $post_modified_gmt = '';
        public int $post_author = 0;
        public int $post_parent = 0;
        public string $post_name = '';
        public int $menu_order = 0;
        // Extension fields used by NTDST Data Manager
        public array $fields = [];
        public array $meta = [];

        public function __construct(array $data = [])
        {
            foreach ($data as $key => $value) {
                if (property_exists($this, $key)) {
                    $this->$key = $value;
                }
            }
        }
    }
}

if (!class_exists('WP_User')) {
    /**
     * WordPress User Class Stub
     */
    class WP_User
    {
        public int $ID = 0;
        public string $user_login = '';
        public string $user_email = '';
        public string $user_nicename = '';
        public string $display_name = '';
        public string $first_name = '';
        public string $last_name = '';

        public function __construct($id = 0, $name = '', $site_id = '')
        {
            if (is_numeric($id) && $id > 0) {
                $this->ID = (int) $id;
            } elseif (is_array($id)) {
                foreach ($id as $key => $value) {
                    if (property_exists($this, $key)) {
                        $this->$key = $value;
                    }
                }
            }
        }

        public function exists(): bool
        {
            return $this->ID > 0;
        }
    }
}

if (!class_exists('WP_Error')) {
    /**
     * WordPress Error Class
     */
    class WP_Error
    {
        private array $errors = [];
        private array $error_data = [];

        public function __construct($code = '', $message = '', $data = '')
        {
            if (!empty($code)) {
                $this->add($code, $message, $data);
            }
        }

        public function add($code, $message, $data = '')
        {
            $this->errors[$code][] = $message;
            if (!empty($data)) {
                $this->error_data[$code] = $data;
            }
        }

        public function get_error_code()
        {
            return array_key_first($this->errors) ?? '';
        }

        public function get_error_message($code = '')
        {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            return $this->errors[$code][0] ?? '';
        }

        public function get_error_messages($code = '')
        {
            if (empty($code)) {
                $all = [];
                foreach ($this->errors as $msgs) {
                    $all = array_merge($all, $msgs);
                }
                return $all;
            }
            return $this->errors[$code] ?? [];
        }

        public function get_error_data($code = '')
        {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            return $this->error_data[$code] ?? null;
        }

        public function has_errors(): bool
        {
            return !empty($this->errors);
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str): string
    {
        return trim(strip_tags((string) $str));
    }
}

if (!function_exists('sanitize_title')) {
    function sanitize_title(string $title, string $fallback_title = '', string $context = 'save'): string
    {
        $title = strtolower($title);
        $title = preg_replace('/[^a-z0-9\s-]/', '', $title);
        $title = preg_replace('/[\s_]+/', '-', $title);
        $title = trim($title, '-');
        return $title ?: $fallback_title;
    }
}

if (!function_exists('sanitize_email')) {
    function sanitize_email($email): string
    {
        return filter_var($email, FILTER_SANITIZE_EMAIL) ?: '';
    }
}

if (!function_exists('is_email')) {
    function is_email($email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = []): array
    {
        if (is_object($args)) {
            $parsed = get_object_vars($args);
        } elseif (is_array($args)) {
            $parsed = &$args;
        } else {
            parse_str($args, $parsed);
        }
        return array_merge($defaults, $parsed);
    }
}

if (!function_exists('absint')) {
    function absint($maybeint): int
    {
        return abs((int) $maybeint);
    }
}

// User meta storage for testing
global $_test_user_meta;
$_test_user_meta = [];

if (!function_exists('get_user_meta')) {
    function get_user_meta($user_id, $key = '', $single = false)
    {
        global $_test_user_meta;

        if (empty($key)) {
            return $_test_user_meta[$user_id] ?? [];
        }

        $value = $_test_user_meta[$user_id][$key] ?? [];

        if ($single) {
            return $value[0] ?? '';
        }

        return $value;
    }
}

if (!function_exists('update_user_meta')) {
    function update_user_meta($user_id, $meta_key, $meta_value, $prev_value = '')
    {
        global $_test_user_meta;

        if (!isset($_test_user_meta[$user_id])) {
            $_test_user_meta[$user_id] = [];
        }

        $_test_user_meta[$user_id][$meta_key] = [$meta_value];
        return true;
    }
}

if (!function_exists('delete_user_meta')) {
    function delete_user_meta($user_id, $meta_key, $meta_value = '')
    {
        global $_test_user_meta;
        unset($_test_user_meta[$user_id][$meta_key]);
        return true;
    }
}

// Configurable current user for testing
global $_test_current_user_id;
$_test_current_user_id = 1;

if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        global $_test_current_user_id;
        return $_test_current_user_id;
    }
}

if (!function_exists('wp_update_user')) {
    function wp_update_user(array $userdata)
    {
        global $_test_users;

        $userId = $userdata['ID'] ?? 0;
        if (!$userId) {
            return new WP_Error('invalid_user_id', 'Invalid user ID');
        }

        // Update user object if it exists
        if (isset($_test_users[$userId])) {
            foreach ($userdata as $key => $value) {
                if (property_exists($_test_users[$userId], $key)) {
                    $_test_users[$userId]->$key = $value;
                }
            }
        }

        return $userId;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($capability, ...$args): bool
    {
        global $current_user_caps;
        // If caps are explicitly set, use them; otherwise allow everything
        if (isset($current_user_caps) && is_array($current_user_caps)) {
            return $current_user_caps[$capability] ?? false;
        }
        return true; // Allow everything in tests by default
    }
}

if (!function_exists('user_can')) {
    function user_can($user, string $capability, ...$args): bool
    {
        global $current_user_caps;

        $userId = is_object($user) ? $user->ID : (int) $user;

        // If caps are explicitly set, use them; otherwise allow everything
        if (isset($current_user_caps) && is_array($current_user_caps)) {
            return $current_user_caps[$capability] ?? false;
        }

        return true; // Allow everything in tests by default
    }
}

if (!function_exists('get_current_blog_id')) {
    function get_current_blog_id(): int
    {
        return 1; // Single site default
    }
}

if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user(): WP_User
    {
        global $_test_current_user_id, $_test_users;

        $userId = $_test_current_user_id ?? 0;

        if ($userId && isset($_test_users[$userId])) {
            return $_test_users[$userId];
        }

        // Return an empty user (not logged in)
        $user = new WP_User();
        $user->ID = 0;
        return $user;
    }
}

if (!function_exists('wp_insert_user')) {
    function wp_insert_user(array $userdata)
    {
        global $_test_users;
        static $nextId = 1000;

        if (!isset($_test_users)) {
            $_test_users = [];
        }

        $userId = $nextId++;
        $user = new WP_User();
        $user->ID = $userId;
        $user->user_login = $userdata['user_login'] ?? '';
        $user->user_email = $userdata['user_email'] ?? '';
        $user->display_name = $userdata['display_name'] ?? '';

        $_test_users[$userId] = $user;

        return $userId;
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1)
    {
        return parse_url($url, $component);
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $url, ?array $protocols = null, string $_context = 'display'): string
    {
        return filter_var($url, FILTER_SANITIZE_URL) ?: '';
    }
}

if (!function_exists('get_user_by')) {
    function get_user_by($field, $value)
    {
        global $_test_users;

        if (!isset($_test_users)) {
            $_test_users = [];
        }

        foreach ($_test_users as $user) {
            if ($field === 'ID' && $user->ID === $value) {
                return $user;
            }
            if ($field === 'email' && $user->user_email === $value) {
                return $user;
            }
        }

        return false;
    }
}

// Action/filter storage for testing
global $_test_actions, $_test_filters, $_test_action_calls;
$_test_actions = [];
$_test_filters = [];
$_test_action_calls = [];

if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
    {
        global $_test_actions;
        $_test_actions[$hook][] = [
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $accepted_args,
        ];
    }
}

if (!function_exists('do_action')) {
    function do_action($hook, ...$args)
    {
        global $_test_actions, $_test_action_calls;

        // Track action calls for assertions
        $_test_action_calls[$hook][] = $args;

        if (!isset($_test_actions[$hook])) {
            return;
        }

        // Sort by priority
        usort($_test_actions[$hook], fn($a, $b) => $a['priority'] <=> $b['priority']);

        foreach ($_test_actions[$hook] as $action) {
            $callback = $action['callback'];
            $accepted_args = $action['accepted_args'];
            call_user_func_array($callback, array_slice($args, 0, $accepted_args));
        }
    }
}

if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1)
    {
        global $_test_filters;
        $_test_filters[$hook][] = [
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $accepted_args,
        ];
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value, ...$args)
    {
        global $_test_filters;

        if (!isset($_test_filters[$hook])) {
            return $value;
        }

        // Sort by priority
        usort($_test_filters[$hook], fn($a, $b) => $a['priority'] <=> $b['priority']);

        foreach ($_test_filters[$hook] as $filter) {
            $callback = $filter['callback'];
            $accepted_args = $filter['accepted_args'];
            $value = call_user_func_array($callback, array_merge([$value], array_slice($args, 0, $accepted_args - 1)));
        }

        return $value;
    }
}

if (!function_exists('remove_action')) {
    function remove_action($hook, $callback, $priority = 10): bool
    {
        global $_test_actions;
        if (!isset($_test_actions[$hook])) {
            return false;
        }

        foreach ($_test_actions[$hook] as $key => $action) {
            if ($action['callback'] === $callback && $action['priority'] === $priority) {
                unset($_test_actions[$hook][$key]);
                return true;
            }
        }

        return false;
    }
}

// Post meta storage for testing
global $_test_post_meta;
$_test_post_meta = [];

if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key = '', $single = false)
    {
        global $_test_post_meta;

        if (empty($key)) {
            return $_test_post_meta[$post_id] ?? [];
        }

        $value = $_test_post_meta[$post_id][$key] ?? [];

        if ($single) {
            return $value[0] ?? '';
        }

        return $value;
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $meta_key, $meta_value, $prev_value = '')
    {
        global $_test_post_meta;

        if (!isset($_test_post_meta[$post_id])) {
            $_test_post_meta[$post_id] = [];
        }

        $_test_post_meta[$post_id][$meta_key] = [$meta_value];
        return true;
    }
}

if (!function_exists('get_post')) {
    function get_post($post = null)
    {
        global $_test_posts;

        if (is_int($post)) {
            $found = $_test_posts[$post] ?? null;
            if ($found === null) {
                return null;
            }
            // Convert stdClass to WP_Post if needed
            if ($found instanceof \WP_Post) {
                return $found;
            }
            return new \WP_Post((array) $found);
        }

        if ($post instanceof \WP_Post) {
            return $post;
        }

        if (is_object($post)) {
            return new \WP_Post((array) $post);
        }

        return $post;
    }
}

if (!function_exists('get_posts')) {
    function get_posts(array $args = []): array
    {
        global $_test_posts;

        if (empty($_test_posts)) {
            return [];
        }

        $results = [];
        $postType = $args['post_type'] ?? 'post';
        $postStatus = $args['post_status'] ?? 'publish';
        $name = $args['name'] ?? null;
        $limit = $args['posts_per_page'] ?? -1;

        foreach ($_test_posts as $post) {
            // Filter by post_type
            if (isset($post->post_type) && $post->post_type !== $postType) {
                continue;
            }

            // Filter by post_status
            if (isset($post->post_status) && $post->post_status !== $postStatus) {
                continue;
            }

            // Filter by name (slug)
            if ($name !== null && isset($post->post_name) && $post->post_name !== $name) {
                continue;
            }

            $results[] = $post;

            // Apply limit
            if ($limit > 0 && count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }
}

if (!function_exists('get_the_title')) {
    function get_the_title($post = null): string
    {
        $post = get_post($post);
        return $post->post_title ?? '';
    }
}

if (!function_exists('get_stylesheet_directory')) {
    function get_stylesheet_directory(): string
    {
        return '/tmp/test-theme';
    }
}

if (!function_exists('get_template_directory')) {
    function get_template_directory(): string
    {
        return '/tmp/test-theme';
    }
}

if (!function_exists('locate_template')) {
    function locate_template(array $templates, bool $load = false, bool $require_once = true): string
    {
        return '';
    }
}

if (!function_exists('nocache_headers')) {
    function nocache_headers(): void
    {
        // No-op in tests
    }
}

if (!function_exists('http_response_code')) {
    // Already exists in PHP, no stub needed
}

// Options storage
global $_test_options;
$_test_options = [];

if (!function_exists('get_option')) {
    function get_option($option, $default = false)
    {
        global $_test_options;
        return $_test_options[$option] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null): bool
    {
        global $_test_options;
        $_test_options[$option] = $value;
        return true;
    }
}

// Transients
global $_test_transients;
$_test_transients = [];

if (!function_exists('get_transient')) {
    function get_transient($transient)
    {
        global $_test_transients;
        return $_test_transients[$transient] ?? false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration = 0): bool
    {
        global $_test_transients;
        $_test_transients[$transient] = $value;
        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($transient): bool
    {
        global $_test_transients;
        unset($_test_transients[$transient]);
        return true;
    }
}

// CPT registered post types storage
global $_test_registered_post_types;
$_test_registered_post_types = [];

if (!function_exists('post_type_exists')) {
    function post_type_exists(string $post_type): bool
    {
        global $_test_registered_post_types;
        return isset($_test_registered_post_types[$post_type]);
    }
}

if (!function_exists('register_post_type')) {
    function register_post_type(string $post_type, array $args = [])
    {
        global $_test_registered_post_types;
        $_test_registered_post_types[$post_type] = $args;
        return (object) $args;
    }
}

// NTDST Core interface stubs
if (!interface_exists('NTDST_Service_Meta')) {
    interface NTDST_Service_Meta
    {
        public static function metadata(): array;
    }
}

// NTDST Core function stubs
if (!function_exists('ntdst_get')) {
    function ntdst_get(string $key)
    {
        global $_test_container;
        return $_test_container[$key] ?? null;
    }
}

if (!function_exists('ntdst_set')) {
    function ntdst_set(string $key, $value)
    {
        global $_test_container;
        $_test_container[$key] = $value;
    }
}

// REST API classes for testing
if (!class_exists('WP_REST_Request')) {
    /**
     * WordPress REST Request Class Stub
     */
    class WP_REST_Request
    {
        private array $params = [];
        private array $headers = [];
        private string $method = 'GET';
        private string $route = '';

        public function __construct(string $method = 'GET', string $route = '', array $params = [])
        {
            $this->method = $method;
            $this->route = $route;
            $this->params = $params;
        }

        public function get_param(string $key)
        {
            return $this->params[$key] ?? null;
        }

        public function get_params(): array
        {
            return $this->params;
        }

        public function set_param(string $key, $value): void
        {
            $this->params[$key] = $value;
        }

        public function get_method(): string
        {
            return $this->method;
        }

        public function get_route(): string
        {
            return $this->route;
        }

        public function get_header(string $key): ?string
        {
            return $this->headers[strtolower($key)] ?? null;
        }

        public function set_header(string $key, string $value): void
        {
            $this->headers[strtolower($key)] = $value;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    /**
     * WordPress REST Response Class Stub
     */
    class WP_REST_Response
    {
        private mixed $data;
        private int $status;
        private array $headers = [];

        public function __construct(mixed $data = null, int $status = 200, array $headers = [])
        {
            $this->data = $data;
            $this->status = $status;
            $this->headers = $headers;
        }

        public function get_data(): mixed
        {
            return $this->data;
        }

        public function set_data(mixed $data): void
        {
            $this->data = $data;
        }

        public function get_status(): int
        {
            return $this->status;
        }

        public function set_status(int $status): void
        {
            $this->status = $status;
        }

        public function header(string $key, string $value, bool $replace = true): void
        {
            $this->headers[$key] = $value;
        }

        public function get_headers(): array
        {
            return $this->headers;
        }
    }
}

if (!class_exists('WP_User_Query')) {
    /**
     * WordPress User Query Class Stub
     */
    class WP_User_Query
    {
        private array $args;
        private array $results = [];
        private int $total = 0;

        public function __construct(array $args = [])
        {
            $this->args = $args;
            $this->runQuery();
        }

        private function runQuery(): void
        {
            global $_test_users, $_test_user_meta;

            if (empty($_test_users)) {
                return;
            }

            $results = [];
            $metaKey = $this->args['meta_key'] ?? null;
            $metaValue = $this->args['meta_value'] ?? null;

            foreach ($_test_users as $user) {
                // Filter by meta if specified
                if ($metaKey && $metaValue !== null) {
                    $userMeta = $_test_user_meta[$user->ID][$metaKey][0] ?? null;
                    if ($userMeta != $metaValue) {
                        continue;
                    }
                }

                // Return ID only if requested
                if (($this->args['fields'] ?? null) === 'ID') {
                    $results[] = $user->ID;
                } else {
                    $results[] = $user;
                }
            }

            $this->total = count($results);

            // Apply pagination
            $number = $this->args['number'] ?? -1;
            $paged = $this->args['paged'] ?? 1;

            if ($number > 0) {
                $offset = ($paged - 1) * $number;
                $results = array_slice($results, $offset, $number);
            }

            $this->results = $results;
        }

        public function get_results(): array
        {
            return $this->results;
        }

        public function get_total(): int
        {
            return $this->total;
        }
    }
}

if (!function_exists('register_rest_route')) {
    function register_rest_route(string $namespace, string $route, array $args): bool
    {
        global $_test_rest_routes;
        if (!isset($_test_rest_routes)) {
            $_test_rest_routes = [];
        }
        $_test_rest_routes[$namespace . $route] = $args;
        return true;
    }
}

if (!function_exists('get_userdata')) {
    function get_userdata(int $userId)
    {
        global $_test_users;
        return $_test_users[$userId] ?? false;
    }
}

if (!function_exists('wp_create_user')) {
    function wp_create_user(string $username, string $password, string $email = '')
    {
        global $_test_users;
        static $nextId = 100;

        // Check if email already exists
        foreach ($_test_users as $user) {
            if ($user->user_email === $email) {
                return new WP_Error('existing_user_email', 'Email already exists');
            }
        }

        $userId = $nextId++;
        $_test_users[$userId] = (object) [
            'ID' => $userId,
            'user_login' => $username,
            'user_email' => $email ?: $username,
            'first_name' => '',
            'last_name' => '',
            'display_name' => $username,
            'roles' => ['subscriber'],
            'user_registered' => date('Y-m-d H:i:s'),
        ];

        return $userId;
    }
}

if (!function_exists('wp_generate_password')) {
    function wp_generate_password(int $length = 12, bool $special_chars = true, bool $extra_special_chars = false): string
    {
        return bin2hex(random_bytes($length / 2));
    }
}

if (!function_exists('current_time')) {
    function current_time(string $type = 'mysql'): string
    {
        return $type === 'mysql' ? date('Y-m-d H:i:s') : date('U');
    }
}

// Data Manager mock storage
global $_test_data_manager_meta;
$_test_data_manager_meta = [];

if (!function_exists('ntdst_data')) {
    function ntdst_data()
    {
        return new class {
            public function register($type, $args = [])
            {
                // Mirror real Data Manager behavior: register post type if label is set
                if (isset($args['label'])) {
                    register_post_type($type, $args);
                }
            }

            public function get($type)
            {
                return new class($type) {
                    private string $postType;
                    private array $whereConditions = [];
                    private array $whereNotConditions = [];
                    private bool $includeMeta = false;
                    private ?int $limitValue = null;

                    public function __construct(string $postType)
                    {
                        $this->postType = $postType;
                    }

                    public function getMeta(int $postId, string $key, mixed $default = null): mixed
                    {
                        global $_test_data_manager_meta;
                        return $_test_data_manager_meta[$this->postType][$postId][$key] ?? $default;
                    }

                    public function updateMetaBatch(int $postId, array $data): bool
                    {
                        global $_test_data_manager_meta;
                        if (!isset($_test_data_manager_meta[$this->postType])) {
                            $_test_data_manager_meta[$this->postType] = [];
                        }
                        if (!isset($_test_data_manager_meta[$this->postType][$postId])) {
                            $_test_data_manager_meta[$this->postType][$postId] = [];
                        }
                        foreach ($data as $key => $value) {
                            $_test_data_manager_meta[$this->postType][$postId][$key] = $value;
                        }
                        return true;
                    }

                    public function find(int $postId): \WP_Post|\WP_Error|null
                    {
                        global $_test_posts, $_test_data_manager_meta;
                        $post = $_test_posts[$postId] ?? null;
                        if (!$post) {
                            return new \WP_Error('not_found', 'Post not found');
                        }
                        // Return WP_Post with fields attached
                        $wpPost = new \WP_Post((array) $post);
                        $wpPost->fields = $_test_data_manager_meta[$this->postType][$postId] ?? [];
                        $wpPost->meta = $_test_data_manager_meta[$this->postType][$postId] ?? [];
                        return $wpPost;
                    }

                    public function where(string $field, $value): self
                    {
                        $this->whereConditions[$field] = $value;
                        return $this;
                    }

                    public function whereNot(string $field, $value): self
                    {
                        $this->whereNotConditions[$field] = $value;
                        return $this;
                    }

                    public function withMeta(): self
                    {
                        $this->includeMeta = true;
                        return $this;
                    }

                    public function limit(int $limit): self
                    {
                        $this->limitValue = $limit;
                        return $this;
                    }

                    public function orderBy(string $field, string $direction = 'ASC'): self
                    {
                        // Stub: sorting is handled at the result level in tests
                        // Real implementation would store and apply sorting
                        return $this;
                    }

                    public function first(): ?object
                    {
                        $results = $this->get();
                        if (empty($results)) {
                            return null;
                        }
                        // Return first result as object (stdClass)
                        return (object) $results[0];
                    }

                    public function get(): array
                    {
                        global $_test_posts, $_test_data_manager_meta;

                        $results = [];
                        foreach ($_test_posts as $post) {
                            // Filter by post_type
                            if (($post->post_type ?? '') !== $this->postType) {
                                continue;
                            }

                            // Apply where conditions
                            $match = true;
                            foreach ($this->whereConditions as $field => $value) {
                                // Check if it's a core field
                                if (property_exists($post, $field)) {
                                    if ($post->$field !== $value) {
                                        $match = false;
                                        break;
                                    }
                                } else {
                                    // It's a meta field
                                    $metaValue = $_test_data_manager_meta[$this->postType][$post->ID][$field] ?? '';
                                    if ($metaValue !== $value) {
                                        $match = false;
                                        break;
                                    }
                                }
                            }

                            // Apply whereNot conditions
                            foreach ($this->whereNotConditions as $field => $value) {
                                if (property_exists($post, $field)) {
                                    if ($post->$field === $value) {
                                        $match = false;
                                        break;
                                    }
                                } else {
                                    // It's a meta field
                                    $metaValue = $_test_data_manager_meta[$this->postType][$post->ID][$field] ?? '';
                                    if ($metaValue === $value) {
                                        $match = false;
                                        break;
                                    }
                                }
                            }

                            if ($match) {
                                $result = (array) $post;
                                if ($this->includeMeta) {
                                    $result['fields'] = $_test_data_manager_meta[$this->postType][$post->ID] ?? [];
                                    $result['meta'] = $_test_data_manager_meta[$this->postType][$post->ID] ?? [];
                                }
                                $results[] = $result;
                            }
                        }

                        // Apply limit if set
                        if ($this->limitValue !== null && $this->limitValue > 0) {
                            $results = array_slice($results, 0, $this->limitValue);
                        }

                        // Reset query state
                        $this->whereConditions = [];
                        $this->whereNotConditions = [];
                        $this->includeMeta = false;
                        $this->limitValue = null;

                        return $results;
                    }

                    public function create(array $data): \WP_Post|\WP_Error
                    {
                        global $_test_posts, $_test_data_manager_meta;
                        static $nextId = 10000;

                        $postId = $data['ID'] ?? $nextId++;
                        $post = new \WP_Post([
                            'ID' => $postId,
                            'post_type' => $this->postType,
                            'post_title' => $data['title'] ?? $data['post_title'] ?? '',
                            'post_content' => $data['content'] ?? $data['post_content'] ?? '',
                            'post_status' => $data['post_status'] ?? 'draft',
                            'post_name' => $data['post_name'] ?? sanitize_title($data['title'] ?? ''),
                        ]);

                        $_test_posts[$postId] = $post;

                        // Extract meta fields
                        $metaFields = array_diff_key($data, array_flip([
                            'ID', 'title', 'post_title', 'content', 'post_content',
                            'post_status', 'post_name', 'post_type'
                        ]));

                        if (!empty($metaFields)) {
                            if (!isset($_test_data_manager_meta[$this->postType])) {
                                $_test_data_manager_meta[$this->postType] = [];
                            }
                            $_test_data_manager_meta[$this->postType][$postId] = $metaFields;
                        }

                        $post->fields = $_test_data_manager_meta[$this->postType][$postId] ?? [];
                        $post->meta = $post->fields;

                        return $post;
                    }

                    public function update(int $id, array $data): \WP_Post|\WP_Error
                    {
                        global $_test_posts, $_test_data_manager_meta;

                        if (!isset($_test_posts[$id])) {
                            return new \WP_Error('not_found', 'Post not found');
                        }

                        $post = $_test_posts[$id];

                        // Update core fields
                        foreach (['post_title', 'post_content', 'post_status', 'post_name'] as $field) {
                            if (isset($data[$field])) {
                                $post->$field = $data[$field];
                            }
                        }
                        // Also accept 'title', 'content' shortcuts
                        if (isset($data['title'])) {
                            $post->post_title = $data['title'];
                        }
                        if (isset($data['content'])) {
                            $post->post_content = $data['content'];
                        }

                        // Update meta fields
                        $metaFields = array_diff_key($data, array_flip([
                            'ID', 'title', 'post_title', 'content', 'post_content',
                            'post_status', 'post_name', 'post_type'
                        ]));

                        if (!empty($metaFields)) {
                            if (!isset($_test_data_manager_meta[$this->postType])) {
                                $_test_data_manager_meta[$this->postType] = [];
                            }
                            if (!isset($_test_data_manager_meta[$this->postType][$id])) {
                                $_test_data_manager_meta[$this->postType][$id] = [];
                            }
                            $_test_data_manager_meta[$this->postType][$id] = array_merge(
                                $_test_data_manager_meta[$this->postType][$id],
                                $metaFields
                            );
                        }

                        // Return as WP_Post
                        $wpPost = new \WP_Post((array) $post);
                        $wpPost->fields = $_test_data_manager_meta[$this->postType][$id] ?? [];
                        $wpPost->meta = $wpPost->fields;

                        return $wpPost;
                    }

                    public function delete(int $id, bool $force = false): bool|\WP_Error
                    {
                        global $_test_posts, $_test_data_manager_meta;

                        if (!isset($_test_posts[$id])) {
                            return new \WP_Error('not_found', 'Post not found');
                        }

                        if ($force) {
                            unset($_test_posts[$id]);
                            unset($_test_data_manager_meta[$this->postType][$id]);
                        } else {
                            $_test_posts[$id]->post_status = 'trash';
                        }

                        return true;
                    }
                };
            }
        };
    }
}

// Site info functions
if (!function_exists('get_bloginfo')) {
    function get_bloginfo(string $show = '', string $filter = 'raw')
    {
        global $_test_options;

        return match ($show) {
            'name' => $_test_options['blogname'] ?? 'Test Site',
            'url', 'wpurl', 'home' => $_test_options['siteurl'] ?? 'https://example.com',
            'admin_email' => $_test_options['admin_email'] ?? 'admin@example.com',
            default => '',
        };
    }
}

if (!function_exists('home_url')) {
    function home_url(string $path = '', ?string $scheme = null): string
    {
        global $_test_options;
        $url = $_test_options['home'] ?? 'https://example.com';
        return rtrim($url, '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('wp_date')) {
    function wp_date(string $format, ?int $timestamp = null, ?\DateTimeZone $timezone = null): string|false
    {
        $timestamp = $timestamp ?? time();
        return date($format, $timestamp);
    }
}

// ntdst_mail fluent mailer stub
if (!function_exists('ntdst_mail')) {
    function ntdst_mail()
    {
        return new class {
            private string $to = '';
            private string $subject = '';
            private string $body = '';
            private array $cc = [];
            private array $bcc = [];
            private array $attachments = [];

            public function to(string $email): self
            {
                $this->to = $email;
                return $this;
            }

            public function subject(string $subject): self
            {
                $this->subject = $subject;
                return $this;
            }

            public function html(string $body): self
            {
                $this->body = $body;
                return $this;
            }

            public function cc(string|array $emails): self
            {
                $this->cc = is_array($emails) ? $emails : [$emails];
                return $this;
            }

            public function bcc(string|array $emails): self
            {
                $this->bcc = is_array($emails) ? $emails : [$emails];
                return $this;
            }

            public function attach(string $path): self
            {
                $this->attachments[] = $path;
                return $this;
            }

            public function send(): bool|\WP_Error
            {
                // Store the sent mail for testing assertions
                global $_test_sent_mails;
                if (!isset($_test_sent_mails)) {
                    $_test_sent_mails = [];
                }

                $_test_sent_mails[] = [
                    'to' => $this->to,
                    'subject' => $this->subject,
                    'body' => $this->body,
                    'cc' => $this->cc,
                    'bcc' => $this->bcc,
                    'attachments' => $this->attachments,
                ];

                return true;
            }
        };
    }
}

// Attachment file storage for testing
global $_test_attached_files;
$_test_attached_files = [];

if (!function_exists('get_attached_file')) {
    function get_attached_file($attachment_id, $unfiltered = false)
    {
        global $_test_attached_files;
        return $_test_attached_files[$attachment_id] ?? false;
    }
}

// Admin menu functions
if (!function_exists('add_submenu_page')) {
    function add_submenu_page($parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null)
    {
        global $submenu;
        if (!isset($submenu[$parent_slug])) {
            $submenu[$parent_slug] = [];
        }
        $submenu[$parent_slug][] = [$menu_title, $capability, $menu_slug, $page_title];
        return $menu_slug;
    }
}

if (!function_exists('add_meta_box')) {
    function add_meta_box($id, $title, $callback, $screen = null, $context = 'advanced', $priority = 'default', $callback_args = null)
    {
        global $wp_meta_boxes;
        if (!isset($wp_meta_boxes[$screen])) {
            $wp_meta_boxes[$screen] = [];
        }
        if (!isset($wp_meta_boxes[$screen][$context])) {
            $wp_meta_boxes[$screen][$context] = [];
        }
        if (!isset($wp_meta_boxes[$screen][$context][$priority])) {
            $wp_meta_boxes[$screen][$context][$priority] = [];
        }
        $wp_meta_boxes[$screen][$context][$priority][$id] = [
            'id' => $id,
            'title' => $title,
            'callback' => $callback,
            'args' => $callback_args,
        ];
    }
}

// Asset enqueueing functions
if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style($handle, $src = '', $deps = [], $ver = false, $media = 'all')
    {
        global $wp_styles;
        if (!isset($wp_styles)) {
            $wp_styles = [];
        }
        $wp_styles[$handle] = ['src' => $src, 'deps' => $deps, 'ver' => $ver, 'media' => $media, 'enqueued' => true];
    }
}

if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script($handle, $src = '', $deps = [], $ver = false, $in_footer = false)
    {
        global $wp_scripts;
        if (!isset($wp_scripts)) {
            $wp_scripts = [];
        }
        $wp_scripts[$handle] = ['src' => $src, 'deps' => $deps, 'ver' => $ver, 'in_footer' => $in_footer, 'enqueued' => true];
    }
}

if (!function_exists('wp_localize_script')) {
    function wp_localize_script($handle, $object_name, $l10n)
    {
        global $wp_scripts;
        if (isset($wp_scripts[$handle])) {
            $wp_scripts[$handle]['l10n'][$object_name] = $l10n;
        }
        return true;
    }
}

if (!function_exists('wp_script_is')) {
    function wp_script_is($handle, $status = 'enqueued')
    {
        global $wp_scripts;
        return isset($wp_scripts[$handle]) && ($status === 'enqueued' ? ($wp_scripts[$handle]['enqueued'] ?? false) : true);
    }
}

if (!function_exists('wp_enqueue_media')) {
    function wp_enqueue_media($args = [])
    {
        // Stub: WordPress media library enqueue
        return true;
    }
}

if (!function_exists('wp_style_is')) {
    function wp_style_is($handle, $status = 'enqueued')
    {
        global $wp_styles;
        return isset($wp_styles[$handle]) && ($status === 'enqueued' ? ($wp_styles[$handle]['enqueued'] ?? false) : true);
    }
}

if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $echo = true)
    {
        $nonce = wp_create_nonce($action);
        $field = '<input type="hidden" id="' . $name . '" name="' . $name . '" value="' . $nonce . '" />';
        if ($echo) {
            echo $field;
        }
        return $field;
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1)
    {
        return 'test_nonce_' . md5((string)$action);
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action = -1)
    {
        // In tests, always return true for valid-looking nonces
        return strpos($nonce, 'test_nonce_') === 0 ? 1 : false;
    }
}

if (!function_exists('submit_button')) {
    function submit_button($text = null, $type = 'primary', $name = 'submit', $wrap = true, $other_attributes = null)
    {
        echo '<input type="submit" name="' . esc_attr($name) . '" id="' . esc_attr($name) . '" class="button button-' . esc_attr($type) . '" value="' . esc_attr($text ?? 'Save Changes') . '" />';
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return esc_html($text);
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text): string
    {
        return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('has_action')) {
    function has_action($hook, $callback = false)
    {
        global $_test_actions;
        if (!isset($_test_actions[$hook])) {
            return false;
        }
        if ($callback === false) {
            return true;
        }
        foreach ($_test_actions[$hook] as $action) {
            if ($action['callback'] === $callback) {
                return $action['priority'];
            }
        }
        return false;
    }
}

// URL functions
if (!function_exists('esc_url_raw')) {
    function esc_url_raw(string $url, $protocols = null): string
    {
        return filter_var($url, FILTER_SANITIZE_URL) ?: '';
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg(...$args): string
    {
        if (count($args) === 2 && is_array($args[0])) {
            $params = $args[0];
            $url = $args[1];
        } elseif (count($args) === 3) {
            $params = [$args[0] => $args[1]];
            $url = $args[2];
        } elseif (count($args) === 1 && is_array($args[0])) {
            $params = $args[0];
            $url = '';
        } else {
            return '';
        }

        $parsed = parse_url($url);
        $query = [];
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $query);
        }
        $query = array_merge($query, $params);

        $result = '';
        if (!empty($parsed['scheme'])) {
            $result .= $parsed['scheme'] . '://';
        }
        if (!empty($parsed['host'])) {
            $result .= $parsed['host'];
        }
        if (!empty($parsed['port'])) {
            $result .= ':' . $parsed['port'];
        }
        if (!empty($parsed['path'])) {
            $result .= $parsed['path'];
        }
        if (!empty($query)) {
            $result .= '?' . http_build_query($query);
        }
        if (!empty($parsed['fragment'])) {
            $result .= '#' . $parsed['fragment'];
        }

        return $result;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, int $options = 0, int $depth = 512): string|false
    {
        return json_encode($data, $options, $depth);
    }
}

if (!function_exists('wp_redirect')) {
    function wp_redirect(string $location, int $status = 302, string $x_redirect_by = 'WordPress'): bool
    {
        global $_test_redirect_url, $_test_redirect_status;
        $_test_redirect_url = $location;
        $_test_redirect_status = $status;

        throw new \RuntimeException('Redirect to: ' . $location);
    }
}

if (!function_exists('wp_die')) {
    function wp_die($message = '', $title = '', $args = []): void
    {
        global $_test_wp_die_called;
        $_test_wp_die_called = [
            'message' => $message,
            'title' => $title,
            'args' => $args,
        ];

        throw new \RuntimeException($message);
    }
}

// Rewrite rules storage for testing
global $wp_rewrite;
if (!isset($wp_rewrite)) {
    $wp_rewrite = new class {
        public array $extra_rules_top = [];
        public array $extra_permastructs = [];

        public function flush_rules(): void
        {
            // No-op in tests
        }
    };
}

if (!function_exists('add_rewrite_rule')) {
    function add_rewrite_rule(string $regex, string $query, string $after = 'bottom'): void
    {
        global $wp_rewrite;
        if ($after === 'top') {
            $wp_rewrite->extra_rules_top[$regex] = $query;
        }
    }
}

// Query vars storage for testing
global $_test_query_vars;
$_test_query_vars = [];

if (!function_exists('get_query_var')) {
    function get_query_var(string $var, $default = '')
    {
        global $_test_query_vars;
        return $_test_query_vars[$var] ?? $default;
    }
}

if (!function_exists('set_query_var')) {
    function set_query_var(string $var, $value): void
    {
        global $_test_query_vars;
        if (!isset($_test_query_vars)) {
            $_test_query_vars = [];
        }
        $_test_query_vars[$var] = $value;
    }
}

// get_users stub for searching users by meta
if (!function_exists('get_users')) {
    function get_users(array $args = []): array
    {
        global $_test_users, $_test_user_meta;

        if (empty($_test_users)) {
            return [];
        }

        $results = [];
        $metaKey = $args['meta_key'] ?? null;
        $metaValue = $args['meta_value'] ?? null;
        $number = $args['number'] ?? -1;

        foreach ($_test_users as $user) {
            // Filter by meta if specified
            if ($metaKey !== null && $metaValue !== null) {
                $userMeta = $_test_user_meta[$user->ID][$metaKey][0] ?? null;
                if ($userMeta != $metaValue) {
                    continue;
                }
            }

            $results[] = $user;

            // Apply limit
            if ($number > 0 && count($results) >= $number) {
                break;
            }
        }

        return $results;
    }
}

// Shortcode functions
global $_test_shortcodes;
$_test_shortcodes = [];

if (!function_exists('add_shortcode')) {
    function add_shortcode(string $tag, callable $callback): void
    {
        global $_test_shortcodes;
        $_test_shortcodes[$tag] = $callback;
    }
}

if (!function_exists('shortcode_atts')) {
    function shortcode_atts(array $pairs, array|string $atts, string $shortcode = ''): array
    {
        $atts = (array) $atts;
        $out = [];
        foreach ($pairs as $name => $default) {
            if (array_key_exists($name, $atts)) {
                $out[$name] = $atts[$name];
            } else {
                $out[$name] = $default;
            }
        }
        return $out;
    }
}

if (!function_exists('wp_unique_id')) {
    function wp_unique_id(string $prefix = ''): string
    {
        static $counter = 0;
        return $prefix . (string) ++$counter;
    }
}
