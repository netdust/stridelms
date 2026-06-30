<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;

/**
 * The shared trajectory-card args-builder produces a normalized contract
 * from a trajectory id (+ optional per-user progress), so the public catalog
 * and the dashboard feed the one card identically.
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter TrajectoryCardArgs
 */
final class TrajectoryCardArgsTest extends IntegrationTestCase
{
    private int $courseA;
    private int $courseB;
    private int $electiveA;
    private int $electiveB;
    private int $trajectoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->courseA   = $this->makeCourse('Card Req A');
        $this->courseB   = $this->makeCourse('Card Req B');
        $this->electiveA = $this->makeCourse('Card Elect A');
        $this->electiveB = $this->makeCourse('Card Elect B');

        $this->trajectoryId = wp_insert_post([
            'post_type' => 'vad_trajectory', 'post_status' => 'publish',
            'post_title' => 'Card Args Trajectory ' . uniqid(),
        ]);
        self::$testPosts[] = $this->trajectoryId;
        update_post_meta($this->trajectoryId, '_ntdst_status', 'open');
        update_post_meta($this->trajectoryId, '_ntdst_price', '450');
        update_post_meta($this->trajectoryId, '_ntdst_enrollment_deadline', '2026-09-01');
        update_post_meta($this->trajectoryId, '_ntdst_courses', wp_json_encode([
            ['course_id' => $this->courseA, 'required' => true, 'order' => 1],
            ['course_id' => $this->courseB, 'required' => true, 'order' => 2],
            ['course_id' => $this->electiveA, 'required' => false, 'group' => 'Keuze', 'min_choices' => 1],
            ['course_id' => $this->electiveB, 'required' => false, 'group' => 'Keuze', 'min_choices' => 1],
        ]));
    }

    private function makeCourse(string $title): int
    {
        $id = wp_insert_post([
            'post_type' => 'sfwd-courses', 'post_status' => 'publish',
            'post_title' => $title . ' ' . uniqid(),
        ]);
        self::$testPosts[] = $id;

        return $id;
    }

    public function testCatalogArgsHaveCountsPriceDeadlineAndNoProgress(): void
    {
        $args = stridence_build_trajectory_card_args($this->trajectoryId);

        $this->assertSame($this->trajectoryId, $args['id']);
        $this->assertStringContainsString('Card Args Trajectory', $args['title']);
        $this->assertSame('open', $args['status']);
        $this->assertSame(4, $args['course_count'], 'all four courses count');
        $this->assertSame(1, $args['elective_count'], 'one elective group');
        // Price is canonical CENTS as an int — trajectory-card.php casts to int,
        // matching the editions convention; the fixture stores _ntdst_price=450.
        $this->assertSame(450, $args['price']);
        $this->assertSame('2026-09-01', $args['deadline']);
        $this->assertNull($args['progress'] ?? null, 'no progress arg on the catalog path');
    }

    public function testElectiveGroupCountServiceMethod(): void
    {
        $service = ntdst_get(\Stride\Modules\Trajectory\TrajectoryService::class);
        $this->assertSame(1, $service->getElectiveGroupCount($this->trajectoryId));
        $this->assertSame(4, $service->getCourseCount($this->trajectoryId));
    }

    public function testEnrolledArgsPassThroughProgressAndStartedAt(): void
    {
        $args = stridence_build_trajectory_card_args($this->trajectoryId, [
            'progress'      => 50,
            'started_at'    => '2026-02-10',
            'dashboard_url' => '/mijn-account/trajecten/x/',
        ]);

        $this->assertSame(50, $args['progress']);
        $this->assertSame('2026-02-10', $args['started_at']);
        $this->assertSame('/mijn-account/trajecten/x/', $args['dashboard_url']);
        // Progress is clamped to 0-100.
        $clamped = stridence_build_trajectory_card_args($this->trajectoryId, ['progress' => 150]);
        $this->assertSame(100, $clamped['progress']);
    }
}
