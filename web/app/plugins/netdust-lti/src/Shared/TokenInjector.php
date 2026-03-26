<?php

declare(strict_types=1);

namespace NetdustLTI\Shared;

class TokenInjector
{
    /**
     * Output the token injection JS.
     * Call this in wp_footer inside the LTI iframe template.
     */
    public static function render(string $token, int $postId): void
    {
        $contentUrl = home_url('/lti/content');
        ?>
        <script>
        (function() {
            var token = <?php echo wp_json_encode($token); ?>;
            var contentUrl = <?php echo wp_json_encode($contentUrl); ?>;
            var siteOrigin = new URL(contentUrl).origin;
            var postId = <?php echo (int) $postId; ?>;

            // Expose token for SCORM/TinCanny
            window.__lti_token = token;

            // Helper: append _lti param to URL
            function appendToken(url) {
                if (!url || url.indexOf('_lti=') !== -1) return url;
                var sep = url.indexOf('?') === -1 ? '?' : '&';
                return url + sep + '_lti=' + encodeURIComponent(token);
            }

            // 1. Intercept link clicks — rewrite same-origin to /lti/content
            document.addEventListener('click', function(e) {
                var link = e.target.closest('a');
                if (!link || !link.href) return;

                try {
                    var linkUrl = new URL(link.href);
                    if (linkUrl.origin !== siteOrigin) return;

                    // Skip anchor-only links
                    if (linkUrl.pathname === window.location.pathname && linkUrl.hash) return;

                    // Skip SCORM content URLs — let TinCanny/GLightbox handle these
                    if (linkUrl.pathname.indexOf('/lti/scorm-proxy') !== -1) return;
                    if (linkUrl.pathname.indexOf('/uncanny-snc/') !== -1) return;
                    if (link.classList.contains('glightbox')) return;

                    e.preventDefault();
                    var targetPath = linkUrl.pathname + linkUrl.search + linkUrl.hash;
                    window.location.href = contentUrl + '?_lti=' + encodeURIComponent(token) + '&url=' + encodeURIComponent(targetPath);
                } catch(err) {}
            });

            // 2. Intercept form submissions — add hidden _lti input
            document.addEventListener('submit', function(e) {
                var form = e.target;
                if (!form || form.querySelector('input[name="_lti"]')) return;
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = '_lti';
                input.value = token;
                form.appendChild(input);
            });

            // 3. Intercept XMLHttpRequest
            var origOpen = XMLHttpRequest.prototype.open;
            XMLHttpRequest.prototype.open = function(method, url) {
                if (typeof url === 'string') {
                    try {
                        var parsed = new URL(url, window.location.origin);
                        if (parsed.origin === siteOrigin) {
                            url = appendToken(url);
                        }
                    } catch(e) {
                        url = appendToken(url);
                    }
                }
                var args = Array.prototype.slice.call(arguments);
                args[1] = url;
                return origOpen.apply(this, args);
            };

            // 4. Intercept fetch
            var origFetch = window.fetch;
            window.fetch = function(input, init) {
                if (typeof input === 'string') {
                    try {
                        var parsed = new URL(input, window.location.origin);
                        if (parsed.origin === siteOrigin) {
                            input = appendToken(input);
                        }
                    } catch(e) {
                        input = appendToken(input);
                    }
                } else if (input instanceof Request) {
                    var reqUrl = input.url;
                    try {
                        var parsed = new URL(reqUrl);
                        if (parsed.origin === siteOrigin) {
                            input = new Request(appendToken(reqUrl), input);
                        }
                    } catch(e) {}
                }
                return origFetch.call(this, input, init);
            };

            // 5. Patch ajaxurl (LearnDash, WordPress AJAX)
            if (window.ajaxurl && window.ajaxurl.indexOf('_lti=') === -1) {
                var sep = window.ajaxurl.indexOf('?') === -1 ? '?' : '&';
                window.ajaxurl = window.ajaxurl + sep + '_lti=' + encodeURIComponent(token);
            }
        })();
        </script>
        <?php
    }
}
