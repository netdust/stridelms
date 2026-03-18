<?php
declare(strict_types=1);
defined('ABSPATH') || exit;
?>

<div x-data="strideToast()" x-on:stride-toast.window="show($event.detail)" x-cloak
     class="fixed bottom-6 right-6 z-[60]"
     x-show="visible"
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0 translate-y-2"
     x-transition:enter-end="opacity-100 translate-y-0"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0">
    <div class="bg-surface-card border border-border rounded-lg px-4 py-3 flex items-center gap-3 text-sm text-text"
         :class="type === 'error' ? 'border-l-[3px] border-l-error' : 'border-l-[3px] border-l-success'"
         style="box-shadow: var(--shadow-elevated);">
        <span x-text="message"></span>
        <button @click="visible = false" class="text-text-muted hover:text-text cursor-pointer ml-2">
            &times;
        </button>
    </div>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('strideToast', () => ({
        visible: false,
        message: '',
        type: 'success',
        timeout: null,
        show(detail) {
            this.message = detail.message || '';
            this.type = detail.type || 'success';
            this.visible = true;
            clearTimeout(this.timeout);
            this.timeout = setTimeout(() => { this.visible = false; }, 4000);
        }
    }));
});
</script>
