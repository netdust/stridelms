<?php
/**
 * Completion task: Session Selection.
 *
 * Shows available session slots for the user to pick from.
 * When slot config exists, sessions are grouped by slot with pick-count labels.
 * Parent Alpine component `completionPage` provides `completeTask()`.
 *
 * @var array $args {
 *     @type object  $registration  Registration row
 *     @type array   $task          Task status data
 *     @type WP_Post $post          Edition or trajectory post
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use Stride\Modules\Edition\SessionService;
use Stride\Modules\Edition\SessionSelection;

$registration = $args['registration'] ?? null;
$post         = $args['post'] ?? null;

if (!$registration || !$post) {
    return;
}

$sessionService   = ntdst_get(SessionService::class);
$sessionSelection = ntdst_get(SessionSelection::class);
$editionId = $post->post_type === 'vad_edition' ? $post->ID : 0;

if (!$editionId) {
    return;
}

$sessions = $sessionService->getSessionsForEdition($editionId);

if (empty($sessions)) {
    ?>
    <p class="text-sm text-text-muted italic">
        <?= esc_html__('Geen sessies beschikbaar.', 'stridence') ?>
    </p>
    <?php
    return;
}

// Pre-selected sessions from registration
$selectedIds = [];
$selections = $registration->selections ?? [];
if (is_array($selections)) {
    foreach ($selections as $sel) {
        $selectedIds[] = is_array($sel) ? (int) ($sel['session_id'] ?? 0) : (int) $sel;
    }
}
$selectedIds = array_filter($selectedIds);

// Slot configuration
$slotConfig = $sessionSelection->getSlotConfig($editionId);
$hasSlots = !empty($slotConfig);

// Group sessions by slot if slots exist
// When slots are configured, only slot sessions and other optional sessions are selectable
$grouped = [];
$ungrouped = [];
if ($hasSlots) {
    $slotNames = array_column($slotConfig, 'slot');
    foreach ($sessions as $session) {
        $slot = $session['slot'] ?? '';
        if ($slot && in_array($slot, $slotNames, true)) {
            $grouped[$slot][] = $session;
        } elseif (!empty($session['optional'])) {
            // Only show non-slot sessions if they're optional
            $ungrouped[] = $session;
        }
        // Mandatory non-slot sessions are not shown (they're automatic)
    }
} else {
    $ungrouped = $sessions;
}

/**
 * Render a single session checkbox option.
 */
$renderOption = function (array $session) {
    $sessionId = (int) ($session['id'] ?? 0);
    $title     = $session['title'] ?? '';
    $date      = $session['date'] ?? '';
    $startTime = $session['start_time'] ?? '';
    $endTime   = $session['end_time'] ?? '';
    $venue     = $session['venue'] ?? '';
    ?>
    <label class="flex items-center gap-3 p-3 rounded-lg border transition-colors cursor-pointer"
           :class="selected.includes(<?= $sessionId ?>)
               ? 'border-primary bg-primary/5'
               : 'border-border hover:border-primary/50'"
           @click.prevent="toggleSession(<?= $sessionId ?>)">
        <input type="checkbox"
               :checked="selected.includes(<?= $sessionId ?>)"
               class="accent-primary">
        <div class="flex-1 min-w-0">
            <?php if ($title): ?>
                <span class="text-sm font-medium text-text block">
                    <?= esc_html($title) ?>
                </span>
            <?php endif; ?>
            <?php
            $priceModifier = (int) ($session['price_modifier'] ?? 0);
            $sessionSlot = $session['slot'] ?? '';
            if ($priceModifier !== 0 && $sessionSlot !== ''):
                if ($priceModifier > 0) {
                    $formatted = '+€ ' . number_format($priceModifier / 100, 2, ',', '.');
                } else {
                    $formatted = '-€ ' . number_format(abs($priceModifier) / 100, 2, ',', '.');
                }
            ?>
                <span class="text-xs font-medium <?= $priceModifier > 0 ? 'text-amber-600' : 'text-green-600' ?> ml-1">
                    (<?= esc_html($formatted) ?>)
                </span>
            <?php endif; ?>
            <span class="text-sm <?= $title ? 'text-text-muted' : 'font-medium text-text' ?>">
                <?= esc_html($date ? stride_format_date($date) : __('Datum onbekend', 'stridence')) ?>
            </span>
            <?php if ($startTime && $endTime): ?>
                <span class="text-xs text-text-muted ml-1">
                    <?= esc_html($startTime . ' – ' . $endTime) ?>
                </span>
            <?php endif; ?>
            <?php if ($venue): ?>
                <span class="text-xs text-text-muted block mt-0.5">
                    <?= stridence_icon('map-pin', 'w-3 h-3 inline-block') ?>
                    <?= esc_html($venue) ?>
                </span>
            <?php endif; ?>
        </div>
    </label>
    <?php
};
?>

