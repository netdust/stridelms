<?php
/**
 * PHPUnit Bootstrap for netdust-lti plugin tests
 *
 * Loads the main project's test infrastructure.
 */

// Load the main project's bootstrap which includes all stubs
// Path: tests -> netdust-lti -> plugins -> app -> web -> stride
require_once dirname(__DIR__, 5) . '/tests/bootstrap.php';

// Add rewrite rules stub
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

// add_rewrite_rule stub
if (!function_exists('add_rewrite_rule')) {
    function add_rewrite_rule(string $regex, string $query, string $after = 'bottom'): void
    {
        global $wp_rewrite;
        if ($after === 'top') {
            $wp_rewrite->extra_rules_top[$regex] = $query;
        }
    }
}

// get_query_var stub
if (!function_exists('get_query_var')) {
    function get_query_var(string $var, $default = '')
    {
        global $_test_query_vars;
        return $_test_query_vars[$var] ?? $default;
    }
}

// set_query_var helper for tests
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

// wp_die stub for tests
if (!function_exists('wp_die')) {
    function wp_die($message = '', $title = '', $args = []): void
    {
        global $_test_wp_die_called;
        $_test_wp_die_called = [
            'message' => $message,
            'title' => $title,
            'args' => $args,
        ];

        // Throw exception so test can catch it
        throw new \RuntimeException($message);
    }
}

// esc_url_raw stub
if (!function_exists('esc_url_raw')) {
    function esc_url_raw(string $url, $protocols = null): string
    {
        // Basic URL sanitization
        return filter_var($url, FILTER_SANITIZE_URL) ?: '';
    }
}

// wp_json_encode stub
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, int $options = 0, int $depth = 512): string|false
    {
        return json_encode($data, $options, $depth);
    }
}

// add_query_arg stub
if (!function_exists('add_query_arg')) {
    function add_query_arg(...$args): string
    {
        if (count($args) === 2 && is_array($args[0])) {
            // add_query_arg(array $args, string $url)
            $params = $args[0];
            $url = $args[1];
        } elseif (count($args) === 3) {
            // add_query_arg(string $key, string $value, string $url)
            $params = [$args[0] => $args[1]];
            $url = $args[2];
        } elseif (count($args) === 1 && is_array($args[0])) {
            // add_query_arg(array $args) - uses current URL
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

// wp_redirect stub
if (!function_exists('wp_redirect')) {
    function wp_redirect(string $location, int $status = 302, string $x_redirect_by = 'WordPress'): bool
    {
        global $_test_redirect_url, $_test_redirect_status;
        $_test_redirect_url = $location;
        $_test_redirect_status = $status;

        // Throw an exception to simulate the exit that normally follows
        throw new \RuntimeException('Redirect to: ' . $location);
    }
}

// user_can stub for capability checks
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

// get_current_blog_id stub for multisite
if (!function_exists('get_current_blog_id')) {
    function get_current_blog_id(): int
    {
        return 1; // Single site default
    }
}

// wp_get_current_user stub
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

// Add exists() method to WP_User if needed
// (The stub should already have this but let's make sure the ID check works)

// wp_insert_user stub
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
        $user->first_name = $userdata['first_name'] ?? '';
        $user->last_name = $userdata['last_name'] ?? '';
        $user->roles = isset($userdata['role']) ? [$userdata['role']] : ['subscriber'];

        $_test_users[$userId] = $user;

        return $userId;
    }
}

// wp_parse_url stub
if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1)
    {
        return parse_url($url, $component);
    }
}

// esc_url stub
if (!function_exists('esc_url')) {
    function esc_url(string $url, ?array $protocols = null, string $_context = 'display'): string
    {
        return filter_var($url, FILTER_SANITIZE_URL) ?: '';
    }
}

// username_exists stub
if (!function_exists('username_exists')) {
    function username_exists(string $username)
    {
        global $_test_users;
        if (!isset($_test_users)) {
            return false;
        }
        foreach ($_test_users as $user) {
            if ($user->user_login === $username) {
                return $user->ID;
            }
        }
        return false;
    }
}

// sanitize_user stub
if (!function_exists('sanitize_user')) {
    function sanitize_user(string $username, bool $strict = false): string
    {
        if ($strict) {
            $username = preg_replace('/[^a-zA-Z0-9 _.\-@]/', '', $username);
        }
        return trim($username);
    }
}

// $wpdb stub for UserProvisioner (findByLtiSub uses direct queries)
global $wpdb;
if (!isset($wpdb) || !is_object($wpdb)) {
    $wpdb = new class {
        public string $usermeta = 'wp_usermeta';
        public string $prefix = 'wp_';
        private ?string $mockResult = null;

        /**
         * Set mock result for the next get_var call (used in tests)
         */
        public function setMockResult(?string $result): void
        {
            $this->mockResult = $result;
        }

        public function prepare(string $query, ...$args): string
        {
            $prepared = $query;
            foreach ($args as $arg) {
                $prepared = preg_replace('/%[sd]/', is_string($arg) ? "'" . addslashes($arg) . "'" : (string) $arg, $prepared, 1);
            }
            return $prepared;
        }

        public function get_var(?string $query = null, int $x = 0, int $y = 0): ?string
        {
            // Check user meta for LTI sub lookups
            global $_test_user_meta;
            if ($query && isset($_test_user_meta) && str_contains($query, '_netdust_lti_sub')) {
                foreach ($_test_user_meta as $userId => $metas) {
                    if (isset($metas['_netdust_lti_sub'])) {
                        $storedValue = $metas['_netdust_lti_sub'][0] ?? null;
                        if ($storedValue && str_contains($query, addslashes($storedValue))) {
                            return (string) $userId;
                        }
                    }
                }
            }
            return $this->mockResult;
        }
    };
}

// Plugin autoloader
spl_autoload_register(function (string $class): void {
    $prefix = 'NetdustLTI\\';
    $baseDir = dirname(__DIR__) . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});
