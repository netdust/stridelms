<?php

declare(strict_types=1);

namespace Tests\Unit\NetdustMail;

use Netdust\Mail\SmartCodeRegistry;
use PHPUnit\Framework\TestCase;

class SmartCodeRegistryTest extends TestCase
{
    private SmartCodeRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset global filter state
        global $_test_filters;
        $_test_filters = [];

        $this->registry = new SmartCodeRegistry();
    }

    protected function tearDown(): void
    {
        global $_test_filters;
        $_test_filters = [];

        parent::tearDown();
    }

    public function test_get_all_returns_empty_array_when_no_filters(): void
    {
        $result = $this->registry->getAll();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_get_all_returns_filtered_array(): void
    {
        $this->registerSmartCodes([
            'user' => [
                'label' => 'User',
                'codes' => [
                    'email' => [
                        'label' => 'Email Address',
                        'callback' => fn() => 'test@example.com',
                    ],
                ],
            ],
        ]);

        $result = $this->registry->getAll();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('user', $result);
        $this->assertEquals('User', $result['user']['label']);
        $this->assertArrayHasKey('email', $result['user']['codes']);
    }

    public function test_get_all_caches_result(): void
    {
        $callCount = 0;

        add_filter('ndmail_smartcodes', function ($codes) use (&$callCount) {
            $callCount++;
            return [
                'test' => [
                    'label' => 'Test',
                    'codes' => [],
                ],
            ];
        });

        // Call twice
        $this->registry->getAll();
        $this->registry->getAll();

        // Filter should only be called once due to caching
        $this->assertEquals(1, $callCount);
    }

    public function test_get_callback_returns_callable_for_registered_code(): void
    {
        $expectedCallback = fn() => 'test@example.com';

        $this->registerSmartCodes([
            'user' => [
                'label' => 'User',
                'codes' => [
                    'email' => [
                        'label' => 'Email Address',
                        'callback' => $expectedCallback,
                    ],
                ],
            ],
        ]);

        $callback = $this->registry->getCallback('user', 'email');

        $this->assertNotNull($callback);
        $this->assertTrue(is_callable($callback));
        $this->assertEquals('test@example.com', $callback());
    }

    public function test_get_callback_returns_null_for_unknown_category(): void
    {
        $this->registerSmartCodes([
            'user' => [
                'label' => 'User',
                'codes' => [
                    'email' => [
                        'label' => 'Email',
                        'callback' => fn() => 'test',
                    ],
                ],
            ],
        ]);

        $callback = $this->registry->getCallback('unknown', 'email');

        $this->assertNull($callback);
    }

    public function test_get_callback_returns_null_for_unknown_field(): void
    {
        $this->registerSmartCodes([
            'user' => [
                'label' => 'User',
                'codes' => [
                    'email' => [
                        'label' => 'Email',
                        'callback' => fn() => 'test',
                    ],
                ],
            ],
        ]);

        $callback = $this->registry->getCallback('user', 'unknown');

        $this->assertNull($callback);
    }

    public function test_get_callback_returns_null_for_non_callable(): void
    {
        $this->registerSmartCodes([
            'user' => [
                'label' => 'User',
                'codes' => [
                    'email' => [
                        'label' => 'Email',
                        'callback' => 'not_a_callable_string',
                    ],
                ],
            ],
        ]);

        $callback = $this->registry->getCallback('user', 'email');

        $this->assertNull($callback);
    }

    public function test_get_callback_returns_null_when_callback_missing(): void
    {
        $this->registerSmartCodes([
            'user' => [
                'label' => 'User',
                'codes' => [
                    'email' => [
                        'label' => 'Email',
                        // No callback defined
                    ],
                ],
            ],
        ]);

        $callback = $this->registry->getCallback('user', 'email');

        $this->assertNull($callback);
    }

    public function test_get_categories_returns_category_key_to_label_mapping(): void
    {
        $this->registerSmartCodes([
            'user' => [
                'label' => 'User Fields',
                'codes' => [],
            ],
            'site' => [
                'label' => 'Site Info',
                'codes' => [],
            ],
            'date' => [
                'label' => 'Date/Time',
                'codes' => [],
            ],
        ]);

        $categories = $this->registry->getCategories();

        $this->assertIsArray($categories);
        $this->assertCount(3, $categories);
        $this->assertEquals('User Fields', $categories['user']);
        $this->assertEquals('Site Info', $categories['site']);
        $this->assertEquals('Date/Time', $categories['date']);
    }

    public function test_get_categories_uses_key_as_fallback_label(): void
    {
        $this->registerSmartCodes([
            'user' => [
                // No label defined
                'codes' => [],
            ],
        ]);

        $categories = $this->registry->getCategories();

        $this->assertEquals('user', $categories['user']);
    }

    public function test_get_categories_returns_empty_array_when_no_codes(): void
    {
        $categories = $this->registry->getCategories();

        $this->assertIsArray($categories);
        $this->assertEmpty($categories);
    }

    public function test_get_codes_for_category_returns_codes(): void
    {
        $this->registerSmartCodes([
            'user' => [
                'label' => 'User',
                'codes' => [
                    'email' => [
                        'label' => 'Email Address',
                        'callback' => fn() => '',
                    ],
                    'first_name' => [
                        'label' => 'First Name',
                        'callback' => fn() => '',
                    ],
                    'last_name' => [
                        'label' => 'Last Name',
                        'callback' => fn() => '',
                    ],
                ],
            ],
        ]);

        $codes = $this->registry->getCodesForCategory('user');

        $this->assertIsArray($codes);
        $this->assertCount(3, $codes);
        $this->assertEquals('Email Address', $codes['email']);
        $this->assertEquals('First Name', $codes['first_name']);
        $this->assertEquals('Last Name', $codes['last_name']);
    }

    public function test_get_codes_for_category_returns_empty_array_for_unknown_category(): void
    {
        $this->registerSmartCodes([
            'user' => [
                'label' => 'User',
                'codes' => [
                    'email' => [
                        'label' => 'Email',
                        'callback' => fn() => '',
                    ],
                ],
            ],
        ]);

        $codes = $this->registry->getCodesForCategory('unknown');

        $this->assertIsArray($codes);
        $this->assertEmpty($codes);
    }

    public function test_get_codes_for_category_uses_field_as_fallback_label(): void
    {
        $this->registerSmartCodes([
            'user' => [
                'label' => 'User',
                'codes' => [
                    'email' => [
                        // No label defined
                        'callback' => fn() => '',
                    ],
                ],
            ],
        ]);

        $codes = $this->registry->getCodesForCategory('user');

        $this->assertEquals('email', $codes['email']);
    }

    public function test_get_codes_for_category_returns_empty_when_no_codes_key(): void
    {
        $this->registerSmartCodes([
            'user' => [
                'label' => 'User',
                // No 'codes' key
            ],
        ]);

        $codes = $this->registry->getCodesForCategory('user');

        $this->assertIsArray($codes);
        $this->assertEmpty($codes);
    }

    public function test_get_all_flat_returns_flat_list_with_code_category_label(): void
    {
        $this->registerSmartCodes([
            'user' => [
                'label' => 'User',
                'codes' => [
                    'email' => [
                        'label' => 'Email Address',
                        'callback' => fn() => '',
                    ],
                    'name' => [
                        'label' => 'Display Name',
                        'callback' => fn() => '',
                    ],
                ],
            ],
            'site' => [
                'label' => 'Site',
                'codes' => [
                    'name' => [
                        'label' => 'Site Name',
                        'callback' => fn() => '',
                    ],
                ],
            ],
        ]);

        $flat = $this->registry->getAllFlat();

        $this->assertIsArray($flat);
        $this->assertCount(3, $flat);

        // Check first code
        $this->assertEquals('{user.email}', $flat[0]['code']);
        $this->assertEquals('User', $flat[0]['category']);
        $this->assertEquals('Email Address', $flat[0]['label']);

        // Check second code
        $this->assertEquals('{user.name}', $flat[1]['code']);
        $this->assertEquals('User', $flat[1]['category']);
        $this->assertEquals('Display Name', $flat[1]['label']);

        // Check third code
        $this->assertEquals('{site.name}', $flat[2]['code']);
        $this->assertEquals('Site', $flat[2]['category']);
        $this->assertEquals('Site Name', $flat[2]['label']);
    }

    public function test_get_all_flat_returns_empty_array_when_no_codes(): void
    {
        $flat = $this->registry->getAllFlat();

        $this->assertIsArray($flat);
        $this->assertEmpty($flat);
    }

    public function test_get_all_flat_uses_fallback_labels(): void
    {
        $this->registerSmartCodes([
            'custom' => [
                // No label - should use 'custom' as fallback
                'codes' => [
                    'field' => [
                        // No label - should use 'field' as fallback
                        'callback' => fn() => '',
                    ],
                ],
            ],
        ]);

        $flat = $this->registry->getAllFlat();

        $this->assertCount(1, $flat);
        $this->assertEquals('{custom.field}', $flat[0]['code']);
        $this->assertEquals('custom', $flat[0]['category']);
        $this->assertEquals('field', $flat[0]['label']);
    }

    public function test_get_all_flat_handles_categories_without_codes(): void
    {
        $this->registerSmartCodes([
            'empty' => [
                'label' => 'Empty Category',
                // No 'codes' key
            ],
            'user' => [
                'label' => 'User',
                'codes' => [
                    'email' => [
                        'label' => 'Email',
                        'callback' => fn() => '',
                    ],
                ],
            ],
        ]);

        $flat = $this->registry->getAllFlat();

        $this->assertCount(1, $flat);
        $this->assertEquals('{user.email}', $flat[0]['code']);
    }

    public function test_refresh_clears_the_cache(): void
    {
        $callCount = 0;

        add_filter('ndmail_smartcodes', function ($codes) use (&$callCount) {
            $callCount++;
            return ['test' => ['label' => 'Test', 'codes' => []]];
        });

        // First call - should trigger filter
        $this->registry->getAll();
        $this->assertEquals(1, $callCount);

        // Second call - should use cache
        $this->registry->getAll();
        $this->assertEquals(1, $callCount);

        // Refresh - clears cache
        $this->registry->refresh();

        // Third call - should trigger filter again
        $this->registry->getAll();
        $this->assertEquals(2, $callCount);
    }

    public function test_refresh_allows_new_codes_to_be_loaded(): void
    {
        // Initial codes
        $this->registerSmartCodes([
            'user' => [
                'label' => 'User',
                'codes' => [],
            ],
        ]);

        $result1 = $this->registry->getAll();
        $this->assertArrayHasKey('user', $result1);
        $this->assertArrayNotHasKey('site', $result1);

        // Reset filters and add new codes
        global $_test_filters;
        $_test_filters = [];

        $this->registerSmartCodes([
            'user' => [
                'label' => 'User',
                'codes' => [],
            ],
            'site' => [
                'label' => 'Site',
                'codes' => [],
            ],
        ]);

        // Without refresh, should still have old codes
        $result2 = $this->registry->getAll();
        $this->assertArrayNotHasKey('site', $result2);

        // After refresh, should have new codes
        $this->registry->refresh();
        $result3 = $this->registry->getAll();
        $this->assertArrayHasKey('user', $result3);
        $this->assertArrayHasKey('site', $result3);
    }

    public function test_method_signatures_are_correct(): void
    {
        $reflection = new \ReflectionClass(SmartCodeRegistry::class);

        // getAll
        $method = $reflection->getMethod('getAll');
        $this->assertEquals('array', $method->getReturnType()->getName());
        $this->assertCount(0, $method->getParameters());

        // getCallback
        $method = $reflection->getMethod('getCallback');
        $this->assertTrue($method->getReturnType()->allowsNull());
        $this->assertCount(2, $method->getParameters());
        $this->assertEquals('string', $method->getParameters()[0]->getType()->getName());
        $this->assertEquals('string', $method->getParameters()[1]->getType()->getName());

        // getCategories
        $method = $reflection->getMethod('getCategories');
        $this->assertEquals('array', $method->getReturnType()->getName());
        $this->assertCount(0, $method->getParameters());

        // getCodesForCategory
        $method = $reflection->getMethod('getCodesForCategory');
        $this->assertEquals('array', $method->getReturnType()->getName());
        $this->assertCount(1, $method->getParameters());
        $this->assertEquals('string', $method->getParameters()[0]->getType()->getName());

        // getAllFlat
        $method = $reflection->getMethod('getAllFlat');
        $this->assertEquals('array', $method->getReturnType()->getName());
        $this->assertCount(0, $method->getParameters());

        // refresh
        $method = $reflection->getMethod('refresh');
        $this->assertEquals('void', $method->getReturnType()->getName());
        $this->assertCount(0, $method->getParameters());
    }

    public function test_property_codes_exists_and_is_nullable(): void
    {
        $reflection = new \ReflectionClass(SmartCodeRegistry::class);

        $property = $reflection->getProperty('codes');
        $this->assertTrue($property->isPrivate());

        $type = $property->getType();
        $this->assertNotNull($type);
        $this->assertTrue($type->allowsNull());
        $this->assertEquals('array', $type->getName());
    }

    /**
     * Helper to register SmartCodes via the filter.
     *
     * @param array $codes SmartCode definitions.
     */
    private function registerSmartCodes(array $codes): void
    {
        add_filter('ndmail_smartcodes', fn() => $codes);
    }
}
