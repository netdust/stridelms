<?php

declare(strict_types=1);

namespace Stride\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Immutable date range value object.
 *
 * Used for edition scheduling, choice windows, deadlines.
 */
final readonly class DateRange
{
    private function __construct(
        private DateTimeImmutable $start,
        private DateTimeImmutable $end,
    ) {
        if ($end < $start) {
            throw new InvalidArgumentException('End date cannot be before start date');
        }
    }

    /**
     * Create from DateTime objects.
     */
    public static function from(DateTimeImmutable $start, DateTimeImmutable $end): self
    {
        return new self($start, $end);
    }

    /**
     * Create from date strings (Y-m-d format).
     */
    public static function fromStrings(string $start, string $end): self
    {
        return new self(
            new DateTimeImmutable($start),
            new DateTimeImmutable($end),
        );
    }

    public function start(): DateTimeImmutable
    {
        return $this->start;
    }

    public function end(): DateTimeImmutable
    {
        return $this->end;
    }

    /**
     * Check if a date falls within this range (inclusive).
     */
    public function contains(DateTimeImmutable $date): bool
    {
        return $date >= $this->start && $date <= $this->end;
    }

    /**
     * Check if now is within this range.
     */
    public function isActive(): bool
    {
        return $this->contains(new DateTimeImmutable());
    }

    /**
     * Check if this range has passed.
     */
    public function isPast(): bool
    {
        return new DateTimeImmutable() > $this->end;
    }

    /**
     * Check if this range is in the future.
     */
    public function isFuture(): bool
    {
        return new DateTimeImmutable() < $this->start;
    }

    /**
     * Check if this range overlaps with another.
     */
    public function overlapsWith(DateRange $other): bool
    {
        return $this->start <= $other->end && $this->end >= $other->start;
    }

    /**
     * Get duration in days.
     */
    public function days(): int
    {
        return (int) $this->start->diff($this->end)->days + 1;
    }

    public function format(string $pattern = 'd/m/Y'): string
    {
        return sprintf(
            '%s - %s',
            $this->start->format($pattern),
            $this->end->format($pattern),
        );
    }
}
