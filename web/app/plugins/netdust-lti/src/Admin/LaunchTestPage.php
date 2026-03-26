<?php
declare(strict_types=1);

namespace NetdustLTI\Admin;

use NetdustLTI\Platform\ToolRepository;
use NTDST_Service_Meta;

/**
 * Admin page for testing LTI tool launches.
 *
 * Provides a UI for administrators to test launching external LTI tools
 * configured on this site (acting as Platform/Consumer).
 */
final class LaunchTestPage implements NTDST_Service_Meta
{
    public static function metadata(): array
    {
        return [
            'name' => 'LTI Launch Test Page',
            'description' => 'Admin page for testing LTI tool launches',
            'priority' => 20,
        ];
    }

    public function __construct(
        private readonly ToolRepository $toolRepository
    ) {
        add_action('admin_menu', [$this, 'registerPage']);
    }

    public function registerPage(): void
    {
        add_submenu_page(
            'options-general.php',
            'LTI Launch Test',
            'LTI Launch Test',
            'manage_options',
            'lti-launch-test',
            [$this, 'renderPage']
        );
    }

    public function renderPage(): void
    {
        $tools = $this->toolRepository->all();
        $currentUser = wp_get_current_user();

        include dirname(__DIR__, 2) . '/templates/admin/launch-test.php';
    }

    /**
     * Get platform endpoints for display.
     *
     * @return array<string, string>
     */
    public function getPlatformEndpoints(): array
    {
        return [
            'issuer' => home_url('/'),
            'auth_endpoint' => home_url('/lti/platform/auth'),
            'jwks_url' => home_url('/lti/jwks'),
            'ags_endpoint' => home_url('/lti/platform/grades'),
            'deep_link_return' => home_url('/lti/platform/deep-link-return'),
        ];
    }
}
