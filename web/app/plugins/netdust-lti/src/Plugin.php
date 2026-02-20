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
    }

    public static function pluginPath(): string
    {
        return dirname(__DIR__);
    }

    public static function pluginUrl(): string
    {
        return plugin_dir_url(dirname(__DIR__) . '/netdust-lti.php');
    }
}
