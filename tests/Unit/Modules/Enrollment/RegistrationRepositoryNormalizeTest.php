<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Modules\Enrollment;

use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Tests\TestCase;

final class RegistrationRepositoryNormalizeTest extends TestCase
{
    public function testWrapStageBuildsThreeKeyEnvelope(): void
    {
        $result = RegistrationRepository::wrapStage(['name' => 'Jan'], 42, '2026-05-24T12:00:00+00:00');

        $this->assertSame([
            'submitted_at' => '2026-05-24T12:00:00+00:00',
            'submitted_by' => 42,
            'data' => ['name' => 'Jan'],
        ], $result);
    }

    public function testWrapStageDefaultsSubmittedAtToNow(): void
    {
        $result = RegistrationRepository::wrapStage(['name' => 'Jan'], 42);

        $this->assertArrayHasKey('submitted_at', $result);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $result['submitted_at']);
        $this->assertSame(42, $result['submitted_by']);
    }

    public function testWrapStageAcceptsNullSubmittedBy(): void
    {
        $result = RegistrationRepository::wrapStage(['email' => 'a@b.c'], null, '2026-05-24T12:00:00+00:00');

        $this->assertNull($result['submitted_by']);
        $this->assertSame(['email' => 'a@b.c'], $result['data']);
    }

    public function testWrapStageEmptyDataIsAllowed(): void
    {
        $result = RegistrationRepository::wrapStage([], 42, '2026-05-24T12:00:00+00:00');

        $this->assertSame([], $result['data']);
    }
}
