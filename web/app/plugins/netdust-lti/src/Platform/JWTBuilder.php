<?php
declare(strict_types=1);

namespace NetdustLTI\Platform;

use ceLTIc\LTI\Jwt\FirebaseClient;
use NetdustLTI\Repositories\ToolRepository;
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

/**
 * Builds and signs JWTs for LTI 1.3 tool launches.
 *
 * Handles the auth callback from an LTI tool by:
 * 1. Validating the state parameter against session
 * 2. Loading tool configuration
 * 3. Building LTI claims
 * 4. Signing the JWT with platform RSA key
 * 5. Outputting auto-submit form to tool's launch URL
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
     * it redirects back here with the state parameter. We validate state,
     * build the LTI claims, sign a JWT, and POST it to the tool's launch URL.
     */
    public function handleAuthCallback(): void
    {
        // Validate state
        $state = sanitize_text_field($_GET['state'] ?? '');
        $sessionState = $_SESSION['lti_platform_state'] ?? '';

        if (empty($state) || $state !== $sessionState) {
            wp_die('Invalid state parameter', 'LTI Platform Error', ['response' => 400]);
        }

        // Get session data
        $toolId = absint($_SESSION['lti_platform_tool_id'] ?? 0);
        $nonce = $_SESSION['lti_platform_nonce'] ?? '';
        $resourceLinkId = $_SESSION['lti_platform_resource_link_id'] ?? '';
        $targetLinkUri = $_SESSION['lti_platform_target_link_uri'] ?? '';

        // Clear session data
        unset($_SESSION['lti_platform_state']);
        unset($_SESSION['lti_platform_nonce']);
        unset($_SESSION['lti_platform_tool_id']);
        unset($_SESSION['lti_platform_resource_link_id']);
        unset($_SESSION['lti_platform_target_link_uri']);

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

        // Build JWT claims
        $claims = $this->buildLTIClaims(
            $user,
            $resourceLinkId ?: 'resource-' . $toolId,
            $targetLinkUri ?: $tool->fields['launch_url']
        );

        // Add tool-specific claims
        $claims['aud'] = $tool->fields['client_id'];
        $claims['azp'] = $tool->fields['client_id'];
        $claims['nonce'] = $nonce;
        $claims['https://purl.imsglobal.org/spec/lti/claim/deployment_id'] =
            $tool->fields['deployment_id'] ?: '1';

        // Sign JWT
        $idToken = $this->signJWT($claims);

        // Output auto-submit form to tool's launch URL
        $this->outputLaunchForm($tool->fields['launch_url'], $idToken, $state);
    }

    /**
     * Build the LTI 1.3 claims array.
     *
     * @param object $user WordPress user object (or similar with ID, user_email, display_name)
     * @param string $resourceLinkId Unique identifier for this resource link
     * @param string $targetLinkUri The tool's target URL for the launch
     * @return array LTI claims for the JWT payload
     */
    public function buildLTIClaims(object $user, string $resourceLinkId, string $targetLinkUri): array
    {
        $now = time();

        return [
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
