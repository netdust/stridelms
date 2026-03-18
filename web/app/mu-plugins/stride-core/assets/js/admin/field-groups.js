/**
 * Field Groups Settings Page — Admin JS
 *
 * Two-level repeater: groups containing fields.
 * Select2 for assignment multi-select.
 */
jQuery(document).ready(function ($) {
    var $container = $('#stride-field-groups-container');
    var groupTemplate = $('#stride-group-template').html();
    var fieldTemplate = $('#stride-field-template').html();
    var groupIndex = $container.find('.stride-group-block').length;

    // Track field indices per group
    var fieldIndices = {};
    $container.find('.stride-group-block').each(function () {
        var gi = $(this).data('group-index');
        fieldIndices[gi] = $(this).find('.stride-field-row').length;
    });

    // Initialize Select2 on existing groups
    function initSelect2($el) {
        $el.select2({
            placeholder: strideFieldGroups.i18n.searchPlaceholder,
            allowClear: true,
            width: '100%',
            language: {
                noResults: function () {
                    return strideFieldGroups.i18n.noResults;
                },
            },
        });
    }

    // Rebuild Select2 options from localized data
    function buildAssignmentSelect($select, selectedValues) {
        $select.empty();
        var assignments = strideFieldGroups.assignments || [];

        assignments.forEach(function (optgroup) {
            var $optgroup = $('<optgroup>').attr('label', optgroup.label);
            (optgroup.options || []).forEach(function (opt) {
                var $option = $('<option>')
                    .val(opt.value)
                    .text(opt.label);

                // Check if selected (compare loosely for int/string)
                if (selectedValues.indexOf(String(opt.value)) !== -1 ||
                    selectedValues.indexOf(opt.value) !== -1) {
                    $option.prop('selected', true);
                }

                $optgroup.append($option);
            });
            $select.append($optgroup);
        });
    }

    // Warn when a field name matches a reserved user meta key
    var reservedFields = strideFieldGroups.userMetaFields || [];
    function checkReservedName($nameField) {
        var name = $nameField.val();
        var $row = $nameField.closest('tr');
        $row.find('.stride-meta-warning').remove();
        if (name && reservedFields.indexOf(name) !== -1) {
            $nameField.after(
                '<p class="stride-meta-warning" style="color:#b26200;margin:4px 0 0;font-size:12px;">' +
                strideFieldGroups.i18n.userMetaWarning + '</p>'
            );
        }
    }

    // Init existing Select2 instances
    $container.find('.stride-assignments-select').each(function () {
        initSelect2($(this));
    });

    // Show warnings for existing fields that match reserved names
    $container.find('.stride-field-name').each(function () {
        checkReservedName($(this));
    });

    // Add group
    $('#stride-add-group').on('click', function () {
        var html = groupTemplate.replace(/__GI__/g, groupIndex);
        // Remove any __FI__ field rows from the group template
        var $newGroup = $(html);
        $newGroup.find('.stride-field-row').remove();
        $container.append($newGroup);

        // Rebuild and init Select2 on the new group
        var $select = $newGroup.find('.stride-assignments-select');
        buildAssignmentSelect($select, []);
        initSelect2($select);

        // Init sortable on new group's fields
        $newGroup.find('.stride-fields-rows').sortable({
            handle: '.stride-drag-handle',
            placeholder: 'ui-sortable-placeholder',
        });

        fieldIndices[groupIndex] = 0;
        groupIndex++;
    });

    // Remove group
    $container.on('click', '.stride-remove-group', function () {
        if (confirm(strideFieldGroups.i18n.confirmDeleteGroup)) {
            var $group = $(this).closest('.stride-group-block');
            $group.find('.stride-assignments-select').select2('destroy');
            $group.remove();
        }
    });

    // Add field within a group
    $container.on('click', '.stride-add-field', function () {
        var $group = $(this).closest('.stride-group-block');
        var gi = $group.data('group-index');
        var fi = fieldIndices[gi] || 0;

        var html = fieldTemplate.replace(/__GI__/g, gi).replace(/__FI__/g, fi);
        $group.find('.stride-fields-rows').append(html);
        fieldIndices[gi] = fi + 1;
    });

    // Remove field row
    $container.on('click', '.stride-remove-row', function () {
        $(this).closest('tr').remove();
    });

    // Auto-generate name from label
    $container.on('blur', '.stride-field-label', function () {
        var $row = $(this).closest('tr');
        var $nameField = $row.find('.stride-field-name');
        if ($nameField.val() === '') {
            var name = $(this)
                .val()
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '_')
                .replace(/^_|_$/g, '');
            $nameField.val(name);
            checkReservedName($nameField);
        }
    });

    // Check on manual name entry
    $container.on('blur', '.stride-field-name', function () {
        checkReservedName($(this));
    });

    // Toggle options visibility based on type
    $container.on('change', '.stride-field-type', function () {
        var $row = $(this).closest('tr');
        if ($(this).val() === 'select') {
            $row.find('.stride-field-options').show();
            $row.find('.stride-options-hint').hide();
        } else {
            $row.find('.stride-field-options').hide();
            $row.find('.stride-options-hint').show();
        }
    });

    // Make groups sortable
    $container.sortable({
        handle: '> .stride-group-header .stride-drag-handle',
        placeholder: 'ui-sortable-placeholder',
        items: '.stride-group-block',
        tolerance: 'pointer',
    });

    // Make field rows sortable within each group
    $container.find('.stride-fields-rows').sortable({
        handle: '.stride-drag-handle',
        placeholder: 'ui-sortable-placeholder',
    });

    // Destroy Select2 before form submit to ensure values are sent
    $('#stride-field-groups-form').on('submit', function () {
        $container.find('.stride-assignments-select').each(function () {
            // Select2 already keeps the underlying <select> values in sync
        });
    });
});
