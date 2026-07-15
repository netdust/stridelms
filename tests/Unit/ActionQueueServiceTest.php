<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stride\Admin\ActionQueueService;

class ActionQueueServiceTest extends TestCase
{
    public function test_returns_empty_array_when_no_rules_active(): void
    {
        $service = new ActionQueueService();
        $result = $service->evaluate([
            'capacity_threshold' => ['enabled' => false, 'value' => 80],
            'session_approaching' => ['enabled' => false, 'value' => 1],
            'stale_quote' => ['enabled' => false, 'value' => 7],
            'edition_starting' => ['enabled' => false, 'value' => 3],
            'incomplete_tasks' => ['enabled' => false, 'value' => 7],
        ], []);
        $this->assertSame([], $result);
    }

    public function test_capacity_rule_fires_when_above_threshold(): void
    {
        $service = new ActionQueueService();
        $editions = [
            ['id' => 1, 'title' => 'Excel Basis', 'registered' => 16, 'capacity' => 20],
        ];
        $rules = $this->defaultRules(['capacity_threshold' => ['enabled' => true, 'value' => 80]]);
        $result = $service->evaluate($rules, ['editions' => $editions]);
        $this->assertCount(1, $result);
        $this->assertSame('capacity_threshold', $result[0]['rule']);
        $this->assertSame('amber', $result[0]['priority']);
    }

    public function test_capacity_rule_does_not_fire_below_threshold(): void
    {
        $service = new ActionQueueService();
        $editions = [
            ['id' => 1, 'title' => 'Excel Basis', 'registered' => 10, 'capacity' => 20],
        ];
        $rules = $this->defaultRules(['capacity_threshold' => ['enabled' => true, 'value' => 80]]);
        $result = $service->evaluate($rules, ['editions' => $editions]);
        $this->assertCount(0, $result);
    }

    public function test_capacity_rule_skips_unlimited_editions(): void
    {
        $service = new ActionQueueService();
        $editions = [
            ['id' => 1, 'title' => 'Open Course', 'registered' => 50, 'capacity' => 0],
        ];
        $rules = $this->defaultRules(['capacity_threshold' => ['enabled' => true, 'value' => 80]]);
        $result = $service->evaluate($rules, ['editions' => $editions]);
        $this->assertCount(0, $result);
    }

    public function test_stale_quote_rule(): void
    {
        $service = new ActionQueueService();
        $rules = $this->defaultRules(['stale_quote' => ['enabled' => true, 'value' => 7]]);
        $data = ['stale_quotes' => [
            ['id' => 201, 'number' => 'Q-2026-042'],
        ]];
        $result = $service->evaluate($rules, $data);
        $this->assertCount(1, $result);
        $this->assertSame('amber', $result[0]['priority']);
    }

    public function test_edition_starting_rule_blue_priority(): void
    {
        $service = new ActionQueueService();
        $rules = $this->defaultRules(['edition_starting' => ['enabled' => true, 'value' => 3]]);
        $data = ['starting_soon' => [
            ['id' => 2, 'title' => 'EHBO', 'start_date' => date('Y-m-d', strtotime('+2 days'))],
        ]];
        $result = $service->evaluate($rules, $data);
        $this->assertCount(1, $result);
        $this->assertSame('blue', $result[0]['priority']);
    }

    public function test_results_sorted_by_priority_red_first(): void
    {
        $service = new ActionQueueService();
        $rules = $this->defaultRules([
            'capacity_threshold' => ['enabled' => true, 'value' => 80],
            'stale_quote' => ['enabled' => true, 'value' => 7],
            'edition_starting' => ['enabled' => true, 'value' => 3],
        ]);
        $data = [
            // Full edition → red; under-full-but-above-threshold edition → amber.
            'editions' => [
                ['id' => 1, 'title' => 'Excel', 'registered' => 20, 'capacity' => 20],
                ['id' => 3, 'title' => 'Word', 'registered' => 18, 'capacity' => 20],
            ],
            'stale_quotes' => [['id' => 201, 'number' => 'Q-2026-042']],
            'starting_soon' => [['id' => 2, 'title' => 'EHBO', 'start_date' => date('Y-m-d', strtotime('+2 days'))]],
        ];
        $result = $service->evaluate($rules, $data);
        $priorities = array_column($result, 'priority');
        $this->assertSame('red', $priorities[0]);
        // amber before blue
        $amberIdx = array_search('amber', $priorities);
        $blueIdx = array_search('blue', $priorities);
        $this->assertLessThan($blueIdx, $amberIdx);
    }

    /**
     * Navigation contract (F-V1/F-V13): edition-scoped meldingen carry a
     * workspace `target` (grid filtered to the edition) instead of raw
     * wp-admin edit URLs; the stale-tasks aggregate targets the Acties
     * panel's gebruiker tab (the old link was a dead #action-required
     * anchor); a session without edition meta gets NO affordance at all
     * (the old code linked post.php?post=0); quotes keep their wp-admin
     * URL — that screen IS the quote workflow.
     */
    public function test_meldingen_navigation_targets(): void
    {
        $service = new ActionQueueService();
        $rules = $this->defaultRules([
            'capacity_threshold' => ['enabled' => true, 'value' => 80],
            'session_approaching' => ['enabled' => true, 'value' => 1],
            'stale_quote' => ['enabled' => true, 'value' => 7],
            'incomplete_tasks' => ['enabled' => true, 'value' => 7],
        ]);
        $result = $service->evaluate($rules, [
            'editions' => [['id' => 9, 'title' => 'Excel', 'registered' => 19, 'capacity' => 20]],
            'approaching_sessions' => [
                ['id' => 31, 'edition_id' => 9, 'edition_title' => 'Excel', 'date' => '2026-07-15'],
                ['id' => 32, 'edition_id' => null, 'edition_title' => 'Zwevend', 'date' => '2026-07-15'],
            ],
            'stale_quotes' => [['id' => 201, 'number' => 'Q-2026-042']],
            'incomplete_tasks' => [['id' => 1], ['id' => 2]],
        ]);

        $byRule = [];
        foreach ($result as $item) {
            $byRule[$item['rule']][] = $item;
        }

        $this->assertSame(
            ['view' => 'inschrijvingen', 'params' => ['edition_id' => 9]],
            $byRule['capacity_threshold'][0]['target'],
        );
        $this->assertSame('', $byRule['capacity_threshold'][0]['url']);

        $sessions = $byRule['session_approaching'];
        $this->assertSame(['view' => 'inschrijvingen', 'params' => ['edition_id' => 9]], $sessions[0]['target']);
        $this->assertNull($sessions[1]['target'], 'session without edition meta must carry no target');
        $this->assertSame('', $sessions[1]['url'], 'session without edition meta must carry no url (was post.php?post=0)');

        $this->assertNull($byRule['stale_quote'][0]['target']);
        $this->assertStringContainsString('post.php?post=201', $byRule['stale_quote'][0]['url']);

        $this->assertSame(
            ['view' => 'vandaag', 'params' => ['tab' => 'gebruiker']],
            $byRule['incomplete_tasks'][0]['target'],
        );
    }

    private function defaultRules(array $overrides = []): array
    {
        $defaults = [
            'capacity_threshold' => ['enabled' => false, 'value' => 80],
            'session_approaching' => ['enabled' => false, 'value' => 1],
            'stale_quote' => ['enabled' => false, 'value' => 7],
            'edition_starting' => ['enabled' => false, 'value' => 3],
            'incomplete_tasks' => ['enabled' => false, 'value' => 7],
        ];
        return array_merge($defaults, $overrides);
    }
}
