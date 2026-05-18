<?php
declare(strict_types=1);

/**
 * NTDST Router - Minimal URL routing
 * Maps URL patterns to callables with WordPress template integration
 *
 * Usage:
 *
 * // Simple route
 * ntdst_route('/projects/:slug', function($params) {
 *     $project = get_post($params['slug']);
 *     return ntdst_response()->with('project', $project)->template('project/single');
 * });
 *
 * // With specific template type
 * ntdst_router()->single('project', function($post) {
 *     return ntdst_response()->with('project', $post)->template('project/detail');
 * });
 *
 * // With conditions
 * ntdst_router()->when(fn() => is_singular('project'), function($post) {
 *     // Custom handling
 * });
 */

defined('ABSPATH') || exit;

class NTDST_Router
{
    protected array $routes = [];
    protected array $template_hooks = [];

    public function __construct()
    {
        add_filter('redirect_canonical', [$this, 'preventRedirectForRoutes'], 10, 2);
        add_filter('template_include', [$this, 'handleTemplateInclude'], 999);
    }

    /**
     * Prevent WordPress from redirecting URLs that match our routes
     */
    public function preventRedirectForRoutes(string|false $redirect_url, ?string $requested_url = null): string|false
    {
        // Check both current URL and redirect target. Guards: $_SERVER keys
        // can be missing under CLI/test SAPIs, and parse_url returns null on
        // malformed URLs.
        $urls_to_check = [
            trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '', '/'),
        ];

        if ($redirect_url) {
            $urls_to_check[] = trim(parse_url($redirect_url, PHP_URL_PATH) ?? '', '/');
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            foreach ($urls_to_check as $url) {
                if (preg_match($route['regex'], $url)) {
                    return false;
                }
            }
        }

