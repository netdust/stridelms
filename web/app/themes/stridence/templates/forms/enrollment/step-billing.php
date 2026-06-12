<?php
/**
 * Enrollment Form — Step 2: Billing + Voucher + Field Groups
 *
 * @var array $args {
 *     @type array $billing_groups  Field groups for the billing step
 * }
 */

$billing_groups = $args['billing_groups'] ?? [];
?>
<div x-show="currentStep === 2" x-transition>
    <h2 class="text-xl font-heading font-semibold mb-6">Facturatiegegevens</h2>

    <div class="space-y-4">
        <div>
            <label class="input-label" for="company">Organisatie / Naam *</label>
            <input type="text" id="company" name="company" x-model="form.company"
                   class="input-text" required>
        </div>

        <div>
            <label class="input-label" for="invoice_email">E-mail voor factuur *</label>
            <input type="email" id="invoice_email" name="invoice_email" x-model="form.invoice_email"
                   class="input-text" required>
        </div>

        <div>
            <label class="input-label" for="address">Adres *</label>
            <input type="text" id="address" name="address" x-model="form.address"
                   class="input-text" required>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="input-label" for="postal_code">Postcode *</label>
                <input type="text" id="postal_code" name="postal_code" x-model="form.postal_code"
                       class="input-text" required>
            </div>
            <div>
                <label class="input-label" for="city">Gemeente *</label>
                <input type="text" id="city" name="city" x-model="form.city"
                       class="input-text" required>
            </div>
        </div>

        <!-- VAT/GLN only for non-private -->
        <template x-if="form.enrollment_type !== 'prive'">
            <div class="space-y-4 pt-4 border-t border-border">
                <div>
                    <label class="input-label" for="vat_number">BTW-nummer</label>
                    <input type="text" id="vat_number" name="vat_number" x-model="form.vat_number"
                           class="input-text" placeholder="BE0123.456.789">
                </div>
                <div>
                    <label class="input-label" for="gln_number">GLN-nummer (Peppol)</label>
                    <input type="text" id="gln_number" name="gln_number" x-model="form.gln_number"
                           class="input-text" placeholder="5412345678901">
                </div>
                <div>
                    <label class="input-label" for="po_number">Bestelbonnummer</label>
                    <input type="text" id="po_number" name="po_number" x-model="form.po_number"
                           class="input-text">
                </div>
            </div>
        </template>

        <!-- Dynamic field groups for billing step -->
        <?php foreach ($billing_groups as $group) : ?>
            <?php
            stridence_template_part('forms/fields/field-group', null, [
                'group' => $group,
            ]);
            ?>
        <?php endforeach; ?>

        <!-- Voucher code -->
        <div class="pt-4 border-t border-border">
            <label class="input-label" for="voucher_code">Kortingscode</label>
            <div class="flex gap-2">
                <input type="text" id="voucher_code" name="voucher_code" x-model="form.voucher_code"
                       class="input-text flex-1" placeholder="CODE123"
                       :disabled="voucherValid">
                <button type="button" @click="validateVoucher"
                        :disabled="!form.voucher_code || voucherLoading || voucherValid"
                        class="btn-secondary whitespace-nowrap">
                    <span x-show="!voucherLoading && !voucherValid">Controleren</span>
                    <span x-show="voucherLoading" class="flex items-center gap-2">
                        <span class="spinner"></span>
                    </span>
                    <span x-show="voucherValid" class="text-status-success">✓ Geldig</span>
                </button>
            </div>
            <p x-show="voucherError" class="input-error" x-text="voucherError"></p>
            <p x-show="voucherDiscount" class="text-sm text-status-success mt-1" x-text="voucherDiscount"></p>
        </div>
    </div>

    <div class="mt-8 flex justify-between">
        <button type="button" @click="prevStep" class="btn-secondary">Vorige</button>
        <button type="button" @click="nextStep" class="btn-primary">Volgende</button>
    </div>
</div>
