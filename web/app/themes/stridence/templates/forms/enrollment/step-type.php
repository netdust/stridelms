<?php
/**
 * Enrollment Form — Step 0: Enrollment Type Selection
 */
?>
<div x-show="currentStep === 0" x-transition>
    <h2 class="text-xl font-heading font-semibold mb-6">Voor wie is deze inschrijving?</h2>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <label class="relative card-bordered p-4 cursor-pointer text-center transition-all"
               :class="form.enrollment_type === 'werknemer' ? '!bg-primary text-text-inverse' : 'hover:bg-surface-alt'">
            <input type="radio" name="enrollment_type" value="werknemer"
                   x-model="form.enrollment_type" class="sr-only">
            <span x-show="form.enrollment_type === 'werknemer'" x-cloak
                  class="absolute top-2 right-2 w-6 h-6 rounded-full bg-text-inverse text-primary flex items-center justify-center">
                <?= stridence_icon('check', 'w-4 h-4') ?>
            </span>
            <div class="w-12 h-12 mx-auto mb-3 rounded-full flex items-center justify-center"
                 :class="form.enrollment_type === 'werknemer' ? 'bg-text-inverse/20 text-text-inverse' : 'bg-primary/10 text-primary'">
                <?= stridence_icon('user', 'w-6 h-6') ?>
            </div>
            <p class="font-semibold">Mezelf</p>
            <p class="text-xs mt-1"
               :class="form.enrollment_type === 'werknemer' ? 'text-text-inverse/80' : 'text-text-muted'">
                Als werknemer
            </p>
        </label>

        <label class="relative card-bordered p-4 cursor-pointer text-center transition-all"
               :class="form.enrollment_type === 'collega' ? '!bg-primary text-text-inverse' : 'hover:bg-surface-alt'">
            <input type="radio" name="enrollment_type" value="collega"
                   x-model="form.enrollment_type" class="sr-only">
            <span x-show="form.enrollment_type === 'collega'" x-cloak
                  class="absolute top-2 right-2 w-6 h-6 rounded-full bg-text-inverse text-primary flex items-center justify-center">
                <?= stridence_icon('check', 'w-4 h-4') ?>
            </span>
            <div class="w-12 h-12 mx-auto mb-3 rounded-full flex items-center justify-center"
                 :class="form.enrollment_type === 'collega' ? 'bg-text-inverse/20 text-text-inverse' : 'bg-primary/10 text-primary'">
                <?= stridence_icon('users', 'w-6 h-6') ?>
            </div>
            <p class="font-semibold">Collega</p>
            <p class="text-xs mt-1"
               :class="form.enrollment_type === 'collega' ? 'text-text-inverse/80' : 'text-text-muted'">
                Iemand anders
            </p>
        </label>

        <label class="relative card-bordered p-4 cursor-pointer text-center transition-all"
               :class="form.enrollment_type === 'prive' ? '!bg-primary text-text-inverse' : 'hover:bg-surface-alt'">
            <input type="radio" name="enrollment_type" value="prive"
                   x-model="form.enrollment_type" class="sr-only">
            <span x-show="form.enrollment_type === 'prive'" x-cloak
                  class="absolute top-2 right-2 w-6 h-6 rounded-full bg-text-inverse text-primary flex items-center justify-center">
                <?= stridence_icon('check', 'w-4 h-4') ?>
            </span>
            <div class="w-12 h-12 mx-auto mb-3 rounded-full flex items-center justify-center"
                 :class="form.enrollment_type === 'prive' ? 'bg-text-inverse/20 text-text-inverse' : 'bg-primary/10 text-primary'">
                <?= stridence_icon('user', 'w-6 h-6') ?>
            </div>
            <p class="font-semibold">Particulier</p>
            <p class="text-xs mt-1"
               :class="form.enrollment_type === 'prive' ? 'text-text-inverse/80' : 'text-text-muted'">
                Als privépersoon
            </p>
        </label>
    </div>

    <div class="mt-8 flex justify-end">
        <button type="button" @click="nextStep" :disabled="!form.enrollment_type"
                class="btn-primary" :class="!form.enrollment_type && 'opacity-50 cursor-not-allowed'">
            Volgende
        </button>
    </div>
</div>
