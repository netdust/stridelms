<?php

declare(strict_types=1);

/**
 * Core formatting helpers.
 *
 * Owned by stride-core: mail + notification rendering (StrideMailBridge,
 * NotificationMapper) format dates with these helpers, so they must work
 * with ANY active theme (audit finding H-6 / ARCHITECTURE-INVARIANTS INV-5).
 * mu-plugins load before the theme, so this definition always wins; the
 * function_exists guard keeps a stale theme copy from fataling on redeclare.
 *
 * @package stride-core
 */

defined('ABSPATH') || exit;

if (!function_exists('stride_format_date')) {
    /**
     * Format date in Dutch.
     *
     * @param string $date   Date string
     * @param string $format PHP date format
     * @return string        Dutch formatted date ('' when unparseable)
     */
    function stride_format_date(string $date, string $format = 'j F Y'): string
    {
        $timestamp = strtotime($date);

        if ($timestamp === false) {
            return '';
        }

        $months_en = [
            'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December',
        ];

        $months_nl = [
            'januari', 'februari', 'maart', 'april', 'mei', 'juni',
            'juli', 'augustus', 'september', 'oktober', 'november', 'december',
        ];

        $days_en = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $days_nl = ['maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag', 'zondag'];

        $formatted = date($format, $timestamp);
        $formatted = str_replace($months_en, $months_nl, $formatted);
        $formatted = str_replace($days_en, $days_nl, $formatted);

        return $formatted;
    }
}

if (!function_exists('stride_session_description_allowed_html')) {
    /**
     * The HTML tag safelist for a session description (rich text).
     *
     * Single source of truth shared by the SAVE path (wp_kses on the admin
     * AJAX input) and the RENDER path (wp_kses in the theme's session-row
     * partial) so what an admin can store and what a visitor sees agree
     * exactly. Allows only basic block/inline formatting — headings, bold,
     * italic, ordered/unordered lists, paragraphs, line breaks and links —
     * so an admin can type a speaker list or a simple day programme; anything
     * else (scripts, styles, event handlers, iframes) is stripped.
     *
     * @return array<string, array<string, bool>> wp_kses allowed-HTML map.
     */
    function stride_session_description_allowed_html(): array
    {
        return [
            'p'      => [],
            'br'     => [],
            'strong' => [],
            'b'      => [],
            'em'     => [],
            'i'      => [],
            'u'      => [],
            // Pell's heading buttons emit h1/h2; h3/h4 kept for hand-written
            // or pasted markup. All render compactly via .prose-session.
            'h1'     => [],
            'h2'     => [],
            'h3'     => [],
            'h4'     => [],
            'ul'     => [],
            'ol'     => [],
            'li'     => [],
            'a'      => [
                'href'   => true,
                'title'  => true,
                'target' => true,
                'rel'    => true,
            ],
        ];
    }
}
