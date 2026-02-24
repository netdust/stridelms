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
        return true; // Allow everything in tests by default
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
            return $_test_posts[$post] ?? null;
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

// Data Manager mock storage
global $_test_data_manager_meta;
$_test_data_manager_meta = [];

if (!function_exists('ntdst_data')) {
    function ntdst_data()
    {
        return new class {
            public function register($type, $args = []) {}

            public function get($type)
            {
                return new class($type) {
                    private string $postType;

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

                    public function find(int $postId): ?object
                    {
                        global $_test_posts, $_test_data_manager_meta;
                        $post = $_test_posts[$postId] ?? null;
                        if (!$post) {
                            return null;
                        }
                        $meta = $_test_data_manager_meta[$this->postType][$postId] ?? [];
                        return (object) array_merge(
                            (array) $post,
                            ['fields' => $meta]
                        );
                    }
                };
            }
        };
    }
}