<div x-data="{
    selected: <?= esc_attr(wp_json_encode(array_map('intval', $selectedIds))) ?>,

    toggleSession(id) {
        const idx = this.selected.indexOf(id);
        if (idx > -1) {
            this.selected.splice(idx, 1);
        } else {
            this.selected.push(id);
        }
    },

    submitSessions() {
        if (this.selected.length === 0) return;
        $data.completeTask('session_selection', { session_ids: this.selected });
    }
}">

    <?php if ($hasSlots): ?>
        <!-- Grouped by slot -->
        <div class="space-y-5">
            <?php foreach ($slotConfig as $sc):
                $slotName = $sc['slot'] ?? '';
                $slotLabel = $sc['label'] ?? $slotName;
                $pickCount = (int) ($sc['pick_count'] ?? 1);
                $required = $sc['required'] ?? false;
                $slotSessions = $grouped[$slotName] ?? [];

                if (empty($slotSessions)) continue;
            ?>
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <h4 class="text-sm font-semibold text-text"><?= esc_html($slotLabel) ?></h4>
                        <span class="text-xs text-text-muted">
                            <?php if ($pickCount > 1): ?>
                                <?= esc_html(sprintf(__('Kies %d sessies', 'stridence'), $pickCount)) ?>
                            <?php else: ?>
                                <?= esc_html__('Kies 1 sessie', 'stridence') ?>
                            <?php endif; ?>
                            <?php if ($required): ?>
                                <span class="text-amber-500">*</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="space-y-2">
                        <?php foreach ($slotSessions as $session): ?>
                            <?php $renderOption($session); ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (!empty($ungrouped)): ?>
                <div>
                    <h4 class="text-sm font-semibold text-text mb-2">
                        <?= esc_html__('Overige sessies', 'stridence') ?>
                    </h4>
                    <div class="space-y-2">
                        <?php foreach ($ungrouped as $session): ?>
                            <?php $renderOption($session); ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    <?php else: ?>
        <!-- Flat list -->
        <div class="space-y-2">
            <?php foreach ($sessions as $session): ?>
                <?php $renderOption($session); ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php
    $hasModifiers = false;
    foreach ($sessions as $s) {
        if (!empty($s['slot']) && (int) ($s['price_modifier'] ?? 0) !== 0) {
            $hasModifiers = true;
            break;
        }
    }
    if ($hasModifiers):
    ?>
        <p class="text-xs text-text-muted mt-3 flex items-center gap-1">
            <?= stridence_icon('info', 'w-3.5 h-3.5') ?>
            <?= esc_html__('Je offerte wordt automatisch aangepast op basis van je sessiekeuze.', 'stridence') ?>
        </p>
    <?php endif; ?>

    <div class="mt-4 flex items-center gap-3">
        <button type="button"
                @click="submitSessions()"
                class="btn-primary text-sm"
                :disabled="selected.length === 0 || loading">
            <span x-show="!loading"><?= !empty($selectedIds) ? esc_html__('Sessiekeuze bijwerken', 'stridence') : esc_html__('Sessies bevestigen', 'stridence') ?></span>
            <span x-show="loading"><?= esc_html__('Opslaan...', 'stridence') ?></span>
        </button>
        <span class="text-xs text-text-muted"
              x-text="selected.length + ' <?= esc_attr__('geselecteerd', 'stridence') ?>'"></span>
    </div>
</div>
