<?php

declare(strict_types=1);

namespace Stride\Domain;

use InvalidArgumentException;

/**
 * Immutable money value object.
 *
 * Stores amounts in cents to avoid float precision issues.
 * All operations return new instances.
 */
final readonly class Money
{
    private function __construct(
        private int $cents,
        private string $currency = 'EUR',
    ) {
        if ($cents < 0) {
            throw new InvalidArgumentException('Money cannot be negative');
        }
    }

    /**
     * Create from euro cents.
     */
    public static function cents(int $cents): self
    {
        return new self($cents);
    }

    /**
     * Create from euro amount (e.g., 250.00).
     */
    public static function eur(float $amount): self
    {
        return new self((int) round($amount * 100));
    }

    /**
     * Create zero amount.
     */
    public static function zero(): self
    {
        return new self(0);
    }

    public function inCents(): int
    {
        return $this->cents;
    }

    public function amount(): float
    {
        return $this->cents / 100;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function add(Money $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->cents + $other->cents, $this->currency);
    }

    public function subtract(Money $other): self
    {
        $this->assertSameCurrency($other);
        $result = $this->cents - $other->cents;

        if ($result < 0) {
            throw new InvalidArgumentException('Result cannot be negative');
        }

        return new self($result, $this->currency);
    }

    public function multiply(float $factor): self
    {
        return new self((int) round($this->cents * $factor), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function equals(Money $other): bool
    {
        return $this->cents === $other->cents
            && $this->currency === $other->currency;
    }

    public function format(): string
    {
        return sprintf('€ %s', number_format($this->amount(), 2, ',', '.'));
    }

    private function assertSameCurrency(Money $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException('Cannot operate on different currencies');
        }
    }
}
