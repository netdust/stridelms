<?php

declare(strict_types=1);

namespace Stride\Handlers;

use WP_Error;

/**
 * Handles user profile AJAX requests.
 *
 * Thin handler - validates input, updates user data.
 * Supports personal, billing, and notification form types.
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

        $formType = sanitize_text_field($_POST['form_type'] ?? 'personal');

        $result = match ($formType) {
            'billing' => $this->handleUpdateBilling($_POST),
            'notifications' => $this->handleUpdateNotifications($_POST),
            default => $this->handleUpdatePersonal($_POST),
        };

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }

    /**
     * Handle personal profile update.
     *
     * @param array<string, mixed> $params Request parameters
     * @return array<string, mixed>|WP_Error
     */
    public function handleUpdatePersonal(array $params): array|WP_Error
    {
        $userId = get_current_user_id();
        if (!$userId) {
            return new WP_Error('not_logged_in', __('Je moet ingelogd zijn.', 'stride'));
        }

        // Sanitize input
        $firstName = sanitize_text_field($params['first_name'] ?? '');
        $lastName = sanitize_text_field($params['last_name'] ?? '');
        $phone = sanitize_text_field($params['phone'] ?? '');

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

        // Update phone meta
        update_user_meta($userId, 'phone', $phone);

        return [
            'success' => true,
            'message' => __('Persoonlijke gegevens bijgewerkt.', 'stride'),
        ];
    }

    /**
     * Handle billing profile update.
     *
     * @param array<string, mixed> $params Request parameters
     * @return array<string, mixed>|WP_Error
     */
    public function handleUpdateBilling(array $params): array|WP_Error
    {
        $userId = get_current_user_id();
        if (!$userId) {
            return new WP_Error('not_logged_in', __('Je moet ingelogd zijn.', 'stride'));
        }

        // Sanitize billing fields
        $billingFields = [
            'invoice_organization_name' => sanitize_text_field($params['billing_company'] ?? ''),
            'vat_number' => sanitize_text_field($params['billing_vat'] ?? ''),
            'invoice_address' => sanitize_text_field($params['billing_address'] ?? ''),
            'invoice_postal_code' => sanitize_text_field($params['billing_postal_code'] ?? ''),
            'invoice_city' => sanitize_text_field($params['billing_city'] ?? ''),
            'invoice_email' => sanitize_email($params['billing_email'] ?? ''),
            'gln_number' => sanitize_text_field($params['billing_gln'] ?? ''),
        ];

        // Also update legacy field names for compatibility
        $legacyMappings = [
            'invoice_organization_name' => 'company',
            'invoice_address' => 'address_line_1',
            'invoice_postal_code' => 'postal_code',
            'invoice_city' => 'city',
        ];

        // Update all billing meta
        foreach ($billingFields as $key => $value) {
            update_user_meta($userId, $key, $value);

            // Also update legacy field if mapped
            if (isset($legacyMappings[$key])) {
                update_user_meta($userId, $legacyMappings[$key], $value);
            }
        }

        return [
            'success' => true,
            'message' => __('Facturatiegegevens bijgewerkt.', 'stride'),
        ];
    }

    /**
     * Handle notification preferences update.
     *
     * @param array<string, mixed> $params Request parameters
     * @return array<string, mixed>|WP_Error
     */
    public function handleUpdateNotifications(array $params): array|WP_Error
    {
        $userId = get_current_user_id();
        if (!$userId) {
            return new WP_Error('not_logged_in', __('Je moet ingelogd zijn.', 'stride'));
        }

        // Notification preferences (checkboxes - absent means 'no')
        $notifyReminders = isset($params['notify_reminders']) ? 'yes' : 'no';
        $notifyNewCourses = isset($params['notify_new_courses']) ? 'yes' : 'no';
        $notifyNewsletter = isset($params['notify_newsletter']) ? 'yes' : 'no';

        update_user_meta($userId, 'stride_notify_reminders', $notifyReminders);
        update_user_meta($userId, 'stride_notify_new_courses', $notifyNewCourses);
        update_user_meta($userId, 'stride_notify_newsletter', $notifyNewsletter);

        // Communication language (validate against allowed values)
        $allowedLanguages = ['nl', 'fr', 'en'];
        $language = sanitize_text_field($params['communication_language'] ?? 'nl');
        if (!in_array($language, $allowedLanguages, true)) {
            $language = 'nl'; // Default to Dutch
        }
        update_user_meta($userId, 'stride_communication_language', $language);

        return [
            'success' => true,
            'message' => __('Meldingsvoorkeuren bijgewerkt.', 'stride'),
        ];
    }

    /**
     * Handle profile update (legacy method for backward compatibility).
     *
     * @param array<string, mixed> $params Request parameters
     * @return array<string, mixed>|WP_Error
     */
    public function handleUpdateProfile(array $params): array|WP_Error
    {
        return $this->handleUpdatePersonal($params);
    }
}
