<?php

namespace stride\services\smartcode;

defined('ABSPATH') || exit;

use stride\services\smartcode\contracts\SmartCodeProviderInterface;
use stride\services\smartcode\providers\ContactSmartCodeProvider;
use stride\services\smartcode\providers\CourseSmartCodeProvider;
use stride\services\smartcode\providers\InvoiceSmartCodeProvider;

/**
 * SmartCode Service
 *
 * Main orchestrator for SmartCode system.
 * Registers all providers with FluentCRM and FluentForms.
 *
 * Available hooks:
 * - stride/smartcode/providers - Filter to add/remove providers
 * - stride/smartcode/context - Filter to modify context resolution
 *
 * SECURITY: External providers added via filter are logged for audit purposes.
 *
 * PERFORMANCE: Uses singleton pattern for providers and includes batch
 * prefetch hooks for FluentCRM email campaigns.
 *
 * @package stride\services\smartcode
 */
class SmartCodeService implements \NTDST_Service_Meta
{
    /**
     * @var SmartCodeProviderInterface[]
     */
    private array $providers = [];

    /**
     * @var SmartCodeContext
     */
    private SmartCodeContext $context;

    /**
     * Default provider classes (for security validation)
     */
    private const DEFAULT_PROVIDER_CLASSES = [
        ContactSmartCodeProvider::class,
        CourseSmartCodeProvider::class,
        InvoiceSmartCodeProvider::class,
    ];

    /**
     * Service metadata for NTDST Bootstrap
     */
    public static function metadata(): array
    {
        return [
            'name' => 'SmartCode Service',
            'description' => 'SmartCode integration for FluentCRM and FluentForms',
            'admin_only' => false,
            'enabled' => true,
            'priority' => 20, // Load after core services
        ];
    }

    /**
     * Constructor
     *
     * PERFORMANCE: Dependencies can be injected; defaults use singleton pattern
     * via DI container when available.
     *
     * @param SmartCodeContext|null $context
     * @param array|null $providers Optional pre-configured providers
     */
    public function __construct(?SmartCodeContext $context = null, ?array $providers = null)
    {
        $this->context = $context ?? $this->getOrCreateContext();
        $this->registerProviders($providers);
        $this->init();
    }

    /**
     * Get or create context using DI container if available
     *
     * @return SmartCodeContext
     */
    private function getOrCreateContext(): SmartCodeContext
    {
        // Try DI container first for singleton behavior
        if (function_exists('ntdst_get')) {
            try {
                $context = ntdst_get(SmartCodeContext::class);
                if ($context instanceof SmartCodeContext) {
                    return $context;
                }
            } catch (\Exception $e) {
                // Container doesn't have it, create new
            }
        }

        return new SmartCodeContext();
    }

    /**
     * Register default providers
     *
     * PERFORMANCE: Uses DI container for singleton behavior when available.
     * SECURITY: External providers are validated and logged.
     *
     * @param array|null $providers Pre-configured providers to use
     */
    private function registerProviders(?array $providers = null): void
    {
        if ($providers !== null) {
            // Use provided providers directly
            foreach ($providers as $provider) {
                if ($provider instanceof SmartCodeProviderInterface) {
                    $this->providers[$provider->getKey()] = $provider;
                }
            }
            return;
        }

        // Create default providers using DI container for singleton behavior
        $defaultProviders = $this->createDefaultProviders();

        // Allow filtering of providers
        $filteredProviders = apply_filters('stride/smartcode/providers', $defaultProviders);

        // Validate and register providers
        foreach ($filteredProviders as $provider) {
            if (!($provider instanceof SmartCodeProviderInterface)) {
                continue;
            }

            $this->providers[$provider->getKey()] = $provider;

            // Log external providers for security audit
            if (!$this->isDefaultProvider($provider)) {
                $this->logExternalProvider($provider);
            }
        }
    }

