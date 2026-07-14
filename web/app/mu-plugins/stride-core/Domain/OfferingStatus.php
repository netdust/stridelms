<?php

declare(strict_types=1);

namespace Stride\Domain;

/**
 * Unified status for editions and trajectories.
 *
 * Both offering types share the same lifecycle:
 * Draft → Announcement → Open → Full → InProgress → Completed → Archived
 *                                     → Postponed / Cancelled
 */
enum OfferingStatus: string
{
    case Draft = 'draft';
    case Announcement = 'announcement';
    case Open = 'open';
    case Full = 'full';
    case InProgress = 'in_progress';
    case Postponed = 'postponed';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case Archived = 'archived';

    /**
     * Check if enrollment is allowed.
     */
    public function allowsEnrollment(): bool
    {
        return $this === self::Open;
    }

    /**
     * Check if interest registration is allowed.
     */
    public function allowsInterest(): bool
    {
        return $this === self::Announcement;
    }

    /**
     * Check if waitlist registration is allowed.
     */
    public function allowsWaitlist(): bool
    {
        return $this === self::Full;
    }

    /**
     * Check if offering is visible to the public.
     */
    public function isActive(): bool
    {
        return in_array($this, self::activeCases(), true);
    }

    /**
     * Status cases that count as "active" (publicly visible, listed in
     * catalogs, reachable via slug routing).
     *
     * @return list<self>
     */
    public static function activeCases(): array
    {
        return [
            self::Announcement,
            self::Open,
            self::Full,
            self::InProgress,
        ];
    }

    /**
     * Active status values as strings — for meta_query / WHERE IN clauses.
     *
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return array_map(fn(self $s) => $s->value, self::activeCases());
    }

    /**
     * Check if offering is in a terminal state.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Cancelled, self::Completed, self::Archived], true);
    }

    /**
     * Statuses that mean the ADMIN has closed the book on this offering —
     * the admin-workspace scope boundary (decision 2026-07-14): an edition
     * stays in every worklist queue and the default registrations grid until
     * the admin explicitly completes or archives it, so post-course work
     * (certificates, quote follow-up) never silently drops out because a
     * date passed. Cancelled is deliberately NOT closed: a cancelled edition
     * still carries cleanup work (registrations to cancel/notify).
     *
     * @return list<string>
     */
    public static function adminClosedValues(): array
    {
        return [self::Completed->value, self::Archived->value];
    }

    /**
     * Get human-readable Dutch label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Concept',
            self::Announcement => 'Vooraankondiging',
            self::Open => 'Open voor inschrijving',
            self::Full => 'Volzet',
            self::InProgress => 'Lopend',
            self::Postponed => 'Uitgesteld',
            self::Cancelled => 'Geannuleerd',
            self::Completed => 'Afgelopen',
            self::Archived => 'Gearchiveerd',
        };
    }

    /**
     * Get the Tailwind class used to render this status on the public frontend.
     *
     * Maps every status to one of the five badge tokens defined in
     * `themes/stridence/src/css/tokens.css`. Distinct from badgeConfig()
     * which is admin-only (raw hex colours for WP admin badges).
     */
    public function frontendBadgeClass(): string
    {
        return match ($this) {
            self::Draft => 'badge-cancelled',
            self::Announcement => 'badge-few',
            self::Open => 'badge-open',
            self::Full => 'badge-full',
            self::InProgress => 'badge-online',
            self::Postponed => 'badge-few',
            self::Cancelled => 'badge-full',
            self::Completed => 'badge-cancelled',
            self::Archived => 'badge-cancelled',
        };
    }

    /**
     * Get badge styling config for admin UI.
     *
     * @return array{color: string, bg: string, icon: string}
     */
    public function badgeConfig(): array
    {
        return match ($this) {
            self::Draft => ['color' => '#787c82', 'bg' => '#f0f0f1', 'icon' => 'edit'],
            self::Announcement => ['color' => '#dba617', 'bg' => '#fef9e7', 'icon' => 'megaphone'],
            self::Open => ['color' => '#00a32a', 'bg' => '#e6f4ea', 'icon' => 'yes-alt'],
            self::Full => ['color' => '#d63638', 'bg' => '#fcf0f1', 'icon' => 'warning'],
            self::InProgress => ['color' => '#2271b1', 'bg' => '#e5f0f8', 'icon' => 'controls-play'],
            self::Postponed => ['color' => '#dba617', 'bg' => '#fcf9e8', 'icon' => 'clock'],
            self::Cancelled => ['color' => '#a7aaad', 'bg' => '#f0f0f1', 'icon' => 'dismiss'],
            self::Completed => ['color' => '#646970', 'bg' => '#f6f7f7', 'icon' => 'flag'],
            self::Archived => ['color' => '#787c82', 'bg' => '#f0f0f1', 'icon' => 'archive'],
        };
    }
}
