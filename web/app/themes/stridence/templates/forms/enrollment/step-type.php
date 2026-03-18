<?php
/**
 * Enrollment Form — Step 0: Enrollment Type Selection
 */
?>
<div x-show="currentStep === 0" x-transition>
    <h2 class="text-xl font-heading font-semibold mb-6">Voor wie is deze inschrijving?</h2>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <label class="card-bordered p-4 cursor-pointer text-center transition-colors"
               :class="form.enrollment_type === 'werknemer' ? 'border-primary bg-primary/5' : 'hover:border-primary/50'">
            <input type="radio" name="enrollment_type" value="werknemer"
                   x-model="form.enrollment_type" class="sr-only">
            <div class="w-12 h-12 mx-auto mb-3 rounded-full bg-primary/10 flex items-center justify-center">
                <?= stridence_icon('user', 'w-6 h-6 text-primary') ?>
            </div>
            <p class="font-medium">Mezelf</p>
            <p class="text-xs text-text-muted mt-1">Als werknemer</p>
        </label>

        <label class="card-bordered p-4 cursor-pointer text-center transition-colors"
               :class="form.enrollment_type === 'collega' ? 'border-primary bg-primary/5' : 'hover:border-primary/50'">
            <input type="radio" name="enrollment_type" value="collega"
                   x-model="form.enrollment_type" class="sr-only">
            <div class="w-12 h-12 mx-auto mb-3 rounded-full bg-primary/10 flex items-center justify-center">
                <?= stridence_icon('users', 'w-6 h-6 text-primary') ?>
            </div>
            <p class="font-medium">Collega</p>
            <p class="text-xs text-text-muted mt-1">Iemand anders</p>
        </label>

        <label class="card-bordered p-4 cursor-pointer text-center transition-colors"
               :class="form.enrollment_type === 'prive' ? 'border-primary bg-primary/5' : 'hover:border-primary/50'">
            <input type="radio" name="enrollment_type" value="prive"
                   x-model="form.enrollment_type" class="sr-only">
            <div class="w-12 h-12 mx-auto mb-3 rounded-full bg-primary/10 flex items-center justify-center">
                <?= stridence_icon('user', 'w-6 h-6 text-primary') ?>
            </div>
            <p class="font-medium">Particulier</p>
            <p class="text-xs text-text-muted mt-1">Als privépersoon</p>
        </label>
    </div>

    <div class="mt-8 flex justify-end">
        <button type="button" @click="nextStep" :disabled="!form.enrollment_type"
                class="btn-primary" :class="!form.enrollment_type && 'opacity-50 cursor-not-allowed'">
            Volgende
        </button>
    </div>
</div>
