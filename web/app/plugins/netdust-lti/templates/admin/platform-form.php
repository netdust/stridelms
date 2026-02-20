<div class="wrap">
    <h1><?php echo $platform ? 'Edit Platform' : 'Add Platform'; ?></h1>

    <?php settings_errors('netdust_lti'); ?>

    <form method="post" action="">
        <?php wp_nonce_field('netdust_lti_save_platform', 'netdust_lti_platform_nonce'); ?>

        <?php if ($platform): ?>
            <input type="hidden" name="platform_id" value="<?php echo esc_attr($platform->id); ?>">
        <?php endif; ?>

        <table class="form-table">
            <tr>
                <th><label for="name">Name</label></th>
                <td>
                    <input type="text" id="name" name="name" class="regular-text" value="<?php echo esc_attr($platform?->name ?? ''); ?>" required>
                    <p class="description">A friendly name to identify this platform (e.g., "Canvas Production")</p>
                </td>
            </tr>
            <tr>
                <th><label for="platform_url">Platform ID (Issuer)</label></th>
                <td>
                    <input type="url" id="platform_url" name="platform_url" class="regular-text" value="<?php echo esc_attr($platform?->platformId ?? ''); ?>" required>
                    <p class="description">The platform's issuer URL (e.g., https://canvas.instructure.com)</p>
                </td>
            </tr>
            <tr>
                <th><label for="client_id">Client ID</label></th>
                <td>
                    <input type="text" id="client_id" name="client_id" class="regular-text" value="<?php echo esc_attr($platform?->clientId ?? ''); ?>" required>
                    <p class="description">The client ID provided by the platform when registering your tool</p>
                </td>
            </tr>
            <tr>
                <th><label for="deployment_id">Deployment ID</label></th>
                <td>
                    <input type="text" id="deployment_id" name="deployment_id" class="regular-text" value="<?php echo esc_attr($platform?->deploymentId ?? ''); ?>">
                    <p class="description">Optional deployment ID if the platform uses multiple deployments</p>
                </td>
            </tr>
            <tr>
                <th><label for="auth_endpoint">Auth Endpoint</label></th>
                <td>
                    <input type="url" id="auth_endpoint" name="auth_endpoint" class="regular-text" value="<?php echo esc_attr($platform?->authEndpoint ?? ''); ?>" required>
                    <p class="description">The OIDC authorization endpoint URL</p>
                </td>
            </tr>
            <tr>
                <th><label for="token_endpoint">Token Endpoint</label></th>
                <td>
                    <input type="url" id="token_endpoint" name="token_endpoint" class="regular-text" value="<?php echo esc_attr($platform?->tokenEndpoint ?? ''); ?>" required>
                    <p class="description">The OAuth2 token endpoint URL</p>
                </td>
            </tr>
            <tr>
                <th><label for="jwks_endpoint">JWKS Endpoint</label></th>
                <td>
                    <input type="url" id="jwks_endpoint" name="jwks_endpoint" class="regular-text" value="<?php echo esc_attr($platform?->jwksEndpoint ?? ''); ?>" required>
                    <p class="description">The platform's JSON Web Key Set URL for verifying tokens</p>
                </td>
            </tr>
            <tr>
                <th><label for="enabled">Enabled</label></th>
                <td>
                    <label>
                        <input type="checkbox" id="enabled" name="enabled" value="1" <?php checked($platform?->enabled ?? true); ?>>
                        Allow LTI launches from this platform
                    </label>
                </td>
            </tr>
        </table>

        <p class="submit">
            <input type="submit" class="button-primary" value="Save Platform">
            <a href="<?php echo esc_url(admin_url('options-general.php?page=netdust-lti')); ?>" class="button">Cancel</a>
        </p>
    </form>
</div>
