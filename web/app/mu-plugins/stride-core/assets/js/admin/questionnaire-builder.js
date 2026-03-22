/**
 * Questionnaire Builder — Admin JS
 *
 * Card-based two-level repeater: collapsible groups containing field cards.
 * Select2 for assignment multi-select; jQuery UI Sortable for drag-and-drop ordering.
 */
jQuery(document).ready(function ($) {
    var $container    = $('#stride-questionnaire-container');
    var groupTemplate = $('#stride-group-template').html();
    var cardTemplate  = $('#stride-field-card-template').html();

    // Stage badge colors (must mirror PHP renderGroup)
    var stageBadgeColors = {
        interest:            '#0d7a3e',
        enrollment_personal: '#2271b1',
        enrollment_billing:  '#135e96',
        intake:              '#6c3483',
        evaluation:          '#8c5e10',
    };

    // ── Index counters ────────────────────────────────────────────────────────

    // Start group counter from the highest existing index + 1
    var groupIndex = 0;
    $container.find('.stride-group-block').each(function () {
        var gi = parseInt($(this).data('group-index'), 10);
        if (!isNaN(gi) && gi >= groupIndex) {
            groupIndex = gi + 1;
        }
    });

    // Track field index per group
    var fieldIndices = {};
    $container.find('.stride-group-block').each(function () {
        var gi = $(this).data('group-index');
        var maxFi = -1;
        $(this).find('.stride-field-card').each(function () {
            var fi = parseInt($(this).data('field-index'), 10);
            if (!isNaN(fi) && fi > maxFi) {
                maxFi = fi;
            }
        });
        fieldIndices[gi] = maxFi + 1;
    });

    // ── Select2 helpers ───────────────────────────────────────────────────────

    function initSelect2($el) {
        $el.select2({
            placeholder: strideQuestionnaire.i18n.searchPlaceholder,
            allowClear:  true,
            width:       '100%',
            language: {
                noResults: function () {
                    return strideQuestionnaire.i18n.noResults;
                },
            },
        });
    }

    function buildAssignmentSelect($select, selectedValues) {
        $select.empty();
        var assignments = strideQuestionnaire.assignments || [];

        assignments.forEach(function (optgroup) {
            var $optgroup = $('<optgroup>').attr('label', optgroup.label);
            (optgroup.options || []).forEach(function (opt) {
                var $option = $('<option>').val(opt.value).text(opt.label);
                if (
                    selectedValues.indexOf(String(opt.value)) !== -1 ||
                    selectedValues.indexOf(opt.value) !== -1
                ) {
                    $option.prop('selected', true);
                }
                $optgroup.append($option);
            });
            $select.append($optgroup);
        });
    }

    // Init Select2 on all existing assignment selects
    $container.find('.stride-assignments-select').each(function () {
        initSelect2($(this));
    });

    // ── Reserved field name warning ───────────────────────────────────────────

    var reservedFields = strideQuestionnaire.userMetaFields || [];

    function checkReservedName($nameInput) {
        var name = $nameInput.val();
        $nameInput.siblings('.stride-meta-warning').remove();
        if (name && reservedFields.indexOf(name) !== -1) {
            $nameInput.after(
                '<p class="stride-meta-warning" style="color:#b26200;margin:4px 0 0;font-size:12px;">' +
                strideQuestionnaire.i18n.userMetaWarning + '</p>'
            );
        }
    }

    // Init warnings for existing fields
    $container.find('.stride-field-name-input').each(function () {
        checkReservedName($(this));
    });

    // ── Type-specific row visibility ──────────────────────────────────────────

    function applyTypeVisibility($card, type) {
        $card.find('.stride-field-config-row').each(function () {
            var showFor = $(this).data('show-for') || '';
            var types   = showFor.split(' ');
            if (types.indexOf(type) !== -1) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    }

    // Apply visibility for all existing cards
    $container.find('.stride-field-card').each(function () {
        var type = $(this).data('type') || 'text';
        applyTypeVisibility($(this), type);
    });

    // ── Header preview helpers ────────────────────────────────────────────────

    function updateGroupHeader($group) {
        var label       = $group.find('.stride-group-label-input').val();
        var stage       = $group.find('.stride-stage-select').val();
        var stageLabel  = (strideQuestionnaire.stages || {})[stage] || stage;
        var stageColor  = stageBadgeColors[stage] || '#666';
        var assignCount = $group.find('.stride-assignments-select').val();
        var count       = (assignCount && assignCount.length) ? assignCount.length : 0;

        $group.find('.stride-group-label-preview').text(label || 'Nieuwe groep');
        $group.find('.stride-stage-badge')
            .text(stageLabel)
            .css('background-color', stageColor)
            .attr('data-stage', stage);

        if (count > 0) {
            $group.find('.stride-assignment-count').text(count + (count === 1 ? ' toewijzing' : ' toewijzingen'));
        } else {
            $group.find('.stride-assignment-count').text('Niet toegewezen');
        }
    }

    // ── Group collapse / expand ───────────────────────────────────────────────

    $container.on('click', '.stride-group-toggle', function (e) {
        e.stopPropagation();
        var $group = $(this).closest('.stride-group-block');
        var $body  = $group.find('.stride-group-body');

        if ($group.hasClass('is-collapsed')) {
            $group.removeClass('is-collapsed');
            $body.show();
            $(this).html('&#9660;'); // ▾
        } else {
            $group.addClass('is-collapsed');
            $body.hide();
            $(this).html('&#9658;'); // ▸
        }
    });

    // ── Field card collapse / expand ─────────────────────────────────────────

    $container.on('click', '.stride-field-card-header', function (e) {
        // Don't trigger when clicking the remove button inside the header
        if ($(e.target).closest('.stride-remove-field').length) {
            return;
        }
        var $card = $(this).closest('.stride-field-card');
        var $body = $card.find('.stride-field-card-body');

        if ($card.hasClass('is-collapsed')) {
            $card.removeClass('is-collapsed');
            $body.show();
        } else {
            $card.addClass('is-collapsed');
            $body.hide();
        }
    });

    // ── Add group ─────────────────────────────────────────────────────────────

    $('#stride-add-group').on('click', function () {
        var html     = groupTemplate.replace(/__GI__/g, String(groupIndex));
        var $newGroup = $(html);

        // New group starts expanded
        $newGroup.removeClass('is-collapsed');
        $newGroup.find('.stride-group-body').show();
        $newGroup.find('.stride-group-toggle').html('&#9660;');

        // Remove any stale field cards from the template
        $newGroup.find('.stride-field-card').remove();

        $container.append($newGroup);

        // Rebuild and init Select2
        var $select = $newGroup.find('.stride-assignments-select');
        buildAssignmentSelect($select, []);
        initSelect2($select);

        // Sortable for field cards in this new group
        $newGroup.find('.stride-field-cards').sortable({
            handle:      '.stride-drag-handle',
            placeholder: 'ui-sortable-placeholder',
        });

        fieldIndices[groupIndex] = 0;
        groupIndex++;
    });

    // ── Remove group ──────────────────────────────────────────────────────────

    $container.on('click', '.stride-remove-group', function () {
        if (confirm(strideQuestionnaire.i18n.confirmDeleteGroup)) {
            var $group = $(this).closest('.stride-group-block');
            $group.find('.stride-assignments-select').select2('destroy');
            $group.remove();
        }
    });

    // ── Type picker toggle ────────────────────────────────────────────────────

    $container.on('click', '.stride-add-field-btn', function () {
        var $wrap   = $(this).closest('.stride-add-field-wrap');
        var $picker = $wrap.find('.stride-type-picker');
        $picker.toggle();
    });

    // ── Add field card ────────────────────────────────────────────────────────

    $container.on('click', '.stride-type-pick-btn', function () {
        var type    = $(this).data('type');
        var $group  = $(this).closest('.stride-group-block');
        var gi      = $group.data('group-index');
        var fi      = fieldIndices[gi] || 0;

        // Hide the type picker
        $(this).closest('.stride-type-picker').hide();

        // Build card HTML from template
        var html = cardTemplate
            .replace(/__GI__/g, String(gi))
            .replace(/__FI__/g, String(fi));

        var $card = $(html);

        // Set the correct type on the card and its hidden input
        $card.attr('data-type', type);
        $card.find('.stride-field-type-input').val(type);

        // Update the type pill
        var typeDef = (strideQuestionnaire.fieldTypes || {})[type] || {};
        $card.find('.stride-type-pill')
            .text(typeDef.label || type)
            .css('background-color', typeDef.color || '#666')
            .attr('data-type', type);

        // New cards start expanded
        $card.removeClass('is-collapsed');
        $card.find('.stride-field-card-body').show();

        // Apply row visibility for this type
        applyTypeVisibility($card, type);

        $group.find('.stride-field-cards').append($card);

        fieldIndices[gi] = fi + 1;
    });

    // ── Remove field card ─────────────────────────────────────────────────────

    $container.on('click', '.stride-remove-field', function (e) {
        e.stopPropagation(); // prevent card toggle
        $(this).closest('.stride-field-card').remove();
    });

    // ── Auto-generate name from label ─────────────────────────────────────────

    $container.on('blur', '.stride-field-label-text', function () {
        var $card      = $(this).closest('.stride-field-card');
        var $nameInput = $card.find('.stride-field-name-input');

        // Update header preview
        var labelVal = $(this).val();
        $card.find('.stride-field-label-preview').text(labelVal || 'Nieuw veld');

        if ($nameInput.length && $nameInput.val() === '') {
            var slug = labelVal
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '_')
                .replace(/^_|_$/g, '');
            $nameInput.val(slug);
            checkReservedName($nameInput);
        }
    });

    // ── Validate name on manual entry ─────────────────────────────────────────

    $container.on('blur', '.stride-field-name-input', function () {
        checkReservedName($(this));
    });

    // ── Header preview — live updates ─────────────────────────────────────────

    $container.on('input', '.stride-group-label-input', function () {
        var $group = $(this).closest('.stride-group-block');
        var label  = $(this).val();
        $group.find('.stride-group-label-preview').text(label || 'Nieuwe groep');
    });

    $container.on('change', '.stride-stage-select', function () {
        updateGroupHeader($(this).closest('.stride-group-block'));
    });

    // Select2 fires a custom change event on the underlying <select>
    $container.on('change', '.stride-assignments-select', function () {
        updateGroupHeader($(this).closest('.stride-group-block'));
    });

    // ── Sortable ──────────────────────────────────────────────────────────────

    // Groups sortable
    $container.sortable({
        handle:      '> .stride-group-header .stride-drag-handle',
        placeholder: 'ui-sortable-placeholder',
        items:       '.stride-group-block',
        tolerance:   'pointer',
    });

    // Field cards sortable within each existing group
    $container.find('.stride-field-cards').sortable({
        handle:      '.stride-drag-handle',
        placeholder: 'ui-sortable-placeholder',
    });
});
