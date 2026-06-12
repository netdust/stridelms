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
