<?php
/**
 * Enrollment Form — Confirmation Step
 */
?>
<div x-show="currentStep === confirmStep" x-transition>
    <h2 class="text-xl font-heading font-semibold mb-6" x-text="mode === 'interest' ? 'Interesse bevestigen' : 'Bevestiging'"></h2>

    <!-- Summary -->
    <div class="bg-surface-alt rounded-lg p-4 mb-6 space-y-3">
        <h3 class="font-medium" x-text="itemData.title"></h3>
        <div class="text-sm text-text-muted space-y-1">
            <p x-show="itemData.date">
                <?= stridence_icon('calendar', 'w-4 h-4 inline-block mr-1') ?>
                <span x-text="itemData.date"></span>
            </p>
            <p x-show="itemData.venue">
                <?= stridence_icon('map-pin', 'w-4 h-4 inline-block mr-1') ?>
                <span x-text="itemData.venue"></span>
            </p>
        </div>
        <template x-if="mode !== 'interest'">
            <div class="pt-3 border-t border-border">
                <div class="flex justify-between text-sm">
                    <span>Prijs</span>
                    <span x-text="itemData.priceFormatted"></span>
                </div>
                <template x-if="voucherDiscount">
                    <div class="flex justify-between text-sm text-status-success">
                        <span>Korting</span>
                        <span x-text="'- ' + voucherDiscount"></span>
                    </div>
                </template>
            </div>
        </template>
    </div>

    <!-- Participant summary -->
    <div class="bg-surface-alt rounded-lg p-4 mb-6">
        <h4 class="text-sm font-medium mb-2">Deelnemer</h4>
        <p class="text-sm" x-text="form.first_name + ' ' + form.last_name"></p>
        <p class="text-sm text-text-muted" x-text="form.email"></p>
        <p x-show="form.phone" class="text-sm text-text-muted" x-text="form.phone"></p>
        <p x-show="form.organisation" class="text-sm text-text-muted" x-text="form.organisation"></p>
        <p x-show="form.department" class="text-sm text-text-muted" x-text="form.department"></p>
        <template x-if="form.extra_fields && Object.keys(form.extra_fields).length">
            <template x-for="(val, key) in form.extra_fields" :key="key">
                <p x-show="val" class="text-sm text-text-muted" x-text="val"></p>
            </template>
        </template>
        <p x-show="form.message" class="text-sm text-text-muted mt-1" x-text="form.message"></p>
    </div>

    <!-- Billing summary (only when billing step is in the flow) -->
    <template x-if="mode !== 'interest' && stepMap.includes(2)">
        <div class="bg-surface-alt rounded-lg p-4 mb-6">
            <h4 class="text-sm font-medium mb-2">Facturatie</h4>
            <p class="text-sm" x-text="form.company"></p>
            <p class="text-sm text-text-muted" x-text="form.address + ', ' + form.postal_code + ' ' + form.city"></p>
            <p x-show="form.vat_number" class="text-sm text-text-muted" x-text="'BTW: ' + form.vat_number"></p>
        </div>
    </template>

    <!-- Terms/Privacy acceptance -->
    <div class="mb-6">
        <label class="flex items-start gap-3 cursor-pointer">
            <input type="checkbox" name="terms_accepted" x-model="form.terms_accepted" class="input-checkbox mt-0.5">
            <span class="text-sm" x-show="mode === 'interest'">
                Ik ga akkoord met het
                <a href="/privacybeleid" target="_blank" class="text-primary hover:underline">privacybeleid</a>. *
            </span>
            <span class="text-sm" x-show="mode !== 'interest'">
                Ik ga akkoord met de
                <a href="/algemene-voorwaarden" target="_blank" class="text-primary hover:underline">algemene voorwaarden</a>
                en het
                <a href="/privacybeleid" target="_blank" class="text-primary hover:underline">privacybeleid</a>. *
            </span>
        </label>
    </div>

    <!-- Error message -->
    <div x-show="submitError" class="mb-4 p-3 bg-error/10 border border-error/20 rounded-lg">
        <p class="text-sm text-error" x-text="submitError"></p>
    </div>

    <div class="flex justify-between">
        <button type="button" @click="prevStep" class="btn-secondary">Vorige</button>
        <button type="submit" :disabled="!form.terms_accepted || submitting"
                class="btn-primary">
            <span x-show="!submitting" x-text="submitLabel"></span>
            <span x-show="submitting" class="flex items-center gap-2">
                <span class="spinner"></span>
                Verwerken...
            </span>
        </button>
    </div>
</div>
