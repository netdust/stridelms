<?php

/**
 * Trajectory row/choice metadata helper.
 *
 * Builds the small metadata subline shown under each course row on the
 * Voortgang timeline and under each choice card on the Keuzes tab —
 * "Klassikaal · 2 sessies · volgende sessie do 21 mei, 09:30 · Gent".
 *
 * Pure read: every value comes from existing, tested services
 * (EditionService::isOnline / getSessionCount / getSessionsForEdition,
 * the edition `venue` field). No new data is plumbed. Templates call this
 * and render the returned string — they never derive the parts themselves.
 *
 * The no-edition case (pure-LD course, or not-yet-scheduled) returns an
 * empty parts list so the caller falls back to the bare state label.
 *
 * @package stridence
 */

declare(strict_types=1);

use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionService;

if (!function_exists('stridence_trajectory_meta_parts')) {
    /**
     * Metadata parts for a course row given its linked edition.
     *
     * @param int  $editionId       0 when the course has no linked edition.
     * @param bool $includeNextDate Append "volgende sessie <date>" (active rows).
     * @return list<string> Ordered subline parts; empty when no edition.
     */
    function stridence_trajectory_meta_parts(int $editionId, bool $includeNextDate = false): array
    {
        if ($editionId <= 0) {
            return [];
        }

        $editionService = ntdst_get(EditionService::class);
        $sessionService = ntdst_get(SessionService::class);

        $parts = [];

        // Format — Klassikaal vs Online, derived from the course's stride_format.
        $parts[] = $editionService->isOnline($editionId)
            ? __('Online', 'stridence')
            : __('Klassikaal', 'stridence');

        // Session count — "2 sessies" / "1 sessie".
        $count = $sessionService->getSessionCount($editionId);
        if ($count > 0) {
            $parts[] = sprintf(
                /* translators: %d: number of sessions */
                _n('%d sessie', '%d sessies', $count, 'stridence'),
                $count,
            );
        }

        // Next upcoming session date (active rows only).
        if ($includeNextDate) {
            $next = stridence_trajectory_next_session_label($sessionService, $editionId);
            if ($next !== '') {
                $parts[] = $next;
            }
        }

        // Venue / city.
        $venue = (string) ntdst_get(\Stride\Modules\Edition\EditionRepository::class)
            ->getField($editionId, 'venue', '');
        if ($venue !== '') {
            $parts[] = $venue;
        }

        return $parts;
    }
}

if (!function_exists('stridence_trajectory_next_session_label')) {
    /**
     * "volgende sessie do 21 mei, 09:30" for the first session on/after today.
     * Empty string when the edition has no upcoming dated session.
     */
    function stridence_trajectory_next_session_label(SessionService $sessionService, int $editionId): string
    {
        $today = wp_date('Y-m-d');
        $best = null;

        foreach ($sessionService->getSessionsForEdition($editionId) as $session) {
            $date = (string) ($session['date'] ?? '');
            if ($date === '' || $date < $today) {
                continue;
            }
            if ($best === null || $date < (string) $best['date']) {
                $best = $session;
            }
        }

        if ($best === null) {
            return '';
        }

        $ts = strtotime((string) $best['date']);
        if ($ts === false) {
            return '';
        }

        // "do 21 mei" — short weekday + day + month.
        $label = date_i18n('D j M', $ts);
        $start = (string) ($best['start_time'] ?? '');
        if ($start !== '') {
            $label .= ', ' . $start;
        }

        return sprintf(
            /* translators: %s: session date and time */
            __('volgende sessie %s', 'stridence'),
            $label,
        );
    }
}

if (!function_exists('stridence_trajectory_meta_line')) {
    /**
     * Joined subline string, or '' when there is nothing to show.
     *
     * @param int  $editionId       0 when the course has no linked edition.
     * @param bool $includeNextDate Append the next-session date (active rows).
     */
    function stridence_trajectory_meta_line(int $editionId, bool $includeNextDate = false): string
    {
        return implode(' · ', stridence_trajectory_meta_parts($editionId, $includeNextDate));
    }
}

if (!function_exists('stridence_trajectory_elective_edition_id')) {
    /**
     * The edition whose metadata a choice card should show: the next upcoming
     * edition of the elective course, falling back to any visible edition.
     * Returns 0 when the course has no edition (pure-LD elective).
     */
    function stridence_trajectory_elective_edition_id(int $courseId): int
    {
        $editions = ntdst_get(EditionService::class)->getPubliclyVisibleEditions($courseId);

        $upcoming = $editions['upcoming'] ?? [];
        if (!empty($upcoming)) {
            return (int) ($upcoming[0]['id'] ?? 0);
        }

        $past = $editions['past'] ?? [];
        if (!empty($past)) {
            return (int) ($past[0]['id'] ?? 0);
        }

        return 0;
    }
}
