<?php

/**
 * NTDST Response - Fast template rendering
 * JSON or HTML output with WordPress template hierarchy integration
 */

defined('ABSPATH') || exit;

class NTDST_Response
{
    protected array $data = [];
    protected array $template_paths = [];
    protected ?string $error = null;
    protected int $status = 200;
    protected ?string $template = null;

    /**
     * Cached template paths (shared across all instances)
     * PERFORMANCE: Avoids rebuilding paths on every Response creation
     */
    protected static ?array $cached_paths = null;

    /**
     * Template location cache (shared across all instances)
     * PERFORMANCE: Avoids repeated file_exists() calls for same templates
     */
    protected static array $template_cache = [];

    public function __construct()
    {
        // PERFORMANCE: Use cached paths if available
        if (self::$cached_paths === null) {
            // Start with custom paths from Template Loader (if any)
            self::$cached_paths = NTDST_Template_Loader::getCustomPaths();

            // Add default template paths (theme first, then child theme compatibility)
            self::$cached_paths = array_merge(self::$cached_paths, [
                get_stylesheet_directory() . '/templates',
                get_template_directory() . '/templates',
            ]);
        }

        $this->template_paths = self::$cached_paths;
    }

    /**
     * Reset state for reuse (used by factory pattern)
     */
    public function reset(): self
    {
        $this->data = [];
        $this->error = null;
        $this->status = 200;
        $this->template = null;
        return $this;
    }

    /**
     * Clear template path cache (call when paths change)
     */
    public static function clearPathCache(): void
    {
        self::$cached_paths = null;
        self::$template_cache = [];
    }

    /**
     * Set data
     */
    public function with(string $key, $value): self
    {
        $this->data[$key] = $value;
        return $this;
    }

    /**
     * Set multiple data at once
     */
    public function withData(array $data): self
    {
        $this->data = array_merge($this->data, $data);
        return $this;
    }

    /**
     * Set error
     */
    public function error(string $message, int $status = 400): self
    {
        $this->error = $message;
        $this->status = $status;
        return $this;
    }

    /**
     * Add template path (prepend - highest priority)
     */
    public function addPath(string $path): self
    {
        array_unshift($this->template_paths, rtrim($path, '/'));
        return $this;
    }

    /**
     * Set template for deferred rendering (used by router)
     */
    public function template(string $template): self
    {
        $this->template = $template;
        return $this;
    }

    /**
     * Get stored template name
     */
    public function getTemplate(): ?string
    {
        return $this->template;
    }

    /**
     * Return JSON response
     */
    public function json(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json');

        if ($this->error) {
            echo json_encode([
                'success' => false,
                'error' => $this->error,
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'data' => $this->data,
            ]);
        }

        exit;
    }

    /**
     * Render HTML template
     *
     * @param string $template Template name (e.g., 'project/single')
     * @param array $data Additional data to pass
     */
    public function render(string $template, array $data = []): void
    {
        if ($this->error) {
            $this->renderError();
            return;
        }

        $file = $this->locate($template);

        if (!$file) {
            $this->error("Template not found: {$template}", 404)->renderError();
            return;
        }

        // Merge data
        $data = array_merge($this->data, $data);

        // Extract variables for template
        extract($data, EXTR_SKIP);

        // Include template
        include $file;
        exit;
    }

    /**
     * Return HTML as string (for AJAX)
     */
    public function html(string $template, array $data = []): string
    {
        if ($this->error) {
            return $this->getErrorHtml();
        }

        $file = $this->locate($template);

        if (!$file) {
            return $this->error("Template not found: {$template}", 404)->getErrorHtml();
        }

        $data = array_merge($this->data, $data);
        extract($data, EXTR_SKIP);

        ob_start();
        include $file;
        return ob_get_clean();
    }

    /**
     * Locate template file (checks all paths)
     * PERFORMANCE: Uses static cache to avoid repeated file_exists() calls
     */
    protected function locate(string $template): ?string
    {
        // Add .php if not present
        if (!str_ends_with($template, '.php')) {
            $template .= '.php';
        }

        // PERFORMANCE: Check cache first
        if (isset(self::$template_cache[$template])) {
            return self::$template_cache[$template];
        }

        // Check each path
        foreach ($this->template_paths as $path) {
            $file = $path . '/' . $template;
            if (file_exists($file)) {
                // Cache the result
                self::$template_cache[$template] = $file;
                return $file;
            }
        }

        // Try WordPress template hierarchy
        $located = locate_template([$template]);
        $result = $located ?: null;

        // Cache the result (even null to avoid repeated lookups)
        self::$template_cache[$template] = $result;

        return $result;
    }

    /**
     * Render error page
     */
    protected function renderError(): void
    {
        http_response_code($this->status);

        // Try to load error template
        $error_file = $this->locate('error');

        if ($error_file) {
            $error = $this->error;
            $status = $this->status;
            include $error_file;
        } else {
            echo $this->getErrorHtml();
        }

        exit;
    }

    /**
     * Get error HTML
     */
    protected function getErrorHtml(): string
    {
        return sprintf(
            '<div style="padding:20px;background:#fee;border:1px solid #c33;border-radius:4px;"><strong>Error %d:</strong> %s</div>',
            $this->status,
            esc_html($this->error),
        );
    }
}

/**
 * Global helper - create response
 */
function ntdst_response(): NTDST_Response
{
    return new NTDST_Response();
}

/**
 * WordPress Template Integration
 * Adds custom template paths to WordPress template hierarchy
 */
class NTDST_Template_Loader
{
    protected static array $custom_paths = [];

    /**
     * Add custom template path
     */
    public static function addPath(string $path): void
    {
        self::$custom_paths[] = rtrim($path, '/');
    }

    /**
     * Get custom template paths
     */
    public static function getCustomPaths(): array
    {
        return self::$custom_paths;
    }

    /**
     * Initialize WordPress hooks
     */
    public static function init(): void
    {
        // Hook into template hierarchy
        add_filter('template_include', [self::class, 'templateInclude'], 99);

        // Add to locate_template search
        add_filter('theme_file_path', [self::class, 'locateInCustomPaths'], 10, 2);
    }

    /**
     * Check custom paths for templates
     */
    public static function templateInclude(string $template): string
    {
        // Get template hierarchy for current request
        $templates = [];

        if (is_single()) {
            global $post;
            $templates[] = "single-{$post->post_type}-{$post->post_name}.php";
            $templates[] = "single-{$post->post_type}.php";
            $templates[] = "single.php";
        } elseif (is_archive()) {
            $post_type = get_query_var('post_type');
            $templates[] = "archive-{$post_type}.php";
            $templates[] = "archive.php";
        }

        // Check custom paths
        foreach ($templates as $template_name) {
            foreach (self::$custom_paths as $path) {
                $file = $path . '/' . $template_name;
                if (file_exists($file)) {
                    return $file;
                }
            }
        }

        return $template;
    }

    /**
     * Locate template in custom paths
     */
    public static function locateInCustomPaths(string $path, string $file): string
    {
        foreach (self::$custom_paths as $custom_path) {
            $custom_file = $custom_path . '/' . $file;
            if (file_exists($custom_file)) {
                return $custom_file;
            }
        }

        return $path;
    }
}

// Initialize template loader
NTDST_Template_Loader::init();
