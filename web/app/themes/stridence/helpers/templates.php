<?php

/**
 * Template helpers — thin wrappers around NTDST_Response.
 *
 * Why wrappers and not direct ntdst_response() calls everywhere?
 * - ~95 existing callers use stridence_template_part(); rewriting them
 *   has high blast radius for zero functional gain. The wrapper gives
 *   them NTDST's path + locate cache for free.
 * - Client mu-plugins override templates by registering their own
 *   directories via NTDST_Template_Loader::addPath(), no filter needed.
 *
 * @package stridence
 */

declare(strict_types=1);

/**
 * Echo a template part with NTDST's cached lookup.
 *
 * Resolution order (highest priority first):
 *   1. Paths registered via NTDST_Template_Loader::addPath()      (client plugins)
 *   2. <stylesheet>/templates                                      (NTDST default)
 *   3. <template>/templates                                        (NTDST default)
 *   4. <stylesheet>                                                (theme root — added per call)
 *
 * Slug semantics: relative to the theme root, e.g. 'partials/card-course'
 * or 'templates/course/header'. No leading slash, no .php extension required.
 *
 * Template-side contract:
 *   Templates receive the data dictionary as `$args` (compatible with WP's
 *   native get_template_part() since 5.5). Every key is also extracted as a
 *   loose variable, which is what callers of ntdst_response()->html() expect.
 *   Both contracts work simultaneously.
 *
 * @param string      $slug Template slug (e.g., 'partials/card-course')
 * @param string|null $name Optional name variant — appended as '-{name}'
 * @param array       $args Variables exposed to the template as $args + extracted
 */
if (!function_exists('stridence_template_part')) :
    function stridence_template_part(string $slug, ?string $name = null, array $args = []): void
    {
        echo stridence_template_html($slug, $name, $args);
    }
endif;

/**
 * Render a template part and return its output as a string.
 *
 * Same resolution and $args contract as stridence_template_part(), but
 * returns instead of echoing — for shortcodes and any caller that needs
 * the rendered HTML as a value.
 *
 * @param string      $slug Template slug (e.g., 'partials/card-course')
 * @param string|null $name Optional name variant — appended as '-{name}'
 * @param array       $args Variables exposed to the template as $args + extracted
 */
if (!function_exists('stridence_template_html')) :
    function stridence_template_html(string $slug, ?string $name = null, array $args = []): string
    {
        $template = $name ? "{$slug}-{$name}" : $slug;

        return ntdst_response()
            ->addPath(get_stylesheet_directory())
            ->withData(['args' => $args] + $args)
            ->html($template);
    }
endif;

/**
 * Render a centered error card with icon, title, message and action link.
 *
 * Used by the form shortcodes (enrollment, interest, intake, evaluation)
 * when their target edition is missing or invalid.
 */
function stridence_render_error_state(string $icon, string $title, string $message, string $action_label, string $action_url): string
{
    return stridence_template_html('partials/error-state', null, [
        'icon'         => $icon,
        'title'        => $title,
        'message'      => $message,
        'action_label' => $action_label,
        'action_url'   => $action_url,
    ]);
}

/**
 * Build course-card partial args from a UserDashboardService enrollment array.
 *
 * Maps the dashboard service's enrollment shape (edition or online) into the
 * normalised contract consumed by templates/components/course-card.php.
 *
 * @param array $enrollment One element of $data['active_editions'], $data['active_online'],
 *                          or $data['completed_items'] from UserDashboardService::getEnrollmentData().
 * @param bool  $completed  When true: clears primary_cta, sets status_pill to 'Voltooid'.
 * @return array            See course-card.php docblock for the full contract.
 */
