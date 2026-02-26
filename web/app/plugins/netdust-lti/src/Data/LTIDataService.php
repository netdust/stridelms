<?php

declare(strict_types=1);

namespace NetdustLTI\Data;

use NTDST_Service_Meta;

/**
 * LTI Data Service
 *
 * Registers LTI Platform and Tool CPTs via NTDST Data Manager.
 * Replaces custom database tables with WordPress CPTs for consistency
 * with the NTDST framework patterns.
 */
final class LTIDataService implements NTDST_Service_Meta
{
    public static function metadata(): array
    {
        return [
            'name' => 'LTI Data Service',
            'description' => 'Registers LTI CPTs via Data Manager',
            'priority' => 5,
        ];
    }

    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        add_action('init', [$this, 'registerModels'], 5);
    }

    public function registerModels(): void
    {
        $this->registerPlatformModel();
        $this->registerToolModel();
    }

    private function registerPlatformModel(): void
    {
        ntdst_data()->register('lti_platform', [
            'label' => 'LTI Platforms',
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'options-general.php',
            'supports' => ['title'],
            'meta_prefix' => 'lti_',
            'fields' => [
                'platform_id' => [
                    'type' => 'url',
                    'label' => 'Platform ID (Issuer)',
                    'required' => true,
                    'description' => 'The platform issuer URL (e.g., https://canvas.instructure.com)',
                ],
                'client_id' => [
                    'type' => 'text',
                    'label' => 'Client ID',
                    'required' => true,
                    'description' => 'Client ID assigned by the platform',
                ],
                'deployment_id' => [
                    'type' => 'text',
                    'label' => 'Deployment ID',
                    'description' => 'Optional deployment ID for multi-tenancy',
                ],
                'auth_endpoint' => [
                    'type' => 'url',
                    'label' => 'Authorization Endpoint',
                    'required' => true,
                    'description' => 'OIDC authorization URL',
                ],
                'token_endpoint' => [
                    'type' => 'url',
                    'label' => 'Token Endpoint',
                    'required' => true,
                    'description' => 'OAuth2 token URL',
                ],
                'jwks_endpoint' => [
                    'type' => 'url',
                    'label' => 'JWKS Endpoint',
                    'required' => true,
                    'description' => 'Platform public key set URL',
                ],
                'enabled' => [
                    'type' => 'boolean',
                    'label' => 'Enabled',
                    'default' => true,
                    'description' => 'Enable or disable this platform',
                ],
            ],
            'field_groups' => [
                'credentials' => [
                    'title' => 'Platform Credentials',
                    'fields' => ['platform_id', 'client_id', 'deployment_id'],
                ],
                'endpoints' => [
                    'title' => 'Endpoints',
                    'fields' => ['auth_endpoint', 'token_endpoint', 'jwks_endpoint'],
                ],
                'settings' => [
                    'title' => 'Settings',
                    'fields' => ['enabled'],
                ],
            ],
            'use_tabs' => true,
        ]);
    }

    private function registerToolModel(): void
    {
        ntdst_data()->register('lti_tool', [
            'label' => 'LTI Tools',
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'options-general.php',
            'supports' => ['title'],
            'meta_prefix' => 'lti_',
            'fields' => [
                'launch_url' => [
                    'type' => 'url',
                    'label' => 'Launch URL',
                    'required' => true,
                    'description' => 'Tool launch endpoint',
                ],
                'oidc_url' => [
                    'type' => 'url',
                    'label' => 'OIDC Login URL',
                    'required' => true,
                    'description' => 'Tool OIDC initiation URL',
                ],
                'jwks_url' => [
                    'type' => 'url',
                    'label' => 'JWKS URL',
                    'required' => true,
                    'description' => 'Tool public key set URL',
                ],
                'client_id' => [
                    'type' => 'text',
                    'label' => 'Client ID',
                    'required' => true,
                    'description' => 'Client ID for this tool',
                ],
                'deployment_id' => [
                    'type' => 'text',
                    'label' => 'Deployment ID',
                    'description' => 'Optional deployment ID',
                ],
            ],
            'field_groups' => [
                'credentials' => [
                    'title' => 'Tool Credentials',
                    'fields' => ['client_id', 'deployment_id'],
                ],
                'endpoints' => [
                    'title' => 'Endpoints',
                    'fields' => ['launch_url', 'oidc_url', 'jwks_url'],
                ],
            ],
            'use_tabs' => true,
        ]);
    }
}
