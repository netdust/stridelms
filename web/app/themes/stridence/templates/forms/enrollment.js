/**
 * Enrollment Form — Alpine.js Component
 *
 * Multi-step enrollment form with mode-based step flow.
 * Modes: 'interest' (skip type/billing), 'waitlist' (full edition, skip type/billing),
 * 'pending_approval', 'enrollment' (full flow).
 *
 * @param {Object} config
 * @param {number} config.itemId
 * @param {string} config.itemType       'edition' | 'trajectory'
 * @param {Object} config.itemData       Pre-fetched item data
 * @param {string} config.userEmail
 * @param {Object} config.prefill        User meta prefill values
 * @param {Array}  config.fieldGroups    Grouped field definitions
 * @param {string} config.enrollmentMode 'interest' | 'pending_approval' | 'enrollment'
 */
function enrollmentForm(config) {
    const mode = config.enrollmentMode || 'enrollment';
    const isOnline = config.isOnline || false;
    const formType = config.formType || 'default';

    // Template step indices (hardcoded in HTML):
    //   0 = Enrollment Type, 1 = Personal Info, 2 = Billing, 3 = Confirmation
    //
    // Each mode defines which template steps to visit (stepMap) and labels for progress bar.
    // Minimal forms and online editions use a short flow (personal + confirm only).
    const isShortForm = formType === 'minimal' || isOnline;

    const stepConfig = {
        interest: {
            labels: ['Gegevens', 'Bevestigen'],
            stepMap: [1, 3],
        },
        waitlist: {
            labels: ['Gegevens', 'Bevestigen'],
            stepMap: [1, 3],
        },
        pending_approval: {
            labels: isShortForm ? ['Gegevens', 'Bevestigen'] : ['Type', 'Gegevens', 'Facturatie', 'Bevestigen'],
            stepMap: isShortForm ? [1, 3] : [0, 1, 2, 3],
        },
        enrollment: {
            labels: isShortForm ? ['Gegevens', 'Bevestigen'] : ['Type', 'Gegevens', 'Facturatie', 'Bevestigen'],
            stepMap: isShortForm ? [1, 3] : [0, 1, 2, 3],
        },
    };

    const steps = stepConfig[mode] || stepConfig.enrollment;

    // Build extra_fields init from all field groups, prefilling from user meta where available
    const extraFields = {};
    const fieldGroups = config.fieldGroups || [];
    const prefill = config.prefill || {};
    fieldGroups.forEach(function(group) {
        (group.fields || []).forEach(function(field) {
            if (field.name) {
                extraFields[field.name] = field.type === 'checkbox' ? false : (prefill[field.name] || '');
            }
        });
    });

    return {
        mode,
        stepMap: steps.stepMap,
        stepIndex: 0,
        stepLabels: steps.labels,
        itemId: config.itemId,
        itemType: config.itemType,
        itemData: config.itemData,

        get currentStep() {
            return this.stepMap[this.stepIndex];
        },

        get confirmStep() {
            return 3;
        },

        get progressIndex() {
            return this.stepIndex;
        },

        get submitLabel() {
            const labels = {
                interest: 'Interesse melden',
                waitlist: 'Op wachtlijst plaatsen',
                pending_approval: 'Inschrijving indienen',
                enrollment: 'Nu inschrijven',
            };
            return labels[this.mode] || 'Nu inschrijven';
        },

        get submitAction() {
            if (this.mode === 'interest') return 'stride_submit_interest';
            if (this.mode === 'waitlist') return 'stride_submit_waitlist';
            return 'stride_submit_enrollment';
        },

        form: {
            enrollment_type: (mode === 'interest' || mode === 'waitlist' || isShortForm) ? 'self' : 'werknemer',
            first_name: config.prefill.first_name || '',
            last_name: config.prefill.last_name || '',
            email: config.userEmail || '',
            phone: config.prefill.phone || '',
            organisation: config.prefill.organisation || '',
            department: config.prefill.department || '',
            message: '',
            company: config.prefill.company || '',
            invoice_email: config.prefill.invoice_email || '',
            address: config.prefill.address || '',
            postal_code: config.prefill.postal_code || '',
            city: config.prefill.city || '',
            vat_number: config.prefill.vat_number || '',
            gln_number: config.prefill.gln_number || '',
            po_number: '',
            voucher_code: '',
            terms_accepted: false,
            extra_fields: extraFields,
        },

        voucherLoading: false,
        voucherValid: false,
        voucherError: '',
        voucherDiscount: '',

        submitting: false,
        submitError: '',

        // Required fields per template step (0=type, 1=personal, 2=billing).
        // Single source of truth for both per-step "Next" gating and the
        // final pre-submit sweep below — a gap in one can no longer let a
        // field through that the other would have caught.
        requiredFieldsForStep(step) {
            if (step === 1) {
                return ['first_name', 'last_name', 'email', 'phone'];
            }
            if (step === 2) {
                return ['company', 'invoice_email', 'address', 'postal_code', 'city'];
            }
            return [];
        },

        isStepValid(step) {
            return this.requiredFieldsForStep(step).every((field) => String(this.form[field] || '').trim() !== '');
        },

        nextStep() {
            if (!this.isStepValid(this.currentStep)) {
                return;
            }
            if (this.stepIndex < this.stepMap.length - 1) {
                this.stepIndex++;
            }
        },

        prevStep() {
            if (this.stepIndex > 0) {
                this.stepIndex--;
            }
        },

        async validateVoucher() {
            if (!this.form.voucher_code) return;

            this.voucherLoading = true;
            this.voucherError = '';

            try {
                const result = await ntdstAPI.call('stride_validate_voucher', {
                    code: this.form.voucher_code,
                    item_id: this.itemId,
                    item_type: this.itemType,
                });

                this.voucherValid = true;
                this.voucherDiscount = result.discount_formatted || result.discount;
            } catch (error) {
                this.voucherError = error.message || 'Ongeldige kortingscode';
                this.voucherValid = false;
            } finally {
                this.voucherLoading = false;
            }
        },

        async submitForm() {
            if (!this.form.terms_accepted) return;

            // Final full-form sweep, independent of per-step nextStep() gating:
            // walk every step actually in this mode's flow (stepMap already
            // excludes billing for short forms / interest / waitlist) so a gap
            // in step navigation can never let an incomplete submit through.
            const hasInvalidStep = this.stepMap.some((step) => !this.isStepValid(step));
            if (hasInvalidStep) {
                return;
            }

            this.submitting = true;
            this.submitError = '';

            try {
                const payload = {
                    item_type: this.itemType,
                    ...this.form,
                };

                if (this.itemType === 'trajectory') {
                    payload.trajectory_id = this.itemId;
                } else {
                    payload.edition_id = this.itemId;
                }

                const result = await ntdstAPI.call(this.submitAction, payload);

                const toastType = result.status === 'pending' ? 'info' : 'success';
                this.$dispatch('toast', { message: result.message, type: toastType });

                if (result.redirect_url) {
                    setTimeout(() => {
                        window.location.href = result.redirect_url;
                    }, 1500);
                }
            } catch (error) {
                this.submitError = error.message || 'Er ging iets mis. Probeer opnieuw.';
            } finally {
                this.submitting = false;
            }
        },
    };
}

// Classic <script> global in the browser (no bundler/module system here — see
// enrollment.php). This guard only runs under a CommonJS host (Vitest) so the
// factory can be unit tested against its real source; `module` is undefined
// in the browser, so this is a no-op there.
if (typeof module !== 'undefined' && module.exports) {
    module.exports = enrollmentForm;
}
