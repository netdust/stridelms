<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Admin;

use Stride\Admin\AdminRegistrationQueryService;
use Stride\Admin\WorklistQueueResolver;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Invoicing\QuoteRepository;
use Stride\Tests\TestCase;

/**
 * Unit: applyScopePins — THE one place scope enters a grid filter set.
 *
 * Both the grid READ (getGridPage) and the bulk select-all expansion
 * (BulkRunner::resolveBulkIds) route client filter input through this method,
 * so the set a select-all mutates is exactly the set the grid showed. The
 * BulkRunner half is covered by BulkRunnerSelectAllScopeTest — here the
 * injection rules themselves are pinned:
 *
 *   - queue → queue_ids (the resolver's id-set) + edition_scope forced 'all';
 *   - unknown queue → WP_Error(400), never a silent no-filter;
 *   - default → active_edition_ids (the admin-active scope);
 *   - edition_scope=all / explicit edition_id → NO scope pin.
 */
final class AdminRegistrationQueryServiceScopeTest extends TestCase
{
    private AdminRegistrationQueryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AdminRegistrationQueryService(
            $this->createMock(RegistrationRepository::class),
            $this->createMock(QuoteRepository::class),
        );
    }

    private function stubResolver(array $queueIds = [], array $activeIds = []): void
    {
        $resolver = $this->createMock(WorklistQueueResolver::class);
        $resolver->method('idsForQueue')->willReturnCallback(
            static fn(string $queue): ?array => in_array($queue, WorklistQueueResolver::QUEUES, true) ? $queueIds : null,
        );
        $resolver->method('activeEditionIds')->willReturn($activeIds);
        ntdst_set(WorklistQueueResolver::class, $resolver);
    }

    public function test_queue_pins_the_resolver_id_set_and_skips_the_scope_pin(): void
    {
        $this->stubResolver(queueIds: [11, 12], activeIds: [1, 2, 3]);

        $out = $this->service->applyScopePins(['queue' => 'pending']);

        $this->assertIsArray($out);
        $this->assertSame([11, 12], $out['queue_ids']);
        $this->assertSame('all', $out['edition_scope'], 'the queue ids already encode the scope');
        $this->assertArrayNotHasKey('active_edition_ids', $out);
    }

    public function test_unknown_queue_is_a_hard_400_never_a_silent_no_filter(): void
    {
        $this->stubResolver();

        $out = $this->service->applyScopePins(['queue' => 'not-a-queue']);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_queue', $out->get_error_code());
        $this->assertSame(400, $out->get_error_data()['status'] ?? null);
    }

    public function test_default_scope_pins_the_admin_active_edition_set(): void
    {
        $this->stubResolver(activeIds: [5, 6]);

        $out = $this->service->applyScopePins([]);

        $this->assertIsArray($out);
        $this->assertSame([5, 6], $out['active_edition_ids']);
        $this->assertArrayNotHasKey('queue_ids', $out);
    }

    public function test_widened_scope_and_explicit_edition_skip_the_pin(): void
    {
        $this->stubResolver(activeIds: [5, 6]);

        $all = $this->service->applyScopePins(['edition_scope' => 'all']);
        $this->assertIsArray($all);
        $this->assertArrayNotHasKey('active_edition_ids', $all);

        // A picked edition is reachable regardless of its status.
        $picked = $this->service->applyScopePins(['edition_id' => 42]);
        $this->assertIsArray($picked);
        $this->assertArrayNotHasKey('active_edition_ids', $picked);
    }

    public function test_an_empty_queue_set_pins_empty_never_drops_the_pin(): void
    {
        // A queue that resolves to ZERO rows must pin queue_ids=[] (the repo
        // renders that as 1=0 — zero rows), never omit the key ("no filter").
        $this->stubResolver(queueIds: []);

        $out = $this->service->applyScopePins(['queue' => 'nocert']);

        $this->assertIsArray($out);
        $this->assertArrayHasKey('queue_ids', $out);
        $this->assertSame([], $out['queue_ids']);
    }
}
