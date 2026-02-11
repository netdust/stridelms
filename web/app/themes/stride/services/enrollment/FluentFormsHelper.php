<?php

declare(strict_types=1);

namespace stride\services\enrollment;

use NTDST_Service_Meta;

/**
 * FluentForms Helper Service
 *
 * Populates dynamic fields in enrollment forms:
 * - Organisation dropdown from FluentCRM companies
 * - Course/Group ID from URL parameters
 *
 * @package stride\services\enrollment
 */
class FluentFormsHelper implements NTDST_Service_Meta
{
    /**
     * CSS class used to identify organisation select fields.
     */
    private const COMPANY_SELECT_CLASS = 'stride-company-select';

    /**
     * Service metadata for NTDST framework.
     */
    public static function metadata(): array
    {
        return [
            'name'        => 'FluentForms Helper',
            'description' => 'Populates dynamic fields in enrollment forms',
            'priority'    => 15,
        ];
    }

    /**
     * Initialize hooks.
     */
    public function __construct()
    {
        // Populate organisation dropdown options
        add_filter('fluentform/rendering_field_data_select', [$this, 'populateOrganisationOptions'], 10, 2);

        // Populate hidden fields from URL parameters
        add_filter('fluentform/rendering_field_data_input_hidden', [$this, 'populateHiddenFields'], 10, 2);

        // Also handle regular input fields for pre-fill
        add_filter('fluentform/rendering_field_data_input_text', [$this, 'prefillFromUser'], 10, 2);
        add_filter('fluentform/rendering_field_data_input_email', [$this, 'prefillFromUser'], 10, 2);
        add_filter('fluentform/rendering_field_data_input_name', [$this, 'prefillFromUser'], 10, 2);
        add_filter('fluentform/rendering_field_data_phone', [$this, 'prefillFromUser'], 10, 2);
    }

    /**
     * Populate organisation select dropdown with FluentCRM companies.
     *
     * Targets select fields with class 'stride-company-select'.
     *
     * @param array $data  Field data.
     * @param mixed $form  Form object.
     *
     * @return array Modified field data.
     */
    public function populateOrganisationOptions(array $data, $form): array
    {
        $class = $data['attributes']['class'] ?? '';

        if (strpos($class, self::COMPANY_SELECT_CLASS) === false) {
            return $data;
        }

        $companies = $this->getFluentCrmCompanies();

        if (empty($companies)) {
            return $data;
        }

        // Build options array for FluentForms
        $options = [];
        foreach ($companies as $index => $company) {
            $options['option_' . $index] = [
                'label' => $company['name'],
                'value' => (string) $company['id'],
            ];
        }

        $data['options'] = $options;

        return $data;
    }

    /**
     * Populate hidden fields from URL parameters.
     *
     * Handles: course_id, group_id
     *
     * @param array $data  Field data.
     * @param mixed $form  Form object.
     *
     * @return array Modified field data.
     */
    public function populateHiddenFields(array $data, $form): array
    {
        $name = $data['attributes']['name'] ?? '';

        // Map field names to URL parameters
        $urlParamMap = [
            'course_id' => ['course_id', 'course', 'cursus'],
            'group_id'  => ['group_id', 'group', 'traject'],
        ];

        if (!isset($urlParamMap[$name])) {
            return $data;
        }

        // Try each possible URL parameter
        foreach ($urlParamMap[$name] as $param) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $value = isset($_GET[$param]) ? absint($_GET[$param]) : 0;

            if ($value > 0) {
                $data['attributes']['value'] = (string) $value;
                break;
            }
        }

        return $data;
    }

    /**
     * Prefill form fields for logged-in users.
     *
     * @param array $data  Field data.
     * @param mixed $form  Form object.
     *
     * @return array Modified field data.
     */
    public function prefillFromUser(array $data, $form): array
    {
        if (!is_user_logged_in()) {
            return $data;
        }

        // Only prefill if field is empty
        $currentValue = $data['attributes']['value'] ?? '';
        if (!empty($currentValue)) {
            return $data;
        }

        $name = $data['attributes']['name'] ?? '';
        $user = wp_get_current_user();

        $prefillMap = [
            'voornaam'   => $user->first_name,
            'achternaam' => $user->last_name,
            'email'      => $user->user_email,
        ];

        if (isset($prefillMap[$name]) && !empty($prefillMap[$name])) {
            $data['attributes']['value'] = $prefillMap[$name];
        }

        // Try FluentCRM subscriber for phone
        if ($name === 'telefoon') {
            $phone = $this->getSubscriberPhone($user->user_email);
            if ($phone) {
                $data['attributes']['value'] = $phone;
            }
        }

        return $data;
    }

    /**
     * Get all companies from FluentCRM.
     *
     * @return array Array of companies with 'id' and 'name' keys.
     */
    private function getFluentCrmCompanies(): array
    {
        if (!function_exists('FluentCrmApi')) {
            return [];
        }

        try {
            $companiesApi = FluentCrmApi('companies');

            if (!method_exists($companiesApi, 'all')) {
                return [];
            }

            $companies = $companiesApi->all();

            if (empty($companies)) {
                return [];
            }

            $result = [];
            foreach ($companies as $company) {
                $result[] = [
                    'id'   => $company->id,
                    'name' => $company->name ?? $company->title ?? 'Unknown',
                ];
            }

            // Sort alphabetically by name
            usort($result, fn($a, $b) => strcasecmp($a['name'], $b['name']));

            return $result;
        } catch (\Exception $e) {
            if (function_exists('ntdst_log')) {
                ntdst_log()->warning('Failed to fetch FluentCRM companies', [
                    'error' => $e->getMessage(),
                ]);
            }

            return [];
        }
    }

    /**
     * Get subscriber phone number from FluentCRM.
     *
     * @param string $email User email.
     *
     * @return string|null Phone number or null.
     */
    private function getSubscriberPhone(string $email): ?string
    {
        if (!function_exists('FluentCrmApi')) {
            return null;
        }

        try {
            $subscriber = FluentCrmApi('contacts')->getContactByUserRef($email);

            if ($subscriber && !empty($subscriber->phone)) {
                return $subscriber->phone;
            }
        } catch (\Exception $e) {
            // Silently fail - phone prefill is not critical
        }

        return null;
    }
}
