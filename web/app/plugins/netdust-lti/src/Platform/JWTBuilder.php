<?php
declare(strict_types=1);

namespace NetdustLTI\Platform;

use ceLTIc\LTI\Jwt\FirebaseClient;
use NetdustLTI\Platform\ToolRepository;
use WP_Error;
use WP_User;

use function absint;
use function sanitize_text_field;
use function wp_die;
use function is_wp_error;
use function home_url;
use function wp_get_current_user;
use function get_option;
use function get_user_meta;
use function user_can;
use function get_current_blog_id;
use function get_bloginfo;
use function wp_insert_user;
use function wp_generate_password;
use function update_user_meta;
use function get_user_by;
use function esc_url;
use function esc_attr;
use function wp_parse_url;
use function esc_url_raw;

/**
 * Builds and signs JWTs for LTI 1.3 tool launches.
 *
 * Handles the auth callback from an LTI tool by:
 * 1. Receiving the auth request from the Tool (form_post)
 * 2. Loading tool configuration
 * 3. Building LTI claims with Tool's nonce
 * 4. Signing the JWT with platform RSA key
 * 5. Outputting auto-submit form to tool's redirect_uri
 */
final class JWTBuilder
{
    public function __construct(
        private readonly ToolRepository $toolRepository
    ) {}

