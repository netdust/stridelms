<?php
/**
 * Enrollment Form — Progress Bar
 *
 * @var array $args Not used; Alpine handles state via parent x-data.
 */
?>
<nav class="mb-8" aria-label="Voortgang">
    <ol class="flex items-center justify-center gap-2 text-sm">
        <template x-for="(label, index) in stepLabels" :key="index">
            <li class="flex items-center">
                <span :class="progressIndex > index ? 'bg-primary text-white' :
                              progressIndex === index ? 'bg-primary text-white' :
                              'bg-surface-alt text-text-muted'"
                      class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-medium"
                      x-text="index + 1"></span>
                <span class="ml-2 hidden sm:inline"
                      :class="progressIndex >= index ? 'text-text font-medium' : 'text-text-muted'"
                      x-text="label"></span>
                <template x-if="index < stepLabels.length - 1">
                    <span class="mx-3 h-px w-8 bg-border"></span>
                </template>
            </li>
        </template>
    </ol>
</nav>
