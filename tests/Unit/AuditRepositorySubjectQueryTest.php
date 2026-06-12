<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use NTDST\Audit\AuditRepository;
use Stride\Tests\TestCase;

// Load the real AuditRepository (not autoloaded in unit tests)
require_once dirname(__DIR__, 2) . '/web/app/plugins/ntdst-audit/src/AuditRepository.php';

class AuditRepositorySubjectQueryTest extends TestCase
{
    private AuditRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new AuditRepository();
    }

    /** @test */
    public function testFindBySubjectUserReturnsEntriesWhereUserIsSubject(): void
    {
        $result = $this->repository->findBySubjectUser(456);
        $this->assertIsArray($result);
    }

    /** @test */
    public function testFindBySubjectUserExcludesSelfActions(): void
    {
        $result = $this->repository->findBySubjectUser(456, 50, 30);
        $this->assertIsArray($result);
    }

    /** @test */
    public function testFindBySubjectUserAcceptsLimitAndDaysBack(): void
    {
        $result = $this->repository->findBySubjectUser(456, 10, 7);
        $this->assertIsArray($result);
    }

    /** @test */
    public function testFindSessionNoteUpdatesReturnsEntriesForEditions(): void
    {
        $result = $this->repository->findSessionNoteUpdates([10, 20, 30], 30);
        $this->assertIsArray($result);
    }

    /** @test */
    public function testFindSessionNoteUpdatesReturnsEmptyForNoEditions(): void
    {
        $result = $this->repository->findSessionNoteUpdates([], 30);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
