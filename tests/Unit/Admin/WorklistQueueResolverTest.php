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
     * REGRESSION (2026-07-14, $wants/$sets rename miss): every earlier test
     * exercised the empty-scope early-returns and never reached the
     * row-classification switch — a refactor that broke ALL of its per-queue
     * guards shipped with this suite green (all six Vandaag counts rendered 0
     * and every ?queue= click-through opened an empty grid). This test drives
     * a real row set THROUGH the switch and pins the classified id-sets.
     */
    public function test_rows_are_classified_into_their_queues(): void
    {
        global $wpdb;
        $previousWpdb = $wpdb;
        $wpdb = new class {
            public string $prefix = 'wp_';

            public function prepare(string $query, ...$args): string
            {
                foreach ($args as $arg) {
                    $query = preg_replace('/%[sd]/', is_string($arg) ? "'" . addslashes($arg) . "'" : (string) $arg, $query, 1);
                }

                return $query;
            }

            public function get_var(?string $query = null): ?string
            {
                // RegistrationTable::exists() probes SHOW TABLES LIKE.
                return 'wp_vad_registrations';
            }
        };

        try {
            $rows = [
                // → pending queue; NULL tasks = nothing for the user to do →
                //   ADMIN-ready (F-V5: these rows counted on the card but
                //   were invisible in the approvals panel).
                (object) ['id' => 1, 'user_id' => 10, 'edition_id' => 100, 'status' => 'pending', 'registered_at' => '2026-07-01 10:00:00', 'completed_at' => null, 'completion_tasks' => null],
                // → pending, open user task → BLOCKED on the participant.
                (object) ['id' => 4, 'user_id' => 11, 'edition_id' => 100, 'status' => 'pending', 'registered_at' => '2026-07-01 10:00:00', 'completed_at' => null, 'completion_tasks' => ['questionnaire' => ['status' => 'pending']]],
                // → pending, user done + open approval → ADMIN-ready.
                (object) ['id' => 5, 'user_id' => 12, 'edition_id' => 100, 'status' => 'pending', 'registered_at' => '2026-07-01 10:00:00', 'completed_at' => null, 'completion_tasks' => ['questionnaire' => ['status' => 'completed'], 'approval' => ['status' => 'pending']]],
                // → oldinterest (200 days old, undated edition).
                (object) ['id' => 2, 'user_id' => 0, 'edition_id' => 101, 'status' => 'interest', 'registered_at' => gmdate('Y-m-d H:i:s', strtotime('-200 days')), 'completed_at' => null, 'completion_tasks' => null],
                // → interest_to_invite (fresh, edition 102 has a start date).
                (object) ['id' => 3, 'user_id' => 0, 'edition_id' => 102, 'status' => 'interest', 'registered_at' => gmdate('Y-m-d H:i:s'), 'completed_at' => null, 'completion_tasks' => null],
            ];

            $registrations = Mockery::mock(RegistrationRepository::class);
            $registrations->shouldReceive('findByEditionsAndStatuses')->andReturn($rows);

            $editions = Mockery::mock(EditionService::class);

            $editionRepo = Mockery::mock(EditionRepository::class);
            $editionRepo->shouldReceive('filterIdsWithStartDate')
                ->with([101, 102])->andReturn([102]);

            // Readiness rule (pendingSplit): THE shared awaitsAdmin predicate
            // — "user tasks done" = every non-approval task completed;
            // empty/NULL = nothing to do.
            $completion = Mockery::mock(\Stride\Modules\Enrollment\EnrollmentCompletion::class);
            $completion->shouldReceive('awaitsAdmin')->andReturnUsing(
                static function (array $tasks): bool {
                    foreach ($tasks as $type => $task) {
                        if ($type !== 'approval' && ($task['status'] ?? 'pending') !== 'completed') {
                            return false;
                        }
                    }

                    return true;
                },
            );
            ntdst_set(\Stride\Modules\Enrollment\EnrollmentCompletion::class, $completion);

            $resolver = new WorklistQueueResolver($registrations, $editions, $editionRepo);
            $sets = $resolver->idsByQueue([100, 101, 102]);

            $this->assertSame([1, 4, 5], $sets['pending'], 'pending rows must land in the pending queue');
            $this->assertArrayNotHasKey('pending_ready', $sets, 'the split sub-set is internal — not part of the public queue vocabulary');
            $this->assertSame([2], $sets['oldinterest'], 'a 200-day-old interest row must land in oldinterest');
            $this->assertSame([3], $sets['interest_to_invite'], 'an interest row on a dated edition must land in interest_to_invite');
            $this->assertSame([], $sets['waitlist']);
            $this->assertSame([], $sets['offerte']);
            $this->assertSame([], $sets['nocert']);

            // Decision 7a: ready ∪ blocked ≡ pending, same fetch + definition.
            $split = $resolver->pendingSplit([100, 101, 102]);
            $this->assertSame([1, 5], $split['ready'], 'NULL-task and user-done rows wait on the ADMIN');
            $this->assertSame([4], $split['blocked'], 'an open user task blocks on the participant');
        } finally {
            $wpdb = $previousWpdb;
        }
    }

    /**
     * Cross-language contract: the resolver's queue vocabulary must appear
     * verbatim in BOTH client files — grid.js (QUEUE_META: chip labels + the
     * ?queue= passthrough allowlist) and vandaag.js (QUEUE_DEFS: the cards
     * that emit the deep-links). A queue added server-side without the client
     * halves (or renamed on one side) fails HERE, not weeks later as a dead
     * card or a 400 on click-through.
     *
     * Keys are asserted INSIDE the extracted QUEUE_META object literal — a
     * whole-file grep false-passed when a queue key collided with the status
     * vocabulary ('pending:' also exists in STATUS_META).
     */
    public function test_queue_keys_exist_in_both_client_files(): void
    {
        $gridQueueMeta = $this->extractJsBlock('grid.js', 'QUEUE_META');
        $vandaagJs     = $this->clientJs('vandaag.js');

        foreach (WorklistQueueResolver::QUEUES as $queue) {
            $this->assertMatchesRegularExpression(
                '/\b' . preg_quote($queue, '/') . '\s*:/',
                $gridQueueMeta,
                "grid.js QUEUE_META is missing queue key '{$queue}'",
            );
            $this->assertStringContainsString(
                "key: '{$queue}'",
                $vandaagJs,
                "vandaag.js QUEUE_DEFS is missing queue key '{$queue}'",
            );
        }
    }

    /**
     * Label half of the contract: the Dutch queue label is duplicated in
     * grid.js (QUEUE_META — the filter chip) and vandaag.js (QUEUE_DEFS — the
     * card). A wording tweak landing on one side only would rename the filter
     * the admin just clicked — the card/click-through vocabulary drift the
     * resolver exists to kill, at the label layer.
     */
    public function test_queue_labels_match_between_card_and_chip(): void
    {
        $gridQueueMeta = $this->extractJsBlock('grid.js', 'QUEUE_META');
        $vandaagJs     = $this->clientJs('vandaag.js');

        foreach (WorklistQueueResolver::QUEUES as $queue) {
            $matched = preg_match(
                '/\b' . preg_quote($queue, '/') . "\s*:\s*\{\s*label:\s*'((?:[^'\\\\]|\\\\.)*)'/",
                $gridQueueMeta,
                $m,
            );
            $this->assertSame(1, $matched, "grid.js QUEUE_META has no label for '{$queue}'");
            $label = stripslashes($m[1]);

            $this->assertMatchesRegularExpression(
                "/key: '" . preg_quote($queue, '/') . "'[^\\n]*label: '" . preg_quote(addslashes($label), '/') . "'/",
                $vandaagJs,
                "vandaag.js QUEUE_DEFS label for '{$queue}' differs from grid.js QUEUE_META ('{$label}')",
            );
        }
    }

    /**
     * Status half of the contract: every queue's server predicate starts from
     * exactly ONE registration status (queueStatuses — statusForQueue is its
     * public accessor), and grid.js QUEUE_META's per-key `status` mirrors it
     * so the ARMED cross-page bulk bar offers the right actions for a queue
     * selection. A server predicate rewired to a different (or a second)
     * status without the client half fails HERE.
     */
    public function test_queue_row_statuses_match_the_server_predicates(): void
    {
        $method = new \ReflectionMethod(WorklistQueueResolver::class, 'queueStatuses');
        /** @var array<string, list<string>> $serverStatuses */
        $serverStatuses = $method->invoke(null);

        $this->assertSame(WorklistQueueResolver::QUEUES, array_keys($serverStatuses));

        $gridQueueMeta = $this->extractJsBlock('grid.js', 'QUEUE_META');

        foreach ($serverStatuses as $queue => $statuses) {
            $this->assertCount(
                1,
                $statuses,
                "queue '{$queue}' is no longer status-homogeneous — the client QUEUE_META.status contract (armed bulk bar) and the funnel shortcut break",
            );
            $this->assertSame(
                $statuses[0],
                WorklistQueueResolver::statusForQueue($queue),
                'statusForQueue must expose the predicate status',
            );
            $this->assertMatchesRegularExpression(
                '/\b' . preg_quote($queue, '/') . "\s*:\s*\{[^}]*status:\s*'" . preg_quote($statuses[0], '/') . "'/",
                $gridQueueMeta,
                "grid.js QUEUE_META['{$queue}'].status must be '{$statuses[0]}' (the server predicate's status)",
            );
        }
    }

    private function clientJs(string $file): string
    {
        $jsDir = dirname(__DIR__, 3) . '/web/app/mu-plugins/stride-core/assets/js/admin/';

        return (string) file_get_contents($jsDir . $file);
    }

    /**
     * Extract ONE `const <name> = { … };` object literal from a client file, so
     * key assertions run against the actual table — not the whole file.
     */
    private function extractJsBlock(string $file, string $constName): string
    {
        $js = $this->clientJs($file);
        $matched = preg_match(
            '/const ' . preg_quote($constName, '/') . '\s*=\s*\{(.*?)\};/s',
            $js,
            $m,
        );
        $this->assertSame(1, $matched, "{$file} no longer defines const {$constName}");

        return $m[1];
    }
}
