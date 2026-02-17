/**
 * Voucher Admin Scripts
 *
 * Handles voucher admin interface functionality.
 *
 * @package stride
 */

(function($) {
    'use strict';

    // Initialize when document is ready
    $(document).ready(function() {
        initDatePickers();
        initCodeGenerator();
        initDiscountTypeToggle();
    });

    /**
     * Initialize Flatpickr date pickers
     */
    function initDatePickers() {
        if (typeof flatpickr === 'undefined') {
            return;
        }

        // Set locale to Dutch
        if (flatpickr.l10ns && flatpickr.l10ns.nl) {
            flatpickr.localize(flatpickr.l10ns.nl);
        }

        $('.stride-datepicker').flatpickr({
            dateFormat: 'Y-m-d',
            altInput: true,
            altFormat: 'd M Y',
            allowInput: true,
        });
    }

    /**
     * Initialize code generator button
     */
    function initCodeGenerator() {
        $('#stride-generate-code').on('click', function(e) {
            e.preventDefault();

            var $button = $(this);
            var $input = $('#voucher_code');
            var originalText = $button.text();

            $button.prop('disabled', true).text(strideVoucherAdmin.i18n.generating);

            $.ajax({
                url: strideVoucherAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'stride_generate_voucher_code',
                    nonce: strideVoucherAdmin.nonce
                },
                success: function(response) {
                    if (response.success && response.data.code) {
                        $input.val(response.data.code);
                    } else {
                        alert(strideVoucherAdmin.i18n.error);
                    }
                },
                error: function() {
                    alert(strideVoucherAdmin.i18n.error);
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });
    }

    /**
     * Initialize discount type toggle
     */
    function initDiscountTypeToggle() {
        var $typeSelect = $('#discount_type');
        var $valueField = $('#discount-value-field');
        var $valueInput = $('#discount_value');
        var $labelFixed = $valueField.find('.label-fixed');
        var $labelPercentage = $valueField.find('.label-percentage');

        function updateDiscountUI() {
            var type = $typeSelect.val();

            if (type === 'full') {
                $valueField.hide();
                $valueInput.val(0);
            } else {
                $valueField.show();

                if (type === 'fixed') {
                    $labelFixed.show();
                    $labelPercentage.hide();
                    $valueInput.attr('step', '0.01').attr('max', '');
                } else if (type === 'percentage') {
                    $labelFixed.hide();
                    $labelPercentage.show();
                    $valueInput.attr('step', '1').attr('max', '100');
                }
            }
        }

        $typeSelect.on('change', updateDiscountUI);

        // Initial state
        updateDiscountUI();
    }

})(jQuery);
