<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Unit tests for NTDST_MetaboxGenerator.
 *
 * Covers the audit fixes:
 *  - isDataModel() no longer mutates the Data Manager (item 11)
 *  - sanitize_field() type-based dispatch
 *
 * HTML rendering paths echo to stdout and depend on WP admin context —
 * those are covered by integration tests.
 */
final class NtdstMetaboxGeneratorTest extends TestCase
{
    private \NTDST_MetaboxGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        // Singleton — reach in to reset registered_models so tests are isolated.
        $this->generator = \NTDST_MetaboxGenerator::instance();
        $ref = new ReflectionProperty($this->generator, 'registered_models');
        $ref->setAccessible(true);
        $ref->setValue($this->generator, []);
    }

    // ---------------------------------------------------------------------
    // isDataModel — must not mutate Data Manager (item 11)
    // ---------------------------------------------------------------------

    public function testIsDataModelFalseForUnregisteredName(): void
    {
        $this->assertFalse($this->callPrivate('isDataModel', ['totally_unregistered_xyz']));
    }

    public function testIsDataModelTrueForRegisteredWithFields(): void
    {
        $this->generator->register('my_model', [
            'fields' => ['name' => 'text'],
        ]);
        $this->assertTrue($this->callPrivate('isDataModel', ['my_model']));
    }

    public function testIsDataModelFalseForRegisteredWithoutFields(): void
    {
        $this->generator->register('label_only', ['fields' => []]);
        $this->assertFalse($this->callPrivate('isDataModel', ['label_only']));
    }

    // ---------------------------------------------------------------------
    // sanitize_field — type-based dispatch
    // ---------------------------------------------------------------------

    public function testSanitizeText(): void
    {
        $this->assertSame('hello', $this->callPrivate('sanitize_field', ['  hello  ', 'text']));
    }

    public function testSanitizeInteger(): void
    {
        $this->assertSame(42, $this->callPrivate('sanitize_field', ['42', 'integer']));
        // absint returns abs((int)$v) — negative numbers become their absolute value
        $this->assertSame(5, $this->callPrivate('sanitize_field', ['-5', 'integer']));
    }

    public function testSanitizeFloat(): void
    {
        $this->assertSame(3.14, $this->callPrivate('sanitize_field', ['3.14', 'float']));
    }

    public function testSanitizeBooleanTruthyAndFalsy(): void
    {
        $this->assertTrue($this->callPrivate('sanitize_field', ['1', 'boolean']));
        $this->assertTrue($this->callPrivate('sanitize_field', ['anything', 'boolean']));
        $this->assertFalse($this->callPrivate('sanitize_field', ['', 'boolean']));
        $this->assertFalse($this->callPrivate('sanitize_field', ['0', 'boolean']));
    }

    public function testSanitizeJsonValidArray(): void
    {
        $this->assertSame(
            ['a', 'b', 'c'],
            $this->callPrivate('sanitize_field', ['["a","b","c"]', 'json'])
        );
    }

    public function testSanitizeJsonInvalidReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->callPrivate('sanitize_field', ['not-json', 'json']));
    }

    public function testSanitizeJsonAlreadyArray(): void
    {
        $this->assertSame(['x' => 1], $this->callPrivate('sanitize_field', [['x' => 1], 'json']));
    }

    public function testSanitizeRelationFiltersInvalidIds(): void
    {
        // array_filter removes '0' and '' (falsy), then absint maps the rest.
        // Keys are preserved by array_filter — the output is sparse-keyed.
        $result = $this->callPrivate('sanitize_field', [['1', '2', '0', '3', ''], 'relation']);
        $this->assertSame([1, 2, 3], array_values($result));
    }

    public function testSanitizeRelationScalarToArray(): void
    {
        $this->assertSame([42], $this->callPrivate('sanitize_field', ['42', 'relation']));
    }

    public function testSanitizeGalleryFiltersInvalidIds(): void
    {
        $this->assertSame(
            [5, 6],
            $this->callPrivate('sanitize_field', [['5', '6', '0'], 'gallery'])
        );
    }

    public function testSanitizeRepeaterDropsRowsThatAreFullyEmptyStrings(): void
    {
        // Empty-row detection: a row is dropped only if EVERY sub-field
        // sanitizes to '' or null. An integer sub-field with '' input
        // becomes 0 (truthy under the check) and keeps the row alive.
        $config = [
            'sub_fields' => [
                'label' => 'text',
                'note' => 'text',
            ],
        ];
        $input = [
            ['label' => 'first', 'note' => 'a'],
            ['label' => '', 'note' => ''],   // all-empty → dropped
            ['label' => 'third', 'note' => 'b'],
        ];

        $result = $this->callPrivate('sanitize_field', [$input, 'repeater', $config]);

        $this->assertCount(2, $result);
        $this->assertSame('first', $result[0]['label']);
        $this->assertSame('third', $result[1]['label']);
    }

    public function testSanitizeRepeaterWithFloatSubField(): void
    {
        $config = [
            'sub_fields' => [
                'price' => 'float',
            ],
        ];
        $input = [['price' => '9.99']];

        $result = $this->callPrivate('sanitize_field', [$input, 'repeater', $config]);
        $this->assertSame(9.99, $result[0]['price']);
    }

    public function testSanitizeRepeaterNonArrayReturnsEmpty(): void
    {
        $this->assertSame([], $this->callPrivate('sanitize_field', ['not-array', 'repeater', []]));
    }

    public function testSanitizeUnknownTypeFallsBackToTextField(): void
    {
        $this->assertSame('plain', $this->callPrivate('sanitize_field', ['plain', 'unknown_type']));
    }

    // ---------------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------------

    private function callPrivate(string $method, array $args)
    {
        $ref = new ReflectionMethod($this->generator, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($this->generator, $args);
    }
}
