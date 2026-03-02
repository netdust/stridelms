<?php
declare(strict_types=1);

namespace NetdustLTI;

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
            ntdst_get(ToolProvider\DeepLinkHandler::class);
        }

        // Logs REST endpoint (needed outside is_admin for REST API calls)
        ntdst_get(Admin\LogsController::class);

        // Register shortcodes
        ntdst_get(Platform\LtiLaunchShortcode::class);

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
