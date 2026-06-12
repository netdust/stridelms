<?php
declare(strict_types=1);

namespace NetdustLTI;

use NetdustLTI\Shared\TokenAuthMiddleware;
use NTDST_Service_Meta;

final class Plugin implements NTDST_Service_Meta
{
    public const VERSION = '1.0.0';
    public const SLUG = 'netdust-lti';

    public function __construct()
    {
        $this->init();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Netdust LTI',
            'description' => 'LTI 1.3 Tool Provider',
            'priority' => 10,
        ];
    }

    private function init(): void
    {
        // Activation/deactivation hooks are registered in netdust-lti.php
        // This method initializes runtime hooks and services

        // Token-based auth for AJAX/REST calls from within the LTI iframe.
        // When third-party cookies are blocked, AJAX requests can't carry auth cookies.
        // The LTI iframe JS adds _lti token to ajaxurl so these requests still authenticate.
        (new TokenAuthMiddleware())->register();

        // Allow LTI iframe users to navigate freely (remove X-Frame-Options)
        $this->registerIframeSupport();

        // Register data models first (CPTs via Data Manager)
        ntdst_get(Shared\LTIDataService::class);

        // Register cleanup cron handler
        add_action('netdust_lti_cleanup', [$this, 'runCleanup']);

        // Register endpoint router for LTI requests (Tool Provider role)
        ntdst_get(ToolProvider\Router::class);

        // Register platform router (Platform/Consumer role - launching external tools)
        ntdst_get(Platform\Router::class);

        // Register AGS (Assignment and Grade Services) for grade passback
        ntdst_get(Platform\AGSReceiver::class);

        // Register admin UI
        if (is_admin()) {
            ntdst_get(Admin\SettingsPage::class);
            ntdst_get(Admin\CourseSettingsMetabox::class);
            ntdst_get(Admin\LaunchTestPage::class);
            ntdst_get(ToolProvider\DeepLinkHandler::class);
        }

        // Logs REST endpoint (needed outside is_admin for REST API calls)
        ntdst_get(Admin\LogsController::class);

        // Register shortcodes
        ntdst_get(Platform\LtiLaunchShortcode::class);

        // Register SCORM proxy for LTI iframe compatibility
        // (must register early — rewrite rules + template_redirect handler)
        ntdst_get(ToolProvider\Bridges\ScormProxyBridge::class);

        // Register bridges after LearnDash is loaded
        add_action('learndash_init', function () {
            ntdst_get(ToolProvider\Bridges\LearnDashBridge::class);

            // Register TinCanny bridge if available
            if (class_exists('UCTINCAN\Database')) {
                ntdst_get(ToolProvider\Bridges\TinCannyBridge::class);
            }
        });
    }

    public static function pluginPath(): string
    {
        return dirname(__DIR__);
    }

    public static function pluginUrl(): string
    {
        return plugin_dir_url(dirname(__DIR__) . '/netdust-lti.php');
    }

    /**
     * Check if the current user is in an active LTI iframe session.
     *
     * Returns true when the user has a valid _lti_iframe_until meta flag,
     * used by iframe support hooks (X-Frame-Options removal, template switching).
     */
    public static function isLtiIframeUser(): bool
    {
        $userId = get_current_user_id();
        return $userId && get_user_meta($userId, '_lti_iframe_until', true) > time();
    }

    private function registerIframeSupport(): void
    {
        // Remove X-Frame-Options for active LTI iframe users
        add_action('send_headers', function (): void {
            if (self::isLtiIframeUser()) {
                header_remove('X-Frame-Options');
                header('Content-Security-Policy: frame-ancestors *');
            }
        }, PHP_INT_MAX);

        // Use minimal LTI template for LearnDash content in iframe
        add_filter('template_include', function (string $template): string {
            if (!self::isLtiIframeUser()) {
                return $template;
            }
            $postType = get_post_type();
            $ldTypes = ['sfwd-courses', 'sfwd-lessons', 'sfwd-topic', 'sfwd-quiz'];
            if (is_singular($ldTypes)) {
                $ltiTemplate = dirname(__DIR__) . '/templates/lti-iframe.php';
                if (file_exists($ltiTemplate)) {
                    return $ltiTemplate;
                }
            }
            return $template;
        }, PHP_INT_MAX);

        // Strip focus mode user menu items for LTI iframe users
        add_filter('learndash_focus_header_user_dropdown_items', function (array $items): array {
            if (!self::isLtiIframeUser()) {
                return $items;
            }
            // Remove all menu items — no course home, no logout, no custom items
            return [];
        }, PHP_INT_MAX);
    }

    /**
     * Run cleanup of expired data.
     *
     * With transients, WordPress handles cleanup automatically.
     * This method is kept for backwards compatibility with the cron job.
     */
    public function runCleanup(): void
    {
        // Nonces and access tokens now use WordPress transients
        // which auto-expire - no manual cleanup needed
    }
}
