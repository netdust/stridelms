<?php
declare(strict_types=1);

/**
 * NTDST Response - Fast template rendering
 * JSON, HTML, or file download output with WordPress template hierarchy integration
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
     */
    protected static ?array $cached_paths = null;

    /**
     * Template location cache (shared across all instances)
     */
    protected static array $template_cache = [];

    /**
     * MIME type mappings
     */
    protected static array $mimeTypes = [
        // Documents
        'pdf' => 'application/pdf',
        'csv' => 'text/csv; charset=utf-8',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'txt' => 'text/plain; charset=utf-8',

        // Calendar/Contact
        'ics' => 'text/calendar; charset=utf-8',
        'vcf' => 'text/vcard; charset=utf-8',

        // Images
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',

        // Archives
        'zip' => 'application/zip',
        'gz' => 'application/gzip',

        // Office
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    public function __construct()
    {
        if (self::$cached_paths === null) {
            self::$cached_paths = NTDST_Template_Loader::getCustomPaths();
            self::$cached_paths = array_merge(self::$cached_paths, [
                get_stylesheet_directory() . '/templates',
                get_template_directory() . '/templates',
            ]);
        }

        $this->template_paths = self::$cached_paths;
    }

    /**
     * Reset state for reuse
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
     * Clear template path cache
     */
    public static function clearPathCache(): void
    {
        self::$cached_paths = null;
        self::$template_cache = [];
    }

    /**
     * Set data
     */
    public function with(string $key, mixed $value): self
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
     * Set template for deferred rendering
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

    // =========================================================================
    // OUTPUT METHODS
    // =========================================================================

    /**
     * Return JSON response
     */
    public function json(): never
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
     * Redirect to URL
     *
     * Uses wp_safe_redirect() for internal URLs.
     * If error is set, appends ?error= query param to the URL.
     *
     * @example ntdst_response()->redirect(home_url('/dashboard'));
     * @example ntdst_response()->error('Invalid token.')->redirect(home_url('/login'));
     */
    public function redirect(string $url): never
    {
        if ($this->error) {
            $url = add_query_arg('error', $this->error, $url);
        }

        wp_safe_redirect($url, $this->status ?: 302);
        exit;
    }

    /**
     * Render HTML template
     */
    public function render(string $template, array $data = []): never
    {
        if ($this->error) {
            $this->renderError();
        }

        $file = $this->locate($template);

        if (!$file) {
            $this->error("Template not found: {$template}", 404)->renderError();
        }

        $data = array_merge($this->data, $data);
        extract($data, EXTR_SKIP);

        include $file;
        exit;
    }

    /**
     * Return HTML as string
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
     * Send file as download (attachment)
     *
     * @param string $content File content
     * @param string $filename Download filename
     * @param string|null $contentType MIME type (auto-detected if null)
     *
     * @example ntdst_response()->download($pdf, 'invoice.pdf');
     * @example ntdst_response()->download($ical, 'calendar.ics');
     */
    public function download(string $content, string $filename, ?string $contentType = null): never
    {
        $this->sendFile($content, $filename, $contentType, 'attachment');
    }

    /**
     * Send file inline (display in browser)
     *
     * @param string $content File content
     * @param string $filename Filename for content-type detection
     * @param string|null $contentType MIME type (auto-detected if null)
     *
     * @example ntdst_response()->inline($pdf, 'invoice.pdf');
     */
    public function inline(string $content, string $filename, ?string $contentType = null): never
    {
        $this->sendFile($content, $filename, $contentType, 'inline');
    }

    /**
     * Send file response
     */
    protected function sendFile(
        string $content,
        string $filename,
        ?string $contentType,
        string $disposition
    ): never {
        $contentType ??= self::getMimeType($filename);

        nocache_headers();
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: ' . $disposition . '; filename="' . basename($filename) . '"');
        header('Content-Length: ' . strlen($content));

        echo $content;
        exit;
    }

    /**
     * Get MIME type from filename
     */
    public static function getMimeType(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return self::$mimeTypes[$ext] ?? 'application/octet-stream';
    }

    /**
     * Register additional MIME type
     */
    public static function registerMimeType(string $extension, string $mimeType): void
    {
        self::$mimeTypes[strtolower($extension)] = $mimeType;
    }

    // =========================================================================
    // TEMPLATE HELPERS
    // =========================================================================

    /**
     * Locate template file
     */
    protected function locate(string $template): ?string
    {
        if (!str_ends_with($template, '.php')) {
            $template .= '.php';
        }

        if (isset(self::$template_cache[$template])) {
            return self::$template_cache[$template];
        }

        foreach ($this->template_paths as $path) {
            $file = $path . '/' . $template;
            if (file_exists($file)) {
                self::$template_cache[$template] = $file;
                return $file;
            }
        }

        $located = locate_template([$template]);
        $result = $located ?: null;

        self::$template_cache[$template] = $result;

        return $result;
    }

    /**
     * Render error page
     */
    protected function renderError(): never
    {
        http_response_code($this->status);

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

// =============================================================================
// GLOBAL HELPERS
// =============================================================================

/**
 * Create response instance
 */
function ntdst_response(): NTDST_Response
{
    return new NTDST_Response();
}

/**
 * Quick redirect
 *
 * @example ntdst_redirect(home_url('/dashboard'));
 */
function ntdst_redirect(string $url, int $status = 302): never
{
    wp_safe_redirect($url, $status);
    exit;
}

/**
 * Quick file download
 *
 * @example ntdst_download($content, 'file.pdf');
 */
function ntdst_download(string $content, string $filename, ?string $contentType = null): never
{
    ntdst_response()->download($content, $filename, $contentType);
}

/**
 * Quick inline file display
 *
 * @example ntdst_inline($pdf, 'document.pdf');
 */
function ntdst_inline(string $content, string $filename, ?string $contentType = null): never
{
    ntdst_response()->inline($content, $filename, $contentType);
}

// =============================================================================
// TEMPLATE LOADER
// =============================================================================

final class NTDST_Template_Loader
{
    protected static array $custom_paths = [];

    public static function addPath(string $path): void
    {
        self::$custom_paths[] = rtrim($path, '/');
    }

    public static function getCustomPaths(): array
    {
        return self::$custom_paths;
    }

    public static function init(): void
    {
        add_filter('template_include', [self::class, 'templateInclude'], 99);
        add_filter('theme_file_path', [self::class, 'locateInCustomPaths'], 10, 2);
    }

    public static function templateInclude(string $template): string
    {
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

NTDST_Template_Loader::init();
