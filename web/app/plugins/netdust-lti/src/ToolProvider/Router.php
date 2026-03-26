<?php
declare(strict_types=1);

namespace NetdustLTI\ToolProvider;

use NetdustLTI\Shared\JsonResponseTrait;

/**
 * Routes /lti/* endpoints for Tool Provider role.
 *
 * Handles incoming LTI launches from external platforms.
 */
final class Router
{
    use JsonResponseTrait;

    public function __construct()
    {
        add_action('init', [$this, 'registerRewriteRules']);
        add_filter('query_vars', [$this, 'registerQueryVars']);

        // Priority 1 to run before redirect_canonical (priority 10)
        add_action('template_redirect', [$this, 'handleRequest'], 1);

        // Prevent canonical redirects for LTI tool endpoints
        add_filter('redirect_canonical', [$this, 'preventLtiRedirects'], 10, 2);
    }

    /**
     * Prevent WordPress from adding canonical redirects to LTI tool endpoints.
     */
    public function preventLtiRedirects(string $redirectUrl, string $requestedUrl): string|false
    {
        if (str_contains($requestedUrl, '/lti/')) {
            return false;
        }

        return $redirectUrl;
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

        // Allow LTI endpoints to be loaded in platform iframes
        // CSP frame-ancestors overrides X-Frame-Options in modern browsers
        header_remove('X-Frame-Options');
        header('Content-Security-Policy: frame-ancestors *');

        // Strip WordPress magic quotes from superglobals.
        // WordPress's wp_magic_quotes() adds backslashes to $_POST/$_GET/$_REQUEST,
        // which corrupts LTI parameters (e.g., JSON in lti_message_hint).
        // The ceLTIc library reads from these superglobals directly.
        $_POST = wp_unslash($_POST);
        $_GET = wp_unslash($_GET);
        $_REQUEST = wp_unslash($_REQUEST);

        // Let ceLTIc manage its own session lifecycle for OIDC state.
        // Starting a session early interferes with ceLTIc's cookie detection,
        // platform storage fallback, and session-id-in-state recovery.

        switch ($action) {
            case 'login':
            case 'launch':
                $this->handleLaunch();
                break;

            case 'content':
                $this->handleContent();
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

            case 'scorm-proxy':
                ntdst_get(Bridges\ScormProxyBridge::class)->serve();
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
        // Safari re-requests iframe URLs as GET 2-3 seconds after a POST load.
        // This can replay either the final /lti/launch URL (bare GET) or the
        // initial /lti/login?iss=...&login_hint=... OIDC initiation URL.
        // In both cases, check for a recent launch transient and redirect.
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $sessionKey = 'lti_reload_' . md5($_SERVER['REMOTE_ADDR'] . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
            $lastLaunch = get_transient($sessionKey);
            if ($lastLaunch) {
                delete_transient($sessionKey);
                $contentUrl = home_url('/lti/content') . '?_lti=' . urlencode($lastLaunch['token'])
                    . '&post_id=' . $lastLaunch['post_id'];
                wp_redirect($contentUrl);
                exit;
            }

            // No transient — might be a genuine OIDC initiation (GET with iss+login_hint).
            // Let those through to ceLTIc. Block bare GETs with friendly message.
            if (empty($_GET['login_hint']) && empty($_GET['iss']) && empty($_GET['openid_configuration'])) {
                wp_die('Open deze cursus via uw LMS (bijv. Moodle).', 'LTI', ['response' => 400]);
            }
        }

        $dataConnector = ntdst_get(WPDataConnector::class);
        $tool = new Tool($dataConnector);

        // Skip ceLTIc's cookie detection that triggers a popup window.
        // In iframe contexts, third-party cookies are blocked, so $_COOKIE is empty.
        // ceLTIc interprets this as "browser blocking" and opens a _blank window.
        // Since we use token-based auth (not cookies), this check is unnecessary.
        // Setting _new_window tells ceLTIc to skip the popup fallback.
        if (!isset($_POST['_new_window'])) {
            $_POST['_new_window'] = '';
        }

        // handleRequest() calls onLaunch() which sets ltiTargetPostId
        // instead of redirectUrl, so the library returns without exiting.
        $tool->handleRequest();

        // Render target post inline using our LTI iframe template
        $postId = $tool->getLtiTargetPostId();
        if ($postId) {
            // Store launch context for Safari's spurious GET reload (30s TTL)
            $sessionKey = 'lti_reload_' . md5($_SERVER['REMOTE_ADDR'] . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
            set_transient($sessionKey, [
                'post_id' => $postId,
                'token' => $tool->getLtiNavToken(),
            ], 30);

            $this->renderInlinePost($postId, $tool->getLtiNavToken());
            // renderInlinePost exits
        }

        // Library handled redirect (e.g. /mijn-account/ fallback) or error
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

        if ($tool->getErrorOutput()) {
            echo $tool->getErrorOutput();
            exit;
        }
    }

    /**
     * Handle cookie-free content navigation within the LTI iframe.
     *
     * Validates a navigation token (generated during LTI launch) and renders
     * the requested post. This allows lesson-to-lesson navigation without
     * relying on third-party cookies for authentication.
     */
    private function handleContent(): void
    {
        $token = sanitize_text_field($_GET['_lti'] ?? $_POST['_lti'] ?? '');
        $postId = absint($_GET['post_id'] ?? $_POST['post_id'] ?? 0);
        $url = sanitize_text_field($_GET['url'] ?? '');

        ntdst_log('lti')->info('Content request', [
            'token' => $token ? substr($token, 0, 8) . '...' : 'EMPTY',
            'post_id' => $postId,
            'url' => $url,
        ]);

        if (!$token) {
            wp_die('Missing authentication token', 'LTI Error', ['response' => 400]);
        }

        $data = get_transient('lti_nav_' . $token);
        if (!$data) {
            ntdst_log('lti')->error('Nav token expired/invalid', ['token' => substr($token, 0, 8) . '...']);
            wp_die('Session expired. Please reopen this course from your LMS.', 'LTI Error', ['response' => 403]);
        }

        // Authenticate without cookies
        wp_set_current_user($data['user_id']);

        // Refresh token TTL on each use
        set_transient('lti_nav_' . $token, $data, 8 * HOUR_IN_SECONDS);

        // Resolve URL path to post ID if not provided directly
        if (!$postId && $url) {
            $postId = url_to_postid(home_url($url));

            // Fallback: LearnDash URLs (courses/lessons/topics) may not resolve via url_to_postid
            if (!$postId) {
                $postId = $this->resolveLearnDashUrl($url);
            }
        }

        ntdst_log('lti')->info('Content resolved', [
            'post_id' => $postId,
            'user_id' => $data['user_id'],
        ]);

        if (!$postId) {
            wp_die('Content not found', 'LTI Error', ['response' => 404]);
        }

        // Prevent referrer leaking the token
        header('Referrer-Policy: no-referrer');

        $this->renderInlinePost($postId, $token);
    }

    /**
     * Render a post directly using the LTI iframe template, then exit.
     *
     * Bypasses the WordPress/BuddyBoss template system entirely.
     * Sets up the query so LearnDash shortcodes and the_content() work correctly.
     */
    private function renderInlinePost(int $postId, ?string $navToken = null): void
    {
        global $wp_query, $post;

        $post = get_post($postId);
        if (!$post) {
            wp_die('Post not found', 'LTI Error', ['response' => 404]);
        }

        // Build a proper singular query so is_singular() and get_post_type() work.
        // LearnDash enqueue functions check these to load focus mode assets.
        $wp_query = new \WP_Query([
            'p' => $postId,
            'post_type' => $post->post_type,
            'post_status' => 'any',
        ]);
        $wp_query->is_singular = true;
        $wp_query->is_single   = true;

        setup_postdata($post);

        // Make token available to the template for link rewriting
        $GLOBALS['lti_nav_token'] = $navToken;

        // Prevent referrer leaking the token
        if ($navToken) {
            header('Referrer-Policy: no-referrer');
        }

        $template = dirname(__DIR__, 2) . '/templates/lti-iframe.php';
        if (file_exists($template)) {
            include $template;
        } else {
            echo '<p>LTI template not found.</p>';
        }
        exit;
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
        // Retrieve deep link data from transient (session-free for cross-origin iframe support)
        $token = sanitize_text_field($_GET['dl_token'] ?? '');
        $deepLinkData = $token ? get_transient('lti_dl_' . $token) : false;

        if (!$deepLinkData) {
            wp_die(
                __('Invalid or expired deep link token. Please try again from your LMS.', 'netdust-lti'),
                __('Deep Link Error', 'netdust-lti'),
                ['response' => 400]
            );
        }

        // Get online courses only (exclude in-person 'VAD vormingen' category)
        $courses = get_posts([
            'post_type' => 'sfwd-courses',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'post_status' => 'publish',
            'tax_query' => [
                [
                    'taxonomy' => 'ld_course_category',
                    'field' => 'name',
                    'terms' => 'VAD vormingen',
                    'operator' => 'NOT IN',
                ],
            ],
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
        // Retrieve deep link data from transient (session-free for cross-origin iframe support)
        $token = sanitize_text_field($_POST['dl_token'] ?? '');
        $deepLinkData = $token ? get_transient('lti_dl_' . $token) : false;

        if (!$deepLinkData) {
            wp_die(
                __('Invalid or expired deep link token. Please try again from your LMS.', 'netdust-lti'),
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

        // Delete the transient (one-time use)
        delete_transient('lti_dl_' . $token);

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
                <input type="hidden" name="dl_token" value="<?php echo esc_attr(sanitize_text_field($_GET['dl_token'] ?? '')); ?>">
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

        // ceLTIc handles the full Dynamic Registration protocol:
        // fetch OpenID config, register with platform, save, show response page, exit
        $tool->handleRequest();

        // Safety net — handleRequest normally exits via doExit()
        if ($tool->getErrorOutput()) {
            echo $tool->getErrorOutput();
        }
        exit;
    }

    private function handleConfigureJson(): void
    {
        header('Cache-Control: public, max-age=3600');
        $mode = sanitize_text_field($_GET['mode'] ?? '1.3');
        if ($mode === 'legacy') {
            $this->sendJsonSuccess($this->buildLegacyJsonConfig(), 200);
        } else {
            $this->sendJsonSuccess($this->buildJsonConfig(), 200);
        }
    }

    private function handleConfigureXml(): void
    {
        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        $mode = sanitize_text_field($_GET['mode'] ?? '1.3');
        if ($mode === 'legacy') {
            echo $this->buildLegacyCanvasXml();
        } else {
            echo $this->buildCanvasXml();
        }
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

    /**
     * Build LTI 1.1/1.2 JSON configuration (legacy).
     * Consumer key/secret are NOT included — copy from admin UI.
     */
    protected function buildLegacyJsonConfig(): array
    {
        $homeUrl = home_url();

        return [
            'title'       => get_bloginfo('name') ?: 'Stride LMS',
            'description' => 'LearnDash course delivery via LTI 1.1/1.2',
            'launch_url'  => $homeUrl . '/lti/launch',
        ];
    }

    /**
     * Build LTI 1.1/1.2 Canvas-compatible XML configuration (legacy).
     * Consumer key/secret are NOT included — copy from admin UI.
     */
    protected function buildLegacyCanvasXml(): string
    {
        $homeUrl = home_url();
        $domain = wp_parse_url($homeUrl, PHP_URL_HOST);
        $title = esc_html(get_bloginfo('name') ?: 'Stride LMS');

        return '<?xml version="1.0" encoding="UTF-8"?>
<cartridge_basiclti_link xmlns="http://www.imsglobal.org/xsd/imslticc_v1p0"
    xmlns:blti="http://www.imsglobal.org/xsd/imsbasiclti_v1p0"
    xmlns:lticm="http://www.imsglobal.org/xsd/imslticm_v1p0"
    xmlns:lticp="http://www.imsglobal.org/xsd/imslticp_v1p0">
  <blti:title>' . $title . '</blti:title>
  <blti:description>LearnDash course delivery via LTI 1.1/1.2</blti:description>
  <blti:launch_url>' . esc_url($homeUrl) . '/lti/launch</blti:launch_url>
  <blti:extensions platform="canvas.instructure.com">
    <lticm:property name="privacy_level">public</lticm:property>
    <lticm:property name="domain">' . esc_html($domain) . '</lticm:property>
  </blti:extensions>
</cartridge_basiclti_link>';
    }

    /**
     * Resolve a LearnDash URL path to a post ID.
     *
     * WordPress's url_to_postid() doesn't handle nested CPT slugs
     * like /courses/X/lessons/Y/ or /courses/X/topic/Z/.
     */
    private function resolveLearnDashUrl(string $urlPath): int
    {
        $path = trim($urlPath, '/');
        $segments = explode('/', $path);

        // Try the last meaningful slug segment
        $slug = end($segments);
        if (!$slug) {
            return 0;
        }

        $ldTypes = ['sfwd-courses', 'sfwd-lessons', 'sfwd-topic', 'sfwd-quiz'];

        $posts = get_posts([
            'name' => $slug,
            'post_type' => $ldTypes,
            'post_status' => 'any',
            'numberposts' => 1,
        ]);

        if (!empty($posts)) {
            return $posts[0]->ID;
        }

        return 0;
    }
}
