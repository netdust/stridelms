<?php
/**
 * Completion task: Session Selection.
 *
 * Shows available session slots for the user to pick from.
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

$registration = $args['registration'] ?? null;
$post         = $args['post'] ?? null;

if (!$registration || !$post) {
    return;
}

$sessionService = ntdst_get(SessionService::class);
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
$selections = $registration->selections ?? [];
$selectedIds = is_array($selections) ? array_column($selections, 'session_id') : [];
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
    <div class="space-y-2">
        <?php foreach ($sessions as $session): ?>
            <?php
            $sessionId = (int) ($session['id'] ?? 0);
            $date = $session['date'] ?? '';
            $startTime = $session['start_time'] ?? '';
            $endTime = $session['end_time'] ?? '';
            $venue = $session['venue'] ?? '';
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
                    <span class="text-sm font-medium text-text">
                        <?= esc_html($date ? stride_format_date($date) : __('Datum onbekend', 'stridence')) ?>
                    </span>
                    <?php if ($startTime && $endTime): ?>
                        <span class="text-xs text-text-muted ml-2">
                            <?= esc_html($startTime . ' – ' . $endTime) ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($venue): ?>
                        <span class="text-xs text-text-muted block">
                            <?= stridence_icon('map-pin', 'w-3 h-3 inline-block') ?>
                            <?= esc_html($venue) ?>
                        </span>
                    <?php endif; ?>
                </div>
            </label>
        <?php endforeach; ?>
    </div>

    <div class="mt-4 flex items-center gap-3">
        <button type="button"
                @click="submitSessions()"
                class="btn-primary text-sm"
                :disabled="selected.length === 0 || loading">
            <span x-show="!loading"><?= esc_html__('Sessies bevestigen', 'stridence') ?></span>
            <span x-show="loading"><?= esc_html__('Opslaan...', 'stridence') ?></span>
        </button>
        <span class="text-xs text-text-muted"
              x-text="selected.length + ' <?= esc_attr__('geselecteerd', 'stridence') ?>'"></span>
    </div>
</div>
