<?php
/**
 * Formatting Helper Functions
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

/**
 * Format date in Dutch
 *
 * @param string $date   Date string
 * @param string $format PHP date format
 * @return string        Dutch formatted date
 */
function stride_format_date(string $date, string $format = 'j F Y'): string
{
    $timestamp = strtotime($date);

    if ($timestamp === false) {
        return '';
    }

    $months_en = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'
    ];

    $months_nl = [
        'januari', 'februari', 'maart', 'april', 'mei', 'juni',
        'juli', 'augustus', 'september', 'oktober', 'november', 'december'
    ];

    $days_en = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $days_nl = ['maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag', 'zondag'];

    $formatted = date($format, $timestamp);
    $formatted = str_replace($months_en, $months_nl, $formatted);
    $formatted = str_replace($days_en, $days_nl, $formatted);

    return $formatted;
}

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
 * - Edition: /vormingen/{slug}/inschrijving/
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

    return home_url('/vormingen/' . $slug . '/inschrijving/');
}

