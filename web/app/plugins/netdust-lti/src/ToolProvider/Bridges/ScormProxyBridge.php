<?php
declare(strict_types=1);

namespace NetdustLTI\ToolProvider\Bridges;

use NetdustLTI\Plugin;

/**
 * SCORM content proxy for LTI iframe compatibility.
 *
 * Problem: Combell's nginx adds X-Frame-Options: SAMEORIGIN and its own CSP
 * headers to responses with .html extensions, blocking SCORM content in the
 * nested LTI iframe (External LMS → LTI iframe → SCORM iframe).
 *
 * Solution: Route SCORM through /lti/scorm-proxy (no .html extension in URL)
 * so nginx doesn't apply extension-based security headers. The LTI Router
 * already handles /lti/{action} patterns and correctly sets frame-ancestors *.
 *
 * For HTML files, a <base> tag is injected so relative resources (JS, CSS,
 * images) still load from the original uploads path served by nginx.
 */
final class ScormProxyBridge
{
    public function __construct()
    {
        // Rewrite SCORM URLs in LTI iframe context
        add_filter('tincanny_module_url', [$this, 'rewriteScormUrl'], 10, 3);

        // Remove X-Frame-Options from TinCanny-served content for LTI users
        // (safety net for environments where .htaccess rewrite works, e.g. production)
        add_filter('uo_tincanny_protection_headers', [$this, 'filterTinCannyHeaders']);

        // Prevent TinCanny's xAPI Server from intercepting /lti/scorm-proxy requests.
        // TinCanny's Server::check_request() (wp_loaded, priority 10) checks if
        // REQUEST_URI contains 'ucTinCan'. TinCanny's JS appends endpoint=.../ucTinCan/
        // to the iframe URL at runtime, causing the Server to intercept our proxy
        // request and exit before the LTI Router can handle it.
        add_action('wp_loaded', [$this, 'preventTinCannyInterception'], 0);
    }

