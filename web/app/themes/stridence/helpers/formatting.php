<?php

/**
 * Formatting Helper Functions
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

// stride_format_date moved to stride-core/Support/formatting.php (Task C2,
// audit H-6): core mail/notification rendering uses it, so the mu-plugin owns
// it. mu-plugins load before the theme, so it is always defined here — theme
// callers keep working unchanged. Do NOT re-add a copy (redeclare fatal).

/**
 * Format money (cents to EUR)
 *
 * @param int $cents Amount in cents
 * @return string    Formatted price
 */
function stride_format_money(int $cents): string
{
    return '€ ' . number_format($cents / 100, 2, ',', '.');
}

/**
 * Get enrollment URL for an edition or trajectory.
 *
 * Uses the router clean URLs:
 * - Edition: /edities/{slug}/inschrijving/
 * - Trajectory: /trajecten/{slug}/inschrijving/
 *
 * Works for all modes (enrollment, interest, pending approval) since
 * the form adapts based on offering status and requires_approval setting.
 *
 * @param int    $id   Post ID (edition or trajectory)
 * @param string $type 'edition' or 'trajectory'
 * @return string      URL
 */
function stride_enrollment_url(int $id, string $type = 'edition'): string
{
    $post = get_post($id);
    if (!$post) {
        return home_url('/');
    }

    $slug = $post->post_name;

    if ($type === 'trajectory') {
        return home_url('/trajecten/' . $slug . '/inschrijving/');
    }

    return home_url('/edities/' . $slug . '/inschrijving/');
}

/**
 * Compute per-session seat display state for the session pickers.
 *
 * Display-only helper — the authoritative seat gate is server-side in
 * SessionService (Seats-3). This mirrors that logic for the UI so full
 * sessions read as unavailable before the user submits.
 *
 * Capacity 0 = unlimited (the common case) → no badge, never disabled.
 * When capacity > 0, remaining = max(0, capacity - selectedCount).
 *
 * Own-seat nuance: a session the current user has ALREADY selected is never
 * disabled even when full — they already hold that seat (the server gate
 * exempts it too). Pass the current registration's selected session ids to
 * enable this exemption; omit it (default []) to fall back to a pure
 * capacity check.
 *
 * @param int        $sessionId          Session post id.
 * @param int|null   $capacity           Capacity from the session array, if
 *                                        already known (avoids a service call);
 *                                        null → read via SessionService.
 * @param array<int> $currentSelectionIds Session ids the current user holds.
 * @return array{unlimited:bool,remaining:int,isFull:bool,ownSeat:bool,disabled:bool}
 */
function stridence_session_seat_state(
    int $sessionId,
    ?int $capacity = null,
    array $currentSelectionIds = [],
): array {
    $service = ntdst_get(\Stride\Modules\Edition\SessionService::class);

    $cap = $capacity !== null ? $capacity : $service->getCapacity($sessionId);

    // Unlimited: no badge, never disabled.
    if ($cap <= 0) {
        return [
            'unlimited' => true,
            'remaining' => 0,
            'isFull'    => false,
            'ownSeat'   => false,
            'disabled'  => false,
        ];
    }

    $ownSeat = in_array($sessionId, array_map('intval', $currentSelectionIds), true);

    $selected  = $service->getSelectedCount($sessionId);
    $remaining = max(0, $cap - $selected);
    $isFull    = $remaining === 0;

    // Disable only a full session the user does NOT already hold.
    $disabled = $isFull && !$ownSeat;

    return [
        'unlimited' => false,
        'remaining' => $remaining,
        'isFull'    => $isFull,
        'ownSeat'   => $ownSeat,
        'disabled'  => $disabled,
    ];
}

/**
 * Render the Dutch seat-availability badge for a session, or '' when nothing
 * should show (unlimited capacity).
 *
 * @param array{unlimited:bool,remaining:int,isFull:bool,ownSeat:bool,disabled:bool} $state
 * @return string HTML for the badge (already escaped), or '' for no badge.
 */
function stridence_session_seat_badge(array $state): string
{
    if (!empty($state['unlimited'])) {
        return '';
    }

    if (!empty($state['isFull'])) {
        return '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-status-error/10 text-status-error shrink-0">'
            . esc_html__('Vol', 'stridence')
            . '</span>';
    }

    $remaining = (int) ($state['remaining'] ?? 0);
    $label = $remaining === 1
        ? __('1 plaats over', 'stridence')
        /* translators: %d = number of remaining seats */
        : sprintf(__('%d plaatsen over', 'stridence'), $remaining);

    return '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary shrink-0">'
        . esc_html($label)
        . '</span>';
}
