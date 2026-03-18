<?php
declare(strict_types=1);

namespace NetdustLTI\Platform;

use NetdustLTI\Platform\ToolRepository;
use WP_Error;

use function absint;
use function sanitize_text_field;
use function esc_url_raw;
use function wp_die;
use function is_wp_error;
use function home_url;
use function get_current_user_id;
use function wp_json_encode;
use function add_query_arg;
use function wp_redirect;

/**
 * Initiates OIDC login flow for LTI 1.3 tool launches.
 *
 * This is the first step when acting as an LTI Platform launching external tools:
 * 1. Validate input parameters (tool_id required)
 * 2. Load tool configuration from ToolRepository
 * 3. Generate cryptographic state and nonce
 * 4. Store session data for callback validation
 * 5. Build OIDC login request parameters
 * 6. Redirect to tool's OIDC login endpoint
 */
final class OIDCInitiator
{
    public function __construct(
        private readonly ToolRepository $toolRepository
    ) {}

    /**
     * Initiate the LTI launch by starting the OIDC login flow.
     *
     * Reads tool_id from POST or GET, validates the tool exists,
     * generates security tokens, and redirects to the tool's OIDC endpoint.
     */
    public function initiateLaunch(): void
    {
        // Validate required parameters
        $toolId = absint($_POST['tool_id'] ?? $_GET['tool_id'] ?? 0);
        $resourceLinkId = sanitize_text_field($_POST['resource_link_id'] ?? $_GET['resource_link_id'] ?? '');
        $targetLinkUri = esc_url_raw($_POST['target_link_uri'] ?? $_GET['target_link_uri'] ?? '');
        $messageType = sanitize_text_field($_POST['message_type'] ?? $_GET['message_type'] ?? 'LtiResourceLinkRequest');

        if (!$toolId) {
            wp_die('Missing tool_id parameter', 'LTI Platform Error', ['response' => 400]);
        }

        // Get tool configuration
        $tool = $this->toolRepository->find($toolId);

        if (is_wp_error($tool)) {
            wp_die($tool->get_error_message(), 'LTI Platform Error', ['response' => 404]);
        }

        // Generate state and nonce
        $state = $this->generateState();
        $nonce = $this->generateNonce();

        // Ensure session is started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Store in session for validation on callback
        $_SESSION['lti_platform_state'] = $state;
        $_SESSION['lti_platform_nonce'] = $nonce;
        $_SESSION['lti_platform_tool_id'] = $toolId;
        $_SESSION['lti_platform_resource_link_id'] = $resourceLinkId;
        $_SESSION['lti_platform_target_link_uri'] = $targetLinkUri;
        $_SESSION['lti_platform_message_type'] = $messageType;

        // Build OIDC login request parameters
        // Note: state is included so the tool can echo it back for CSRF validation
        $loginParams = [
            'iss' => home_url(),
            'target_link_uri' => $targetLinkUri ?: $tool->fields['launch_url'],
            'login_hint' => (string) get_current_user_id(),
            'lti_message_hint' => wp_json_encode([
                'resource_link_id' => $resourceLinkId,
                'tool_id' => $toolId,
            ]),
            'client_id' => $tool->fields['client_id'],
            'lti_deployment_id' => $tool->fields['deployment_id'] ?: '1',
            'state' => $state,
        ];

        // Redirect to tool's OIDC login endpoint
        $loginUrl = add_query_arg($loginParams, $tool->fields['oidc_url']);

        wp_redirect($loginUrl);
        exit;
    }

    /**
     * Generate a cryptographically secure state parameter.
     *
     * @return string 64-character hex string (32 bytes)
     */
    public function generateState(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Generate a cryptographically secure nonce parameter.
     *
     * @return string 32-character hex string (16 bytes)
     */
    public function generateNonce(): string
    {
        return bin2hex(random_bytes(16));
    }
}
