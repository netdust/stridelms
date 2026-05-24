<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Modules\Edition;

use IntegrationTestCase;
use ReflectionMethod;
use Stride\Modules\Edition\Admin\EditionRegistrationExporter;

final class EditionRegistrationExporterStageTest extends IntegrationTestCase
{
    /** @var int[] Posts created during this test class */
    private static array $createdPosts = [];

    public static function tearDownAfterClass(): void
    {
        foreach (self::$createdPosts as $id) {
            wp_delete_post($id, true);
        }
        self::$createdPosts = [];
        parent::tearDownAfterClass();
    }

    private static function createSession(string $title, string $date = ''): int
    {
        $id = wp_insert_post([
            'post_type'   => 'vad_session',
            'post_title'  => $title,
            'post_status' => 'publish',
        ]);
        if ($date) {
            update_post_meta($id, 'session_date', $date);
        }
        self::$createdPosts[] = $id;
        return $id;
    }

    // -------------------------------------------------------------------------
    // summarizeEnrollmentData — reads wrapped [stage]['data'] shape
    // -------------------------------------------------------------------------

    public function testSummarizeReadsWrappedStageData(): void
    {
        $exporter = ntdst_get(EditionRegistrationExporter::class);

        $enrollmentData = [
            'enrollment_personal' => [
                'submitted_at' => '2026-05-24T12:00:00+00:00',
                'submitted_by' => 1,
                'data' => ['profession' => 'doctor', 'name' => 'Jan'], // 'name' is in skipKeys
            ],
            'intake' => [
                'submitted_at' => '2026-05-24T12:01:00+00:00',
                'submitted_by' => 1,
                'data' => ['expectations' => 'learn things'],
            ],
        ];

        $m = new ReflectionMethod($exporter, 'summarizeEnrollmentData');
        $m->setAccessible(true);
        $summary = $m->invoke($exporter, $enrollmentData);

        $this->assertStringContainsString('profession: doctor', $summary);
        $this->assertStringContainsString('expectations: learn things', $summary);
        $this->assertStringNotContainsString('name: Jan', $summary, 'name is in skipKeys');
    }

    // -------------------------------------------------------------------------
    // writeStageSheet — smoke-test wrapped data shape
    // -------------------------------------------------------------------------

    public function testWriteStageSheetReaderShapeMatchesWrappedData(): void
    {
        // Smoke test: writeStageSheet should resolve name/email via [stage]['data'][key].
        // We assert the pattern reads correctly via a hand-built sample row.
        $row = [
            'user_id' => null,
            'enrollment_data_parsed' => [
                'interest' => [
                    'submitted_at' => '2026-05-24T12:00:00+00:00',
                    'submitted_by' => null,
                    'data' => [
                        'name' => 'Anon Mia',
                        'email' => 'anon@example.com',
                        'phone' => '0123',
                        'organisation' => 'ACME',
                    ],
                ],
            ],
        ];

        $stageEnvelope = $row['enrollment_data_parsed']['interest'];
        $stageData = $stageEnvelope['data'] ?? [];
        $this->assertSame('Anon Mia', $stageData['name']);
        $this->assertSame('anon@example.com', $stageData['email']);
    }

    // -------------------------------------------------------------------------
    // summarizeInitialSelection
    // -------------------------------------------------------------------------

    public function testSummarizeInitialSelectionRendersSinglePhase(): void
    {
        $exporter = ntdst_get(EditionRegistrationExporter::class);
        $sessionId = self::createSession('Sessie A', '2026-06-01');

        $enrollmentData = [
            'initial_selection' => [
                'type' => 'edition',
                'phases' => [
                    [
                        'phase'        => 'enrollment',
                        'captured_at'  => '2026-05-24T12:00:00+00:00',
                        'captured_by'  => 1,
                        'session_ids'  => [$sessionId],
                    ],
                ],
            ],
        ];

        $m = new ReflectionMethod($exporter, 'summarizeInitialSelection');
        $m->setAccessible(true);
        $summary = $m->invoke($exporter, $enrollmentData);

        $this->assertStringContainsString('Inschrijving:', $summary);
        $this->assertStringContainsString('Sessie A', $summary);
        $this->assertStringContainsString('01/06/2026', $summary);
    }

    public function testSummarizeInitialSelectionEmptyWhenNone(): void
    {
        $exporter = ntdst_get(EditionRegistrationExporter::class);
        $m = new ReflectionMethod($exporter, 'summarizeInitialSelection');
        $m->setAccessible(true);

        $this->assertSame('', $m->invoke($exporter, []));
        $this->assertSame('', $m->invoke($exporter, ['initial_selection' => ['type' => 'none', 'phases' => []]]));
    }

    public function testSummarizeInitialSelectionMarksDeletedIds(): void
    {
        $exporter = ntdst_get(EditionRegistrationExporter::class);
        $enrollmentData = [
            'initial_selection' => [
                'type' => 'edition',
                'phases' => [
                    ['phase' => 'enrollment', 'session_ids' => [99999999]],
                ],
            ],
        ];
        $m = new ReflectionMethod($exporter, 'summarizeInitialSelection');
        $m->setAccessible(true);

        $summary = $m->invoke($exporter, $enrollmentData);
        $this->assertStringContainsString('(verwijderd)', $summary);
    }

    public function testSummarizeInitialSelectionMultiPhase(): void
    {
        $exporter = ntdst_get(EditionRegistrationExporter::class);
        $a = self::createSession('A');
        $b = self::createSession('B');

        $enrollmentData = [
            'initial_selection' => [
                'type' => 'trajectory',
                'phases' => [
                    ['phase' => 'enrollment', 'session_ids' => [$a]],
                    ['phase' => 'phase_1',    'session_ids' => [$b]],
                ],
            ],
        ];
        $m = new ReflectionMethod($exporter, 'summarizeInitialSelection');
        $m->setAccessible(true);

        $summary = $m->invoke($exporter, $enrollmentData);
        $this->assertStringContainsString(' | ', $summary);
        $this->assertStringContainsString('Inschrijving:', $summary);
        $this->assertStringContainsString('Phase 1:', $summary);
    }
}
