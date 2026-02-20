<?php
declare(strict_types=1);

namespace NetdustLTI\LTI;

use NetdustLTI\DataConnector\WPDataConnector;

final class EndpointRouter
{
    public function __construct()
    {
        add_action('init', [$this, 'registerRewriteRules']);
        add_filter('query_vars', [$this, 'registerQueryVars']);
        add_action('template_redirect', [$this, 'handleRequest']);
    }

    public function registerRewriteRules(): void
    {
        add_rewrite_rule('^lti/([a-z-]+)/?$', 'index.php?lti_action=$matches[1]', 'top');
    }

    public function registerQueryVars(array $vars): array
    {
        $vars[] = 'lti_action';
        return $vars;
    }

    public function handleRequest(): void
    {
        $action = get_query_var('lti_action');

        if (!$action) {
            return;
        }

        // Configure session for cross-site requests
        $this->configureSession();

        switch ($action) {
            case 'login':
            case 'launch':
                $this->handleLaunch();
                break;

            case 'jwks':
                $this->handleJwks();
                break;

            case 'deep-link':
                $this->handleDeepLink();
                break;

            default:
                wp_die('Invalid LTI action', 'LTI Error', ['response' => 400]);
        }
    }

    private function configureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Cross-site session cookies for LTI in iframe
            if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
                ini_set('session.cookie_samesite', 'None');
                ini_set('session.cookie_secure', '1');
            }
            session_start();
        }
    }

    private function handleLaunch(): void
    {
        $dataConnector = new WPDataConnector();
        $tool = new NetdustLTITool($dataConnector);

        $tool->handleRequest();

        if ($tool->redirectUrl) {
            wp_redirect($tool->redirectUrl);
            exit;
        }

        if (!$tool->ok) {
            wp_die(
                esc_html($tool->reason ?: 'LTI launch failed'),
                'LTI Error',
                ['response' => 400]
            );
        }

        // Output error if set
        if ($tool->errorOutput) {
            echo $tool->errorOutput;
            exit;
        }
    }

    private function handleJwks(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: public, max-age=3600');

        $publicKey = get_option('netdust_lti_public_key');
        $kid = get_option('netdust_lti_kid');

        if (!$publicKey || !$kid) {
            http_response_code(500);
            echo json_encode(['error' => 'Keys not configured']);
            exit;
        }

        // Convert PEM to JWKS using FirebaseClient
        $jwks = \ceLTIc\LTI\Jwt\FirebaseClient::getJWKS($publicKey, 'RS256', $kid);

        echo json_encode($jwks);
        exit;
    }

    private function handleDeepLink(): void
    {
        // Deep linking uses the same launch handler initially
        // The tool's onContentItem() method handles the redirect
        $this->handleLaunch();
    }
}
