<?php
// Temporary auto-login script - DELETE AFTER USE
require_once dirname(__DIR__) . '/web/wp/wp-load.php';

wp_clear_auth_cookie();
wp_set_current_user(1);
wp_set_auth_cookie(1, true);

$redirect = $_GET['redirect'] ?? admin_url();
wp_safe_redirect($redirect);
exit;
