<?php

/**
 * Enrollment Form Page Template (router-rendered).
 *
 * Rendered by EnrollmentRouter via ntdst_response()->render().
 * Wraps the forms/enrollment.php partial with page chrome.
 *
 * Available variables (from ntdst_response()->with()):
 * @var WP_Post $item             The edition or trajectory post object
 * @var string  $type             'edition' or 'trajectory'
 * @var bool    $enrollment_open  Whether enrollment is currently possible
 * @var string  $enrollment_mode  'interest' | 'pending_approval' | 'enrollment' | 'closed'
 */

defined('ABSPATH') || exit;

get_header();

$item_id = $item->ID ?? 0;
$item_type = $type ?? 'edition';
$enrollment_mode = $enrollment_mode ?? 'enrollment';
$enrollment_open = $enrollment_open ?? false;

// Already enrolled: show message instead of form
if (!empty($already_enrolled)) {
    stridence_template_part('partials/empty-state', null, [
        'icon'    => 'check-circle',
        'title'   => __('Je bent al ingeschreven', 'stridence'),
        'message' => __('Je hebt al een actieve inschrijving voor dit aanbod.', 'stridence'),
        'action'  => __('Naar mijn opleidingen', 'stridence'),
        'url'     => home_url('/dashboard/opleidingen/'),
    ]);
    get_footer();
    return;
}

// Closed mode: show message instead of form.
// Interest and waitlist modes are allowed even when enrollment_open is false —
// they're the alternative paths for Announcement / Full editions.
$allowsAlternativePath = in_array($enrollment_mode, ['interest', 'waitlist'], true);
if ($enrollment_mode === 'closed' || (!$enrollment_open && !$allowsAlternativePath)) {
    stridence_template_part('partials/empty-state', null, [
        'icon'    => 'alert-circle',
        'title'   => __('Inschrijving niet mogelijk', 'stridence'),
        'message' => __('Inschrijvingen voor dit aanbod zijn momenteel gesloten.', 'stridence'),
        'action'  => $item_type === 'trajectory'
            ? __('Naar trajecten', 'stridence')
            : __('Naar opleidingen', 'stridence'),
        'url'     => $item_type === 'trajectory'
            ? get_post_type_archive_link('vad_trajectory')
            : get_post_type_archive_link('sfwd-courses'),
    ]);
    get_footer();
    return;
}

// Pre-fetch item data for the Alpine component
$item_data = [
    'id' => $item_id,
    'title' => $item->post_title ?? '',
];

stridence_template_part('templates/forms/enrollment', null, [
    'item_id'         => $item_id,
    'item_type'       => $item_type,
    'item_data'       => $item_data,
    'enrollment_mode' => $enrollment_mode,
    'enrollment_open' => $enrollment_open,
    'is_online'       => $is_online ?? false,
    'form_type'       => $form_type ?? 'default',
]);

get_footer();
