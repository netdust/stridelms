<?php
/**
 * Completion task: Approval (enrollment + post-course).
 *
 * No user action -- admin approves from edition admin page.
 */
declare(strict_types=1);
defined('ABSPATH') || exit;
?>
<div class="flex items-center gap-3 p-3 rounded-lg bg-surface-alt/50">
    <?= stridence_icon('clock', 'w-5 h-5 text-text-muted shrink-0') ?>
    <p class="text-sm text-text-muted">
        <?= esc_html__('Deze stap wordt afgehandeld door een beheerder. Je hoeft niets te doen.', 'stridence') ?>
    </p>
</div>
