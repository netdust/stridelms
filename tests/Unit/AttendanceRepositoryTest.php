<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Tests\TestCase;

/**
 * Unit tests for AttendanceRepository meta access
 *
 * Tests that AttendanceRepository correctly uses Data Manager to look up
 * session->edition_id after refactoring away from _vad_edition_id prefix.
 */
class AttendanceRepositoryTest extends TestCase
{
    /**
     * @test
     */
    public function testSessionEditionIdLookupUsesDataManager(): void
    {
        $session = $this->createSession(['ID' => 100]);
        $edition = $this->createEdition(['ID' => 200]);

        // Link session to edition via Data Manager
        $this->setDataManagerMeta('vad_session', 100, [
            'edition_id' => 200,
        ]);

        // Verify lookup works
        $model = ntdst_data()->get('vad_session');
        $editionId = (int) $model->getMeta(100, 'edition_id');

        $this->assertEquals(200, $editionId);
    }

    /**
     * @test
     */
    public function testSessionEditionIdReturnsZeroWhenNotSet(): void
    {
        $session = $this->createSession(['ID' => 101]);

        $model = ntdst_data()->get('vad_session');
        $editionId = $model->getMeta(101, 'edition_id');

        $this->assertNull($editionId);

        // When cast to int, should be 0
        $this->assertEquals(0, (int) $editionId);
    }

    /**
     * @test
     */
    public function testLegacyVadEditionIdNotUsed(): void
    {
        $session = $this->createSession(['ID' => 102]);

        // Simulate old data with _vad_edition_id prefix
        global $_test_post_meta;
        $_test_post_meta[102]['_vad_edition_id'] = [999];

        // Data Manager should NOT find this
        $model = ntdst_data()->get('vad_session');
        $editionId = $model->getMeta(102, 'edition_id');

        $this->assertNull($editionId);
    }

    /**
     * @test
     */
    public function testSessionMetaIncludesAllRequiredFields(): void
    {
        $session = $this->createSession(['ID' => 103]);

        $this->setDataManagerMeta('vad_session', 103, [
            'edition_id' => 300,
            'date' => '2024-03-15',
            'start_time' => '09:00',
            'end_time' => '12:00',
            'type' => 'in_person',
        ]);

        $model = ntdst_data()->get('vad_session');

        $this->assertEquals(300, $model->getMeta(103, 'edition_id'));
        $this->assertEquals('2024-03-15', $model->getMeta(103, 'date'));
        $this->assertEquals('09:00', $model->getMeta(103, 'start_time'));
        $this->assertEquals('12:00', $model->getMeta(103, 'end_time'));
        $this->assertEquals('in_person', $model->getMeta(103, 'type'));
    }

    /**
     * @test
     */
    public function testUpdateMetaBatchSetsSessionEditionId(): void
    {
        $session = $this->createSession(['ID' => 104]);

        $model = ntdst_data()->get('vad_session');
        $result = $model->updateMetaBatch(104, [
            'edition_id' => 500,
        ]);

        $this->assertTrue($result);
        $this->assertEquals(500, $this->getDataManagerMeta('vad_session', 104, 'edition_id'));
    }

    /**
     * @test
     */
    public function testMultipleSessionsCanLinkToSameEdition(): void
    {
        $edition = $this->createEdition(['ID' => 400]);
        $session1 = $this->createSession(['ID' => 401]);
        $session2 = $this->createSession(['ID' => 402]);
        $session3 = $this->createSession(['ID' => 403]);

        // Link all sessions to same edition
        $this->setDataManagerMeta('vad_session', 401, ['edition_id' => 400]);
        $this->setDataManagerMeta('vad_session', 402, ['edition_id' => 400]);
        $this->setDataManagerMeta('vad_session', 403, ['edition_id' => 400]);

        $model = ntdst_data()->get('vad_session');

        $this->assertEquals(400, $model->getMeta(401, 'edition_id'));
        $this->assertEquals(400, $model->getMeta(402, 'edition_id'));
        $this->assertEquals(400, $model->getMeta(403, 'edition_id'));
    }

    /**
     * @test
     */
    public function testSessionEditionIdCanBeUpdated(): void
    {
        $session = $this->createSession(['ID' => 500]);

        // Initially set to edition 100
        $this->setDataManagerMeta('vad_session', 500, ['edition_id' => 100]);

        $model = ntdst_data()->get('vad_session');
        $this->assertEquals(100, $model->getMeta(500, 'edition_id'));

        // Update to edition 200
        $model->updateMetaBatch(500, ['edition_id' => 200]);

        $this->assertEquals(200, $model->getMeta(500, 'edition_id'));
    }
}