    /**
     * Create default providers using DI container when available
     *
     * @return SmartCodeProviderInterface[]
     */
    private function createDefaultProviders(): array
    {
        $providers = [];

        foreach (self::DEFAULT_PROVIDER_CLASSES as $class) {
            $provider = $this->createProvider($class);
            if ($provider) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }

    /**
     * Create a provider instance using DI container if available
     *
     * @param string $class
     * @return SmartCodeProviderInterface|null
     */
    private function createProvider(string $class): ?SmartCodeProviderInterface
    {
        // Try DI container first for singleton behavior
        if (function_exists('ntdst_get')) {
            try {
                $provider = ntdst_get($class);
                if ($provider instanceof SmartCodeProviderInterface) {
                    return $provider;
                }
            } catch (\Exception $e) {
                // Container doesn't have it, create new
            }
        }

        // Fallback to direct instantiation
        if (class_exists($class)) {
            return new $class();
        }

        return null;
    }

    /**
     * Check if provider is a default (built-in) provider
     *
     * @param SmartCodeProviderInterface $provider
     * @return bool
     */
    private function isDefaultProvider(SmartCodeProviderInterface $provider): bool
    {
        foreach (self::DEFAULT_PROVIDER_CLASSES as $class) {
            if ($provider instanceof $class) {
                return true;
            }
        }
        return false;
    }

    /**
     * Log external provider registration for security audit
     *
     * SECURITY: External providers can expose sensitive data, so we log
     * when they are registered for audit purposes.
     *
     * @param SmartCodeProviderInterface $provider
     */
    private function logExternalProvider(SmartCodeProviderInterface $provider): void
    {
        if (!function_exists('do_action')) {
            return;
        }

        $providerClass = get_class($provider);
        $providerKey = $provider->getKey();

        // Log via WordPress action for monitoring
        do_action('stride/smartcode/external_provider_registered', $providerKey, $providerClass);

        // Also log if error_log is available (for debugging)
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log(sprintf(
                '[Stride SmartCode] External provider registered: %s (%s)',
                $providerKey,
                $providerClass
            ));
        }
    }

    /**
     * Initialize hooks
     */
    private function init(): void
    {
        // FluentCRM registration - after FluentCRM initializes
        add_action('fluent_crm/after_init', [$this, 'registerFluentCRM']);

        // FluentForms editor registration
        add_filter('fluentform/editor_shortcodes', [$this, 'registerFluentFormsEditor'], 10, 1);
        add_filter('fluentform/all_editor_shortcodes', [$this, 'registerFluentFormsAll'], 10, 1);

        // Register FluentForms callbacks for each provider
        foreach ($this->providers as $provider) {
            add_filter(
                'fluentform/editor_shortcode_callback_group_' . $provider->getKey(),
                fn($value, $form, $handler) => $this->handleFluentForms($provider, $handler),
                10,
                3
            );
        }

        // PERFORMANCE: Hook into FluentCRM batch operations for prefetch
        add_action('fluent_crm/email_campaign_starting', [$this, 'prefetchCampaignData'], 10, 2);
        add_action('fluent_crm/sending_emails_starting', [$this, 'prefetchBatchEmails'], 10, 2);

        // Action for when service is ready
        do_action('stride/smartcode_service_ready', $this);
    }

    /**
     * Prefetch data for email campaign
     *
     * PERFORMANCE: Warms cache with subscriber data before batch email send.
     *
     * @param object $campaign FluentCRM campaign object
     * @param array $subscribers Array of subscriber objects
     */
    public function prefetchCampaignData($campaign, $subscribers): void
    {
        if (empty($subscribers) || !is_array($subscribers)) {
            return;
        }

        // Extract user IDs from subscribers
        $userIds = [];
        foreach ($subscribers as $subscriber) {
            $userId = is_object($subscriber) ? ($subscriber->user_id ?? null) : ($subscriber['user_id'] ?? null);
            if ($userId) {
                $userIds[] = (int) $userId;
            }
        }

        if (empty($userIds)) {
            return;
        }

        // Prefetch via ContactSmartCodeProvider if available
        $contactProvider = $this->getProvider('stride_contact');
        if ($contactProvider instanceof ContactSmartCodeProvider) {
            $contactProvider->prefetchUsers($userIds);
        }
    }

    /**
     * Prefetch data for batch email sending
     *
     * @param array $emails Array of emails being sent
     * @param object $campaign Campaign object
     */
    public function prefetchBatchEmails($emails, $campaign): void
    {
        // Delegate to campaign prefetch
        $this->prefetchCampaignData($campaign, $emails);
    }

    /**
     * Register SmartCodes with FluentCRM
     */
    public function registerFluentCRM(): void
    {
        // Check if FluentCRM extender API is available
        if (!function_exists('FluentCrmApi')) {
            return;
        }

        $extender = FluentCrmApi('extender');

        if (!$extender || !method_exists($extender, 'addSmartCode')) {
            return;
        }

        foreach ($this->providers as $provider) {
            $extender->addSmartCode(
                $provider->getKey(),
                $provider->getTitle(),
                $provider->getShortCodes(),
                function ($code, $valueKey, $defaultValue, $subscriber) use ($provider) {
                    $context = apply_filters('stride/smartcode/context', $this->context);
                    $value = $provider->getValue($valueKey, $subscriber, $context);

                    return $value ?? $defaultValue;
                }
            );
        }
    }

