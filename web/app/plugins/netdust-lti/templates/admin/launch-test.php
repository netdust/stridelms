<?php
/**
 * Launch Test Page Template
 *
 * @var array $tools Array of tool data arrays from ToolRepository::all()
 * @var WP_User $currentUser Current WordPress user
 */

use NetdustLTI\Admin\LaunchTestPage;

defined('ABSPATH') || exit;

$launchTestPage = ntdst_get(LaunchTestPage::class);
$endpoints = $launchTestPage->getPlatformEndpoints();
?>
<div class="wrap">
    <h1><?php esc_html_e('LTI Launch Test', 'netdust-lti'); ?></h1>
    <p><?php esc_html_e('Test launching external LTI tools from this platform.', 'netdust-lti'); ?></p>

    <?php if (empty($tools)): ?>
        <div class="notice notice-warning">
            <p>
                <?php esc_html_e('No LTI tools configured.', 'netdust-lti'); ?>
                <a href="<?php echo esc_url(admin_url('edit.php?post_type=lti_tool')); ?>">
                    <?php esc_html_e('Add a tool first.', 'netdust-lti'); ?>
                </a>
            </p>
        </div>
    <?php else: ?>

        <!-- Resource Launch Section -->
        <h2><?php esc_html_e('Resource Launch', 'netdust-lti'); ?></h2>
        <p class="description"><?php esc_html_e('Launch a tool with LtiResourceLinkRequest message type.', 'netdust-lti'); ?></p>

        <form method="post" action="<?php echo esc_url(home_url('/lti/platform/launch')); ?>" target="_blank">
            <?php wp_nonce_field('lti_launch_test', 'lti_launch_nonce'); ?>
            <input type="hidden" name="message_type" value="LtiResourceLinkRequest">

            <table class="form-table">
                <tr>
                    <th><label for="tool_id"><?php esc_html_e('Tool', 'netdust-lti'); ?></label></th>
                    <td>
                        <select name="tool_id" id="tool_id" class="regular-text" required>
                            <option value=""><?php esc_html_e('-- Select a tool --', 'netdust-lti'); ?></option>
                            <?php foreach ($tools as $tool): ?>
                                <option value="<?php echo esc_attr($tool['ID']); ?>">
                                    <?php echo esc_html($tool['post_title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Select the external tool to launch.', 'netdust-lti'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="resource_link_id"><?php esc_html_e('Resource Link ID', 'netdust-lti'); ?></label></th>
                    <td>
                        <input type="text" name="resource_link_id" id="resource_link_id" class="regular-text" placeholder="test-resource-123">
                        <p class="description"><?php esc_html_e('Optional. A unique identifier for this resource link. Auto-generated if empty.', 'netdust-lti'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="target_link_uri"><?php esc_html_e('Target Link URI', 'netdust-lti'); ?></label></th>
                    <td>
                        <input type="url" name="target_link_uri" id="target_link_uri" class="regular-text" placeholder="https://tool.example.com/resource/123">
                        <p class="description"><?php esc_html_e('Optional. Overrides the tool\'s default launch URL for deep linking to specific content.', 'netdust-lti'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Launch As', 'netdust-lti'); ?></th>
                    <td>
                        <fieldset>
                            <label>
                                <input type="radio" name="launch_as" value="current" checked>
                                <?php
                                printf(
                                    /* translators: %s: current user display name */
                                    esc_html__('Current user (%s)', 'netdust-lti'),
                                    esc_html($currentUser->display_name)
                                );
                                ?>
                            </label>
                            <br>
                            <label>
                                <input type="radio" name="launch_as" value="test_learner">
                                <?php esc_html_e('Test learner (anonymous)', 'netdust-lti'); ?>
                            </label>
                        </fieldset>
                        <p class="description"><?php esc_html_e('Choose which user context to use for the launch.', 'netdust-lti'); ?></p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <input type="submit" class="button button-primary" value="<?php esc_attr_e('Launch Tool', 'netdust-lti'); ?>">
            </p>
        </form>

        <hr>

        <!-- Deep Linking Section -->
        <h2><?php esc_html_e('Deep Linking Discovery', 'netdust-lti'); ?></h2>
        <p class="description"><?php esc_html_e('Request content selection from a tool using LtiDeepLinkingRequest message type.', 'netdust-lti'); ?></p>

        <form method="post" action="<?php echo esc_url(home_url('/lti/platform/launch')); ?>" target="_blank">
            <?php wp_nonce_field('lti_deep_link_test', 'lti_deep_link_nonce'); ?>
            <input type="hidden" name="message_type" value="LtiDeepLinkingRequest">

            <table class="form-table">
                <tr>
                    <th><label for="dl_tool_id"><?php esc_html_e('Tool', 'netdust-lti'); ?></label></th>
                    <td>
                        <select name="tool_id" id="dl_tool_id" class="regular-text" required>
                            <option value=""><?php esc_html_e('-- Select a tool --', 'netdust-lti'); ?></option>
                            <?php foreach ($tools as $tool): ?>
                                <option value="<?php echo esc_attr($tool['ID']); ?>">
                                    <?php echo esc_html($tool['post_title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Select the tool to request content from.', 'netdust-lti'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="accept_types"><?php esc_html_e('Accept Types', 'netdust-lti'); ?></label></th>
                    <td>
                        <fieldset>
                            <label>
                                <input type="checkbox" name="accept_types[]" value="ltiResourceLink" checked>
                                <?php esc_html_e('LTI Resource Link', 'netdust-lti'); ?>
                            </label>
                            <br>
                            <label>
                                <input type="checkbox" name="accept_types[]" value="link">
                                <?php esc_html_e('Link', 'netdust-lti'); ?>
                            </label>
                            <br>
                            <label>
                                <input type="checkbox" name="accept_types[]" value="file">
                                <?php esc_html_e('File', 'netdust-lti'); ?>
                            </label>
                            <br>
                            <label>
                                <input type="checkbox" name="accept_types[]" value="html">
                                <?php esc_html_e('HTML', 'netdust-lti'); ?>
                            </label>
                            <br>
                            <label>
                                <input type="checkbox" name="accept_types[]" value="image">
                                <?php esc_html_e('Image', 'netdust-lti'); ?>
                            </label>
                        </fieldset>
                        <p class="description"><?php esc_html_e('Content types the platform will accept.', 'netdust-lti'); ?></p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <input type="submit" class="button button-secondary" value="<?php esc_attr_e('Request Content Selection', 'netdust-lti'); ?>">
            </p>
        </form>

    <?php endif; ?>

    <hr>

    <!-- Platform Endpoints Reference -->
    <h2><?php esc_html_e('Platform Endpoints', 'netdust-lti'); ?></h2>
    <p class="description"><?php esc_html_e('Reference information for configuring external tools to work with this platform.', 'netdust-lti'); ?></p>

    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Endpoint', 'netdust-lti'); ?></th>
                <th><?php esc_html_e('URL', 'netdust-lti'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong><?php esc_html_e('Issuer', 'netdust-lti'); ?></strong></td>
                <td><code><?php echo esc_html($endpoints['issuer']); ?></code></td>
            </tr>
            <tr>
                <td><strong><?php esc_html_e('Auth Endpoint', 'netdust-lti'); ?></strong></td>
                <td><code><?php echo esc_html($endpoints['auth_endpoint']); ?></code></td>
            </tr>
            <tr>
                <td><strong><?php esc_html_e('JWKS URL', 'netdust-lti'); ?></strong></td>
                <td><code><?php echo esc_html($endpoints['jwks_url']); ?></code></td>
            </tr>
            <tr>
                <td><strong><?php esc_html_e('AGS Endpoint', 'netdust-lti'); ?></strong></td>
                <td><code><?php echo esc_html($endpoints['ags_endpoint']); ?></code></td>
            </tr>
            <tr>
                <td><strong><?php esc_html_e('Deep Link Return', 'netdust-lti'); ?></strong></td>
                <td><code><?php echo esc_html($endpoints['deep_link_return']); ?></code></td>
            </tr>
        </tbody>
    </table>

    <p style="margin-top: 1em;">
        <a href="<?php echo esc_url(admin_url('options-general.php?page=netdust-lti')); ?>" class="button">
            <?php esc_html_e('Back to LTI Settings', 'netdust-lti'); ?>
        </a>
    </p>
</div>
