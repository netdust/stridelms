/**
 * Quote Admin JavaScript
 *
 * Handles quote admin interactions:
 * - Select2 user dropdown with search
 * - Auto-populate billing fields from user data
 * - Line item management (add/remove/edit)
 * - Real-time total calculations
 * - Notes management
 *
 * NOTE: Uses jQuery for WordPress admin compatibility. Consider Alpine.js
 * refactor if building more complex admin UIs - dashboard uses Alpine for
 * reactive state management. Select2 would need jQuery bridge regardless.
 *
 * @package stride
 */

(function($) {
    'use strict';

    var QuoteAdmin = {
        taxRate: 0.21, // 21% BTW
        itemIndex: 0,

        /**
         * Initialize the quote admin
         */
        init: function() {
            this.itemIndex = $('#stride-quote-items-body tr').length;

            this.initSelect2();
            this.initUserDataLoader();
            this.initItemManagement();
            this.initNotesManagement();
            this.initSidebarActions();
        },

        /**
         * Initialize Select2 on user dropdown
         */
        initSelect2: function() {
            if (!$.fn.select2) return;

            // User dropdown on existing quote
            if ($('#quote_user_id').length) {
                $('#quote_user_id').select2({
                    placeholder: strideQuoteAdmin.i18n.searchCustomer || 'Zoek klant...',
                    allowClear: true,
                    width: '100%',
                    minimumInputLength: 0,
                    language: {
                        noResults: function() { return strideQuoteAdmin.i18n.noResults || 'Geen resultaten gevonden'; },
                        searching: function() { return strideQuoteAdmin.i18n.searching || 'Zoeken...'; },
                        inputTooShort: function() { return strideQuoteAdmin.i18n.typeToSearch || 'Typ om te zoeken...'; }
                    }
                });
            }

            // Course dropdown on new quote form
            if ($('#quote_course_id').length) {
                $('#quote_course_id').select2({
                    placeholder: strideQuoteAdmin.i18n.searchCourse || 'Zoek cursus...',
                    allowClear: true,
                    width: '100%'
                });
            }
        },

        /**
         * Load user data when user is selected
         */
        initUserDataLoader: function() {
            var self = this;

            $('#quote_user_id').on('change', function() {
                var userId = $(this).val();

                if (!userId) {
                    self.clearBillingFields();
                    return;
                }

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'stride_get_user_data',
                        user_id: userId,
                        nonce: strideQuoteAdmin.nonce
                    },
                    success: function(response) {
                        if (response.success && response.data) {
                            self.populateBillingFields(response.data);
                        }
                    },
                    error: function() {
                        console.error('Failed to load user data');
                    }
                });
            });
        },

        /**
         * Clear billing fields
         */
        clearBillingFields: function() {
            $('#billing_email').val('');
            $('#billing_company').val('');
            $('#billing_address').val('');
            $('#billing_postal_code').val('');
            $('#billing_city').val('');
            $('#billing_vat_number').val('');
            $('#billing_gln_number').val('');
        },

        /**
         * Populate billing fields with user data
         */
        populateBillingFields: function(data) {
            $('#billing_email').val(data.email || '');
            $('#billing_company').val(data.company || '');
            $('#billing_address').val(data.address || '');
            $('#billing_postal_code').val(data.postal_code || '');
            $('#billing_city').val(data.city || '');
            $('#billing_vat_number').val(data.vat_number || '');
            $('#billing_gln_number').val(data.gln_number || '');
        },

        /**
         * Initialize line item management
         */
        initItemManagement: function() {
            var self = this;

            // Add new item row
            $('#stride-add-item').on('click', function(e) {
                e.preventDefault();
                self.addItemRow();
            });

            // Remove item row
            $(document).on('click', '.stride-remove-item', function(e) {
                e.preventDefault();
                $(this).closest('tr').remove();
                self.recalculateTotals();
            });

            // Recalculate on input change
            $(document).on('input change', '.item-qty, .item-price', function() {
                self.updateRowTotal($(this).closest('tr'));
            });

            // Recalculate button
            $('#stride-recalculate').on('click', function(e) {
                e.preventDefault();
                self.recalculateTotals();
            });
        },

        /**
         * Add a new item row
         */
        addItemRow: function() {
            var i18n = strideQuoteAdmin.i18n || {};
            var newRow = '<tr class="item-row" data-index="' + this.itemIndex + '">' +
                '<td class="description">' +
                    '<input type="text" name="items[' + this.itemIndex + '][title]" value="" class="item-title" placeholder="' + (i18n.description || 'Omschrijving') + '">' +
                    '<input type="hidden" name="items[' + this.itemIndex + '][type]" value="custom">' +
                '</td>' +
                '<td class="qty">' +
                    '<input type="number" name="items[' + this.itemIndex + '][quantity]" value="1" min="1" step="1" class="item-qty">' +
                '</td>' +
                '<td class="price">' +
                    '<input type="number" name="items[' + this.itemIndex + '][unit_price]" value="0" min="0" step="0.01" class="item-price">' +
                '</td>' +
                '<td class="total">€ 0,00</td>' +
                '<td class="actions">' +
                    '<button type="button" class="button-link stride-remove-item" title="' + (i18n.remove || 'Verwijderen') + '">' +
                        '<span class="dashicons dashicons-trash"></span>' +
                    '</button>' +
                '</td>' +
            '</tr>';

            $('#stride-quote-items-body').append(newRow);
            this.itemIndex++;
        },

        /**
         * Update a single row's total
         */
        updateRowTotal: function($row) {
            var qty = parseFloat($row.find('.item-qty').val()) || 0;
            var price = parseFloat($row.find('.item-price').val()) || 0;
            var total = qty * price;
            $row.find('td.total').text(this.formatCurrency(total));
        },

        /**
         * Recalculate all totals
         */
        recalculateTotals: function() {
            var self = this;
            var subtotal = 0;
            var discount = 0;

            $('#stride-quote-items-body tr').each(function() {
                var qty = parseFloat($(this).find('.item-qty').val()) || 0;
                var price = parseFloat($(this).find('.item-price').val()) || 0;
                var type = $(this).find('input[name*="[type]"]').val() || 'course';
                var total = qty * price;

                $(this).find('td.total').text(self.formatCurrency(total));

                if (type === 'discount') {
                    discount += Math.abs(total);
                }
                subtotal += total;
            });

            // subtotal already has discount subtracted (discount items are negative)
            var subtotalBeforeDiscount = subtotal + discount;
            var discountedSubtotal = Math.max(0, subtotal);
            var tax = discountedSubtotal * this.taxRate;
            var total = discountedSubtotal + tax;

            // Update displayed totals
            $('.stride-quote-items tfoot tr.subtotal td.amount').text(this.formatCurrency(subtotalBeforeDiscount));
            $('.stride-quote-items tfoot tr.tax td.amount').text(this.formatCurrency(tax));
            $('.stride-quote-items tfoot tr.grand-total td.amount').text(this.formatCurrency(total));

            // Update discount row if present
            if (discount > 0) {
                $('.stride-quote-items tfoot tr.discount td.amount').text('- ' + this.formatCurrency(discount));
            }

            // Update hidden fields
            $('#quote_subtotal').val(subtotalBeforeDiscount.toFixed(2));
            $('#quote_tax').val(tax.toFixed(2));
            $('#quote_total').val(total.toFixed(2));
            $('#quote_discount').val(discount.toFixed(2));
        },

        /**
         * Format amount as currency
         */
        formatCurrency: function(amount) {
            return '€ ' + amount.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        },

        /**
         * Initialize notes management
         */
        initNotesManagement: function() {
            var self = this;
            var notesData = [];

            // Parse existing notes from hidden field
            var existingNotes = $('#stride_notes_data').val();
            if (existingNotes) {
                try {
                    notesData = JSON.parse(existingNotes);
                } catch (e) {
                    notesData = [];
                }
            }

            // Add new note
            $('#stride-add-note').on('click', function(e) {
                e.preventDefault();

                var content = $('#stride-note-content').val().trim();
                if (!content) {
                    alert(strideQuoteAdmin.i18n.enterNote || 'Vul een notitie in.');
                    return;
                }

                var noteType = $('input[name="stride_note_type"]:checked').val() || 'admin';
                var currentUser = strideQuoteAdmin.currentUser || 'Admin';

                var newNote = {
                    type: noteType,
                    content: content,
                    author: currentUser,
                    date: new Date().toISOString().slice(0, 19).replace('T', ' ')
                };

                notesData.unshift(newNote);
                self.renderNotes(notesData);
                self.updateNotesData(notesData);

                // Clear input
                $('#stride-note-content').val('');
            });

            // Delete note
            $(document).on('click', '.stride-note-delete', function(e) {
                e.preventDefault();
                var index = $(this).closest('.stride-note-item').data('index');

                if (confirm(strideQuoteAdmin.i18n.confirmDelete || 'Notitie verwijderen?')) {
                    notesData[index]._deleted = true;
                    $(this).closest('.stride-note-item').fadeOut(function() {
                        $(this).remove();
                    });
                    self.updateNotesData(notesData);
                }
            });
        },

        /**
         * Render notes list
         */
        renderNotes: function(notes) {
            var $list = $('#stride-notes-list');
            $list.empty();

            if (!notes.length) {
                $list.html('<div class="stride-empty-notes">' + (strideQuoteAdmin.i18n.noNotes || 'Nog geen notities toegevoegd.') + '</div>');
                return;
            }

            var i18n = strideQuoteAdmin.i18n || {};

            notes.forEach(function(note, index) {
                if (note._deleted) return;

                var isCustomer = note.type === 'customer';
                var typeClass = isCustomer ? 'customer' : 'admin';
                var typeLabel = isCustomer ? (i18n.customer || 'Klant') : (i18n.internal || 'Intern');
                var icon = isCustomer ? 'format-quote' : 'shield';

                var html = '<div class="stride-note-item" data-index="' + index + '">' +
                    '<div class="stride-note-icon ' + typeClass + '">' +
                        '<span class="dashicons dashicons-' + icon + '"></span>' +
                    '</div>' +
                    '<div class="stride-note-body">' +
                        '<div class="stride-note-meta">' +
                            '<span class="author">' + (note.author || 'Onbekend') + '</span>' +
                            '<span class="type-badge ' + typeClass + '">' + typeLabel + '</span>' +
                            '<span class="date">' + note.date + '</span>' +
                        '</div>' +
                        '<div class="stride-note-content">' + note.content + '</div>' +
                    '</div>' +
                    '<span class="stride-note-delete dashicons dashicons-no-alt" title="' + (i18n.remove || 'Verwijderen') + '"></span>' +
                '</div>';

                $list.append(html);
            });
        },

        /**
         * Update hidden notes data field
         */
        updateNotesData: function(notes) {
            $('#stride_notes_data').val(JSON.stringify(notes));
        },

        /**
         * Initialize sidebar action buttons
         */
        initSidebarActions: function() {
            // Send quote button
            $('#stride-send-quote-btn').on('click', function(e) {
                e.preventDefault();
                var sendTo = $('#stride_send_to').val();
                if (!sendTo) {
                    alert(strideQuoteAdmin.i18n.enterEmail || 'Vul een e-mailadres in.');
                    return;
                }
                $('#stride_send_quote').val('1');
                $('#publish').click();
            });

            // Lock/Unlock buttons
            $('#stride-lock-btn, #stride-unlock-btn').on('click', function(e) {
                e.preventDefault();
                var action = $(this).attr('id') === 'stride-lock-btn' ? 'lock' : 'unlock';
                $('#stride_lock_action').val(action);
                $('#publish').click();
            });

            // Regenerate PDF button
            $('#stride-regenerate-pdf-btn').on('click', function(e) {
                e.preventDefault();
                $('#stride_regenerate_pdf').val('1');
                $('#publish').click();
            });

            // Apply voucher
            $('#stride-apply-voucher').on('click', function(e) {
                e.preventDefault();
                var code = $('#stride_voucher_code').val().trim();
                if (!code) {
                    alert(strideQuoteAdmin.i18n.enterVoucher || 'Vul een vouchercode in.');
                    return;
                }
                $('#stride_apply_voucher_action').val(code);
                $('#publish').click();
            });

            // Apply manual discount
            $('#stride-apply-discount').on('click', function(e) {
                e.preventDefault();
                var amount = parseFloat($('#stride_manual_discount').val()) || 0;
                if (amount <= 0) {
                    alert(strideQuoteAdmin.i18n.enterDiscount || 'Vul een kortingsbedrag in.');
                    return;
                }
                $('#stride_apply_discount_action').val(amount);
                $('#publish').click();
            });

            // Remove voucher
            $('#stride-remove-voucher').on('click', function(e) {
                e.preventDefault();
                if (confirm(strideQuoteAdmin.i18n.confirmRemoveDiscount || 'Korting verwijderen?')) {
                    $('input[name="stride_remove_voucher"]').val('1');
                    $('#publish').click();
                }
            });
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        // Only initialize on quote edit screens
        if ($('.stride-quote-admin').length || $('.stride-new-quote-form').length) {
            QuoteAdmin.init();
        }
    });

    // Expose for external use
    window.StrideQuoteAdmin = QuoteAdmin;

})(jQuery);