    /**
     * Register SmartCodes with FluentForms editor
     *
     * @param array $shortcodes Existing shortcodes
     * @return array Modified shortcodes
     */
    public function registerFluentFormsEditor(array $shortcodes): array
    {
        foreach ($this->providers as $provider) {
            $shortcodes[] = [
                'title' => $provider->getTitle(),
                'shortcodes' => $this->formatForFluentForms($provider),
            ];
        }

        return $shortcodes;
    }

    /**
     * Register SmartCodes with FluentForms (all shortcodes)
     *
     * @param array $shortcodes Existing shortcodes
     * @return array Modified shortcodes
     */
    public function registerFluentFormsAll(array $shortcodes): array
    {
        foreach ($this->providers as $provider) {
            $formatted = $this->formatForFluentForms($provider);

            foreach ($formatted as $code => $label) {
                $shortcodes[$code] = $label;
            }
        }

        return $shortcodes;
    }

    /**
     * Handle FluentForms shortcode replacement
     *
     * @param SmartCodeProviderInterface $provider
     * @param mixed $handler FluentForms handler
     * @return string|null
     */
    public function handleFluentForms(SmartCodeProviderInterface $provider, mixed $handler): ?string
    {
        // Extract the value key from the handler
        $valueKey = $this->extractValueKeyFromHandler($handler, $provider->getKey());

        if (!$valueKey) {
            return null;
        }

        // Create a minimal subscriber object from current user
        $subscriber = $this->getCurrentUserAsSubscriber();

        $context = apply_filters('stride/smartcode/context', $this->context);

        return $provider->getValue($valueKey, $subscriber, $context);
    }

    /**
     * Format provider shortcodes for FluentForms
     *
     * @param SmartCodeProviderInterface $provider
     * @return array
     */
    private function formatForFluentForms(SmartCodeProviderInterface $provider): array
    {
        $formatted = [];
        $key = $provider->getKey();

        foreach ($provider->getShortCodes() as $code => $label) {
            // Format: {stride_contact.first_name}
            $fullCode = '{' . $key . '.' . $code . '}';
            $formatted[$fullCode] = $label;
        }

        return $formatted;
    }

    /**
     * Extract value key from FluentForms handler
     *
     * @param mixed $handler
     * @param string $providerKey
     * @return string|null
     */
    private function extractValueKeyFromHandler(mixed $handler, string $providerKey): ?string
    {
        // FluentForms passes the shortcode string
        if (is_string($handler)) {
            // Pattern: {stride_contact.first_name} or stride_contact.first_name
            $pattern = '/^(?:\{)?' . preg_quote($providerKey, '/') . '\.([a-z_]+)(?:\})?$/';

            if (preg_match($pattern, $handler, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Get current user as subscriber array
     *
     * @return array|null
     */
    private function getCurrentUserAsSubscriber(): ?array
    {
        $userId = get_current_user_id();

        if (!$userId) {
            return null;
        }

        return [
            'user_id' => $userId,
        ];
    }

    /**
     * Get a specific provider by key
     *
     * @param string $key
     * @return SmartCodeProviderInterface|null
     */
    public function getProvider(string $key): ?SmartCodeProviderInterface
    {
        return $this->providers[$key] ?? null;
    }

    /**
     * Get all registered providers
     *
     * @return SmartCodeProviderInterface[]
     */
    public function getProviders(): array
    {
        return $this->providers;
    }

    /**
     * Get the context instance
     *
     * @return SmartCodeContext
     */
    public function getContext(): SmartCodeContext
    {
        return $this->context;
    }

    /**
     * Manually resolve a SmartCode value
     *
     * Useful for testing or programmatic access.
     *
     * @param string $fullCode Full SmartCode (e.g., 'stride_contact.first_name')
     * @param int|null $userId User ID (null for current user)
     * @param int|null $courseId Course ID (null for context resolution)
     * @return string|null
     */
    public function resolve(string $fullCode, ?int $userId = null, ?int $courseId = null): ?string
    {
        // Parse the full code
        $parts = explode('.', $fullCode, 2);

        if (count($parts) !== 2) {
            return null;
        }

        [$providerKey, $valueKey] = $parts;

        // Get the provider
        $provider = $this->getProvider($providerKey);

        if (!$provider) {
            return null;
        }

        // Set up context
        $context = clone $this->context;
        if ($courseId !== null) {
            $context->setCourseId($courseId);
        }

        // Create subscriber from user ID
        $targetUserId = $userId ?? get_current_user_id();
        $subscriber = $targetUserId ? ['user_id' => $targetUserId] : null;

        return $provider->getValue($valueKey, $subscriber, $context);
    }
}
