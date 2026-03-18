<?php
/**
 * Inline collapsible session list for enrollment cards.
 *
 * Shared between tab-home.php and tab-inschrijvingen.php.
 * Shows future sessions with attendance icons and crossing-out for non-chosen slotted sessions.
 *
 * @param array $args {
 *     @type array $sessions  Future sessions array (with 'selected', 'attendance', 'slot' keys)
 *     @type int   $limit     Max sessions to show (default 5)
 * }
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$sessions = $args['sessions'] ?? [];
$limit = $args['limit'] ?? 5;

if (empty($sessions)) {
    return;
}

$hasSelections = !empty(array_filter(array_column($sessions, 'selected')));
?>
<div class="border-t border-border/50">
    <button @click="sessionsOpen = !sessionsOpen"
            class="w-full flex items-center justify-between px-4 py-2 text-xs text-text-muted hover:text-text transition-colors cursor-pointer">
        <span><?php echo esc_html(sprintf(
            _n('%d komende sessie', '%d komende sessies', count($sessions), 'stridence'),
            count($sessions)
        )); ?></span>
        <span class="transition-transform duration-200" :class="{ 'rotate-180': sessionsOpen }">
            <?php echo stridence_icon('chevron-down', 'w-4 h-4'); ?>
        </span>
    </button>
    <div x-show="sessionsOpen" x-collapse x-cloak>
        <div class="px-4 pb-3 space-y-1">
            <?php foreach (array_slice(array_values($sessions), 0, $limit) as $s) :
                $sDate = $s['date'] ?? '';
                $sStart = $s['start_time'] ?? '';
                $sTitle = $s['title'] ?? '';
                $sAttendance = $s['attendance'] ?? null;
                $sSelected = !empty($s['selected']);
                $notChosen = $hasSelections && !$sSelected && !empty($s['slot'] ?? '');
            ?>
                <div class="flex items-center gap-2 text-xs <?php echo $notChosen ? 'opacity-40 line-through' : ''; ?>">
                    <?php if ($sAttendance === 'present') : ?>
                        <?php echo stridence_icon('check', 'w-3.5 h-3.5 text-success shrink-0'); ?>
                    <?php elseif ($sAttendance === 'absent') : ?>
                        <?php echo stridence_icon('x', 'w-3.5 h-3.5 text-error shrink-0'); ?>
                    <?php else : ?>
                        <span class="w-1.5 h-1.5 rounded-full bg-border shrink-0"></span>
                    <?php endif; ?>
                    <span class="text-text-muted w-16 shrink-0">
                        <?php echo esc_html($sDate ? date_i18n('j M', strtotime($sDate)) : ''); ?>
                    </span>
                    <span class="text-text-muted shrink-0">
                        <?php if ($sStart) echo esc_html($sStart); ?>
                    </span>
                    <?php if ($sTitle) : ?>
                        <span class="text-text truncate"><?php echo esc_html($sTitle); ?></span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
