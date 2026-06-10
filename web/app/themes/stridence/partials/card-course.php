<?php
/**
 * Course Card Partial
 *
 * Renders a course card with status badge showing enrollment/progress state.
 * For logged-in users: shows their enrollment status (enrolled, in progress, completed).
 * For guests: shows course availability status.
 *
 * @param array $args {
 *     @type WP_Post $course Course post object
 * }
 */

defined('ABSPATH') || exit;

use Stride\Integrations\LearnDash\LearnDashHelper;
use Stride\Modules\Edition\EditionService;
use Stride\Domain\OfferingStatus;

$course = $args['course'] ?? null;

// Early return if no course
if (!$course instanceof WP_Post) {
    return;
}

/**
 * Find the course's primary visible edition (if any). Same shape as the
 * /edities/<course>/ router's resolver: enrollable > active > nothing.
 * Returns ['id' => int, 'status' => OfferingStatus, 'spots' => ?int]
 * or null when no visible edition exists (pure-LD course).
 */
$primary_edition = null;
$edition_ids = get_posts([
    'post_type'      => 'vad_edition',
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'fields'         => 'ids',
    'meta_query'     => [
        ['key' => '_ntdst_course_id', 'value' => $course->ID],
        [
            'key'     => '_ntdst_status',
            'value'   => ['announcement', 'open', 'full', 'in_progress'],
            'compare' => 'IN',
        ],
    ],
]);

if (!empty($edition_ids)) {
    $editionSvc = ntdst_get(EditionService::class);
    $best = null;
    $bestRank = -1;
    foreach ($edition_ids as $eid) {
        $eff = $editionSvc->getEffectiveStatus((int) $eid);
        // Effective status may flip a past edition to Completed — filter that out
        // (we already wanted active statuses; the date-flip is a stale visibility leak).
        if (!$eff->isActive()) {
            continue;
        }
        $rank = $eff->allowsEnrollment() ? 2 : 1;
        if ($rank > $bestRank) {
            $best = ['id' => (int) $eid, 'status' => $eff];
            $bestRank = $rank;
            if ($rank === 2) {
                break;
            }
        }
    }
    if ($best) {
        $capacity = (int) $editionSvc->getCapacity($best['id']);
        $best['spots'] = $capacity > 0
            ? max(0, $capacity - $editionSvc->getRegisteredCount($best['id']))
            : null;
        $primary_edition = $best;
    }
}

// Pure-LD course card. Link to the canonical course URL — LD owns
// /opleidingen/<slug>/, Stride decorates it via single-sfwd-courses.php.
// See tasks/url-structure-rework.md.
$permalink = get_permalink($course);
$title     = get_the_title($course);

// Generate excerpt: prefer post_excerpt, fallback to trimmed content
$excerpt = !empty($course->post_excerpt)
    ? $course->post_excerpt
    : wp_trim_words(wp_strip_all_tags($course->post_content), 20, '...');

// Get thumbnail - use stride_course_card size (400x225), fallback to medium
$thumbnail = get_the_post_thumbnail(
    $course,
    'stride_course_card',
    ['class' => 'w-full h-full object-cover transition-transform hover:scale-105'],
);

// Determine status badge.
// User-level state (enrolled / in-progress / completed) wins when present —
// that's the visitor's own status with the course, regardless of edition.
// Otherwise: if course has a primary visible edition, show its effective
// status. Pure-LD courses (no edition) fall back to the generic "Beschikbaar"
// availability badge.
$userId = get_current_user_id();
$badge_status = null;     // 'edition' → render via badge-status partial; else inline
$badge_class = '';
$badge_label = '';
$badge_icon = '';

if ($userId && LearnDashHelper::isActive() && LearnDashHelper::isEnrolled($course->ID, $userId)) {
    $progress = LearnDashHelper::getProgress($course->ID, $userId);

    if ($progress >= 100) {
        $badge_status = 'completed';
        $badge_class = 'bg-success text-text-inverse';
        $badge_label = __('Afgerond', 'stridence');
        $badge_icon = 'check';
    } elseif ($progress > 0) {
        $badge_status = 'in_progress';
        $badge_class = 'bg-accent text-text-inverse';
        $badge_label = sprintf(__('%d%% voltooid', 'stridence'), $progress);
        $badge_icon = 'clock';
    } else {
        $badge_status = 'enrolled';
        $badge_class = 'bg-primary text-text-inverse';
        $badge_label = __('Ingeschreven', 'stridence');
        $badge_icon = 'check';
    }
} elseif ($primary_edition) {
    // Course has an edition — its status drives the card badge.
    $badge_status = 'edition';
} else {
    // Pure-LD course (no edition) — generic availability badge.
    $badge_status = 'available';
    $badge_class = 'bg-surface text-text-muted border border-border';
    $badge_label = __('Beschikbaar', 'stridence');
    $badge_icon = 'wifi';
}

?>
<article class="card overflow-hidden flex flex-col h-full">
    <!-- Thumbnail -->
    <a href="<?php echo esc_url($permalink); ?>" class="block aspect-video overflow-hidden bg-surface-alt relative">
        <?php if ($thumbnail): ?>
            <?php echo $thumbnail; ?>
        <?php else: ?>
            <div class="w-full h-full flex items-center justify-center">
                <?php echo stridence_icon('book-open', 'w-12 h-12 text-text-muted'); ?>
            </div>
        <?php endif; ?>

        <!-- Status Badge -->
        <div class="absolute top-3 right-3">
            <?php if ($badge_status === 'edition' && $primary_edition) : ?>
                <?php stridence_template_part('partials/badge-status', null, [
                    'status' => $primary_edition['status']->value,
                    'spots'  => $primary_edition['spots'],
                ]); ?>
            <?php else : ?>
                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium <?php echo esc_attr($badge_class); ?>">
                    <?php echo stridence_icon($badge_icon, 'w-3 h-3'); ?>
                    <?php echo esc_html($badge_label); ?>
                </span>
            <?php endif; ?>
        </div>
    </a>

    <div class="p-5 flex-1 flex flex-col">
        <h3 class="font-heading font-semibold text-lg mb-2 line-clamp-2">
            <a href="<?php echo esc_url($permalink); ?>" class="text-text hover:text-primary transition-colors">
                <?php echo esc_html($title); ?>
            </a>
        </h3>

        <p class="text-sm text-text-muted line-clamp-2 mb-4 flex-1">
            <?php echo esc_html($excerpt); ?>
        </p>

        <a href="<?php echo esc_url($permalink); ?>" class="btn-primary w-full text-center">
            <?php esc_html_e('Meer info', 'stridence'); ?>
        </a>
    </div>
</article>
