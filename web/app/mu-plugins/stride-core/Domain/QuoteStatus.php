<?php

declare(strict_types=1);

namespace Stride\Domain;

/**
 * Quote/invoice status values.
 */
enum QuoteStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Exported = 'exported';
    case Cancelled = 'cancelled';

    /**
     * Check if quote can be edited.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Check if quote is finalized.
     */
    public function isFinalized(): bool
    {
        return in_array($this, [self::Sent, self::Exported], true);
    }

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Concept',
            self::Sent => 'Verzonden',
            self::Exported => 'Geëxporteerd',
            self::Cancelled => 'Geannuleerd',
        };
    }
}