function stridence_build_course_card_args_from_enrollment(array $enrollment, bool $completed = false): array
{
    $type = $enrollment['type'] ?? 'edition';
    $isOnline = $type === 'online';

    $courseId    = (int) ($enrollment['course_id'] ?? 0);
    $courseTitle = (string) ($enrollment['course_title'] ?? '');
    $thumbnailId = $courseId ? (int) get_post_thumbnail_id($courseId) : 0;

    // Status pill
    $statusPill = null;
    if ($completed) {
        $statusPill = ['label' => __('Voltooid', 'stridence'), 'tone' => 'muted'];
    } elseif ($isOnline) {
        $statusPill = ['label' => __('Online', 'stridence'), 'tone' => 'accent'];
    } else {
        $statusPill = ['label' => __('Klassikaal', 'stridence'), 'tone' => 'primary'];
    }

    // Meta (collapsed-header secondary line)
    $meta = [
        'start_date'          => null,
        'venue'               => null,
        'progress_label'      => null,
        'days_remaining'      => null,
        'pending_tasks_count' => null,
        'imminence'           => null, // 'today' | 'tomorrow' | null — drives Vandaag/Morgen badge
    ];

    // Body (expanded content)
    $body = [
        'excerpt'           => null,
        'progress_pct'      => null,
        'sessions'          => [],
        'upcoming_editions' => [],
        'task_summary'      => null,
        'primary_cta'       => null,
        'secondary_cta'     => null,
    ];

    if ($isOnline) {
        $totalLessons     = (int) ($enrollment['total_lessons'] ?? 0);
        $completedLessons = (int) ($enrollment['completed_lessons'] ?? 0);
        $progressPct      = (int) ($enrollment['progress'] ?? 0);

        $meta['progress_label'] = $totalLessons > 0
            ? sprintf(
                _n('%d van %d les', '%d van %d lessen', $totalLessons, 'stridence'),
                $completedLessons,
                $totalLessons,
            )
            : null;
        $meta['days_remaining'] = isset($enrollment['days_remaining']) ? (int) $enrollment['days_remaining'] : null;

        $body['progress_pct'] = $progressPct;

        if (!$completed) {
            // Primary path: LearnDash resume URL (deep-link to next incomplete
            // lesson). Fallback when resume URL is empty: the /edities/<slug>/
            // catalog page, NOT get_permalink($courseId) — that returns the raw
            // /opleidingen/<slug>/ LD permalink, which violates the catalog
            // URL rule. See [[lesson_url_role_split]].
            $ctaUrl = $enrollment['course_url'] ?? '';
            if (!$ctaUrl && $courseId) {
                $courseSlug = get_post_field('post_name', $courseId);
                $ctaUrl = $courseSlug ? home_url('/edities/' . $courseSlug . '/') : '';
            }
            if ($ctaUrl) {
                $body['primary_cta'] = [
                    'url'   => $ctaUrl,
                    'label' => $progressPct > 0
                        ? __('Verder leren', 'stridence')
                        : __('Start cursus', 'stridence'),
                ];
            }
        }
    } else {
        // edition
        $meta['start_date'] = !empty($enrollment['start_date']) ? (string) $enrollment['start_date'] : null;
        $meta['venue']      = !empty($enrollment['venue']) ? (string) $enrollment['venue'] : null;

        // Imminence: prefer next_session date, fall back to start_date
        $imminenceDate = $enrollment['next_session']['date'] ?? $enrollment['start_date'] ?? null;
        if ($imminenceDate) {
            if ($imminenceDate === date('Y-m-d')) {
                $meta['imminence'] = 'today';
            } elseif ($imminenceDate === date('Y-m-d', strtotime('+1 day'))) {
                $meta['imminence'] = 'tomorrow';
            }
        }

        $taskSummary = $enrollment['task_summary'] ?? null;
        if ($taskSummary) {
            $body['task_summary']       = $taskSummary;
            $pending                    = (int) ($taskSummary['total'] ?? 0) - (int) ($taskSummary['completed'] ?? 0);
            $meta['pending_tasks_count'] = $pending > 0 ? $pending : null;
        }

        // Sessions list (already on the enrollment shape)
        if (!empty($enrollment['sessions']) && is_array($enrollment['sessions'])) {
            $body['sessions'] = array_values($enrollment['sessions']);
        }

        // Progress for editions = attended/required
        $progress = $enrollment['progress'] ?? null;
        if (is_array($progress)) {
            $required = (int) ($progress['required'] ?? 0);
            $attended = (int) ($progress['attended'] ?? 0);
            $body['progress_pct'] = $required > 0 ? (int) round(($attended / $required) * 100) : null;
            if ($required > 0) {
                $meta['progress_label'] = sprintf(
                    _n('%d van %d sessie', '%d van %d sessies', $required, 'stridence'),
                    $attended,
                    $required,
                );
            }
        }

        if (!$completed && !empty($enrollment['cta'])) {
            $body['primary_cta'] = $enrollment['cta'];
        }
    }

    // Secondary CTA: always link to /edities/<slug>/ — the canonical
    // transactional surface. Slug is the edition slug for edition enrollments,
    // and the course slug for pure-LD online enrollments. EditionRouter resolves
    // either shape. Never link to /opleidingen/<slug>/ (the raw LD permalink) —
    // catalog cards link to /edities/, the dashboard must round-trip to the
    // same surface. See [[lesson_url_role_split]] and partials/card-course.php.
    $secondaryUrl = null;
    if (!$isOnline) {
        $editionId = (int) ($enrollment['edition_id'] ?? 0);
        if ($editionId) {
            $secondaryUrl = get_permalink($editionId) ?: null;
        }
    } elseif ($courseId) {
        $courseSlug = get_post_field('post_name', $courseId);
        if ($courseSlug) {
            $secondaryUrl = home_url('/edities/' . $courseSlug . '/');
        }
    }

    if ($secondaryUrl) {
        $body['secondary_cta'] = [
            'url'   => $secondaryUrl,
            'label' => __('Bekijk cursus', 'stridence'),
        ];
    }

    // Completed enrollments: surface certificate download as primary CTA when present
    if ($completed && !empty($enrollment['certificate_url'])) {
        $body['primary_cta'] = [
            'url'   => (string) $enrollment['certificate_url'],
            'label' => __('Download certificaat', 'stridence'),
        ];
    }

    return [
        'course_id'    => $courseId,
        'edition_id'   => (int) ($enrollment['edition_id'] ?? 0),
        'course_title' => $courseTitle,
        'thumbnail_id' => $thumbnailId ?: null,
        'type'         => $isOnline ? 'online' : 'edition',
        'status_pill'  => $statusPill,
        'enrolled'     => true,
        'initial_open' => false,
        'meta'         => $meta,
        'body'         => $body,
    ];
}

