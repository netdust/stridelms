<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Admin;

use Mockery;
use Stride\Admin\WorklistQueueResolver;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Tests\TestCase;

/**
 * Unit: the shared worklist-queue definition (RC-2 anti-drift).
 *
 * The resolver is THE single queue definition: the Vandaag counts are count()
 * of its id-sets and the grid's ?queue= filter pins to the same sets. These
 * tests cover the key surface + the cross-language contract (the client files
 * must speak exactly the same queue vocabulary — the drift class where a card
 * key exists that the grid cannot consume, or vice versa).
 */
final class WorklistQueueResolverTest extends TestCase
{
    private function resolver(): WorklistQueueResolver
    {
        // The empty-corpus paths never touch the collaborators.
        return new WorklistQueueResolver(
            Mockery::mock(RegistrationRepository::class),
            Mockery::mock(EditionService::class),
            Mockery::mock(EditionRepository::class),
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_unknown_queue_key_resolves_to_null_never_an_empty_filter(): void
    {
        $resolver = $this->resolver();

        // null = "unknown queue" (endpoint 400s); [] would mean "empty queue"
        // and silently render an empty grid for a typo'd deep-link.
        $this->assertNull($resolver->idsForQueue('not-a-queue', []));
        $this->assertNull($resolver->idsForQueue('', []));
        $this->assertNull($resolver->idsForQueue('waitlist_open', []), 'legacy stats payload keys are NOT queue keys');
    }

    public function test_empty_edition_scope_yields_empty_sets_for_every_queue(): void
    {
        $sets = $this->resolver()->idsByQueue([]);

        $this->assertSame(WorklistQueueResolver::QUEUES, array_keys($sets));
        foreach (WorklistQueueResolver::QUEUES as $queue) {
            $this->assertSame([], $sets[$queue]);
        }
    }

    public function test_known_queue_on_empty_scope_returns_empty_list_not_null(): void
    {
        foreach (WorklistQueueResolver::QUEUES as $queue) {
            $this->assertSame([], $this->resolver()->idsForQueue($queue, []));
        }
    }

    /**
     * Cross-language contract: the resolver's queue vocabulary must appear
     * verbatim in BOTH client files — grid.js (QUEUE_META: chip labels + the
     * ?queue= passthrough allowlist) and vandaag.js (QUEUE_DEFS: the cards
     * that emit the deep-links). A queue added server-side without the client
     * halves (or renamed on one side) fails HERE, not weeks later as a dead
     * card or a 400 on click-through.
     */
    public function test_queue_keys_exist_in_both_client_files(): void
    {
        $jsDir = dirname(__DIR__, 3) . '/web/app/mu-plugins/stride-core/assets/js/admin/';
        $gridJs = (string) file_get_contents($jsDir . 'grid.js');
        $vandaagJs = (string) file_get_contents($jsDir . 'vandaag.js');

        foreach (WorklistQueueResolver::QUEUES as $queue) {
            $this->assertMatchesRegularExpression(
                '/\b' . preg_quote($queue, '/') . '\b\s*:/',
                $gridJs,
                "grid.js QUEUE_META is missing queue key '{$queue}'",
            );
            $this->assertStringContainsString(
                "key: '{$queue}'",
                $vandaagJs,
                "vandaag.js QUEUE_DEFS is missing queue key '{$queue}'",
            );
        }
    }
}