    /**
     * Remove TinCanny's xAPI Server hook for /lti/ requests.
     *
     * TinCanny's Server checks if REQUEST_URI contains 'ucTinCan' anywhere.
     * When LTI URLs carry SCORM params (e.g. /lti/content?url=...ucTinCan...),
     * TinCanny would intercept and return a GUID response instead of content.
     */
    public function preventTinCannyInterception(): void
    {
        if (!isset($_SERVER['REQUEST_URI']) || !str_contains($_SERVER['REQUEST_URI'], '/lti/')) {
            return;
        }

        global $wp_filter;
        if (!isset($wp_filter['wp_loaded'])) {
            return;
        }

        foreach ($wp_filter['wp_loaded']->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                if (
                    is_array($callback['function'] ?? null)
                    && is_object($callback['function'][0] ?? null)
                    && get_class($callback['function'][0]) === 'UCTINCAN\\Server'
                    && ($callback['function'][1] ?? '') === 'check_request'
                ) {
                    remove_action('wp_loaded', $callback['function'], $priority);
                    return;
                }
            }
        }
    }

    /**
     * Serve SCORM content through the proxy.
     *
     * Called from the LTI Router's handleRequest() for the 'scorm-proxy' action.
     * At this point, the Router has already set frame-ancestors * and removed
     * X-Frame-Options.
     */
    public function serve(): void
    {
        $contentId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $filePath  = $_GET['file'] ?? '';

        if (!$contentId || !$filePath) {
            wp_die('Missing SCORM parameters', 'SCORM Error', ['response' => 400]);
        }

        if (!is_user_logged_in()) {
            wp_die('Authentication required', 'SCORM Error', ['response' => 403]);
        }

        $uploads  = wp_get_upload_dir();
        $basePath = realpath($uploads['basedir'] . '/uncanny-snc');
        $fullPath = realpath($uploads['basedir'] . '/uncanny-snc/' . $contentId . '/' . $filePath);

        // Prevent directory traversal
        if (!$basePath || !$fullPath || !str_starts_with($fullPath, $basePath)) {
            wp_die('File not found', 'SCORM Error', ['response' => 404]);
        }

        // Handle directory requests
        if (is_dir($fullPath)) {
            if (file_exists($fullPath . '/index.html')) {
                $fullPath .= '/index.html';
            } elseif (file_exists($fullPath . '/index.htm')) {
                $fullPath .= '/index.htm';
            } else {
                wp_die('File not found', 'SCORM Error', ['response' => 404]);
            }
        }

        if (!is_file($fullPath)) {
            wp_die('File not found', 'SCORM Error', ['response' => 404]);
        }

        $this->serveFile($fullPath, $contentId);
    }

    /**
     * Rewrite SCORM URLs to use the proxy for LTI iframe users.
     *
     * Changes: https://site.com/content/uploads/uncanny-snc/{id}/path
     * To:      https://site.com/lti/scorm-proxy?id={id}&file=path
     *
     * TinCanny's JavaScript adds xAPI params (endpoint, auth, actor, etc.)
     * to the iframe URL at runtime — they are not present at filter time.
     * The preventTinCannyInterception() method handles the resulting
     * 'ucTinCan' in REQUEST_URI that would otherwise cause TinCanny's
     * xAPI Server to intercept the proxy request.
     */
    public function rewriteScormUrl(string $url, array $item, $module): string
    {
        if (!Plugin::isLtiIframeUser()) {
            return $url;
        }

        // Parse the URL to separate path from query string
        $parsed = wp_parse_url($url);
        $path = $parsed['path'] ?? '';

        // Match /content/uploads/uncanny-snc/{id}/{file_path}
        if (!preg_match('#/content/uploads/uncanny-snc/(\d+)/(.+)$#', $path, $matches)) {
            return $url;
        }

        $proxyUrl = add_query_arg([
            'id'   => $matches[1],
            'file' => $matches[2],
        ], home_url('/lti/scorm-proxy'));

        // Include LTI nav token so the proxy request authenticates
        // via TokenAuthMiddleware (cookies don't work in cross-origin iframes).
        // Token sources: $GLOBALS set by Router during LTI launch, or $_GET/_POST
        // propagated by TokenAuthMiddleware during subsequent page navigations.
        $navToken = $GLOBALS['lti_nav_token']
            ?? sanitize_text_field($_GET['_lti'] ?? $_POST['_lti'] ?? '');
        if ($navToken) {
            $proxyUrl = add_query_arg('_lti', $navToken, $proxyUrl);
        }

        return $proxyUrl;
    }

    /**
     * Remove X-Frame-Options from TinCanny-served content for LTI users.
     *
     * Safety net for production where TinCannyProtection serves SCORM HTML
     * directly (via .htaccess rewrite) without going through our proxy.
     */
    public function filterTinCannyHeaders(array $headers): array
    {
        if (!Plugin::isLtiIframeUser()) {
            return $headers;
        }

        unset($headers['X-Frame-Options']);
        $headers['Content-Security-Policy'] = 'Content-Security-Policy: frame-ancestors *';

        return $headers;
    }

    /**
     * Serve a SCORM file with proper headers.
     *
     * For HTML files, injects a <base> tag so relative resources (JS, CSS,
     * images, fonts) resolve to the original uploads path where nginx serves
     * them directly. This avoids routing all SCORM sub-resources through PHP.
     */
    private function serveFile(string $fullPath, int $contentId): void
    {
        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $isHtml = in_array($ext, ['html', 'htm'], true);

        // Content type
        $mimeTypes = [
            'html' => 'text/html; charset=UTF-8',
            'htm'  => 'text/html; charset=UTF-8',
            'js'   => 'application/javascript',
            'css'  => 'text/css',
            'json' => 'application/json',
            'xml'  => 'application/xml',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'svg'  => 'image/svg+xml',
            'webp' => 'image/webp',
        ];
        $contentType = $mimeTypes[$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $contentType);

        // Cache headers
        header('Cache-Control: public, max-age=86400');
        header('X-Robots-Tag: none');

        if ($isHtml) {
            // For HTML: inject <base> tag and output modified content
            $this->serveHtmlWithBase($fullPath, $contentId);
        } else {
            // For non-HTML: serve directly
            header('Content-Length: ' . filesize($fullPath));
            if (ob_get_length()) {
                ob_clean();
            }
            flush();
            readfile($fullPath);
        }

        exit;
    }

    /**
     * Serve an HTML file with an injected <base> tag.
     *
     * The <base> tag makes relative URLs resolve against the original SCORM
     * uploads directory, so sub-resources load from nginx (fast) rather than
     * going through this PHP proxy.
     */
    private function serveHtmlWithBase(string $fullPath, int $contentId): void
    {
        $html = file_get_contents($fullPath);

        if ($html === false) {
            wp_die('Could not read file', 'SCORM Error', ['response' => 500]);
        }

        // Determine the directory path relative to the SCORM content root
        $uploads = wp_get_upload_dir();
        $contentRoot = $uploads['basedir'] . '/uncanny-snc/' . $contentId . '/';
        $fileDir = dirname($fullPath) . '/';

        // Build the base URL pointing to the original file's directory
        $relativePath = str_replace($uploads['basedir'], '', $fileDir);
        $baseUrl = $uploads['baseurl'] . $relativePath;

        // Rewrite <frame src> and <iframe src> for HTML files to go through the proxy.
        // nginx adds X-Frame-Options: SAMEORIGIN to .html files, which blocks them
        // in the nested LTI iframe. The <base> tag still handles CSS/JS/images fine.
        $ltiToken = sanitize_text_field($_GET['_lti'] ?? '');
        $proxyBase = home_url('/lti/scorm-proxy') . '?id=' . $contentId
            . ($ltiToken ? '&_lti=' . urlencode($ltiToken) : '') . '&file=';

        $currentDirRelative = str_replace($contentRoot, '', $fileDir);

        $html = preg_replace_callback(
            '/(<(?:frame|iframe)\b[^>]*\bsrc\s*=\s*["\'])([^"\']+)(["\']\s*)/i',
            function (array $m) use ($proxyBase, $currentDirRelative): string {
                $src = $m[2];
                // Skip absolute URLs and non-HTML files
                if (preg_match('#^https?://#i', $src) || str_starts_with($src, '//')) {
                    return $m[0];
                }
                if (!preg_match('/\.html?(\?|$)/i', $src)) {
                    return $m[0];
                }
                // Resolve relative path from current directory within the SCORM package
                $filePath = $currentDirRelative . $src;
                return $m[1] . $proxyBase . rawurlencode($filePath) . $m[3];
            },
            $html
        );

        // Inject <base> tag after <head> (or at start if no <head>)
        $baseTag = '<base href="' . esc_url($baseUrl) . '">';

        if (preg_match('/<head[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE)) {
            $insertPos = $match[0][1] + strlen($match[0][0]);
            $html = substr($html, 0, $insertPos) . "\n" . $baseTag . "\n" . substr($html, $insertPos);
        } else {
            $html = $baseTag . "\n" . $html;
        }

        // Inject TinCanny reload shim before </head>.
        // TinCanny's JS appends xAPI params to iframe URLs at runtime and reloads
        // them, bypassing the proxy. This shim intercepts those URL changes and
        // rewrites /tincanny/ URLs back through /lti/scorm-proxy with the _lti token.
        $html = $this->injectTinCannyShim($html);

        header('Content-Length: ' . strlen($html));

        if (ob_get_length()) {
            ob_clean();
        }
        flush();
        echo $html;
    }

    /**
     * Inject a JavaScript shim that intercepts TinCanny's runtime URL changes.
     *
     * TinCanny's JS appends xAPI parameters (endpoint, auth, actor, etc.) to
     * iframe src attributes at runtime and reloads them. These reloaded URLs
     * point directly to /tincanny/ paths, bypassing the proxy and losing the
     * _lti auth token — causing "refused connection" errors.
     *
     * This shim uses three interception strategies:
     * 1. MutationObserver — catches attribute changes on existing iframes
     * 2. iframe.src setter wrap — catches programmatic src assignments
     * 3. iframe load event — fallback for contentWindow.location changes
     *
     * When a /tincanny/ URL is detected that isn't already proxied, the shim
     * rewrites it through /lti/scorm-proxy with the _lti token attached.
     */
    private function injectTinCannyShim(string $html): string
    {
        $shimJs = <<<'JS'
<script>
(function() {
    var ltiToken = (new URLSearchParams(window.location.search)).get('_lti') || '';
    if (!ltiToken) return;

    var proxyBase = '/lti/scorm-proxy';

    // Match SCORM paths: /content/uploads/uncanny-snc/{id}/{file_path}
    var scormPattern = /\/content\/uploads\/uncanny-snc\/(\d+)\/(.+)$/;

    function rewriteUrl(urlStr) {
        try {
            var url = new URL(urlStr, window.location.origin);
            if (url.pathname.indexOf(proxyBase) !== -1) return null; // already proxied
            var match = url.pathname.match(scormPattern);
            if (!match) return null;
            var newSrc = proxyBase + '?id=' + match[1] + '&file=' + encodeURIComponent(match[2]) + '&_lti=' + encodeURIComponent(ltiToken);
            url.searchParams.forEach(function(v, k) { newSrc += '&' + k + '=' + encodeURIComponent(v); });
            return window.location.origin + newSrc;
        } catch(e) { return null; }
    }

    // MutationObserver for attribute changes
    var observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(m) {
            if (m.type === 'attributes' && m.attributeName === 'src' && m.target.tagName === 'IFRAME') {
                var rewritten = rewriteUrl(m.target.src);
                if (rewritten) m.target.src = rewritten;
            }
        });
    });
    observer.observe(document.documentElement, { attributes: true, subtree: true, attributeFilter: ['src'] });

    // Wrap iframe.src setter
    var srcDesc = Object.getOwnPropertyDescriptor(HTMLIFrameElement.prototype, 'src');
    if (srcDesc && srcDesc.set) {
        Object.defineProperty(HTMLIFrameElement.prototype, 'src', {
            get: srcDesc.get,
            set: function(val) {
                var rewritten = rewriteUrl(val);
                srcDesc.set.call(this, rewritten || val);
            },
            configurable: true
        });
    }

    // iframe.onload fallback for contentWindow.location changes
    document.addEventListener('load', function(e) {
        if (e.target && e.target.tagName === 'IFRAME') {
            try {
                var iframeSrc = e.target.contentWindow.location.href;
                var rewritten = rewriteUrl(iframeSrc);
                if (rewritten) e.target.src = rewritten;
            } catch(err) { /* cross-origin — ignore */ }
        }
    }, true);
})();
</script>
JS;

        // Inject before </head> if present, otherwise append to HTML
        if (stripos($html, '</head>') !== false) {
            $html = str_ireplace('</head>', $shimJs . "\n</head>", $html);
        } else {
            $html .= "\n" . $shimJs;
        }

        return $html;
    }
}
