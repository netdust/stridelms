<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Handlers;

use Stride\Admin\AdminRegistrationQueryService;
use Stride\Handlers\Support\BulkRunner;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Tests\TestCase;

/**
 * Unit: the select-all expansion MUST route the client filter through
 * applyScopePins before expanding (the 2026-07-14 blast-radius regression).
 *
 * A queue view arms {select_all:true, filter:{queue:'…'}} — filters.status is
 * deliberately empty. Expanding that raw filter skipped both the queue id-set
 * AND the default active scope, so the bulk action mutated the whole
 * edition-grained table while the confirm dialog counted the visible queue.
 * These tests pin the routing: the repo's idsForGridFilter receives the
 * SCOPED filter (whatever applyScopePins returned), and a scope error
 * (unknown queue) aborts the batch as a WP_Error before any row resolves.
 */
final class BulkRunnerSelectAllScopeTest extends TestCase
{
    /** A minimal concrete host for the trait's private methods. */
    private function runner(): object
    {
        return new class {
            use BulkRunner;

            public function expand(array $params): array|\WP_Error
            {
                return $this->runBulk($params, static fn(int $id, object $reg): bool => true);
            }
        };
    }

    public function test_select_all_expands_the_scoped_filter_not_the_raw_client_filter(): void
    {
        $clientFilter = ['queue' => 'pending'];
        $scopedFilter = ['queue' => 'pending', 'queue_ids' => [7, 8], 'edition_scope' => 'all'];

        $query = $this->createMock(AdminRegistrationQueryService::class);
        $query->expects($this->once())
            ->method('applyScopePins')
            ->with($clientFilter)
            ->willReturn($scopedFilter);
        ntdst_set(AdminRegistrationQueryService::class, $query);

        $repo = $this->createMock(RegistrationRepository::class);
        $repo->expects($this->once())
            ->method('idsForGridFilter')
            ->with($scopedFilter, $this->greaterThan(0))
            ->willReturn([7, 8]);
        $repo->method('find')->willReturnCallback(
            static fn(int $id): object => (object) ['id' => $id, 'status' => 'pending'],
        );
        ntdst_set(RegistrationRepository::class, $repo);

        $report = $this->runner()->expand(['select_all' => true, 'filter' => $clientFilter]);

        $this->assertIsArray($report);
        $this->assertSame(2, $report['total'], 'the expansion covers exactly the scoped id-set');
        $this->assertSame(['ok' => 2, 'error' => 0], $report['summary']);
    }

    public function test_a_scope_error_aborts_the_batch_before_any_row_resolves(): void
    {
        $query = $this->createMock(AdminRegistrationQueryService::class);
        $query->method('applyScopePins')
            ->willReturn(new \WP_Error('invalid_queue', 'Onbekende wachtrij.', ['status' => 400]));
        ntdst_set(AdminRegistrationQueryService::class, $query);

        $repo = $this->createMock(RegistrationRepository::class);
        $repo->expects($this->never())->method('idsForGridFilter');
        $repo->expects($this->never())->method('find');
        ntdst_set(RegistrationRepository::class, $repo);

        $result = $this->runner()->expand(['select_all' => true, 'filter' => ['queue' => 'stale-key']]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_queue', $result->get_error_code());
    }

    public function test_a_plain_ids_payload_never_touches_the_scope_resolver(): void
    {
        $query = $this->createMock(AdminRegistrationQueryService::class);
        $query->expects($this->never())->method('applyScopePins');
        ntdst_set(AdminRegistrationQueryService::class, $query);

        $repo = $this->createMock(RegistrationRepository::class);
        $repo->expects($this->never())->method('idsForGridFilter');
        $repo->method('find')->willReturnCallback(
            static fn(int $id): object => (object) ['id' => $id, 'status' => 'pending'],
        );
        ntdst_set(RegistrationRepository::class, $repo);

        $report = $this->runner()->expand(['ids' => [3]]);

        $this->assertIsArray($report);
        $this->assertSame(1, $report['total']);
    }
}
