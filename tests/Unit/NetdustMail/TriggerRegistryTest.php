<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\NetdustMail;

use Netdust\Mail\TriggerRegistry;
use Stride\Tests\TestCase;

/**
 * Unit tests for TriggerRegistry.
 *
 * @covers \Netdust\Mail\TriggerRegistry
 */
class TriggerRegistryTest extends TestCase
{
    private TriggerRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new TriggerRegistry();
    }

    /**
     * Sample triggers for testing.
     */
    private function getSampleTriggers(): array
    {
        return [
            'user_registered' => [
                'label' => 'User Registered',
                'source' => 'WordPress',
                'context' => ['user_id', 'user_email'],
            ],
            'order_completed' => [
                'label' => 'Order Completed',
                'source' => 'WooCommerce',
                'context' => ['order_id', 'user_id', 'order_total'],
            ],
            'course_completed' => [
                'label' => 'Course Completed',
                'source' => 'LearnDash',
                'context' => ['user_id', 'course_id'],
            ],
            'custom_trigger' => [
                'label' => 'Custom Trigger',
                // No source - should default to 'Core'
                'context' => ['custom_data'],
            ],
            'minimal_trigger' => [
                // No label - should fall back to key
                'source' => 'Other',
                'context' => [],
            ],
        ];
    }

    /**
     * Register sample triggers via filter.
     */
    private function registerSampleTriggers(): void
    {
        add_filter('ndmail_triggers', fn() => $this->getSampleTriggers());
    }

    // -------------------------------------------------------------------------
    // getAll() tests
    // -------------------------------------------------------------------------

    public function testGetAllReturnsEmptyArrayWhenNoTriggersRegistered(): void
    {
        $result = $this->registry->getAll();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetAllReturnsFilteredArray(): void
    {
        $this->registerSampleTriggers();

        $result = $this->registry->getAll();

        $this->assertIsArray($result);
        $this->assertCount(5, $result);
        $this->assertArrayHasKey('user_registered', $result);
        $this->assertArrayHasKey('order_completed', $result);
        $this->assertArrayHasKey('course_completed', $result);
    }

    public function testGetAllCachesResult(): void
    {
        $callCount = 0;
        add_filter('ndmail_triggers', function () use (&$callCount) {
            $callCount++;
            return ['test_trigger' => ['label' => 'Test', 'context' => []]];
        });

        // Call getAll() multiple times
        $this->registry->getAll();
        $this->registry->getAll();
        $this->registry->getAll();

        // Filter should only be called once due to caching
        $this->assertEquals(1, $callCount);
    }

    // -------------------------------------------------------------------------
    // get() tests
    // -------------------------------------------------------------------------

    public function testGetReturnsSpecificTriggerConfig(): void
    {
        $this->registerSampleTriggers();

        $result = $this->registry->get('user_registered');

        $this->assertIsArray($result);
        $this->assertEquals('User Registered', $result['label']);
        $this->assertEquals('WordPress', $result['source']);
        $this->assertEquals(['user_id', 'user_email'], $result['context']);
    }

    public function testGetReturnsNullForUnknownTrigger(): void
    {
        $this->registerSampleTriggers();

        $result = $this->registry->get('nonexistent_trigger');

        $this->assertNull($result);
    }

    public function testGetReturnsNullWhenNoTriggersRegistered(): void
    {
        $result = $this->registry->get('any_trigger');

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // getContextKeys() tests
    // -------------------------------------------------------------------------

    public function testGetContextKeysReturnsExpectedKeys(): void
    {
        $this->registerSampleTriggers();

        $result = $this->registry->getContextKeys('order_completed');

        $this->assertEquals(['order_id', 'user_id', 'order_total'], $result);
    }

    public function testGetContextKeysReturnsEmptyArrayForUnknownTrigger(): void
    {
        $this->registerSampleTriggers();

        $result = $this->registry->getContextKeys('nonexistent_trigger');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetContextKeysReturnsEmptyArrayWhenTriggerHasNoContext(): void
    {
        add_filter('ndmail_triggers', fn() => [
            'no_context_trigger' => [
                'label' => 'No Context',
                // No 'context' key defined
            ],
        ]);

        $result = $this->registry->getContextKeys('no_context_trigger');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetContextKeysReturnsEmptyArrayForTriggerWithEmptyContext(): void
    {
        $this->registerSampleTriggers();

        $result = $this->registry->getContextKeys('minimal_trigger');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // -------------------------------------------------------------------------
    // getOptions() tests
    // -------------------------------------------------------------------------

    public function testGetOptionsReturnsKeyLabelMappingWithNoneOption(): void
    {
        $this->registerSampleTriggers();

        $result = $this->registry->getOptions();

        $this->assertIsArray($result);
        // Should have None option + 5 triggers
        $this->assertCount(6, $result);

        // First option should be empty "None" option
        $this->assertArrayHasKey('', $result);
        $this->assertStringContainsString('None', $result['']);

        // Check trigger labels
        $this->assertEquals('User Registered', $result['user_registered']);
        $this->assertEquals('Order Completed', $result['order_completed']);
        $this->assertEquals('Course Completed', $result['course_completed']);
        $this->assertEquals('Custom Trigger', $result['custom_trigger']);
    }

    public function testGetOptionsUsesKeyWhenLabelMissing(): void
    {
        $this->registerSampleTriggers();

        $result = $this->registry->getOptions();

        // minimal_trigger has no label, should use key
        $this->assertEquals('minimal_trigger', $result['minimal_trigger']);
    }

    public function testGetOptionsReturnsOnlyNoneWhenNoTriggersRegistered(): void
    {
        $result = $this->registry->getOptions();

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('', $result);
    }

    // -------------------------------------------------------------------------
    // getGroupedOptions() tests
    // -------------------------------------------------------------------------

    public function testGetGroupedOptionsGroupsBySource(): void
    {
        $this->registerSampleTriggers();

        $result = $this->registry->getGroupedOptions();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('WordPress', $result);
        $this->assertArrayHasKey('WooCommerce', $result);
        $this->assertArrayHasKey('LearnDash', $result);
        $this->assertArrayHasKey('Core', $result); // Default for custom_trigger
        $this->assertArrayHasKey('Other', $result);
    }

    public function testGetGroupedOptionsContainsCorrectTriggers(): void
    {
        $this->registerSampleTriggers();

        $result = $this->registry->getGroupedOptions();

        // Check WordPress group
        $this->assertEquals(['user_registered' => 'User Registered'], $result['WordPress']);

        // Check WooCommerce group
        $this->assertEquals(['order_completed' => 'Order Completed'], $result['WooCommerce']);

        // Check LearnDash group
        $this->assertEquals(['course_completed' => 'Course Completed'], $result['LearnDash']);

        // Check Core group (no source specified)
        $this->assertEquals(['custom_trigger' => 'Custom Trigger'], $result['Core']);

        // Check Other group (minimal_trigger has no label)
        $this->assertEquals(['minimal_trigger' => 'minimal_trigger'], $result['Other']);
    }

    public function testGetGroupedOptionsReturnsEmptyArrayWhenNoTriggersRegistered(): void
    {
        $result = $this->registry->getGroupedOptions();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetGroupedOptionsUsesDefaultSourceForMissingSource(): void
    {
        add_filter('ndmail_triggers', fn() => [
            'trigger_without_source' => [
                'label' => 'No Source Trigger',
                'context' => [],
            ],
        ]);

        $result = $this->registry->getGroupedOptions();

        $this->assertArrayHasKey('Core', $result);
        $this->assertEquals(['trigger_without_source' => 'No Source Trigger'], $result['Core']);
    }

    // -------------------------------------------------------------------------
    // refresh() tests
    // -------------------------------------------------------------------------

    public function testRefreshClearsCache(): void
    {
        $callCount = 0;
        add_filter('ndmail_triggers', function () use (&$callCount) {
            $callCount++;
            return ['trigger_' . $callCount => ['label' => 'Trigger ' . $callCount, 'context' => []]];
        });

        // First call
        $first = $this->registry->getAll();
        $this->assertArrayHasKey('trigger_1', $first);

        // Second call should use cache
        $second = $this->registry->getAll();
        $this->assertArrayHasKey('trigger_1', $second);
        $this->assertEquals(1, $callCount);

        // Refresh and call again
        $this->registry->refresh();
        $third = $this->registry->getAll();

        // Should have called filter again
        $this->assertEquals(2, $callCount);
        $this->assertArrayHasKey('trigger_2', $third);
    }

    public function testRefreshAllowsNewTriggersToBeLoaded(): void
    {
        // Start with one trigger
        add_filter('ndmail_triggers', fn() => [
            'first_trigger' => ['label' => 'First', 'context' => []],
        ], 10);

        $this->assertCount(1, $this->registry->getAll());

        // Clear and register more triggers
        global $_test_filters;
        $_test_filters['ndmail_triggers'] = [];

        add_filter('ndmail_triggers', fn() => [
            'first_trigger' => ['label' => 'First', 'context' => []],
            'second_trigger' => ['label' => 'Second', 'context' => []],
        ], 10);

        // Before refresh, still cached
        $this->assertCount(1, $this->registry->getAll());

        // After refresh, new triggers loaded
        $this->registry->refresh();
        $this->assertCount(2, $this->registry->getAll());
    }
}
