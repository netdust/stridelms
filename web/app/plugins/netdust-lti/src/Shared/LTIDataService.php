<?php

declare(strict_types=1);

namespace NetdustLTI\Shared;

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
        add_filter('post_row_actions', [$this, 'addResourceRowActions'], 10, 2);
        add_filter('manage_lti_resource_posts_columns', [$this, 'addResourceColumns']);
        add_action('manage_lti_resource_posts_custom_column', [$this, 'renderResourceColumn'], 10, 2);
    }

    /**
     * Add custom columns for LTI Resources.
     */
    public function addResourceColumns(array $columns): array
    {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'title') {
                $new['course_id'] = __('Course ID', 'netdust-lti');
                $new['tool'] = __('Tool', 'netdust-lti');
            }
        }
        return $new;
    }

    /**
     * Render custom column content for LTI Resources.
     */
    public function renderResourceColumn(string $column, int $postId): void
    {
        $model = ntdst_data()->get('lti_resource');
        $resource = $model->find($postId);

        if (!$resource || is_wp_error($resource)) {
            return;
        }

        switch ($column) {
            case 'course_id':
                echo esc_html($resource->fields['course_id'] ?? '-');
                break;
            case 'tool':
                $toolId = $resource->fields['tool_id'] ?? 0;
                if ($toolId) {
                    $tool = get_post($toolId);
                    echo $tool ? esc_html($tool->post_title) : '-';
                } else {
                    echo '-';
                }
                break;
        }
    }

    /**
     * Add custom row actions for LTI Resources.
     */
    public function addResourceRowActions(array $actions, \WP_Post $post): array
    {
        if ($post->post_type !== 'lti_resource') {
            return $actions;
        }

        // Add Launch action
        $launchUrl = home_url('/lti/launch/' . $post->ID);
        $actions['launch'] = sprintf(
            '<a href="%s" target="_blank">%s</a>',
            esc_url($launchUrl),
            esc_html__('Launch', 'netdust-lti')
        );

        return $actions;
    }

    public function registerModels(): void
    {
        $this->registerPlatformModel();
        $this->registerToolModel();
        $this->registerResourceModel();
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
                    'description' => 'Platform public key set URL (optional if RSA key is provided)',
                ],
                'rsa_key' => [
                    'type' => 'textarea',
                    'label' => 'RSA Public Key',
                    'description' => 'Platform RSA public key (PEM format). Preferred over JWKS endpoint.',
                ],
                'kid' => [
                    'type' => 'text',
                    'label' => 'Key ID (kid)',
                    'description' => 'Key ID for the RSA public key',
                ],
                'enabled' => [
                    'type' => 'boolean',
                    'label' => 'Enabled',
                    'default' => true,
                    'description' => 'Enable or disable this platform',
                ],
                'contexts' => [
                    'type' => 'textarea',
                    'label' => 'LTI Contexts',
                    'description' => 'JSON-encoded LTI context data (managed automatically)',
                ],
                'role_instructor' => [
                    'type' => 'text',
                    'label' => 'Instructor Role',
                    'description' => 'WordPress role for LTI Instructor (default: instructor)',
                    'default' => 'instructor',
                ],
                'role_learner' => [
                    'type' => 'text',
                    'label' => 'Learner Role',
                    'description' => 'WordPress role for LTI Learner (default: subscriber)',
                    'default' => 'subscriber',
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
                'keys' => [
                    'title' => 'Platform Keys',
                    'fields' => ['rsa_key', 'kid'],
                ],
                'settings' => [
                    'title' => 'Settings',
                    'fields' => ['enabled'],
                ],
                'roles' => [
                    'title' => 'Role Mapping',
                    'fields' => ['role_instructor', 'role_learner'],
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
                'public_key' => [
                    'type' => 'textarea',
                    'label' => 'Public Key (PEM)',
                    'description' => 'Tool RSA public key in PEM format. Alternative to JWKS URL.',
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
                'keys' => [
                    'title' => 'Tool Keys',
                    'fields' => ['public_key'],
                ],
            ],
            'use_tabs' => true,
        ]);
    }

    private function registerResourceModel(): void
    {
        ntdst_data()->register('lti_resource', [
            'label' => 'LTI Resources',
            'labels' => [
                'singular_name' => 'LTI Resource',
                'add_new' => 'Add Resource',
                'add_new_item' => 'Add New Resource',
                'edit_item' => 'Edit Resource',
                'view_item' => 'View Resource',
                'all_items' => 'LTI Resources',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'options-general.php',
            'supports' => ['title'],
            'meta_prefix' => 'lti_',
            'fields' => [
                'tool_id' => [
                    'type' => 'integer',
                    'label' => 'Tool ID',
                    'required' => true,
                    'description' => 'The LTI Tool this resource belongs to',
                ],
                'launch_url' => [
                    'type' => 'url',
                    'label' => 'Launch URL',
                    'required' => true,
                    'description' => 'URL to launch this resource',
                ],
                'course_id' => [
                    'type' => 'text',
                    'label' => 'Remote Course ID',
                    'description' => 'Course ID on the Tool (e.g., LearnDash course ID)',
                ],
                'custom_params' => [
                    'type' => 'textarea',
                    'label' => 'Custom Parameters',
                    'description' => 'JSON-encoded custom parameters for launch',
                ],
                'description' => [
                    'type' => 'textarea',
                    'label' => 'Description',
                    'description' => 'Resource description from deep link',
                ],
            ],
            'field_groups' => [
                'resource' => [
                    'title' => 'Resource Details',
                    'fields' => ['tool_id', 'launch_url', 'course_id'],
                ],
                'extra' => [
                    'title' => 'Additional Info',
                    'fields' => ['description', 'custom_params'],
                ],
            ],
            'use_tabs' => true,
        ]);
    }
}
