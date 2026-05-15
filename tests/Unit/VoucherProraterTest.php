<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Domain\Money;
use Stride\Modules\Invoicing\Helpers\VoucherProrater;
use Stride\Tests\TestCase;

class VoucherProraterTest extends TestCase
{
    private VoucherProrater $prorater;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prorater = new VoucherProrater();
    }

    public function test_prorates_evenly_across_sessions(): void
    {
        $result = $this->prorater->prorate(Money::eur(100), 4);
        $this->assertSame(2500, $result->inCents());
    }

    public function test_collapses_to_full_subtotal_for_zero_sessions(): void
    {
        // Guards apply_mode=single_session on e-learning (no sessions) —
        // must never divide by zero; subtotal passes through unchanged.
        $result = $this->prorater->prorate(Money::eur(45), 0);
        $this->assertSame(4500, $result->inCents());
    }

    public function test_collapses_to_full_subtotal_for_negative_count(): void
    {
        $result = $this->prorater->prorate(Money::eur(45), -3);
        $this->assertSame(4500, $result->inCents());
    }
}
