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
        // Register activation hook
        register_activation_hook(
            dirname(__DIR__) . '/netdust-lti.php',
            [$this, 'activate']
        );

        // Register deactivation hook
        register_deactivation_hook(
            dirname(__DIR__) . '/netdust-lti.php',
            [$this, 'deactivate']
        );
    }

    public function activate(): void
    {
        // Will be implemented in Task 1.2
        flush_rewrite_rules();
    }

    public function deactivate(): void
    {
        flush_rewrite_rules();
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
