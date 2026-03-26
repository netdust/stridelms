<?php
declare(strict_types=1);

namespace NetdustLTI\Platform;

use NTDST_Service_Meta;
use NetdustLTI\Platform\WPPlatform;
use NetdustLTI\Platform\ToolKeyResolver;
use NetdustLTI\Shared\JsonResponseTrait;
use NetdustLTI\Shared\SessionConfigTrait;

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
final class Router implements NTDST_Service_Meta
{
    use JsonResponseTrait;
    use SessionConfigTrait;

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

        // Handle LTI API endpoints early to avoid redirect issues
        // Priority 1 on template_redirect to run before most other handlers
        add_action('template_redirect', [$this, 'handleRequest'], 1);

        // Prevent canonical redirects for LTI endpoints (avoids trailing slash redirects)
        add_filter('redirect_canonical', [$this, 'preventLtiRedirects'], 10, 2);

        // Handle LTI requests very early for API endpoints (token, grades)
        // These don't need WordPress template handling and must avoid redirects
        add_action('parse_request', [$this, 'handleEarlyApiRequest']);
    }

    /**
     * Handle LTI API requests very early before WordPress redirect logic.
     *
     * This intercepts requests to /lti/platform/token and /lti/platform/grades
     * before WordPress can redirect them. These are machine-to-machine API calls
     * that won't follow redirects.
     *
     * @param \WP $wp WordPress request object
     */
    public function handleEarlyApiRequest(\WP $wp): void
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';

        // Check for LTI API endpoints
        // Note: AGS Score service posts to {lineitem}/scores, so we match both
        // /lti/platform/grades and /lti/platform/grades/scores
        if (preg_match('#/lti/platform/(token|grades(?:/scores)?)/?$#', $requestUri, $matches)) {
            $action = $matches[1];

            // Configure session for cross-site requests
            $this->configureSession();

            // Normalize grades/scores to grades
            if ($action === 'grades/scores' || $action === 'grades') {
                $this->handleGradePassback();
                exit;
            }

            if ($action === 'token') {
                $this->handleTokenRequest();
                exit;
            }
        }
    }

    /**
     * Prevent WordPress from adding trailing slash redirects to LTI endpoints.
     *
     * This is critical for LTI AGS because libraries like ceLTIc don't follow
     * redirects, causing OAuth2 token requests to fail with 301.
     *
     * @param string $redirectUrl The URL WordPress wants to redirect to
     * @param string $requestedUrl The original requested URL
     * @return string|false The redirect URL or false to prevent redirect
     */
    public function preventLtiRedirects(string $redirectUrl, string $requestedUrl): string|false
    {
        // Check if this is an LTI endpoint
        if (str_contains($requestedUrl, '/lti/platform/') || str_contains($requestedUrl, '/lti/launch/')) {
            return false; // Prevent redirect
        }

        return $redirectUrl;
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

        // Resource launch route: /lti/launch/{resource_id}
        add_rewrite_rule(
            '^lti/launch/(\d+)/?$',
            'index.php?lti_platform_action=resource-launch&lti_resource_id=$matches[1]',
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
        $vars[] = 'lti_resource_id';
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

        // Allow LTI endpoints to be loaded in platform iframes
        // CSP frame-ancestors overrides X-Frame-Options in modern browsers
        header_remove('X-Frame-Options');
        header('Content-Security-Policy: frame-ancestors *');

        // Strip WordPress magic quotes from superglobals.
        // WordPress's wp_magic_quotes() adds backslashes to $_POST/$_GET/$_REQUEST,
        // which corrupts LTI parameters (e.g., JSON values, JWTs).
        $_POST = wp_unslash($_POST);
        $_GET = wp_unslash($_GET);
        $_REQUEST = wp_unslash($_REQUEST);

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

            case 'token':
                $this->handleTokenRequest();
                break;

            case 'resource-launch':
                $this->handleResourceLaunch();
                break;

            default:
                wp_die('Invalid platform action', 'LTI Platform Error', ['response' => 400]);
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
        $receiver = ntdst_get(DeepLinkReceiver::class);

        if ($receiver === null) {
            wp_die('DeepLinkReceiver service not available', 'LTI Platform Error', ['response' => 500]);
        }

        $receiver->handleReturn();
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

    /**
     * Handle OAuth2 token request for AGS.
     *
     * Uses celtic/lti Platform::sendAccessToken() which:
     * 1. Validates client_assertion JWT using Tool's public key
     * 2. Signs an access token JWT with Platform's private key
     * 3. Returns the token response
     */
    private function handleTokenRequest(): void
    {
        // Only accept POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendOAuthError('invalid_request', 'POST method required', 405);
        }

        // Validate grant type
        $grantType = $_POST['grant_type'] ?? '';
        if ($grantType !== 'client_credentials') {
            $this->sendOAuthError('unsupported_grant_type', 'Only client_credentials supported', 400);
        }

        // Get client_assertion to find the tool
        $clientAssertion = $_POST['client_assertion'] ?? '';
        if (empty($clientAssertion)) {
            $this->sendOAuthError('invalid_request', 'client_assertion required', 400);
        }

        // Configure Tool::$defaultTool with the requesting tool's public key
        $resolver = new ToolKeyResolver();
        $result = $resolver->configureToolFromAssertion($clientAssertion);

        if (is_wp_error($result)) {
            $this->sendOAuthError('invalid_client', $result->get_error_message(), 401);
        }

        // Create WPPlatform and let the library handle token generation
        $platform = new WPPlatform();
        $platform->ok = true;

        // Supported AGS scopes
        $supportedScopes = [
            'https://purl.imsglobal.org/spec/lti-ags/scope/score',
            'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem',
            'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem.readonly',
            'https://purl.imsglobal.org/spec/lti-ags/scope/result.readonly',
        ];

        // Library handles JWT validation, signing, and response
        // This method calls exit() internally
        $platform->sendAccessToken($supportedScopes);
    }

    /**
     * Handle resource launch - launch a saved LTI resource.
     */
    private function handleResourceLaunch(): void
    {
        $resourceId = absint(get_query_var('lti_resource_id'));

        if (!$resourceId) {
            wp_die('Resource ID required', 'LTI Launch Error', ['response' => 400]);
        }

        // Get the resource
        $resourceModel = ntdst_data()->get('lti_resource');
        $resource = $resourceModel->find($resourceId);

        if (!$resource || is_wp_error($resource)) {
            wp_die('Resource not found', 'LTI Launch Error', ['response' => 404]);
        }

        // Get the associated tool
        $toolId = absint($resource->fields['tool_id'] ?? 0);
        $toolModel = ntdst_data()->get('lti_tool');
        $tool = $toolModel->find($toolId);

        if (!$tool || is_wp_error($tool)) {
            wp_die('Tool not found for this resource', 'LTI Launch Error', ['response' => 404]);
        }

        // Get resource details
        $courseId = $resource->fields['course_id'] ?? '';
        $customParams = json_decode($resource->fields['custom_params'] ?? '{}', true) ?: [];
        $resourceLaunchUrl = $resource->fields['launch_url'] ?? '';

        // Build the OIDC login URL with resource parameters
        $oidcUrl = $tool->fields['oidc_url'] ?? '';
        if (empty($oidcUrl)) {
            wp_die('Tool OIDC URL not configured', 'LTI Launch Error', ['response' => 500]);
        }

        // Store resource info in session for the auth callback
        $_SESSION['lti_platform_resource_link_id'] = 'resource-' . $resourceId;
        $_SESSION['lti_platform_resource_course_id'] = $courseId;
        $_SESSION['lti_platform_resource_custom'] = $customParams;
        $_SESSION['lti_platform_target_link_uri'] = $resourceLaunchUrl;

        // Initiate OIDC flow with the tool
        $initiator = ntdst_get(OIDCInitiator::class);

        if ($initiator === null) {
            wp_die('OIDCInitiator service not available', 'LTI Platform Error', ['response' => 500]);
        }

        // Set POST parameters to simulate a launch form
        $_POST['tool_id'] = $toolId;
        $_POST['resource_link_id'] = 'resource-' . $resourceId;
        $_POST['course_id'] = $courseId;
        $_POST['launch_mode'] = 'launch'; // Normal launch, not deep linking

        $initiator->initiateLaunch();
    }
}
