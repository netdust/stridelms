<?php
declare(strict_types=1);

namespace NetdustLTI\Platform;

use NTDST_Service_Meta;

use function add_action;
use function add_filter;
use function add_rewrite_rule;
use function get_query_var;
use function wp_die;
use function ntdst_get;

/**
 * Routes /lti/platform/* endpoints for when this site acts as an LTI Platform
 * launching external tools.
 *
 * Endpoints:
 * - /lti/platform/launch - initiate OIDC login flow
 * - /lti/platform/auth - receive tool redirect, create JWT
 * - /lti/platform/deep-link-return - receive course selection
 * - /lti/platform/grades - AGS grade passback
 */
final class PlatformRouter implements NTDST_Service_Meta
{
    public static function metadata(): array
    {
        return [
            'name' => 'LTI Platform Router',
            'description' => 'Routes /lti/platform/* endpoints for Platform role',
            'priority' => 10,
        ];
    }

    public function __construct()
    {
        add_action('init', [$this, 'registerRewriteRules']);
        add_filter('query_vars', [$this, 'registerQueryVars']);
        add_action('template_redirect', [$this, 'handleRequest']);
    }

    /**
     * Register rewrite rules for platform endpoints.
     */
    public function registerRewriteRules(): void
    {
        add_rewrite_rule(
            '^lti/platform/([a-z-]+)/?$',
            'index.php?lti_platform_action=$matches[1]',
            'top'
        );
    }

    /**
     * Register query vars for platform endpoints.
     *
     * @param array $vars Existing query vars
     * @return array Modified query vars
     */
    public function registerQueryVars(array $vars): array
    {
        $vars[] = 'lti_platform_action';
        return $vars;
    }

    /**
     * Handle incoming platform requests.
     */
    public function handleRequest(): void
    {
        $action = get_query_var('lti_platform_action');

        if (!$action) {
            return;
        }

        // Configure session for cross-site requests
        $this->configureSession();

        switch ($action) {
            case 'launch':
                $this->handleLaunchInitiation();
                break;

            case 'auth':
                $this->handleAuthCallback();
                break;

            case 'deep-link-return':
                $this->handleDeepLinkReturn();
                break;

            case 'grades':
                $this->handleGradePassback();
                break;

            default:
                wp_die('Invalid platform action', 'LTI Platform Error', ['response' => 400]);
        }
    }

    /**
     * Configure session for cross-site requests (SameSite=None for HTTPS).
     */
    private function configureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
                ini_set('session.cookie_samesite', 'None');
                ini_set('session.cookie_secure', '1');
            }
            session_start();
        }
    }

    /**
     * Handle launch initiation - OIDC login flow start.
     */
    private function handleLaunchInitiation(): void
    {
        $initiator = ntdst_get(OIDCInitiator::class);

        if ($initiator === null) {
            wp_die('OIDCInitiator service not available', 'LTI Platform Error', ['response' => 500]);
        }

        $initiator->initiateLaunch();
    }

    /**
     * Handle auth callback - receive tool redirect, create JWT.
     */
    private function handleAuthCallback(): void
    {
        $builder = ntdst_get(JWTBuilder::class);

        if ($builder === null) {
            wp_die('JWTBuilder service not available', 'LTI Platform Error', ['response' => 500]);
        }

        $builder->handleAuthCallback();
    }

    /**
     * Handle deep link return - receive course selection from tool.
     */
    private function handleDeepLinkReturn(): void
    {
        // TODO: Implement deep link return handling
        wp_die('Deep link return not yet implemented', 'LTI Platform', ['response' => 501]);
    }

    /**
     * Handle grade passback - AGS grade submission from tool.
     */
    private function handleGradePassback(): void
    {
        $receiver = ntdst_get(AGSReceiver::class);

        if ($receiver === null) {
            wp_die('AGSReceiver service not available', 'LTI Platform Error', ['response' => 500]);
        }

        $receiver->handleGradeSubmission();
    }
}
