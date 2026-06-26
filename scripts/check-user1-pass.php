<?php

// Check if we know the password for user 1
$user = get_user_by('ID', 1);
echo "User 1 login: " . $user->user_login . "\n";
echo "User 1 email: " . $user->user_email . "\n";

// Try setting seed password to verify
$valid = wp_check_password('seedpass123', $user->user_pass, $user->ID);
echo "seedpass123 works: " . ($valid ? 'YES' : 'NO') . "\n";
$valid2 = wp_check_password('admin', $user->user_pass, $user->ID);
echo "admin works: " . ($valid2 ? 'YES' : 'NO') . "\n";
