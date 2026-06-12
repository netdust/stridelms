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
            'pending_approval' => ['enabled' => false],
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

    public function test_pending_approval_rule_always_red_priority(): void
    {
        $service = new ActionQueueService();
        $rules = $this->defaultRules(['pending_approval' => ['enabled' => true]]);
        $data = ['pending_approvals' => [
            ['id' => 101, 'user_name' => 'Jan', 'edition_title' => 'Excel'],
            ['id' => 102, 'user_name' => 'Marie', 'edition_title' => 'EHBO'],
        ]];
        $result = $service->evaluate($rules, $data);
        $this->assertCount(1, $result);
        $this->assertSame('red', $result[0]['priority']);
        $this->assertStringContainsString('2', $result[0]['text']);
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
            'pending_approval' => ['enabled' => true],
            'edition_starting' => ['enabled' => true, 'value' => 3],
        ]);
        $data = [
            'editions' => [['id' => 1, 'title' => 'Excel', 'registered' => 18, 'capacity' => 20]],
            'pending_approvals' => [['id' => 101, 'user_name' => 'Jan', 'edition_title' => 'Excel']],
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

    private function defaultRules(array $overrides = []): array
    {
        $defaults = [
            'capacity_threshold' => ['enabled' => false, 'value' => 80],
            'session_approaching' => ['enabled' => false, 'value' => 1],
            'stale_quote' => ['enabled' => false, 'value' => 7],
            'pending_approval' => ['enabled' => false],
            'edition_starting' => ['enabled' => false, 'value' => 3],
            'incomplete_tasks' => ['enabled' => false, 'value' => 7],
        ];
        return array_merge($defaults, $overrides);
    }
}