        return $redirect_url;
    }

    /**
     * Register a URL pattern route.
     *
     * The callback receives (array $params, string $template) — $params holds
     * the named URL placeholders. Query-string parameters are NOT passed;
     * callbacks must read $_GET directly when needed.
     *
     * Return contract:
     *  - string (existing file path) → used as the resolved template
     *  - null  → callback handled output itself; the request is exited
     *  - true  → same as null
     *  - false → fall through to the next matching route
     *  - anything else → ignored, original $template is returned
     *
     * @param string $pattern URL pattern (/path/:param/:id)
     * @param callable $callback Handler function
     * @param string $method HTTP method (GET, POST, etc.)
     */
    public function register(string $pattern, callable $callback, string $method = 'GET'): self
    {
        $regex = $this->compilePattern($pattern);

        $this->routes[] = [
            'pattern' => $pattern,
            'regex' => $regex,
            'callback' => $callback,
            'method' => strtoupper($method),
        ];

        return $this;
    }

    /**
     * Register GET route
     */
    public function get(string $pattern, callable $callback): self
    {
        return $this->register($pattern, $callback, 'GET');
    }

    /**
     * Register POST route
     */
    public function post(string $pattern, callable $callback): self
    {
        return $this->register($pattern, $callback, 'POST');
    }

    /**
     * Hook into specific WordPress template type
     * Smart wrapper around {$type}_template filters
     *
     * @param string $type Template type (single, page, archive, etc.)
     * @param callable $callback Handler receives $post or $template
     * @param string|null $post_type Optional post type to filter
     */
    public function template(string $type, callable $callback, ?string $post_type = null): self
    {
        $hook = $type . '_template';

        // Store for smart filtering
        $this->template_hooks[] = [
            'type' => $type,
            'hook' => $hook,
            'callback' => $callback,
            'post_type' => $post_type,
        ];

        add_filter($hook, function ($template) use ($callback, $post_type) {
            // Filter by post type if specified
            if ($post_type && get_post_type() !== $post_type) {
                return $template;
            }

            global $post;
            $result = $callback($post, $template);

            // If string returned, use as template path
            if (is_string($result)) {
                return $result;
            }

            // If Response object, render it
            if ($result instanceof NTDST_Response) {
                $template_name = $result->getTemplate();
                if ($template_name) {
                    $result->render($template_name);
                }
                exit;
            }

            return $template;
        }, 10, 1);

        return $this;
    }

    /**
     * Shorthand for single template
     */
    public function single(?string $post_type = null, ?callable $callback = null): self
    {
        if ($callback === null && is_callable($post_type)) {
            $callback = $post_type;
            $post_type = null;
        }

        return $this->template('single', $callback, $post_type);
    }

    /**
     * Shorthand for page template
     */
    public function page(string|callable $slug_or_callback, ?callable $callback = null): self
    {
        // page('about', fn() => ...) or page(fn() => ...)
        if (is_callable($slug_or_callback)) {
            return $this->template('page', $slug_or_callback);
        }

        return $this->template('page', function ($post) use ($slug_or_callback, $callback) {
            if ($post->post_name === $slug_or_callback) {
                return $callback($post);
            }
        });
    }

    /**
     * Shorthand for archive template
     */
    public function archive(?string $post_type = null, ?callable $callback = null): self
    {
        if ($callback === null && is_callable($post_type)) {
            $callback = $post_type;
            $post_type = null;
        }

        return $this->template('archive', $callback, $post_type);
    }

    /**
     * Conditional route - executes when condition is true.
     *
     * Note: every call to when() registers a new template_include filter.
     * Call it once per condition; do not invoke in a loop.
     *
     * Callback receives (?WP_Post $post, string $template). See register()
     * for the return-value contract.
     */
    public function when(callable $condition, callable $callback): self
    {
        add_filter('template_include', function ($template) use ($condition, $callback) {
            if (!$condition()) {
                return $template;
            }

            global $post;
            $result = $callback($post, $template);

            // Return string template path
            if (is_string($result)) {
                return $result;
            }

            // Handle Response object
            if ($result instanceof NTDST_Response) {
                $template_name = $result->getTemplate();
                if ($template_name) {
                    $result->render($template_name);
                }
                exit;
            }

            return $template;
        }, 10);

        return $this;
    }

    /**
     * Handle template_include filter
     * Matches URL patterns and executes callbacks
     */
    public function handleTemplateInclude(string $template): string
    {
        // $_SERVER keys can be missing under CLI/test SAPIs; default safely.
        $url = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '', '/');
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        foreach ($this->routes as $route) {
            // Check method
            if ($route['method'] !== $method) {
                continue;
            }

            // Try to match pattern
            if (preg_match($route['regex'], $url, $matches)) {
                // Extract named parameters
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                // Reset 404 state — WordPress marked this as 404 before routing
                global $wp_query;
                if ($wp_query && $wp_query->is_404()) {
                    $wp_query->is_404 = false;
                    status_header(200);
                }

                // Execute callback
                $result = call_user_func($route['callback'], $params, $template);

                // If string, use as template
                if (is_string($result) && file_exists($result)) {
                    return $result;
                }

                // If null/true, assume callback handled output
                if ($result === null || $result === true) {
                    exit;
                }

                // Continue to next route if false
                if ($result === false) {
                    continue;
                }
            }
        }

        return $template;
    }

    /**
     * Compile URL pattern to regex.
     *
     * Converts /path/:param/:id to regex with named groups. Literal segments
     * are preg_quote'd so dots/plus-signs/parens in the URL pattern aren't
     * treated as regex metacharacters (e.g. "v1.0/users" matches that path
     * literally, not "v1X0/users").
     */
    protected function compilePattern(string $pattern): string
    {
        $pattern = trim($pattern, '/');

        // Split on :param placeholders while keeping them via PREG_SPLIT_DELIM_CAPTURE.
        $tokens = preg_split('/(:[a-zA-Z_][a-zA-Z0-9_]*)/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE);

        $regex = '';
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if ($token[0] === ':') {
                $name = substr($token, 1);
                $regex .= '(?P<' . $name . '>[^/]+)';
            } else {
                $regex .= preg_quote($token, '#');
            }
        }

        // Allow optional trailing slash
        return '#^' . $regex . '/?$#';
    }

    /**
     * Generate URL from pattern and parameters.
     *
     * Param values are urlencoded so slashes / spaces / hashes don't break
     * routing. Params that don't match a :placeholder in the pattern are
     * silently ignored (no query-string append).
     */
    public function url(string $pattern, array $params = []): string
    {
        $url = $pattern;

        foreach ($params as $key => $value) {
            $url = str_replace(':' . $key, urlencode((string) $value), $url);
        }

        return home_url($url);
    }

    /**
     * Redirect to URL.
     *
     * Uses wp_safe_redirect() by default — restricts the target to the same
     * host as the site, blocking open-redirect attacks when $url is derived
     * from user input. Pass $allowExternal=true only when the destination is
     * trusted and intentionally off-site.
     */
    public function redirect(string $url, int $status = 302, bool $allowExternal = false): never
    {
        if ($allowExternal) {
            wp_redirect($url, $status);
        } else {
            wp_safe_redirect($url, $status);
        }
        exit;
    }
}

/**
 * Global helper - get router instance (singleton)
 */
if (!function_exists('ntdst_router')) {
    function ntdst_router(): NTDST_Router
    {
        static $router = null;
        return $router ??= new NTDST_Router();
    }
}

/**
 * Quick route registration helper
 */
if (!function_exists('ntdst_route')) {
    function ntdst_route(string $pattern, callable $callback, string $method = 'GET'): NTDST_Router
    {
        return ntdst_router()->register($pattern, $callback, $method);
    }
}

// Initialize router early to register redirect prevention hook
add_action('init', 'ntdst_router', 1);