    /**
     * Handle the auth callback from the LTI tool.
     *
     * After the tool's OIDC endpoint validates the login request,
     * it POSTs back here (form_post response mode) with:
     * - state: Tool's state (for Tool's CSRF protection)
     * - nonce: Tool's nonce (must be included in JWT)
     * - client_id: Identifies the Tool
     * - lti_message_hint: Echoed from login request (contains our tool_id)
     * - redirect_uri: Where to POST the JWT
     *
     * We then create the JWT with the Tool's nonce and POST it to redirect_uri.
     */
    public function handleAuthCallback(): void
    {
        // The Tool POSTs to us (form_post), so check POST params
        // Also check GET for backwards compatibility
        $state = sanitize_text_field($_POST['state'] ?? $_GET['state'] ?? '');
        $nonce = sanitize_text_field($_POST['nonce'] ?? $_GET['nonce'] ?? '');
        $redirectUri = esc_url_raw($_POST['redirect_uri'] ?? $_GET['redirect_uri'] ?? '');
        $clientId = sanitize_text_field($_POST['client_id'] ?? $_GET['client_id'] ?? '');
        $ltiMessageHint = sanitize_text_field($_POST['lti_message_hint'] ?? $_GET['lti_message_hint'] ?? '');

        // Validate required parameters
        if (empty($nonce) || empty($redirectUri)) {
            wp_die(
                'Missing required parameters.<br>nonce: ' . esc_html($nonce ?: '(empty)') .
                '<br>redirect_uri: ' . esc_html($redirectUri ?: '(empty)'),
                'LTI Platform Error',
                ['response' => 400]
            );
        }

        // Get tool_id from lti_message_hint (echoed from our login request)
        // or fall back to session
        $toolId = 0;
        $resourceLinkId = '';
        if (!empty($ltiMessageHint)) {
            $hintData = json_decode($ltiMessageHint, true);
            if (is_array($hintData)) {
                $toolId = absint($hintData['tool_id'] ?? 0);
                $resourceLinkId = sanitize_text_field($hintData['resource_link_id'] ?? '');
            }
        }

        // Fall back to session data if lti_message_hint not available
        if (!$toolId) {
            $toolId = absint($_SESSION['lti_platform_tool_id'] ?? 0);
            $resourceLinkId = $_SESSION['lti_platform_resource_link_id'] ?? '';
        }

        $targetLinkUri = $_SESSION['lti_platform_target_link_uri'] ?? '';
        $messageType = $_SESSION['lti_platform_message_type'] ?? 'LtiResourceLinkRequest';
        $courseId = $_SESSION['lti_platform_resource_course_id'] ?? '';
        $customParams = $_SESSION['lti_platform_resource_custom'] ?? [];

        // Clear session data
        unset($_SESSION['lti_platform_state']);
        unset($_SESSION['lti_platform_nonce']);
        unset($_SESSION['lti_platform_tool_id']);
        unset($_SESSION['lti_platform_resource_link_id']);
        unset($_SESSION['lti_platform_target_link_uri']);
        unset($_SESSION['lti_platform_message_type']);
        unset($_SESSION['lti_platform_resource_course_id']);
        unset($_SESSION['lti_platform_resource_custom']);

        // Get tool configuration
        $tool = $this->toolRepository->find($toolId);

        if (is_wp_error($tool)) {
            wp_die($tool->get_error_message(), 'LTI Platform Error', ['response' => 404]);
        }

        // Get current user
        $user = wp_get_current_user();

        if (!$user->exists()) {
            // Create test user for anonymous launches
            $user = $this->createTestUser();
        }

        // Use redirect_uri from auth request, or fall back to tool's launch_url
        $launchUrl = $redirectUri ?: $tool->fields['launch_url'];

        // Build custom claims array (merge course_id with any other custom params)
        $custom = $customParams;
        if (!empty($courseId)) {
            $custom['ld_course_id'] = $courseId;
        }

        // Build JWT claims
        $claims = $this->buildLTIClaims(
            $user,
            $resourceLinkId ?: 'resource-' . $toolId,
            $targetLinkUri ?: $tool->fields['launch_url'],
            $custom
        );

        // Add tool-specific claims
        // Use client_id from auth request, or fall back to tool config
        $claims['aud'] = $clientId ?: $tool->fields['client_id'];
        $claims['azp'] = $clientId ?: $tool->fields['client_id'];
        // Use Tool's nonce from auth request (required by LTI 1.3)
        $claims['nonce'] = $nonce;
        $claims['https://purl.imsglobal.org/spec/lti/claim/deployment_id'] =
            $tool->fields['deployment_id'] ?: '1';

        // Override message type if deep linking
        $claims['https://purl.imsglobal.org/spec/lti/claim/message_type'] = $messageType;

        // Add deep linking claims if this is a deep linking request
        if ($messageType === 'LtiDeepLinkingRequest') {
            $claims['https://purl.imsglobal.org/spec/lti-dl/claim/deep_linking_settings'] = [
                'deep_link_return_url' => home_url('/lti/platform/deep-link-return'),
                'accept_types' => ['ltiResourceLink'],
                'accept_presentation_document_targets' => ['iframe', 'window'],
                'accept_multiple' => false,
            ];
            // Remove resource_link claim for deep linking (not applicable)
            unset($claims['https://purl.imsglobal.org/spec/lti/claim/resource_link']);
        }

        // Sign JWT
        $idToken = $this->signJWT($claims);

        // Output auto-submit form to POST JWT to Tool's redirect_uri
        $this->outputLaunchForm($launchUrl, $idToken, $state);
    }

    /**
     * Build the LTI 1.3 claims array.
     *
     * @param object $user WordPress user object (or similar with ID, user_email, display_name)
     * @param string $resourceLinkId Unique identifier for this resource link
     * @param string $targetLinkUri The tool's target URL for the launch
     * @param array $custom Custom parameters to include in the launch (e.g., ld_course_id)
     * @return array LTI claims for the JWT payload
     */
    public function buildLTIClaims(object $user, string $resourceLinkId, string $targetLinkUri, array $custom = []): array
    {
        $now = time();

        $claims = [
            'iss' => home_url(),
            'sub' => (string) $user->ID,
            'iat' => $now,
            'exp' => $now + 3600,

            // LTI Claims
            'https://purl.imsglobal.org/spec/lti/claim/version' => '1.3.0',
            'https://purl.imsglobal.org/spec/lti/claim/message_type' => 'LtiResourceLinkRequest',
            'https://purl.imsglobal.org/spec/lti/claim/resource_link' => [
                'id' => $resourceLinkId,
                'title' => 'Course Launch',
            ],
            'https://purl.imsglobal.org/spec/lti/claim/target_link_uri' => $targetLinkUri,
            'https://purl.imsglobal.org/spec/lti/claim/roles' => $this->getUserRoles($user),

            // User identity
            'name' => $user->display_name,
            'email' => $user->user_email,
            'given_name' => get_user_meta($user->ID, 'first_name', true) ?: '',
            'family_name' => get_user_meta($user->ID, 'last_name', true) ?: '',

            // Context (optional)
            'https://purl.imsglobal.org/spec/lti/claim/context' => [
                'id' => 'platform-' . get_current_blog_id(),
                'label' => get_bloginfo('name'),
                'title' => get_bloginfo('name'),
                'type' => ['http://purl.imsglobal.org/vocab/lis/v2/course#CourseOffering'],
            ],

            // AGS (Assignment and Grade Services)
            'https://purl.imsglobal.org/spec/lti-ags/claim/endpoint' => [
                'scope' => [
                    'https://purl.imsglobal.org/spec/lti-ags/scope/score',
                ],
                'lineitem' => home_url('/lti/platform/grades'),
            ],
        ];

        // Add custom parameters if provided (e.g., ld_course_id for course-specific launches)
        if (!empty($custom)) {
            $claims['https://purl.imsglobal.org/spec/lti/claim/custom'] = $custom;
        }

        return $claims;
    }

