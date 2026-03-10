<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

// ========================================
// THEME BOOTSTRAP CLASS
// ========================================

class NTDST_Theme
{
    private array $config;
    private array $mixins = [];

    public function __construct(array $config = [])
    {
        // configuration
        $this->config = $this->validate_config($config);

        // Register self as singleton in DI container
        ntdst_set(self::class, fn() => $this);

        // Wire up service mixins immediately
        $this->wireMixins();

        // Initialize theme
        $this->init();
    }

    /**
     * Wire up NTDST service instances as mixins
     * Called automatically in constructor
     */
    private function wireMixins(): void
    {
        $this
            ->mixin('data', ntdst_data())         // ORM: $theme->data()->get('model')->register()
            ->mixin('router', ntdst_router())     // Routing: $theme->router()->single()
            ->mixin('response', ntdst_response()) // Response: $theme->response()->json()
            ->mixin('log', ntdst_log())           // Logger: $theme->log()->info()
            ->mixin('mail', ntdst_mail());        // Mailer: $theme->mail()->to()->send()
    }

    private function init(): void
    {
        // Theme setup
        add_action('after_setup_theme', [$this, 'setup_theme']);
        // Asset enqueueing
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets'], 9999);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets'], 9999);
    }

    public function setup_theme(): void
    {
        // Load text domain for translations
        if (!empty($this->config['textdomain'])) {
            load_theme_textdomain(sanitize_key($this->config['textdomain']), get_template_directory() . '/languages');
        }

        // Set content width
        if (!isset($GLOBALS['content_width']) && !empty($this->config['content_width'])) {
            $GLOBALS['content_width'] = (int) $this->config['content_width'];
        }

        // Theme support
        foreach ($this->config['theme_support'] as $feature => $args) {
            if (is_bool($args)) {
                add_theme_support($feature);
            } else {
                add_theme_support($feature, $args);
            }
        }

        // Image sizes
        foreach ($this->config['image_sizes'] as $name => $settings) {
            add_image_size(sanitize_key($name), (int) $settings[0], (int) $settings[1], (bool) $settings[2]);
        }

        // Make image sizes selectable
        $this->filter('image_size_names_choose', function ($sizes) {
            $custom_sizes = [];
            foreach ($this->config['image_sizes'] as $name => $settings) {
                $custom_sizes[sanitize_key($name)] = sanitize_text_field($settings[3] ?? $name);
            }
            return array_merge($sizes, $custom_sizes);
        });

        // Register menus
        register_nav_menus(array_map(function ($desc) {
            return __($desc, $this->config['textdomain']);
        }, $this->config['menus']));

        // Register sidebars
        foreach ($this->config['sidebars'] as $sidebar) {
            register_sidebar([
                'name' => $sidebar['name'] ?? '',
                'id' => sanitize_key($sidebar['id'] ?? ''),
                'description' => $sidebar['description'] ?? '',
                'before_widget' => $sidebar['before_widget'] ?? '<div id="%1$s" class="widget %2$s">',
                'after_widget' => $sidebar['after_widget'] ?? '</div>',
                'before_title' => $sidebar['before_title'] ?? '<h2 class="widget-title">',
                'after_title' => $sidebar['after_title'] ?? '</h2>',
            ]);
        }

        // Excerpt settings
        $this->filter('excerpt_length', function () {
            return (int) $this->config['excerpt']['length'];
        }, 999);

        $this->filter('excerpt_more', function () {
            return sprintf($this->config['excerpt']['more'], esc_url(get_permalink()));
        });

        // Remove WordPress version
        $this->filter('the_generator', function () {
            return '';
        });
    }

    public function enqueue_assets(): void
    {
        // Allow filtering assets before enqueueing
        $assets = apply_filters('ntdst_theme_assets', $this->config['assets']);

        // Store script/style attributes for later filtering
        $script_attrs = [];
        $style_attrs = [];

        // Enqueue styles
        foreach ($assets['styles'] ?? [] as $handle => $asset) {
            // Skip if disabled
            if (isset($asset['enabled']) && !$asset['enabled']) {
                continue;
            }

            wp_enqueue_style(
                sanitize_key($handle),
                esc_url($asset['src']),
                array_map('sanitize_key', $asset['deps'] ?? []),
                esc_attr($asset['version'] ?? ''),
                esc_attr($asset['media'] ?? 'all'),
            );

            // Store attributes if provided
            if (!empty($asset['attrs'])) {
                $style_attrs[sanitize_key($handle)] = $asset['attrs'];
            }
        }

        // Enqueue scripts
        foreach ($assets['scripts'] ?? [] as $handle => $asset) {
            // Skip if disabled
            if (isset($asset['enabled']) && !$asset['enabled']) {
                continue;
            }

            wp_enqueue_script(
                sanitize_key($handle),
                esc_url($asset['src']),
                array_map('sanitize_key', $asset['deps'] ?? []),
                esc_attr($asset['version'] ?? ''),
                (bool) ($asset['in_footer'] ?? true),
            );

            // Store attributes if provided
            if (!empty($asset['attrs'])) {
                $script_attrs[sanitize_key($handle)] = $asset['attrs'];
            }
        }

        // Add script attributes via filter
        if (!empty($script_attrs)) {
            add_filter('script_loader_tag', function ($tag, $handle, $src) use ($script_attrs) {
                if (isset($script_attrs[$handle])) {
                    foreach ($script_attrs[$handle] as $attr => $value) {
                        // Boolean attributes (e.g., defer, async, nomodule)
                        if ($value === true || $value === '') {
                            $tag = str_replace(' src', ' ' . esc_attr($attr) . ' src', $tag);
                        }
                        // Attributes with values (e.g., type="module", crossorigin="anonymous")
                        else {
                            $tag = str_replace(' src', ' ' . esc_attr($attr) . '="' . esc_attr($value) . '" src', $tag);
                        }
                    }
                }
                return $tag;
            }, 10, 3);
        }

        // Add style attributes via filter
        if (!empty($style_attrs)) {
            add_filter('style_loader_tag', function ($html, $handle, $href, $media) use ($style_attrs) {
                if (isset($style_attrs[$handle])) {
                    foreach ($style_attrs[$handle] as $attr => $value) {
                        // Boolean attributes
                        if ($value === true || $value === '') {
                            $html = str_replace(' href', ' ' . esc_attr($attr) . ' href', $html);
                        }
                        // Attributes with values
                        else {
                            $html = str_replace(' href', ' ' . esc_attr($attr) . '="' . esc_attr($value) . '" href', $html);
                        }
                    }
                }
                return $html;
            }, 10, 4);
        }

        // Localize script with secure data (use different variable name to avoid conflicts)
        wp_localize_script('ntdst-theme', 'ntdstConfig', [
            'ajax_url' => esc_url(admin_url('admin-ajax.php')),
            'nonce' => wp_create_nonce('ntdst_nonce'),
        ]);
    }

    public function enqueue_admin_assets(): void
    {
        // Allow filtering assets before enqueueing
        $assets = apply_filters('ntdst_theme_assets', $this->config['assets']);

        // Store script/style attributes for later filtering
        $script_attrs = [];
        $style_attrs = [];

        // Enqueue styles marked for admin
        foreach ($assets['styles'] ?? [] as $handle => $asset) {
            // Skip if not for admin
            if (!isset($asset['admin']) || !$asset['admin']) {
                continue;
            }

            // Skip if disabled
            if (isset($asset['enabled']) && !$asset['enabled']) {
                continue;
            }

            wp_enqueue_style(
                sanitize_key($handle),
                esc_url($asset['src']),
                array_map('sanitize_key', $asset['deps'] ?? []),
                esc_attr($asset['version'] ?? ''),
                esc_attr($asset['media'] ?? 'all'),
            );

            // Store attributes if provided
            if (!empty($asset['attrs'])) {
                $style_attrs[sanitize_key($handle)] = $asset['attrs'];
            }
        }

        // Enqueue scripts marked for admin
        foreach ($assets['scripts'] ?? [] as $handle => $asset) {
            // Skip if not for admin
            if (!isset($asset['admin']) || !$asset['admin']) {
                continue;
            }

            // Skip if disabled
            if (isset($asset['enabled']) && !$asset['enabled']) {
                continue;
            }

            wp_enqueue_script(
                sanitize_key($handle),
                esc_url($asset['src']),
                array_map('sanitize_key', $asset['deps'] ?? []),
                esc_attr($asset['version'] ?? ''),
                (bool) ($asset['in_footer'] ?? true),
            );

            // Store attributes if provided
            if (!empty($asset['attrs'])) {
                $script_attrs[sanitize_key($handle)] = $asset['attrs'];
            }
        }

        // Add script attributes via filter (same as frontend)
        if (!empty($script_attrs)) {
            add_filter('script_loader_tag', function ($tag, $handle, $src) use ($script_attrs) {
                if (isset($script_attrs[$handle])) {
                    foreach ($script_attrs[$handle] as $attr => $value) {
                        if ($value === true || $value === '') {
                            $tag = str_replace(' src', ' ' . esc_attr($attr) . ' src', $tag);
                        } else {
                            $tag = str_replace(' src', ' ' . esc_attr($attr) . '="' . esc_attr($value) . '" src', $tag);
                        }
                    }
                }
                return $tag;
            }, 10, 3);
        }

        // Add style attributes via filter (same as frontend)
        if (!empty($style_attrs)) {
            add_filter('style_loader_tag', function ($html, $handle, $href, $media) use ($style_attrs) {
                if (isset($style_attrs[$handle])) {
                    foreach ($style_attrs[$handle] as $attr => $value) {
                        if ($value === true || $value === '') {
                            $html = str_replace(' href', ' ' . esc_attr($attr) . ' href', $html);
                        } else {
                            $html = str_replace(' href', ' ' . esc_attr($attr) . '="' . esc_attr($value) . '" href', $html);
                        }
                    }
                }
                return $html;
            }, 10, 4);
        }
    }

    /**
     * Validate configuration array
     *
     * @param array $config
     * @return array
     */
    private function validate_config(array $config): array
    {
        $defaults = [
            'textdomain' => 'ntdst_theme',
            'content_width' => 1200,
            'theme_support' => [],
            'image_sizes' => [],
            'menus' => [],
            'sidebars' => [],
            'excerpt' => ['length' => 55, 'more' => ''],
            'assets' => ['styles' => [], 'scripts' => []],
            'module_settings' => [
                'security' => [],
                'performance' => [],
                'cookie_manager' => [],
                'barba' => [],
            ],
        ];

        // Merge config with defaults
        $merged = array_merge($defaults, $config);

        // Ensure assets has both styles and scripts keys
        if (isset($merged['assets'])) {
            $merged['assets'] = array_merge(
                ['styles' => [], 'scripts' => []],
                $merged['assets'],
            );
        }

        // Ensure module_settings has all keys
        if (isset($merged['module_settings'])) {
            $merged['module_settings'] = array_merge(
                $defaults['module_settings'],
                $merged['module_settings'],
            );
        }

        return $merged;
    }


    /**
     * Get configuration settings
     *
     * @return array
     */
    public function get_config(): array
    {
        return $this->config;
    }

    /**
     * Configure a module's settings
     * Adds a filter for 'netdust_{module}_config'
     * This runs AFTER database settings are loaded, allowing you to override them
     *
     * @param string $module Module slug (e.g., 'schema', 'barba', 'security')
     * @return object Module configuration helper
     *
     * Example:
     *   $theme->module('barba')->config(function($config) {
     *       if (is_front_page()) $config['animationDuration'] = 400;
     *       return $config;
     *   });
     */
    public function module(string $module): object
    {
        return new class ($module, $this) {
            private readonly string $module;
            private readonly NTDST_Theme $theme;

            public function __construct(string $module, NTDST_Theme $theme)
            {
                $this->module = sanitize_key($module);
                $this->theme = $theme;
            }

            /**
             * Get the module service instance
             */
            public function get(): object
            {
                return ntdst_get($this->module);
            }

            /**
             * Call a method on the service fluently
             */
            public function call(string $method, ...$args): mixed
            {
                return $this->get()->$method(...$args);
            }

            // Configure module settings
            public function config(callable $callback, int $priority = 20): NTDST_Theme
            {
                add_filter("netdust_{$this->module}_config", $callback, $priority);
                return $this->theme;
            }

            // Customize module asset path
            public function path(callable $callback, int $priority = 10): NTDST_Theme
            {
                add_filter("netdust_{$this->module}_path", $callback, $priority);
                return $this->theme;
            }

            // Add custom actions before module initialization
            public function before(callable $callback, int $priority = 10): NTDST_Theme
            {
                add_action("netdust_{$this->module}_before", $callback, $priority);
                return $this->theme;
            }

            // Add custom actions after module initialization
            public function after(callable $callback, int $priority = 10): NTDST_Theme
            {
                add_action("netdust_{$this->module}_after", $callback, $priority);
                return $this->theme;
            }

            // Disable module programmatically
            public function disable(): NTDST_Theme
            {
                add_filter("netdust_{$this->module}_enabled", '__return_false', 999);
                return $this->theme;
            }

            // Enable module programmatically
            public function enable(): NTDST_Theme
            {
                add_filter("netdust_{$this->module}_enabled", '__return_true', 999);
                return $this->theme;
            }

            /**
             * Magic call: $theme->module('analytics')->track()
             */
            public function __call(string $method, array $args): mixed
            {
                return $this->call($method, ...$args);
            }
        };
    }

    /**
     * Add WordPress action with fluent API
     *
     * @param string   $action   Action name
     * @param callable $callback Callback function
     * @param int      $priority Priority (default: 10)
     * @param int      $args     Number of arguments (default: 1)
     * @return $this
     *
     * Example:
     *   $theme->on('wp_footer', function() {
     *       echo '<div>Footer content</div>';
     *   });
     */
    public function on(string $action, callable $callback, int $priority = 10, int $args = 1): self
    {
        add_action(sanitize_key($action), $callback, $priority, $args);
        return $this;
    }

    /**
     * Add WordPress filter with fluent API
     *
     * @param string   $filter   Filter name
     * @param callable $callback Callback function
     * @param int      $priority Priority (default: 10)
     * @param int      $args     Number of arguments (default: 1)
     * @return $this
     *
     * Example:
     *   $theme->filter('body_class', function($classes) {
     *       $classes[] = 'custom-class';
     *       return $classes;
     *   });
     */
    public function filter(string $filter, callable $callback, int $priority = 10, int $args = 1): self
    {
        add_filter(sanitize_key($filter), $callback, $priority, $args);
        return $this;
    }

    /**
     * Conditional configuration based on context
     *
     * @param callable $condition Function that returns boolean
     * @param callable $callback  Function to execute if condition is true
     * @return $this
     *
     * Example:
     *   $theme->when(fn() => is_front_page(), function($theme) {
     *       $theme->module('barba')->config(fn($c) => array_merge($c, ['animationDuration' => 400]));
     *   });
     */
    public function when(callable $condition, callable $callback): self
    {
        if ($condition()) {
            $callback($this);
        }
        return $this;
    }

    /**
     * Register custom API endpoint action
     * Uses the fast REST API alternative to AJAX (endpoints.php)
     *
     * @param string   $action   Action name
     * @param callable $callback Callback function that receives $data and $params
     * @param array|int $args    Priority (int) or array ['priority' => 10, 'capability' => 'edit_posts']
     * @return $this
     *
     * Example (public action):
     *   $theme->apiAction('get_portfolio', function($data, $params) {
     *       $category = sanitize_text_field($params['category'] ?? '');
     *       $posts = get_posts(['category_name' => $category]);
     *       return ['posts' => $posts];
     *   });
     *
     * Example (requires capability):
     *   $theme->apiAction('save_project', function($data, $params) {
     *       // Save logic here
     *   }, ['capability' => 'edit_posts']);
     *
     * Then call from JavaScript:
     *   ntdstAPI.call('get_portfolio', { category: 'design' })
     *       .then(data => console.log(data.posts));
     */
    public function apiAction(string $action, callable $callback, array|int $args = 10): self
    {
        // Parse arguments
        $priority = 10;
        $capability = null;

        if (is_array($args)) {
            $priority = $args['priority'] ?? 10;
            $capability = $args['capability'] ?? null;
        } elseif (is_int($args)) {
            $priority = $args;
        }

        // Wrap callback with capability check if needed
        $wrapped_callback = function ($data, $params) use ($callback, $capability) {
            // Check capability if specified
            if ($capability && !current_user_can($capability)) {
                return ['error' => 'Insufficient permissions', 'code' => 'forbidden'];
            }

            // Execute the actual callback
            return $callback($data, $params);
        };

        add_filter('ntdst/api_data/' . sanitize_key($action), $wrapped_callback, $priority, 2);
        return $this;
    }

    /**
     * Register a data model (post type with schema)
     *
     * @param string $name Post type name
     * @param array  $config Configuration array
     * @return NTDST_Data_Model
     *
     * Example:
     *   $theme->register('projects', [
     *       'label' => 'Projects',
     *       'public' => true,
     *       'fields' => [
     *           'client_name' => 'string',
     *           'project_year' => 'integer',
     *       ],
     *   ]);
     */
    public function register(string $name, array $config = []): NTDST_Data_Model
    {
        return $this->data()->register($name, $config);
    }

    /**
     * Register a taxonomy
     *
     * @param string $taxonomy Taxonomy name
     * @param string|array $post_types Post type(s) to attach to
     * @param array $args Taxonomy arguments
     * @return $this
     *
     * Example:
     *   $theme->taxonomy('project_category', 'portfolio', [
     *       'label' => 'Project Categories',
     *       'hierarchical' => true,
     *   ]);
     */
    public function taxonomy(string $taxonomy, string|array $post_types, array $args = []): self
    {
        $defaults = [
            'public' => true,
            'hierarchical' => false,
            'show_ui' => true,
            'show_in_rest' => true,
            'rewrite' => ['slug' => $taxonomy],
        ];

        $args = array_merge($defaults, $args);

        register_taxonomy($taxonomy, $post_types, $args);

        return $this;
    }

    /**
     * Add custom template path
     *
     * @param string $path Template directory path
     * @return $this
     *
     * Example:
     *   $theme->templatePath(__DIR__ . '/custom-templates');
     */
    public function templatePath(string $path): self
    {
        NTDST_Template_Loader::addPath($path);
        // Clear Response cache so new path is picked up
        NTDST_Response::clearPathCache();
        return $this;
    }

    /**
     * Register single template handler
     *
     * @param string|callable|null $post_type Post type or callback
     * @param callable|null $callback Handler function
     * @return $this
     *
     * Example:
     *   $theme->single('project', function($post) {
     *       return ntdst_response()->with('project', $post)->template('project/detail');
     *   });
     */
    public function single(string|callable|null $post_type = null, ?callable $callback = null): self
    {
        $this->router()->single($post_type, $callback);
        return $this;
    }

    /**
     * Register page template handler
     *
     * @param string|callable $slug Page slug or callback
     * @param callable|null $callback Handler function
     * @return $this
     *
     * Example:
     *   $theme->page('about', function($post) {
     *       return get_template_directory() . '/templates/about.php';
     *   });
     */
    public function page(string|callable $slug, ?callable $callback = null): self
    {
        $this->router()->page($slug, $callback);
        return $this;
    }

    /**
     * Register archive template handler
     *
     * @param string|callable|null $post_type Post type or callback
     * @param callable|null $callback Handler function
     * @return $this
     *
     * Example:
     *   $theme->archive('project', function() {
     *       $projects = ntdst_data()->get('project')->all();
     *       return ntdst_response()->with('projects', $projects)->template('project/archive');
     *   });
     */
    public function archive(string|callable|null $post_type = null, ?callable $callback = null): self
    {
        $this->router()->archive($post_type, $callback);
        return $this;
    }

    /**
     * Mixin: Extend theme with additional methods or instance proxies
     *
     * Two patterns supported:
     * 1. Instance proxying: $theme->mixin('mail', ntdst_mail())
     *    Usage: $theme->mail()->to(...)
     *
     * 2. Method injection: $theme->mixin(new HelperClass())
     *    Copies all public methods from HelperClass to $theme
     *
     * @param string|object $nameOrInstance Mixin name (for instance proxy) or object (for method injection)
     * @param object|null $instance Instance to proxy (only for pattern 1)
     * @return $this
     *
     * Example (instance proxying):
     *   $theme->mixin('mail', ntdst_mail());
     *   $theme->mail()->to('user@example.com')->send();
     *
     * Example (method injection):
     *   class ThemeHelpers {
     *       public function formatDate($date) { return date('Y-m-d', strtotime($date)); }
     *       public function truncate($text, $length) { return substr($text, 0, $length) . '...'; }
     *   }
     *   $theme->mixin(new ThemeHelpers());
     *   $theme->formatDate('2024-01-01');  // Direct method call
     */
    public function mixin(string|object $nameOrInstance, ?object $instance = null): self
    {
        // Pattern 1: Instance proxying (named)
        if (is_string($nameOrInstance) && $instance !== null) {
            $name = sanitize_key($nameOrInstance);
            $this->mixins[$name] = $instance;
            return $this;
        }

        // Pattern 2: Method injection (copy methods from object)
        if (is_object($nameOrInstance) && $instance === null) {
            $class = new ReflectionClass($nameOrInstance);
            foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                // Skip magic methods and constructors
                if (str_starts_with($method->name, '__')) {
                    continue;
                }

                // Store as callable bound to the original instance
                $methodName = $method->name;
                $this->mixins[$methodName] = function (...$args) use ($nameOrInstance, $methodName) {
                    return $nameOrInstance->$methodName(...$args);
                };
            }
            return $this;
        }

        // Invalid usage
        trigger_error('Invalid mixin usage. Use either mixin($name, $instance) or mixin($object)', E_USER_WARNING);
        return $this;
    }

    /**
     * Magic method to handle dynamic calls to mixed-in methods/instances
     *
     * @param string $name Method name
     * @param array $arguments Method arguments
     * @return mixed
     */
    public function __call(string $name, array $arguments): mixed
    {
        if (!isset($this->mixins[$name])) {
            throw new BadMethodCallException("Method or mixin '{$name}' does not exist on " . static::class);
        }

        $mixin = $this->mixins[$name];

        // If it's a callable (method injection), execute it
        if (is_callable($mixin)) {
            return $mixin(...$arguments);
        }

        // If it's an object (instance proxy), return it for chaining
        if (is_object($mixin)) {
            return $mixin;
        }

        throw new BadMethodCallException("Mixin '{$name}' is not callable or an object");
    }
}
