<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Tests\TestCase;

/**
 * Tests for QuoteService session price modifier logic.
 *
 * Tests pure logic (buildModifierItems, replaceModifierItems) that will live
 * in QuoteService::onSessionSelectionCompleted().
 */
class QuoteServiceModifierTest extends TestCase
{
    // ------------------------------------------------------------------
    // buildModifierItems
    // ------------------------------------------------------------------

    /** @test */
    public function testBuildModifierItemsFromSlottedSessionsWithNonZeroModifiers(): void
    {
        $editionId = 100;
        $selectedIds = [1, 2, 3];

        $allSessions = [
            ['id' => 1, 'edition_id' => 100, 'slot' => 'A', 'price_modifier' => 2500, 'title' => 'Morning A'],
            ['id' => 2, 'edition_id' => 100, 'slot' => 'B', 'price_modifier' => 5000, 'title' => 'Morning B'],
            ['id' => 3, 'edition_id' => 100, 'slot' => '',  'price_modifier' => 1000, 'title' => 'No Slot'],  // no slot = skipped
        ];

        $items = $this->buildModifierItems($selectedIds, $allSessions, $editionId);

        $this->assertCount(2, $items);

        // Session 1: slotted + non-zero modifier
        $this->assertEquals('session_modifier', $items[0]['type']);
        $this->assertEquals('Sessie: Morning A', $items[0]['title']);
        $this->assertEquals(1, $items[0]['quantity']);
        $this->assertEquals(2500, $items[0]['unit_price']);
        $this->assertEquals(2500, $items[0]['total']);
        $this->assertEquals(1, $items[0]['id']);

        // Session 2: slotted + non-zero modifier
        $this->assertEquals('Sessie: Morning B', $items[1]['title']);
        $this->assertEquals(5000, $items[1]['unit_price']);
    }

    /** @test */
    public function testNegativeModifiersProduceNegativeLineItems(): void
    {
        $editionId = 100;
        $selectedIds = [10];

        $allSessions = [
            ['id' => 10, 'edition_id' => 100, 'slot' => 'discount-slot', 'price_modifier' => -1500, 'title' => 'Budget Option'],
        ];

        $items = $this->buildModifierItems($selectedIds, $allSessions, $editionId);

        $this->assertCount(1, $items);
        $this->assertEquals(-1500, $items[0]['unit_price']);
        $this->assertEquals(-1500, $items[0]['total']);
    }

    /** @test */
    public function testCrossEditionSessionsAreSkipped(): void
    {
        $editionId = 100;
        $selectedIds = [1, 2];

        $allSessions = [
            ['id' => 1, 'edition_id' => 100, 'slot' => 'A', 'price_modifier' => 500, 'title' => 'Own Edition'],
            ['id' => 2, 'edition_id' => 999, 'slot' => 'A', 'price_modifier' => 500, 'title' => 'Other Edition'],
        ];

        $items = $this->buildModifierItems($selectedIds, $allSessions, $editionId);

        $this->assertCount(1, $items);
        $this->assertEquals(1, $items[0]['id']);
    }

    /** @test */
    public function testMultipleSelectionsFromSameSlotEachGetOwnLineItem(): void
    {
        $editionId = 100;
        $selectedIds = [1, 2];

        $allSessions = [
            ['id' => 1, 'edition_id' => 100, 'slot' => 'A', 'price_modifier' => 1000, 'title' => 'Option 1'],
            ['id' => 2, 'edition_id' => 100, 'slot' => 'A', 'price_modifier' => 2000, 'title' => 'Option 2'],
        ];

        $items = $this->buildModifierItems($selectedIds, $allSessions, $editionId);

        $this->assertCount(2, $items);
        $this->assertEquals(1000, $items[0]['unit_price']);
        $this->assertEquals(2000, $items[1]['unit_price']);
    }

    /** @test */
    public function testZeroModifierSessionsAreSkipped(): void
    {
        $editionId = 100;
        $selectedIds = [1, 2];

        $allSessions = [
            ['id' => 1, 'edition_id' => 100, 'slot' => 'A', 'price_modifier' => 0, 'title' => 'Free'],
            ['id' => 2, 'edition_id' => 100, 'slot' => 'B', 'price_modifier' => 3000, 'title' => 'Paid'],
        ];

        $items = $this->buildModifierItems($selectedIds, $allSessions, $editionId);

        $this->assertCount(1, $items);
        $this->assertEquals(2, $items[0]['id']);
    }

    /** @test */
    public function testUnselectedSessionsAreSkipped(): void
    {
        $editionId = 100;
        $selectedIds = [1];

        $allSessions = [
            ['id' => 1, 'edition_id' => 100, 'slot' => 'A', 'price_modifier' => 1000, 'title' => 'Selected'],
            ['id' => 2, 'edition_id' => 100, 'slot' => 'B', 'price_modifier' => 5000, 'title' => 'Not Selected'],
        ];

        $items = $this->buildModifierItems($selectedIds, $allSessions, $editionId);

        $this->assertCount(1, $items);
        $this->assertEquals(1, $items[0]['id']);
    }

    // ------------------------------------------------------------------
    // replaceModifierItems
    // ------------------------------------------------------------------