    /**
     * Map WordPress user capabilities to LTI roles.
     *
     * @param object $user WordPress user object
     * @return string[] Array of LTI role URIs
     */
    private function getUserRoles(object $user): array
    {
        $roles = [];

        if (user_can($user->ID, 'manage_options')) {
            $roles[] = 'http://purl.imsglobal.org/vocab/lis/v2/membership#Administrator';
            $roles[] = 'http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor';
        } elseif (user_can($user->ID, 'edit_posts')) {
            $roles[] = 'http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor';
        } else {
            $roles[] = 'http://purl.imsglobal.org/vocab/lis/v2/membership#Learner';
        }

        return $roles;
    }

    /**
     * Sign the claims as a JWT using the platform's RSA private key.
     *
     * @param array $claims JWT claims
     * @return string Signed JWT
     */
    private function signJWT(array $claims): string
    {
        $privateKey = get_option('netdust_lti_private_key');
        $kid = get_option('netdust_lti_kid');

        if (!$privateKey || !$kid) {
            wp_die('LTI keys not configured', 'LTI Platform Error', ['response' => 500]);
        }

        return FirebaseClient::sign($claims, 'RS256', $privateKey, $kid);
    }

    /**
     * Create a temporary test user for anonymous launches.
     *
     * @return WP_User The created user
     */
    private function createTestUser(): WP_User
    {
        $testEmail = 'lti-test-' . time() . '@' . wp_parse_url(home_url(), PHP_URL_HOST);

        $userId = wp_insert_user([
            'user_login' => 'lti-test-' . time(),
            'user_email' => $testEmail,
            'user_pass' => wp_generate_password(),
            'display_name' => 'LTI Test User',
            'role' => 'subscriber',
        ]);

        if (is_wp_error($userId)) {
            wp_die('Failed to create test user', 'LTI Platform Error', ['response' => 500]);
        }

        update_user_meta($userId, '_lti_external_user', true);

        return get_user_by('ID', $userId);
    }

    /**
     * Output an auto-submitting HTML form to POST the JWT to the tool.
     *
     * @param string $launchUrl The tool's launch URL
     * @param string $idToken The signed JWT
     * @param string $state The state parameter
     */
    private function outputLaunchForm(string $launchUrl, string $idToken, string $state): void
    {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Launching LTI Tool...</title>
        </head>
        <body>
            <form id="lti-launch-form" action="<?php echo esc_url($launchUrl); ?>" method="POST">
                <input type="hidden" name="id_token" value="<?php echo esc_attr($idToken); ?>">
                <input type="hidden" name="state" value="<?php echo esc_attr($state); ?>">
                <noscript>
                    <p>JavaScript is required. Click to continue:</p>
                    <input type="submit" value="Launch">
                </noscript>
            </form>
            <script>document.getElementById('lti-launch-form').submit();</script>
        </body>
        </html>
        <?php
        exit;
    }
}
