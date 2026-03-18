<?php

declare(strict_types=1);

namespace Netdust\Mail;

defined('ABSPATH') || exit;

/**
 * Registry for email trigger definitions.
 *
 * Triggers are WordPress actions that can automatically send emails
 * when templates are configured to respond to them.
 *
 * Triggers are registered via the 'ndmail_triggers' filter and should
 * provide the following structure:
 *
 * [
 *     'action_hook_name' => [
 *         'label'   => 'Human-readable name',
 *         'source'  => 'Plugin/Feature name',
 *         'context' => ['user_id', 'post_id', ...],  // Expected context keys
 *     ],
 * ]
 */
class TriggerRegistry
{
    /**
     * Cached triggers array.
     */
    private ?array $triggers = null;

    /**
     * Get all registered triggers.
     *
     * @return array<string, array{label: string, source?: string, context: array}>
     */
    public function getAll(): array
    {
        if ($this->triggers === null) {
            $this->triggers = apply_filters('ndmail_triggers', []);
        }

        return $this->triggers;
    }

    /**
     * Get configuration for a specific trigger.
     *
     * @param string $key The trigger key (WordPress action hook name).
     * @return array|null The trigger configuration or null if not found.
     */
    public function get(string $key): ?array
    {
        $triggers = $this->getAll();

        return $triggers[$key] ?? null;
    }

    /**
     * Get expected context keys for a trigger.
     *
     * @param string $key The trigger key.
     * @return array List of expected context keys.
     */
    public function getContextKeys(string $key): array
    {
        $trigger = $this->get($key);

        return $trigger['context'] ?? [];
    }

    /**
     * Get trigger options for dropdown UI.
     *
     * Returns a flat key => label mapping with an empty "None" option.
     *
     * @return array<string, string> Key => label pairs.
     */
    public function getOptions(): array
    {
        $triggers = $this->getAll();
        $options = ['' => __('— None (manual only) —', 'netdust-mail')];

        foreach ($triggers as $key => $config) {
            $options[$key] = $config['label'] ?? $key;
        }

        return $options;
    }

    /**
     * Get trigger options grouped by source.
     *
     * Returns options organized by source for optgroup rendering.
     *
     * @return array<string, array<string, string>> Source => [key => label] pairs.
     */
    public function getGroupedOptions(): array
    {
        $triggers = $this->getAll();
        $grouped = [];

        foreach ($triggers as $key => $config) {
            $source = $config['source'] ?? __('Core', 'netdust-mail');

            if (!isset($grouped[$source])) {
                $grouped[$source] = [];
            }

            $grouped[$source][$key] = $config['label'] ?? $key;
        }

        return $grouped;
    }

    /**
     * Clear the triggers cache.
     *
     * Forces triggers to be reloaded from the filter on next access.
     */
    public function refresh(): void
    {
        $this->triggers = null;
    }
}
