<?php

namespace stride\services\smartcode\contracts;

defined('ABSPATH') || exit;

/**
 * SmartCode Provider Interface
 *
 * Contract for SmartCode providers that supply data to FluentCRM and FluentForms.
 * Each provider handles a specific domain (contact, course, invoice).
 *
 * @package stride\services\smartcode
 */
interface SmartCodeProviderInterface
{
    /**
     * Get the unique key for this provider
     *
     * Used as the group identifier in FluentCRM and FluentForms.
     * Example: 'stride_contact', 'stride_course'
     *
     * @return string
     */
    public function getKey(): string;

    /**
     * Get the display title for this provider
     *
     * Shown in FluentCRM/FluentForms UI when selecting SmartCodes.
     * Example: 'Stride Contact', 'Stride Course'
     *
     * @return string
     */
    public function getTitle(): string;

    /**
     * Get available SmartCodes with labels
     *
     * Returns an associative array of code keys to labels.
     * Example: ['first_name' => 'First Name', 'email' => 'Email Address']
     *
     * @return array<string, string>
     */
    public function getShortCodes(): array;

    /**
     * Get the value for a specific SmartCode
     *
     * Called when a SmartCode needs to be replaced with actual data.
     *
     * @param string $valueKey The SmartCode key (e.g., 'first_name')
     * @param mixed $subscriber FluentCRM subscriber object or array
     * @param SmartCodeContextInterface $context Context for resolving related data
     * @return string|null The resolved value or null if not available
     */
    public function getValue(string $valueKey, mixed $subscriber, SmartCodeContextInterface $context): ?string;
}
