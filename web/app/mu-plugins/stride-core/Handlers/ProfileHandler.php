<?php

declare(strict_types=1);

namespace Stride\Handlers;

use WP_Error;

/**
 * Handles user profile AJAX requests.
 *
 * Thin handler - validates input, updates user data.
 */
final class ProfileHandler
{
    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        add_action('wp_ajax_stride_update_profile', [$this, 'ajaxUpdateProfile']);
    }

    /**
     * AJAX: Update user profile.
     */
    public function ajaxUpdateProfile(): void
    {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'stride_profile')) {
            wp_send_json_error(['message' => __('Ongeldige beveiligingstoken.', 'stride')]);
        }

        $result = $this->handleUpdateProfile($_POST);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }

    /**
     * Handle profile update.
     *
     * @param array<string, mixed> $params Request parameters
     * @return array<string, mixed>|WP_Error
     */
    public function handleUpdateProfile(array $params): array|WP_Error
    {
        $userId = get_current_user_id();
        if (!$userId) {
            return new WP_Error('not_logged_in', __('Je moet ingelogd zijn.', 'stride'));
        }

        // Sanitize input
        $firstName = sanitize_text_field($params['first_name'] ?? '');
        $lastName = sanitize_text_field($params['last_name'] ?? '');
        $phone = sanitize_text_field($params['phone'] ?? '');
        $company = sanitize_text_field($params['company'] ?? '');

        // Update user
        $result = wp_update_user([
            'ID' => $userId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'display_name' => trim($firstName . ' ' . $lastName),
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        // Update meta
        if ($phone) {
            update_user_meta($userId, 'phone', $phone);
        }
        if ($company) {
            update_user_meta($userId, 'company', $company);
        }

        return [
            'success' => true,
            'message' => __('Profiel bijgewerkt.', 'stride'),
        ];
    }
}
