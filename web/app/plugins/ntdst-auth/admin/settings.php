<?php
/**
 * Admin settings page template for ntdst-auth plugin.
 *
 * @var array<string, mixed> $settings Current settings with defaults applied.
 */

defined('ABSPATH') || exit;

$current_tab = sanitize_key($_GET['tab'] ?? 'urls');

$tabs = [
    'urls'         => ['label' => __('URLs', 'ntdst-auth'),                   'icon' => 'dashicons-admin-links'],
    'methods'      => ['label' => __('Authentication', 'ntdst-auth'),         'icon' => 'dashicons-lock'],
    'registration' => ['label' => __('Registration', 'ntdst-auth'),           'icon' => 'dashicons-admin-users'],
    'security'     => ['label' => __('Security', 'ntdst-auth'),               'icon' => 'dashicons-shield'],
];

// Ensure valid tab
if (!isset($tabs[$current_tab])) {
    $current_tab = 'urls';
}
?>
<div class="wrap ntdst-app">
    <div class="ntdst-page-title-bar">
        <h1><?php echo esc_html__('Authentication Settings', 'ntdst-auth'); ?> <span class="ntdst-version">v1.0</span></h1>
    </div>

    <div class="ntdst-layout">
        <aside class="ntdst-sidebar">
            <nav class="ntdst-sidebar-nav">
                <?php foreach ($tabs as $slug => $tab): ?>
                    <a href="<?php echo esc_url(admin_url('options-general.php?page=ntdst-auth&tab=' . $slug)); ?>"
                       class="ntdst-sidebar-item <?php echo $current_tab === $slug ? 'active' : ''; ?>">
                        <span class="dashicons <?php echo esc_attr($tab['icon']); ?>"></span>
                        <?php echo esc_html($tab['label']); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </aside>

        <div class="ntdst-main">
            <form method="post" action="options.php">
                <?php settings_fields('ntdst_auth'); ?>

                <?php if ($current_tab === 'urls'): ?>

                <div class="ntdst-card">
                    <div class="ntdst-card-header">
                        <h3 class="ntdst-card-title"><span class="dashicons dashicons-admin-links"></span> <?php esc_html_e('URL Configuration', 'ntdst-auth'); ?></h3>
                    </div>
                    <div class="ntdst-card-body">
                        <div class="ntdst-form-group">
                            <label class="ntdst-form-label" for="login_url"><?php esc_html_e('Login URL', 'ntdst-auth'); ?></label>
                            <input type="text" id="login_url" name="ntdst_auth_settings[login_url]"
                                   value="<?php echo esc_attr($settings['login_url']); ?>" class="ntdst-form-input" style="max-width:400px;">
                            <p class="ntdst-form-help"><?php esc_html_e('The URL path for the login page (e.g., /login).', 'ntdst-auth'); ?></p>
                        </div>
                        <div class="ntdst-form-group">
                            <label class="ntdst-form-label" for="register_url"><?php esc_html_e('Register URL', 'ntdst-auth'); ?></label>
                            <input type="text" id="register_url" name="ntdst_auth_settings[register_url]"
                                   value="<?php echo esc_attr($settings['register_url']); ?>" class="ntdst-form-input" style="max-width:400px;">
                            <p class="ntdst-form-help"><?php esc_html_e('The URL path for the registration page (e.g., /register).', 'ntdst-auth'); ?></p>
                        </div>
                        <div class="ntdst-form-group">
                            <label class="ntdst-form-label" for="activate_url"><?php esc_html_e('Activate URL', 'ntdst-auth'); ?></label>
                            <input type="text" id="activate_url" name="ntdst_auth_settings[activate_url]"
                                   value="<?php echo esc_attr($settings['activate_url']); ?>" class="ntdst-form-input" style="max-width:400px;">
                            <p class="ntdst-form-help"><?php esc_html_e('The URL path for account activation (e.g., /activate).', 'ntdst-auth'); ?></p>
                        </div>
                    </div>
                </div>

                <div class="ntdst-card">
                    <div class="ntdst-card-header">
                        <h3 class="ntdst-card-title"><span class="dashicons dashicons-migrate"></span> <?php esc_html_e('Redirects', 'ntdst-auth'); ?></h3>
                    </div>
                    <div class="ntdst-card-body">
                        <div class="ntdst-form-group">
                            <label class="ntdst-form-label" for="redirect_after_login"><?php esc_html_e('Redirect After Login', 'ntdst-auth'); ?></label>
                            <input type="text" id="redirect_after_login" name="ntdst_auth_settings[redirect_after_login]"
                                   value="<?php echo esc_attr($settings['redirect_after_login']); ?>" class="ntdst-form-input" style="max-width:400px;">
                            <p class="ntdst-form-help"><?php esc_html_e('Where to redirect users after successful login (e.g., / or /dashboard).', 'ntdst-auth'); ?></p>
                        </div>
                        <div class="ntdst-form-group">
                            <label class="ntdst-form-label" for="redirect_after_logout"><?php esc_html_e('Redirect After Logout', 'ntdst-auth'); ?></label>
                            <input type="text" id="redirect_after_logout" name="ntdst_auth_settings[redirect_after_logout]"
                                   value="<?php echo esc_attr($settings['redirect_after_logout']); ?>" class="ntdst-form-input" style="max-width:400px;">
                            <p class="ntdst-form-help"><?php esc_html_e('Where to redirect users after logout (e.g., /login).', 'ntdst-auth'); ?></p>
                        </div>
                    </div>
                </div>

                <?php elseif ($current_tab === 'methods'): ?>

                <div class="ntdst-card">
                    <div class="ntdst-card-header">
                        <h3 class="ntdst-card-title"><span class="dashicons dashicons-lock"></span> <?php esc_html_e('Authentication Methods', 'ntdst-auth'); ?></h3>
                    </div>
                    <div class="ntdst-card-body">
                        <div class="ntdst-form-group">
                            <div class="ntdst-form-checkbox">
                                <input type="checkbox" id="enable_magic_link" name="ntdst_auth_settings[enable_magic_link]" value="1"
                                       <?php checked($settings['enable_magic_link']); ?>>
                                <label for="enable_magic_link"><?php esc_html_e('Enable magic link authentication', 'ntdst-auth'); ?></label>
                            </div>
                            <p class="ntdst-form-help"><?php esc_html_e('Allow users to log in via email link (passwordless).', 'ntdst-auth'); ?></p>
                        </div>
                        <div class="ntdst-form-group">
                            <div class="ntdst-form-checkbox">
                                <input type="checkbox" id="enable_password" name="ntdst_auth_settings[enable_password]" value="1"
                                       <?php checked($settings['enable_password']); ?>>
                                <label for="enable_password"><?php esc_html_e('Enable password authentication', 'ntdst-auth'); ?></label>
                            </div>
                            <p class="ntdst-form-help"><?php esc_html_e('Allow users to log in with traditional password.', 'ntdst-auth'); ?></p>
                        </div>
                    </div>
                </div>

                <div class="ntdst-card">
                    <div class="ntdst-card-header">
                        <h3 class="ntdst-card-title"><span class="dashicons dashicons-clock"></span> <?php esc_html_e('Token Settings', 'ntdst-auth'); ?></h3>
                    </div>
                    <div class="ntdst-card-body">
                        <div class="ntdst-form-group">
                            <label class="ntdst-form-label" for="magic_link_expiry"><?php esc_html_e('Magic Link Expiry', 'ntdst-auth'); ?></label>
                            <div class="ntdst-form-row">
                                <input type="number" id="magic_link_expiry" name="ntdst_auth_settings[magic_link_expiry]"
                                       value="<?php echo esc_attr($settings['magic_link_expiry']); ?>" class="ntdst-form-input" style="width:80px;" min="1" max="60">
                                <span class="ntdst-form-unit"><?php esc_html_e('minutes', 'ntdst-auth'); ?></span>
                            </div>
                            <p class="ntdst-form-help"><?php esc_html_e('How long a magic link remains valid (default: 15 minutes).', 'ntdst-auth'); ?></p>
                        </div>
                        <div class="ntdst-form-group">
                            <label class="ntdst-form-label" for="magic_link_max_uses"><?php esc_html_e('Magic Link Max Uses', 'ntdst-auth'); ?></label>
                            <div class="ntdst-form-row">
                                <input type="number" id="magic_link_max_uses" name="ntdst_auth_settings[magic_link_max_uses]"
                                       value="<?php echo esc_attr($settings['magic_link_max_uses']); ?>" class="ntdst-form-input" style="width:80px;" min="1" max="10">
                                <span class="ntdst-form-unit"><?php esc_html_e('uses', 'ntdst-auth'); ?></span>
                            </div>
                            <p class="ntdst-form-help"><?php esc_html_e('Maximum times a magic link can be used before invalidation (default: 3).', 'ntdst-auth'); ?></p>
                        </div>
                        <div class="ntdst-form-group">
                            <label class="ntdst-form-label" for="activation_link_expiry"><?php esc_html_e('Activation Link Expiry', 'ntdst-auth'); ?></label>
                            <div class="ntdst-form-row">
                                <input type="number" id="activation_link_expiry" name="ntdst_auth_settings[activation_link_expiry]"
                                       value="<?php echo esc_attr($settings['activation_link_expiry']); ?>" class="ntdst-form-input" style="width:80px;" min="1" max="168">
                                <span class="ntdst-form-unit"><?php esc_html_e('hours', 'ntdst-auth'); ?></span>
                            </div>
                            <p class="ntdst-form-help"><?php esc_html_e('How long an account activation link remains valid (default: 48 hours).', 'ntdst-auth'); ?></p>
                        </div>
                    </div>
                </div>

                <?php elseif ($current_tab === 'registration'): ?>

                <div class="ntdst-card">
                    <div class="ntdst-card-header">
                        <h3 class="ntdst-card-title"><span class="dashicons dashicons-admin-users"></span> <?php esc_html_e('Registration', 'ntdst-auth'); ?></h3>
                    </div>
                    <div class="ntdst-card-body">
                        <div class="ntdst-form-group">
                            <div class="ntdst-form-checkbox">
                                <input type="checkbox" id="enable_registration" name="ntdst_auth_settings[enable_registration]" value="1"
                                       <?php checked($settings['enable_registration']); ?>>
                                <label for="enable_registration"><?php esc_html_e('Enable user registration', 'ntdst-auth'); ?></label>
                            </div>
                            <p class="ntdst-form-help"><?php esc_html_e('Allow new users to create accounts.', 'ntdst-auth'); ?></p>
                        </div>
                    </div>
                </div>

                <div class="ntdst-card">
                    <div class="ntdst-card-header">
                        <h3 class="ntdst-card-title"><span class="dashicons dashicons-forms"></span> <?php esc_html_e('Required Fields', 'ntdst-auth'); ?></h3>
                    </div>
                    <div class="ntdst-card-body">
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
                        <div class="ntdst-form-checkbox">
                            <input type="checkbox"
                                   name="ntdst_auth_settings[registration_fields][]"
                                   value="<?php echo esc_attr($field_key); ?>"
                                   <?php checked(in_array($field_key, $selected_fields, true)); ?>
                                   <?php echo $field_key === 'email' ? 'disabled checked' : ''; ?>>
                            <label>
                                <?php echo esc_html($field_label); ?>
                                <?php if ($field_key === 'email'): ?>
                                    <span class="ntdst-text-muted"><?php esc_html_e('(always required)', 'ntdst-auth'); ?></span>
                                <?php endif; ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                        <!-- Hidden field to ensure email is always included -->
                        <input type="hidden" name="ntdst_auth_settings[registration_fields][]" value="email">
                        <p class="ntdst-form-help" style="margin-top:8px;"><?php esc_html_e('Select which fields are required during registration.', 'ntdst-auth'); ?></p>
                    </div>
                </div>

                <div class="ntdst-card">
                    <div class="ntdst-card-header">
                        <h3 class="ntdst-card-title"><span class="dashicons dashicons-privacy"></span> <?php esc_html_e('Legal', 'ntdst-auth'); ?></h3>
                    </div>
                    <div class="ntdst-card-body">
                        <div class="ntdst-form-group">
                            <label class="ntdst-form-label" for="terms_url"><?php esc_html_e('Terms URL', 'ntdst-auth'); ?></label>
                            <input type="text" id="terms_url" name="ntdst_auth_settings[terms_url]"
                                   value="<?php echo esc_attr($settings['terms_url']); ?>" class="ntdst-form-input" style="max-width:400px;">
                            <p class="ntdst-form-help"><?php esc_html_e('URL path to Terms of Service page (e.g., /terms).', 'ntdst-auth'); ?></p>
                        </div>
                        <div class="ntdst-form-group">
                            <label class="ntdst-form-label" for="privacy_url"><?php esc_html_e('Privacy URL', 'ntdst-auth'); ?></label>
                            <input type="text" id="privacy_url" name="ntdst_auth_settings[privacy_url]"
                                   value="<?php echo esc_attr($settings['privacy_url']); ?>" class="ntdst-form-input" style="max-width:400px;">
                            <p class="ntdst-form-help"><?php esc_html_e('URL path to Privacy Policy page (e.g., /privacy).', 'ntdst-auth'); ?></p>
                        </div>
                        <div class="ntdst-form-group">
                            <label class="ntdst-form-label" for="consent_version"><?php esc_html_e('Consent Version', 'ntdst-auth'); ?></label>
                            <input type="text" id="consent_version" name="ntdst_auth_settings[consent_version]"
                                   value="<?php echo esc_attr($settings['consent_version']); ?>" class="ntdst-form-input" style="max-width:120px;">
                            <p class="ntdst-form-help"><?php esc_html_e('Version string for GDPR consent tracking (e.g., 1.0). Update when terms change.', 'ntdst-auth'); ?></p>
                        </div>
                    </div>
                </div>

                <?php elseif ($current_tab === 'security'): ?>

                <div class="ntdst-card">
                    <div class="ntdst-card-header">
                        <h3 class="ntdst-card-title"><span class="dashicons dashicons-shield"></span> <?php esc_html_e('Rate Limiting', 'ntdst-auth'); ?></h3>
                    </div>
                    <div class="ntdst-card-body">
                        <div class="ntdst-form-group">
                            <label class="ntdst-form-label" for="rate_limit_magic_link_per_email"><?php esc_html_e('Magic Link (per email)', 'ntdst-auth'); ?></label>
                            <div class="ntdst-form-row">
                                <input type="number" id="rate_limit_magic_link_per_email" name="ntdst_auth_settings[rate_limit_magic_link_per_email]"
                                       value="<?php echo esc_attr($settings['rate_limit_magic_link_per_email']); ?>" class="ntdst-form-input" style="width:80px;" min="1" max="20">
                                <span class="ntdst-form-unit"><?php esc_html_e('requests per window', 'ntdst-auth'); ?></span>
                            </div>
                        </div>
                        <div class="ntdst-form-group">
                            <label class="ntdst-form-label" for="rate_limit_magic_link_per_ip"><?php esc_html_e('Magic Link (per IP)', 'ntdst-auth'); ?></label>
                            <div class="ntdst-form-row">
                                <input type="number" id="rate_limit_magic_link_per_ip" name="ntdst_auth_settings[rate_limit_magic_link_per_ip]"
                                       value="<?php echo esc_attr($settings['rate_limit_magic_link_per_ip']); ?>" class="ntdst-form-input" style="width:80px;" min="1" max="50">
                                <span class="ntdst-form-unit"><?php esc_html_e('requests per window', 'ntdst-auth'); ?></span>
                            </div>
                        </div>
                        <div class="ntdst-form-group">
                            <label class="ntdst-form-label" for="rate_limit_login_per_ip"><?php esc_html_e('Login (per IP)', 'ntdst-auth'); ?></label>
                            <div class="ntdst-form-row">
                                <input type="number" id="rate_limit_login_per_ip" name="ntdst_auth_settings[rate_limit_login_per_ip]"
                                       value="<?php echo esc_attr($settings['rate_limit_login_per_ip']); ?>" class="ntdst-form-input" style="width:80px;" min="1" max="20">
                                <span class="ntdst-form-unit"><?php esc_html_e('attempts per window', 'ntdst-auth'); ?></span>
                            </div>
                        </div>
                        <div class="ntdst-form-group">
                            <label class="ntdst-form-label" for="rate_limit_registration_per_ip"><?php esc_html_e('Registration (per IP)', 'ntdst-auth'); ?></label>
                            <div class="ntdst-form-row">
                                <input type="number" id="rate_limit_registration_per_ip" name="ntdst_auth_settings[rate_limit_registration_per_ip]"
                                       value="<?php echo esc_attr($settings['rate_limit_registration_per_ip']); ?>" class="ntdst-form-input" style="width:80px;" min="1" max="20">
                                <span class="ntdst-form-unit"><?php esc_html_e('registrations per window', 'ntdst-auth'); ?></span>
                            </div>
                        </div>
                        <div class="ntdst-form-group">
                            <label class="ntdst-form-label" for="rate_limit_window"><?php esc_html_e('Rate Limit Window', 'ntdst-auth'); ?></label>
                            <div class="ntdst-form-row">
                                <input type="number" id="rate_limit_window" name="ntdst_auth_settings[rate_limit_window]"
                                       value="<?php echo esc_attr($settings['rate_limit_window']); ?>" class="ntdst-form-input" style="width:80px;" min="1" max="60">
                                <span class="ntdst-form-unit"><?php esc_html_e('minutes', 'ntdst-auth'); ?></span>
                            </div>
                            <p class="ntdst-form-help"><?php esc_html_e('Time window for rate limiting (default: 15 minutes).', 'ntdst-auth'); ?></p>
                        </div>
                    </div>
                </div>

                <div class="ntdst-card">
                    <div class="ntdst-card-header">
                        <h3 class="ntdst-card-title"><span class="dashicons dashicons-wordpress"></span> <?php esc_html_e('WordPress Login', 'ntdst-auth'); ?></h3>
                    </div>
                    <div class="ntdst-card-body">
                        <div class="ntdst-form-group">
                            <div class="ntdst-form-checkbox">
                                <input type="checkbox" id="redirect_wp_login" name="ntdst_auth_settings[redirect_wp_login]" value="1"
                                       <?php checked($settings['redirect_wp_login']); ?>>
                                <label for="redirect_wp_login"><?php esc_html_e('Redirect wp-login.php to custom login page', 'ntdst-auth'); ?></label>
                            </div>
                            <p class="ntdst-form-help"><?php esc_html_e('Redirect users from /wp-login.php to your custom login URL. Administrators can still access wp-login.php by adding ?admin=1 to the URL.', 'ntdst-auth'); ?></p>
                        </div>
                    </div>
                </div>

                <?php endif; ?>

                <button type="submit" class="ntdst-btn ntdst-btn-primary"><?php esc_html_e('Save Changes', 'ntdst-auth'); ?></button>
            </form>
        </div><!-- .ntdst-main -->
    </div><!-- .ntdst-layout -->
</div>
