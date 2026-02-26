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
