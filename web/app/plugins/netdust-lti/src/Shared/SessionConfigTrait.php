<?php
declare(strict_types=1);

namespace NetdustLTI\Shared;

/**
 * Provides session configuration for cross-site LTI requests.
 *
 * LTI launches occur in iframes from external platforms, requiring
 * SameSite=None cookies for session persistence across sites.
 */
trait SessionConfigTrait
{
    /**
     * Configure PHP session for cross-site LTI requests.
     *
     * Sets SameSite=None and Secure flags for HTTPS connections,
     * enabling session cookies to work in cross-origin iframes.
     */
    protected function configureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Check for HTTPS (direct or behind proxy like DDEV/Traefik)
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                || (!empty($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https')
                || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

            if ($isHttps) {
                ini_set('session.cookie_samesite', 'None');
                ini_set('session.cookie_secure', '1');
            }
            session_start();
        }
    }
}