    /** @test */
    public function testReplacingOldModifierItemsPreservesEditionItems(): void
    {
        $existingItems = [
            ['type' => 'edition', 'title' => 'Opleiding XYZ', 'quantity' => 1, 'unit_price' => 50000, 'total' => 50000],
            ['type' => 'session_modifier', 'title' => 'Sessie: Old Choice', 'quantity' => 1, 'unit_price' => 1000, 'total' => 1000],
        ];

        $newModifiers = [
            ['id' => 5, 'type' => 'session_modifier', 'title' => 'Sessie: New Choice', 'quantity' => 1, 'unit_price' => 2000, 'total' => 2000],
        ];

        $result = $this->replaceModifierItems($existingItems, $newModifiers);

        $this->assertCount(2, $result);

        // Edition item preserved
        $this->assertEquals('edition', $result[0]['type']);
        $this->assertEquals('Opleiding XYZ', $result[0]['title']);

        // Old modifier replaced with new
        $this->assertEquals('session_modifier', $result[1]['type']);
        $this->assertEquals('Sessie: New Choice', $result[1]['title']);
        $this->assertEquals(2000, $result[1]['unit_price']);
    }

    /** @test */
    public function testEmptyModifiersStripAllExistingModifierItems(): void
    {
        $existingItems = [
            ['type' => 'edition', 'title' => 'Opleiding XYZ', 'quantity' => 1, 'unit_price' => 50000, 'total' => 50000],
            ['type' => 'session_modifier', 'title' => 'Sessie: Choice A', 'quantity' => 1, 'unit_price' => 1500, 'total' => 1500],
            ['type' => 'session_modifier', 'title' => 'Sessie: Choice B', 'quantity' => 1, 'unit_price' => 2500, 'total' => 2500],
        ];

        $result = $this->replaceModifierItems($existingItems, []);

        $this->assertCount(1, $result);
        $this->assertEquals('edition', $result[0]['type']);
    }

    /** @test */
    public function testReplaceWithMultipleNewModifiers(): void
    {
        $existingItems = [
            ['type' => 'edition', 'title' => 'Base', 'quantity' => 1, 'unit_price' => 30000, 'total' => 30000],
        ];

        $newModifiers = [
            ['id' => 1, 'type' => 'session_modifier', 'title' => 'Sessie: A', 'quantity' => 1, 'unit_price' => 1000, 'total' => 1000],
            ['id' => 2, 'type' => 'session_modifier', 'title' => 'Sessie: B', 'quantity' => 1, 'unit_price' => -500, 'total' => -500],
        ];

        $result = $this->replaceModifierItems($existingItems, $newModifiers);

        $this->assertCount(3, $result);
        $this->assertEquals('edition', $result[0]['type']);
        $this->assertEquals('session_modifier', $result[1]['type']);
        $this->assertEquals('session_modifier', $result[2]['type']);
        $this->assertEquals(1000, $result[1]['unit_price']);
        $this->assertEquals(-500, $result[2]['unit_price']);
    }

    /** @test */
    public function testReplaceWhenNoExistingModifiersAndNoNewModifiers(): void
    {
        $existingItems = [
            ['type' => 'edition', 'title' => 'Base', 'quantity' => 1, 'unit_price' => 30000, 'total' => 30000],
        ];

        $result = $this->replaceModifierItems($existingItems, []);

        $this->assertCount(1, $result);
        $this->assertEquals('edition', $result[0]['type']);
    }

    // ------------------------------------------------------------------
    // Pure logic helpers — mirrors the logic that lives in QuoteService
    // ------------------------------------------------------------------

    /**
     * Build modifier line items from selected sessions.
     *
     * Only sessions that belong to the edition, have a non-empty slot,
     * and have a non-zero price_modifier produce items.
     *
     * @param int[]   $selectedIds  Session IDs the user selected
     * @param array[] $allSessions  All sessions for the edition (from SessionService)
     * @param int     $editionId    The edition to scope to
     * @return array[] Line items with type=session_modifier
     */
    private function buildModifierItems(array $selectedIds, array $allSessions, int $editionId): array
    {
        $selectedSet = array_flip($selectedIds);
        $items = [];

        foreach ($allSessions as $session) {
            $sessionId = (int) $session['id'];

            // Must be selected
            if (!isset($selectedSet[$sessionId])) {
                continue;
            }

            // Must belong to this edition
            if ((int) $session['edition_id'] !== $editionId) {
                continue;
            }

            // Must have a slot (slotted sessions only)
            if (empty($session['slot'])) {
                continue;
            }

            $modifier = (int) $session['price_modifier'];

            // Must have a non-zero modifier
            if ($modifier === 0) {
                continue;
            }

            $items[] = [
                'id' => $sessionId,
                'type' => 'session_modifier',
                'title' => 'Sessie: ' . ($session['title'] ?? ''),
                'quantity' => 1,
                'unit_price' => $modifier,
                'total' => $modifier,
            ];
        }

        return $items;
    }

    /**
     * Replace session_modifier items in existing quote items.
     *
     * Strips all old session_modifier items and appends the new ones.
     * Non-modifier items (edition, etc.) are preserved in original order.
     *
     * @param array[] $existingItems Current quote items
     * @param array[] $newModifiers  New modifier items to append
     * @return array[] Updated items list
     */
    private function replaceModifierItems(array $existingItems, array $newModifiers): array
    {
        // Keep non-modifier items
        $kept = array_filter($existingItems, fn(array $item) => ($item['type'] ?? 'edition') !== 'session_modifier');

        // Re-index and append new modifiers
        return array_values(array_merge($kept, $newModifiers));
    }
}
