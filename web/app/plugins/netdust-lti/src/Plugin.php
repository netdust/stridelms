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
        ntdst_get(Data\LTIDataService::class);

        // Register cleanup cron handler
        add_action('netdust_lti_cleanup', [$this, 'runCleanup']);

        // Register endpoint router for LTI requests (Tool Provider role)
        ntdst_get(LTI\EndpointRouter::class);

        // Register platform router (Platform/Consumer role - launching external tools)
        ntdst_get(Platform\PlatformRouter::class);

        // Register admin UI
        if (is_admin()) {
            ntdst_get(Admin\AdminPage::class);
            ntdst_get(Admin\CourseSettingsMetabox::class);
            ntdst_get(LTI\DeepLinkHandler::class);
        }

        // Register bridges after LearnDash is loaded
        add_action('learndash_init', function () {
            ntdst_get(Bridges\LearnDashBridge::class);

            // Register TinCanny bridge if available
            if (class_exists('UCTINCAN\Database')) {
                ntdst_get(Bridges\TinCannyBridge::class);
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
     * Run cleanup of expired nonces and tokens.
     */
    public function runCleanup(): void
    {
        $connector = new DataConnector\WPDataConnector();
        $nonces = $connector->cleanupExpiredNonces();
        $tokens = $connector->cleanupExpiredTokens();

        if ($nonces > 0 || $tokens > 0) {
            ntdst_log('lti')->info('Cleanup completed', [
                'nonces_deleted' => $nonces,
                'tokens_deleted' => $tokens,
            ]);
        }
    }
}
