<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Modules\Trajectory;

use IntegrationTestCase;
use Stride\Modules\Trajectory\TrajectoryRepository;

/**
 * Integration test: the `trajectory_messages` field is REGISTERED on the
 * vad_trajectory schema, so the existing admin metabox writes persist and
 * TrajectoryRepository::getMessages() actually returns them.
 *
 * Regression guard for the silent dead-end found 2026-06-12: the metabox +
 * berichten tab existed, but the field was missing from
 * TrajectoryCPT::getFields(), so every read returned the default.
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter TrajectoryMessagesFieldTest
 */
final class TrajectoryMessagesFieldTest extends IntegrationTestCase
{
    private TrajectoryRepository $repo;
    private int $trajectoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = ntdst_get(TrajectoryRepository::class);

        $this->trajectoryId = wp_insert_post([
            'post_type'   => 'vad_trajectory',
            'post_title'  => 'Messages Field Test Trajectory',
            'post_status' => 'publish',
        ]);
        self::$testPosts[] = $this->trajectoryId;
    }

    public function testUnsetFieldReturnsEmptyDefault(): void
    {
        $this->assertSame([], $this->repo->getMessages($this->trajectoryId));
        $this->assertSame([], $this->repo->getField($this->trajectoryId, 'trajectory_messages', []));
    }

    public function testMessagesRoundTripThroughRegisteredField(): void
    {
        $messages = [
            [
                'type'    => 'announcement',
                'content' => 'Welkom bij het traject!',
                'author'  => 'Beheerder',
                'date'    => '2026-06-01',
            ],
            [
                'type'    => 'faq',
                'content' => 'Nieuwere mededeling',
                'author'  => 'Beheerder',
                'date'    => '2026-06-10',
            ],
        ];

        $result = $this->repo->update($this->trajectoryId, ['trajectory_messages' => $messages]);
        $this->assertNotFalse($result, 'repository update must accept the registered field');

        $read = $this->repo->getMessages($this->trajectoryId);
        $this->assertCount(2, $read, 'both messages must round-trip through the schema');

        // getMessages sorts newest-first.
        $this->assertSame('Nieuwere mededeling', $read[0]['content']);
        $this->assertSame('Welkom bij het traject!', $read[1]['content']);
    }

    public function testDeletedMessagesAreFiltered(): void
    {
        $this->repo->update($this->trajectoryId, ['trajectory_messages' => [
            ['type' => 'announcement', 'content' => 'Zichtbaar', 'author' => 'A', 'date' => '2026-06-01'],
            ['type' => 'announcement', 'content' => 'Verwijderd', 'author' => 'A', 'date' => '2026-06-02', '_deleted' => true],
        ]]);

        $read = $this->repo->getMessages($this->trajectoryId);
        $this->assertCount(1, $read, '_deleted messages must be filtered out');
        $this->assertSame('Zichtbaar', $read[0]['content']);
    }
}
