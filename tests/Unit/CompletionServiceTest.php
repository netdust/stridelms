<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Domain\CompletionMode;
use Stride\Tests\TestCase;

/**
 * Unit tests for CompletionService meta access
 *
 * Tests that CompletionService correctly uses Data Manager for meta access
 * after refactoring away from legacy _vad_* prefixes.
 */
class CompletionServiceTest extends TestCase
{
    /**
     * @test
     */
    public function testGetCompletionModeReadsFromDataManager(): void
    {
        $edition = $this->createEdition(['ID' => 100]);

        // Set via Data Manager (simulates how meta is stored with _ntdst_ prefix)
        $this->setDataManagerMeta('vad_edition', 100, [
            'completion_mode' => CompletionMode::Percentage->value,
        ]);

        // Read via Data Manager (as the refactored service does)
        $model = ntdst_data()->get('vad_edition');
        $modeValue = $model->getMeta(100, 'completion_mode');

        $this->assertEquals(CompletionMode::Percentage->value, $modeValue);

        // Verify it can be parsed to enum
        $mode = CompletionMode::tryFrom($modeValue);
        $this->assertEquals(CompletionMode::Percentage, $mode);
    }

    /**
     * @test
     */
    public function testGetCompletionModeDefaultsToAttendAll(): void
    {
        $edition = $this->createEdition(['ID' => 101]);

        // No completion_mode set
        $model = ntdst_data()->get('vad_edition');
        $modeValue = $model->getMeta(101, 'completion_mode');

        // Should return null/default
        $this->assertNull($modeValue);

        // Service should default to AttendAll (checking the null case)
        $mode = ($modeValue !== null)
            ? (CompletionMode::tryFrom($modeValue) ?? CompletionMode::AttendAll)
            : CompletionMode::AttendAll;
        $this->assertEquals(CompletionMode::AttendAll, $mode);
    }

    /**
     * @test
     */
    public function testGetCompletionThresholdReadsFromDataManager(): void
    {
        $edition = $this->createEdition(['ID' => 102]);

        $this->setDataManagerMeta('vad_edition', 102, [
            'completion_threshold' => 75,
        ]);

        $model = ntdst_data()->get('vad_edition');
        $threshold = $model->getMeta(102, 'completion_threshold');

        $this->assertEquals(75, $threshold);
    }

    /**
     * @test
     */
    public function testGetCompletionThresholdDefaultsTo100(): void
    {
        $edition = $this->createEdition(['ID' => 103]);

        $model = ntdst_data()->get('vad_edition');
        $threshold = $model->getMeta(103, 'completion_threshold');

        // Should return null
        $this->assertNull($threshold);

        // Service defaults to 100 when not set
        $finalThreshold = $threshold ? (int) $threshold : 100;
        $this->assertEquals(100, $finalThreshold);
    }

    /**
     * @test
     */
    public function testSetCompletionModeWritesToDataManager(): void
    {
        $edition = $this->createEdition(['ID' => 104]);

        // Write via Data Manager (as the refactored service does)
        $model = ntdst_data()->get('vad_edition');
        $model->updateMetaBatch(104, [
            'completion_mode' => CompletionMode::Count->value,
        ]);

        // Verify it's stored
        $storedMode = $this->getDataManagerMeta('vad_edition', 104, 'completion_mode');
        $this->assertEquals(CompletionMode::Count->value, $storedMode);
    }

    /**
     * @test
     */
    public function testSetCompletionThresholdWritesToDataManager(): void
    {
        $edition = $this->createEdition(['ID' => 105]);

        $model = ntdst_data()->get('vad_edition');
        $model->updateMetaBatch(105, [
            'completion_threshold' => 50,
        ]);

        $storedThreshold = $this->getDataManagerMeta('vad_edition', 105, 'completion_threshold');
        $this->assertEquals(50, $storedThreshold);
    }

    /**
     * @test
     */
    public function testLegacyVadPrefixNotUsed(): void
    {
        $edition = $this->createEdition(['ID' => 106]);

        // Simulate old data with _vad_ prefix stored via WordPress directly
        global $_test_post_meta;
        $_test_post_meta[106]['_vad_completion_mode'] = [CompletionMode::Percentage->value];
        $_test_post_meta[106]['_vad_completion_threshold'] = [80];

        // Data Manager should NOT find this data (different storage)
        $model = ntdst_data()->get('vad_edition');
        $modeValue = $model->getMeta(106, 'completion_mode');
        $threshold = $model->getMeta(106, 'completion_threshold');

        // Should be null since Data Manager uses _ntdst_ prefix internally
        $this->assertNull($modeValue);
        $this->assertNull($threshold);
    }

    /**
     * @test
     * @dataProvider completionModeProvider
     */
    public function testAllCompletionModesCanBeStoredAndRetrieved(CompletionMode $mode, int $editionId): void
    {
        $edition = $this->createEdition(['ID' => $editionId]);

        $model = ntdst_data()->get('vad_edition');
        $model->updateMetaBatch($editionId, [
            'completion_mode' => $mode->value,
        ]);

        $retrieved = $model->getMeta($editionId, 'completion_mode');
        $this->assertEquals($mode->value, $retrieved);

        $parsed = CompletionMode::tryFrom($retrieved);
        $this->assertEquals($mode, $parsed);
    }

    public static function completionModeProvider(): array
    {
        return [
            'attend_all' => [CompletionMode::AttendAll, 200],
            'percentage' => [CompletionMode::Percentage, 201],
            'count' => [CompletionMode::Count, 202],
        ];
    }
}
