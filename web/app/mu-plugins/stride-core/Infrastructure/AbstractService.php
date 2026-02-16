<?php

declare(strict_types=1);

namespace Stride\Infrastructure;

use NTDST_Service_Meta;

/**
 * Base service class with common patterns.
 *
 * All services implement NTDST_Service_Meta for auto-discovery.
 */
abstract class AbstractService implements NTDST_Service_Meta
{
    /**
     * Service configuration from filters.
     *
     * @var array<string, mixed>
     */
    protected array $config;

    public function __construct()
    {
        $this->config = $this->getDefaultConfig();
        $this->init();
    }

    /**
     * Get default configuration - override in child classes.
     *
     * @return array<string, mixed>
     */
    protected function getDefaultConfig(): array
    {
        $slug = $this->getConfigSlug();

        return apply_filters("stride_{$slug}_config", []);
    }

    /**
     * Get config slug for filters (e.g., 'edition' for EditionService).
     */
    abstract protected function getConfigSlug(): string;

    /**
     * Initialize service - register hooks here.
     */
    abstract protected function init(): void;

    /**
     * Fire a domain event via WordPress action.
     *
     * @param array<string, mixed> $data
     */
    protected function dispatch(string $event, array $data = []): void
    {
        do_action("stride/{$event}", $data);
    }
}
