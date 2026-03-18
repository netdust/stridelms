<?php

declare(strict_types=1);

namespace Netdust\Mail;

defined('ABSPATH') || exit;

/**
 * Registry for SmartCode definitions.
 *
 * SmartCodes are grouped by category (e.g., 'user', 'site', 'date') and
 * each code has a label and callback for value resolution.
 *
 * SmartCodes use {{category.field}} syntax in email templates.
 */
class SmartCodeRegistry
{
    /**
     * Cached SmartCode definitions.
     *
     * @var array<string, array{label: string, codes: array}>|null
     */
    private ?array $codes = null;

    /**
     * Get all registered SmartCodes.
     *
     * @return array<string, array{label: string, codes: array}>
     */
    public function getAll(): array
    {
        if ($this->codes === null) {
            $this->codes = apply_filters('ndmail_smartcodes', []);
        }
        return $this->codes;
    }

    /**
     * Get a specific SmartCode callback.
     *
     * @param string $category Category key (e.g., 'user', 'site').
     * @param string $field    Field key (e.g., 'email', 'name').
     * @return callable|null The callback if found and callable, null otherwise.
     */
    public function getCallback(string $category, string $field): ?callable
    {
        $codes = $this->getAll();

        if (!isset($codes[$category]['codes'][$field]['callback'])) {
            return null;
        }

        $callback = $codes[$category]['codes'][$field]['callback'];

        return is_callable($callback) ? $callback : null;
    }

    /**
     * Get all category keys mapped to their labels.
     *
     * @return array<string, string> Category key => label mapping.
     */
    public function getCategories(): array
    {
        $codes = $this->getAll();
        $categories = [];

        foreach ($codes as $key => $config) {
            $categories[$key] = $config['label'] ?? $key;
        }

        return $categories;
    }

    /**
     * Get all codes for a specific category.
     *
     * @param string $category Category key.
     * @return array<string, string> Field key => label mapping.
     */
    public function getCodesForCategory(string $category): array
    {
        $codes = $this->getAll();

        if (!isset($codes[$category]['codes'])) {
            return [];
        }

        $result = [];
        foreach ($codes[$category]['codes'] as $field => $config) {
            $result[$field] = $config['label'] ?? $field;
        }

        return $result;
    }

    /**
     * Get a flat list of all codes with their metadata.
     *
     * @return array<int, array{code: string, category: string, label: string}>
     */
    public function getAllFlat(): array
    {
        $codes = $this->getAll();
        $flat = [];

        foreach ($codes as $category => $categoryConfig) {
            foreach (($categoryConfig['codes'] ?? []) as $field => $fieldConfig) {
                $flat[] = [
                    'code' => "{{$category}.{$field}}",
                    'category' => $categoryConfig['label'] ?? $category,
                    'label' => $fieldConfig['label'] ?? $field,
                ];
            }
        }

        return $flat;
    }

    /**
     * Clear the cached SmartCodes.
     *
     * Call this when SmartCode definitions may have changed.
     */
    public function refresh(): void
    {
        $this->codes = null;
    }
}