/**
 * Build course-card partial args from a trajectory course WP_Post.
 *
 * Used by `templates/trajectory/course-groups.php` to render each required or
 * elective course as an expandable card. Always produces the 'public' mode
 * (no per-user state, no progress, secondary "Bekijk cursus" CTA only).
 *
 * @param \WP_Post $course      Course post (sfwd-courses)
 * @param array    $statusPill  ['label' => string, 'tone' => 'primary'|'accent']
 * @return array                See course-card.php docblock for the full contract.
 */
function stridence_build_course_card_args_from_trajectory_course(\WP_Post $course, array $statusPill): array
{
    $courseId    = (int) $course->ID;
    $courseTitle = (string) $course->post_title;
    $thumbnailId = (int) get_post_thumbnail_id($courseId);

    // Excerpt: prefer the WP excerpt, fall back to trimmed content
    $excerpt = has_excerpt($courseId)
        ? get_the_excerpt($courseId)
        : wp_trim_words(get_post_field('post_content', $courseId), 25);

    // Upcoming editions via repository; canEnroll() stays on the service
    $editionService    = ntdst_get(\Stride\Modules\Edition\EditionService::class);
    $editionRepository = ntdst_get(\Stride\Modules\Edition\EditionRepository::class);
    $allEditions       = $editionRepository->findByCourse($courseId);
    $upcomingEditions  = [];
    $nextStartDate     = null;

    if (is_array($allEditions)) {
        foreach ($allEditions as $ed) {
            $editionId = (int) ($ed['id'] ?? $ed['ID'] ?? 0);
            if (!$editionId || !$editionService->canEnroll($editionId)) {
                continue;
            }
            $startDate = (string) ($ed['start_date'] ?? $editionRepository->getField($editionId, 'start_date', ''));
            $venue     = (string) ($ed['venue'] ?? $editionRepository->getField($editionId, 'venue', ''));
            $upcomingEditions[] = [
                'id'         => $editionId,
                'start_date' => $startDate ?: null,
                'venue'      => $venue ?: null,
            ];
            if ($nextStartDate === null && $startDate) {
                $nextStartDate = $startDate;
            }
            if (count($upcomingEditions) >= 3) {
                break;
            }
        }
    }

    return [
        'course_id'    => $courseId,
        'course_title' => $courseTitle,
        'thumbnail_id' => $thumbnailId ?: null,
        'type'         => 'public',
        'status_pill'  => $statusPill,
        'enrolled'     => false,
        'initial_open' => false,
        'meta'         => [
            'start_date'          => $nextStartDate,
            'venue'               => null,
            'progress_label'      => null,
            'days_remaining'      => null,
            'pending_tasks_count' => null,
            'imminence'           => null,
        ],
        'body'         => [
            'excerpt'           => $excerpt ?: null,
            'progress_pct'      => null,
            'sessions'          => [],
            'upcoming_editions' => $upcomingEditions,
            'task_summary'      => null,
            'primary_cta'       => null,
            // Always /edities/<course-slug>/ — never the raw LD permalink.
            // EditionRouter renders the course in place when no edition exists.
            'secondary_cta'     => [
                'url'   => home_url('/edities/' . $course->post_name . '/'),
                'label' => __('Bekijk cursus', 'stridence'),
            ],
        ],
    ];
}
