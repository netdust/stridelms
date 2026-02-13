<?php

namespace ntdst\Stride\enrollment;

defined('ABSPATH') || exit;

/**
 * FluentForms Field Handler
 *
 * Populates company dropdown options from FluentCRM companies.
 *
 * This is a handler class initialized by EnrollmentService, NOT a service.
 * It should not be registered directly with the DI container.
 *
 * Note: Other form field population is handled by FluentForms built-in shortcodes:
 * - URL params: {get.course_id}, {get.group_id}
 * - User data: {wp.first_name}, {wp.last_name}, {wp.user_email}
 * - Stride data: {stride_contact.*} via SmartCodeService
 *
 * @package stride\services\enrollment
 */
class FluentFormsFieldHandler
{
    /**
     * CSS class used to identify organisation select fields.
     */
    private const COMPANY_SELECT_CLASS = 'stride-company-select';

    /**
     * Initialize hooks.
     */
    public function __construct()
    {
        add_filter('fluentform/rendering_field_data_select', [$this, 'populateOrganisationOptions'], 10, 2);
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
}
