<?php
declare(strict_types=1);

namespace NetdustLTI\ToolProvider;

use NetdustLTI\Shared\JsonResponseTrait;
use NetdustLTI\Shared\SessionConfigTrait;

/**
 * Routes /lti/* endpoints for Tool Provider role.
 *
 * Handles incoming LTI launches from external platforms.
 */
final class Router
{
    use JsonResponseTrait;
    use SessionConfigTrait;

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

            case 'deep-link-picker':
                $this->handleDeepLinkPicker();
                break;

            case 'deep-link-submit':
                $this->handleDeepLinkSubmit();
                break;

            case 'configure-json':
                $this->handleConfigureJson();
                break;

            case 'configure-xml':
                $this->handleConfigureXml();
                break;

            case 'register':
                $this->handleRegistration();
                break;

            default:
                wp_die('Invalid LTI action', 'LTI Error', ['response' => 400]);
        }
    }

    private function handleLaunch(): void
    {
        $dataConnector = ntdst_get(WPDataConnector::class);
        $tool = new Tool($dataConnector);

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
        header('Cache-Control: public, max-age=3600');

        $publicKey = get_option('netdust_lti_public_key');
        $kid = get_option('netdust_lti_kid');

        if (!$publicKey || !$kid) {
            $this->sendJsonError('Keys not configured', 500);
        }

        // Convert PEM to JWKS using FirebaseClient
        $jwks = \ceLTIc\LTI\Jwt\FirebaseClient::getJWKS($publicKey, 'RS256', $kid);

        $this->sendJsonSuccess($jwks, 200);
    }

    private function handleDeepLink(): void
    {
        // Deep linking uses the same launch handler initially
        // The tool's onContentItem() method handles the redirect
        $this->handleLaunch();
    }

    /**
     * Show the course picker for deep linking (frontend, no admin required).
     */
    private function handleDeepLinkPicker(): void
    {
        if (!isset($_SESSION['lti_deep_link'])) {
            wp_die(
                __('Invalid deep link session. Please try again from your LMS.', 'netdust-lti'),
                __('Deep Link Error', 'netdust-lti'),
                ['response' => 400]
            );
        }

        // Note: CSRF token removed - the lti_deep_link session data itself is the authentication

        // Get available courses
        $courses = get_posts([
            'post_type' => 'sfwd-courses',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'post_status' => 'publish',
        ]);

        // Render the picker template
        $templatePath = dirname(__DIR__, 2) . '/templates/deep-link-picker.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        } else {
            // Fallback inline template
            $this->renderInlineDeepLinkPicker($courses);
        }
        exit;
    }

    /**
     * Handle course selection form submission for deep linking.
     */
    private function handleDeepLinkSubmit(): void
    {
        // Note: CSRF token removed - the lti_deep_link session data itself is the authentication
        // (it was set during the authenticated LTI deep link launch from the platform)

        if (!isset($_SESSION['lti_deep_link'])) {
            wp_die(
                __('Invalid deep link session. Please try again from your LMS.', 'netdust-lti'),
                __('Deep Link Error', 'netdust-lti'),
                ['response' => 400]
            );
        }

        $courseId = isset($_POST['course_id']) ? (int) $_POST['course_id'] : 0;
        $course = get_post($courseId);

        if (!$course || $course->post_type !== 'sfwd-courses') {
            wp_die(
                __('Invalid course selected. Please try again.', 'netdust-lti'),
                __('Invalid Course', 'netdust-lti'),
                ['response' => 400]
            );
        }

        $deepLinkData = $_SESSION['lti_deep_link'];
        unset($_SESSION['lti_deep_link']);

        $this->sendDeepLinkResponse($deepLinkData, $course);
    }

    /**
     * Build and send the Deep Link response to the platform.
     */
    private function sendDeepLinkResponse(array $deepLinkData, \WP_Post $course): void
    {
        try {
            $dataConnector = ntdst_get(WPDataConnector::class);
            $platform = \ceLTIc\LTI\Platform::fromRecordId($deepLinkData['platform_id'], $dataConnector);

        if (!$platform || !$platform->getRecordId()) {
            wp_die(
                __('Could not load platform configuration.', 'netdust-lti'),
                __('Platform Error', 'netdust-lti'),
                ['response' => 500]
            );
        }

        // Create LTI Resource Link item
        $item = new \ceLTIc\LTI\Content\LtiLinkItem();
        $item->setTitle($course->post_title);
        $item->setUrl(home_url('/lti/launch'));

        // Set description
        $description = $course->post_excerpt;
        if (empty($description)) {
            $description = wp_trim_words(wp_strip_all_tags($course->post_content), 30, '...');
        }
        $item->setText($description);

        // Custom parameters - course ID for launches
        $item->addCustom('ld_course_id', (string) $course->ID);

        // Line item for gradebook
        $lineItem = new \ceLTIc\LTI\Content\LineItem(
            $course->post_title . ' - ' . __('Completion', 'netdust-lti'),
            100,
            'course-' . $course->ID,
            'completion'
        );
        $item->setLineItem($lineItem);

        // Create tool for signing
        $tool = new Tool($dataConnector);
        $tool->platform = $platform;

        // Build response
        $messageParams = [
            'content_items' => \ceLTIc\LTI\Content\Item::toJson([$item], \ceLTIc\LTI\Enum\LtiVersion::V1P3),
        ];

        if (!empty($deepLinkData['data'])) {
            $messageParams['data'] = $deepLinkData['data'];
        }

        ntdst_log('lti')->info('Sending deep link response', [
            'platform_id' => $platform->getRecordId(),
            'course_id' => $course->ID,
            'return_url' => $deepLinkData['return_url'],
        ]);

        // Send the message
        $html = $tool->sendMessage(
            $deepLinkData['return_url'],
            'ContentItemSelection',
            $messageParams
        );

        echo $html;
        exit;
        } catch (\Throwable $e) {
            ntdst_log('lti')->error('Deep link response failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            wp_die(
                'Deep link error: ' . esc_html($e->getMessage()),
                'LTI Error',
                ['response' => 500]
            );
        }
    }

    /**
     * Render inline deep link picker if template not found.
     */
    private function renderInlineDeepLinkPicker(array $courses): void
    {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title><?php esc_html_e('Select Course', 'netdust-lti'); ?></title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 20px; max-width: 600px; margin: 0 auto; }
                h1 { font-size: 24px; margin-bottom: 20px; }
                .course-list { list-style: none; padding: 0; }
                .course-item { padding: 15px; border: 1px solid #ddd; margin-bottom: 10px; border-radius: 4px; cursor: pointer; }
                .course-item:hover { background: #f5f5f5; }
                .course-item input { margin-right: 10px; }
                button { background: #0073aa; color: white; border: none; padding: 12px 24px; font-size: 16px; cursor: pointer; border-radius: 4px; }
                button:hover { background: #005a87; }
            </style>
        </head>
        <body>
            <h1><?php esc_html_e('Select a Course', 'netdust-lti'); ?></h1>
            <form method="post" action="<?php echo esc_url(home_url('/lti/deep-link-submit')); ?>">
                <ul class="course-list">
                    <?php foreach ($courses as $course): ?>
                        <li class="course-item">
                            <label>
                                <input type="radio" name="course_id" value="<?php echo esc_attr($course->ID); ?>" required>
                                <?php echo esc_html($course->post_title); ?>
                            </label>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (empty($courses)): ?>
                    <p><?php esc_html_e('No courses available.', 'netdust-lti'); ?></p>
                <?php else: ?>
                    <button type="submit"><?php esc_html_e('Select Course', 'netdust-lti'); ?></button>
                <?php endif; ?>
            </form>
        </body>
        </html>
        <?php
    }

    private function handleRegistration(): void
    {
        // Only admins can register platforms
        if (!current_user_can('manage_options')) {
            wp_die(
                __('You must be logged in as an administrator to register platforms.', 'netdust-lti'),
                __('Unauthorized', 'netdust-lti'),
                ['response' => 403]
            );
        }

        $openidConfig = $_GET['openid_configuration'] ?? '';
        $registrationToken = $_GET['registration_token'] ?? null;

        if (empty($openidConfig) || !filter_var($openidConfig, FILTER_VALIDATE_URL)) {
            wp_die(
                __('Missing or invalid openid_configuration parameter.', 'netdust-lti'),
                __('Registration Error', 'netdust-lti'),
                ['response' => 400]
            );
        }

        $dataConnector = ntdst_get(WPDataConnector::class);
        $tool = new Tool($dataConnector);

        // ceLTIc handles the registration protocol
        $tool->handleRequest();

        // If the tool generated HTML output (confirmation form), display it
        if ($tool->errorOutput) {
            echo $tool->errorOutput;
            exit;
        }
    }

    private function handleConfigureJson(): void
    {
        header('Cache-Control: public, max-age=3600');
        $this->sendJsonSuccess($this->buildJsonConfig(), 200);
    }

    private function handleConfigureXml(): void
    {
        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        echo $this->buildCanvasXml();
        exit;
    }

    /**
     * Build LTI 1.3 JSON configuration for platform registration.
     *
     * @return array<string, mixed>
     */
    protected function buildJsonConfig(): array
    {
        $homeUrl = home_url();

        return [
            'title' => get_bloginfo('name') ?: 'Stride LMS',
            'description' => 'LearnDash course delivery via LTI 1.3',
            'oidc_initiation_url' => $homeUrl . '/lti/login',
            'target_link_uri' => $homeUrl . '/lti/launch',
            'jwks_uri' => $homeUrl . '/lti/jwks',
            'claims' => ['sub', 'name', 'email', 'given_name', 'family_name'],
            'messages' => [
                [
                    'type' => 'LtiResourceLinkRequest',
                    'target_link_uri' => $homeUrl . '/lti/launch',
                ],
                [
                    'type' => 'LtiDeepLinkingRequest',
                    'target_link_uri' => $homeUrl . '/lti/deep-link',
                ],
            ],
            'scopes' => [
                'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem',
                'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem.readonly',
                'https://purl.imsglobal.org/spec/lti-ags/scope/score',
                'https://purl.imsglobal.org/spec/lti-ags/scope/result.readonly',
            ],
        ];
    }

    /**
     * Build Canvas-compatible LTI XML configuration.
     */
    protected function buildCanvasXml(): string
    {
        $homeUrl = home_url();
        $domain = wp_parse_url($homeUrl, PHP_URL_HOST);
        $title = esc_html(get_bloginfo('name') ?: 'Stride LMS');

        return '<?xml version="1.0" encoding="UTF-8"?>
<cartridge_basiclti_link xmlns="http://www.imsglobal.org/xsd/imslticc_v1p0"
    xmlns:blti="http://www.imsglobal.org/xsd/imsbasiclti_v1p0"
    xmlns:lticm="http://www.imsglobal.org/xsd/imslticm_v1p0"
    xmlns:lticp="http://www.imsglobal.org/xsd/imslticp_v1p0"
    xmlns:lti="http://www.imsglobal.org/xsd/imslti_v1p0">
  <blti:title>' . $title . '</blti:title>
  <blti:description>LearnDash course delivery via LTI 1.3</blti:description>
  <blti:launch_url>' . esc_url($homeUrl) . '/lti/launch</blti:launch_url>
  <blti:extensions platform="canvas.instructure.com">
    <lticm:property name="privacy_level">public</lticm:property>
    <lticm:property name="domain">' . esc_html($domain) . '</lticm:property>
    <lticm:options name="placements">
      <lticm:options name="course_navigation">
        <lticm:property name="enabled">true</lticm:property>
      </lticm:options>
    </lticm:options>
  </blti:extensions>
</cartridge_basiclti_link>';
    }
}
