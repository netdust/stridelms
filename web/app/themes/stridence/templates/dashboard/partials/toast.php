<?php
declare(strict_types=1);
defined('ABSPATH') || exit;

/**
 * Dashboard toast — same card recipe as the footer toast (one recipe everywhere).
 * Reuses the global toastStore() factory from main.js; keeps its own
 * `stride-toast` window event as the dispatch contract.
 */
?>

<div x-data="toastStore()"
     x-on:stride-toast.window="show($event.detail)"
     x-show="visible"
     x-cloak
     x-transition:enter="transition ease-out duration-normal"
     x-transition:enter-start="opacity-0 translate-y-2"
     x-transition:enter-end="opacity-100 translate-y-0"
     x-transition:leave="transition ease-in duration-fast"
     x-transition:leave-start="opacity-100 translate-y-0"
     x-transition:leave-end="opacity-0 translate-y-2"
     class="fixed bottom-6 right-6 z-[60] w-[340px] max-w-[calc(100vw-3rem)] rounded-[12px] bg-surface-card shadow-overlay p-3.5 px-4 flex items-start gap-3"
     role="alert">
    <div class="w-7 h-7 rounded-full grid place-items-center text-sm font-extrabold shrink-0"
         :class="type === 'error' ? 'bg-badge-full-bg text-badge-full-text' : 'bg-badge-open-bg text-badge-open-text'"
         x-text="type === 'error' ? '!' : '✓'"
         aria-hidden="true"></div>
    <div class="flex-1 min-w-0">
        <div class="text-sm font-bold text-text" x-text="title"></div>
        <div x-show="sub" class="text-[13px] text-text-muted mt-0.5" x-text="sub"></div>
    </div>
    <button type="button"
            @click="close()"
            class="text-base leading-none text-text-faint hover:text-text cursor-pointer shrink-0"
            aria-label="<?php esc_attr_e('Melding sluiten', 'stridence'); ?>">&times;</button>
</div>
