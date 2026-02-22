<?php
/**
 * Admin settings page template for ntdst-auth plugin.
 *
 * @var array<string, mixed> $settings Current settings with defaults applied.
 */

defined('ABSPATH') || exit;

$current_tab = sanitize_key($_GET['tab'] ?? 'urls');

$tabs = [
    'urls'         => __('URLs', 'ntdst-auth'),
    'methods'      => __('Authentication Methods', 'ntdst-auth'),
    'registration' => __('Registration', 'ntdst-auth'),
    'security'     => __('Security', 'ntdst-auth'),
];

// Ensure valid tab
if (!isset($tabs[$current_tab])) {
    $current_tab = 'urls';
}
?>
<div class="wrap">
    <h1><?php echo esc_html__('Authentication Settings', 'ntdst-auth'); ?></h1>

    <nav class="nav-tab-wrapper">
        <?php foreach ($tabs as $slug => $label): ?>
            <a href="<?php echo esc_url(admin_url('options-general.php?page=ntdst-auth&tab=' . $slug)); ?>"
               class="nav-tab <?php echo $current_tab === $slug ? 'nav-tab-active' : ''; ?>">
                <?php echo esc_html($label); ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <form method="post" action="options.php">
        <?php settings_fields('ntdst_auth'); ?>

        <?php if ($current_tab === 'urls'): ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="login_url"><?php esc_html_e('Login URL', 'ntdst-auth'); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               id="login_url"
                               name="ntdst_auth_settings[login_url]"
                               value="<?php echo esc_attr($settings['login_url']); ?>"
                               class="regular-text">
                        <p class="description">
                            <?php esc_html_e('The URL path for the login page (e.g., /login).', 'ntdst-auth'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="register_url"><?php esc_html_e('Register URL', 'ntdst-auth'); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               id="register_url"
                               name="ntdst_auth_settings[register_url]"
                               value="<?php echo esc_attr($settings['register_url']); ?>"
                               class="regular-text">
                        <p class="description">
                            <?php esc_html_e('The URL path for the registration page (e.g., /register).', 'ntdst-auth'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="activate_url"><?php esc_html_e('Activate URL', 'ntdst-auth'); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               id="activate_url"
                               name="ntdst_auth_settings[activate_url]"
                               value="<?php echo esc_attr($settings['activate_url']); ?>"
                               class="regular-text">
                        <p class="description">
                            <?php esc_html_e('The URL path for account activation (e.g., /activate).', 'ntdst-auth'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="redirect_after_login"><?php esc_html_e('Redirect After Login', 'ntdst-auth'); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               id="redirect_after_login"
                               name="ntdst_auth_settings[redirect_after_login]"
                               value="<?php echo esc_attr($settings['redirect_after_login']); ?>"
                               class="regular-text">
                        <p class="description">
                            <?php esc_html_e('Where to redirect users after successful login (e.g., / or /dashboard).', 'ntdst-auth'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="redirect_after_logout"><?php esc_html_e('Redirect After Logout', 'ntdst-auth'); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               id="redirect_after_logout"
                               name="ntdst_auth_settings[redirect_after_logout]"
                               value="<?php echo esc_attr($settings['redirect_after_logout']); ?>"
                               class="regular-text">
                        <p class="description">
                            <?php esc_html_e('Where to redirect users after logout (e.g., /login).', 'ntdst-auth'); ?>
                        </p>
                    </td>
                </tr>
            </table>

        <?php elseif ($current_tab === 'methods'): ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Authentication Methods', 'ntdst-auth'); ?></th>
                    <td>
                        <fieldset>
                            <label for="enable_magic_link">
                                <input type="checkbox"
                                       id="enable_magic_link"
                                       name="ntdst_auth_settings[enable_magic_link]"
                                       value="1"
                                       <?php checked($settings['enable_magic_link']); ?>>
                                <?php esc_html_e('Enable magic link authentication', 'ntdst-auth'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Allow users to log in via email link (passwordless).', 'ntdst-auth'); ?>
                            </p>
                            <br>
                            <label for="enable_password">
                                <input type="checkbox"
                                       id="enable_password"
                                       name="ntdst_auth_settings[enable_password]"
                                       value="1"
                                       <?php checked($settings['enable_password']); ?>>
                                <?php esc_html_e('Enable password authentication', 'ntdst-auth'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Allow users to log in with traditional password.', 'ntdst-auth'); ?>
                            </p>
                        </fieldset>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="magic_link_expiry"><?php esc_html_e('Magic Link Expiry', 'ntdst-auth'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               id="magic_link_expiry"
                               name="ntdst_auth_settings[magic_link_expiry]"
                               value="<?php echo esc_attr($settings['magic_link_expiry']); ?>"
                               class="small-text"
                               min="1"
                               max="60">
                        <?php esc_html_e('minutes', 'ntdst-auth'); ?>
                        <p class="description">
                            <?php esc_html_e('How long a magic link remains valid (default: 15 minutes).', 'ntdst-auth'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="magic_link_max_uses"><?php esc_html_e('Magic Link Max Uses', 'ntdst-auth'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               id="magic_link_max_uses"
                               name="ntdst_auth_settings[magic_link_max_uses]"
                               value="<?php echo esc_attr($settings['magic_link_max_uses']); ?>"
                               class="small-text"
                               min="1"
                               max="10">
                        <?php esc_html_e('uses', 'ntdst-auth'); ?>
                        <p class="description">
                            <?php esc_html_e('Maximum times a magic link can be used before invalidation (default: 3).', 'ntdst-auth'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="activation_link_expiry"><?php esc_html_e('Activation Link Expiry', 'ntdst-auth'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               id="activation_link_expiry"
                               name="ntdst_auth_settings[activation_link_expiry]"
                               value="<?php echo esc_attr($settings['activation_link_expiry']); ?>"
                               class="small-text"
                               min="1"
                               max="168">
                        <?php esc_html_e('hours', 'ntdst-auth'); ?>
                        <p class="description">
                            <?php esc_html_e('How long an account activation link remains valid (default: 48 hours).', 'ntdst-auth'); ?>
                        </p>
                    </td>
                </tr>
            </table>

        <?php elseif ($current_tab === 'registration'): ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Registration', 'ntdst-auth'); ?></th>
                    <td>
                        <label for="enable_registration">
                            <input type="checkbox"
                                   id="enable_registration"
                                   name="ntdst_auth_settings[enable_registration]"
                                   value="1"
                                   <?php checked($settings['enable_registration']); ?>>
                            <?php esc_html_e('Enable user registration', 'ntdst-auth'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Allow new users to create accounts.', 'ntdst-auth'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Required Fields', 'ntdst-auth'); ?></th>
                    <td>
                        <fieldset>
                            <?php
                            $available_fields = [
                                'email'      => __('Email', 'ntdst-auth'),
                                'first_name' => __('First Name', 'ntdst-auth'),
                                'last_name'  => __('Last Name', 'ntdst-auth'),
                                'phone'      => __('Phone', 'ntdst-auth'),
                                'company'    => __('Company', 'ntdst-auth'),
                            ];
                            $selected_fields = $settings['registration_fields'] ?? [];
                            ?>
                            <?php foreach ($available_fields as $field_key => $field_label): ?>
                                <label style="display: block; margin-bottom: 5px;">
                                    <input type="checkbox"
                                           name="ntdst_auth_settings[registration_fields][]"
                                           value="<?php echo esc_attr($field_key); ?>"
                                           <?php checked(in_array($field_key, $selected_fields, true)); ?>
                                           <?php echo $field_key === 'email' ? 'disabled checked' : ''; ?>>
                                    <?php echo esc_html($field_label); ?>
                                    <?php if ($field_key === 'email'): ?>
                                        <span class="description"><?php esc_html_e('(always required)', 'ntdst-auth'); ?></span>
                                    <?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                            <!-- Hidden field to ensure email is always included -->
                            <input type="hidden" name="ntdst_auth_settings[registration_fields][]" value="email">
                            <p class="description">
                                <?php esc_html_e('Select which fields are required during registration.', 'ntdst-auth'); ?>
                            </p>
                        </fieldset>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="terms_url"><?php esc_html_e('Terms URL', 'ntdst-auth'); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               id="terms_url"
                               name="ntdst_auth_settings[terms_url]"
                               value="<?php echo esc_attr($settings['terms_url']); ?>"
                               class="regular-text">
                        <p class="description">
                            <?php esc_html_e('URL path to Terms of Service page (e.g., /terms).', 'ntdst-auth'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="privacy_url"><?php esc_html_e('Privacy URL', 'ntdst-auth'); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               id="privacy_url"
                               name="ntdst_auth_settings[privacy_url]"
                               value="<?php echo esc_attr($settings['privacy_url']); ?>"
                               class="regular-text">
                        <p class="description">
                            <?php esc_html_e('URL path to Privacy Policy page (e.g., /privacy).', 'ntdst-auth'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="consent_version"><?php esc_html_e('Consent Version', 'ntdst-auth'); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               id="consent_version"
                               name="ntdst_auth_settings[consent_version]"
                               value="<?php echo esc_attr($settings['consent_version']); ?>"
                               class="small-text">
                        <p class="description">
                            <?php esc_html_e('Version string for GDPR consent tracking (e.g., 1.0). Update when terms change.', 'ntdst-auth'); ?>
                        </p>
                    </td>
                </tr>
            </table>

        <?php elseif ($current_tab === 'security'): ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="rate_limit_magic_link_per_email"><?php esc_html_e('Magic Link Rate Limit (per email)', 'ntdst-auth'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               id="rate_limit_magic_link_per_email"
                               name="ntdst_auth_settings[rate_limit_magic_link_per_email]"
                               value="<?php echo esc_attr($settings['rate_limit_magic_link_per_email']); ?>"
                               class="small-text"
                               min="1"
                               max="20">
                        <?php esc_html_e('requests per window', 'ntdst-auth'); ?>
                        <p class="description">
                            <?php esc_html_e('Maximum magic link requests per email address within the rate limit window.', 'ntdst-auth'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="rate_limit_magic_link_per_ip"><?php esc_html_e('Magic Link Rate Limit (per IP)', 'ntdst-auth'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               id="rate_limit_magic_link_per_ip"
                               name="ntdst_auth_settings[rate_limit_magic_link_per_ip]"
                               value="<?php echo esc_attr($settings['rate_limit_magic_link_per_ip']); ?>"
                               class="small-text"
                               min="1"
                               max="50">
                        <?php esc_html_e('requests per window', 'ntdst-auth'); ?>
                        <p class="description">
                            <?php esc_html_e('Maximum magic link requests per IP address within the rate limit window.', 'ntdst-auth'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="rate_limit_login_per_ip"><?php esc_html_e('Login Rate Limit (per IP)', 'ntdst-auth'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               id="rate_limit_login_per_ip"
                               name="ntdst_auth_settings[rate_limit_login_per_ip]"
                               value="<?php echo esc_attr($settings['rate_limit_login_per_ip']); ?>"
                               class="small-text"
                               min="1"
                               max="20">
                        <?php esc_html_e('attempts per window', 'ntdst-auth'); ?>
                        <p class="description">
                            <?php esc_html_e('Maximum login attempts per IP address within the rate limit window.', 'ntdst-auth'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="rate_limit_registration_per_ip"><?php esc_html_e('Registration Rate Limit (per IP)', 'ntdst-auth'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               id="rate_limit_registration_per_ip"
                               name="ntdst_auth_settings[rate_limit_registration_per_ip]"
                               value="<?php echo esc_attr($settings['rate_limit_registration_per_ip']); ?>"
                               class="small-text"
                               min="1"
                               max="20">
                        <?php esc_html_e('registrations per window', 'ntdst-auth'); ?>
                        <p class="description">
                            <?php esc_html_e('Maximum registration attempts per IP address within the rate limit window.', 'ntdst-auth'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="rate_limit_window"><?php esc_html_e('Rate Limit Window', 'ntdst-auth'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               id="rate_limit_window"
                               name="ntdst_auth_settings[rate_limit_window]"
                               value="<?php echo esc_attr($settings['rate_limit_window']); ?>"
                               class="small-text"
                               min="1"
                               max="60">
                        <?php esc_html_e('minutes', 'ntdst-auth'); ?>
                        <p class="description">
                            <?php esc_html_e('Time window for rate limiting (default: 15 minutes).', 'ntdst-auth'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('WordPress Login', 'ntdst-auth'); ?></th>
                    <td>
                        <label for="redirect_wp_login">
                            <input type="checkbox"
                                   id="redirect_wp_login"
                                   name="ntdst_auth_settings[redirect_wp_login]"
                                   value="1"
                                   <?php checked($settings['redirect_wp_login']); ?>>
                            <?php esc_html_e('Redirect wp-login.php to custom login page', 'ntdst-auth'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Redirect users from /wp-login.php to your custom login URL. Administrators can still access wp-login.php by adding ?admin=1 to the URL.', 'ntdst-auth'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        <?php endif; ?>

        <?php submit_button(); ?>
    </form>
</div>
