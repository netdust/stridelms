<?php

declare(strict_types=1);

namespace Stride\Modules\Invoicing\Helpers;

use Stride\Domain\Money;

/**
 * Computes the per-session share of a subtotal.
 *
 * Plain helper — no hooks, no NTDST_Service_Meta. Pure math.
 */
final class VoucherProrater
{
    public function prorate(Money $subtotal, int $sessionCount): Money
    {
        $divisor = max($sessionCount, 1);

        return Money::cents((int) round($subtotal->inCents() / $divisor));
    }
}
