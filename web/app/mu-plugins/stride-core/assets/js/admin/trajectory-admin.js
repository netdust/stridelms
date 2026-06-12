/**
 * Trajectory Admin Scripts
 *
 * Handles trajectory admin interactions:
 * - Mode switching (self-paced / cohort)
 * - Course builder with hybrid selection (editions + online courses)
 * - Enrollment filters
 *
 * @package stride
 */
(function($) {
    'use strict';

    var groupIndex = 0;

    $(document).ready(function() {
        initTabs();
        initModeToggle();
        initCourseSelects();
        initCourseBuilder();
        initEnrollmentFilters();
    });

    /**
     * Tab Navigation
     */
    function initTabs() {
        $('.stride-trajectory-details').on('click', '.stride-tab:not(.hidden)', function() {
            var $tab = $(this);
            var tabId = $tab.data('tab');
            var $container = $tab.closest('.stride-trajectory-details');

            // Update tab states
            $container.find('.stride-tab').removeClass('active');
            $tab.addClass('active');

            // Update content states
            $container.find('.stride-tab-content').removeClass('active');
            $container.find('.stride-tab-content[data-tab="' + tabId + '"]').addClass('active');
        });
    }

    /**
     * Mode Toggle (Cohort/Self-paced)
     */
    function initModeToggle() {
        $('#trajectory_mode').on('change', function() {
            var isCohort = $(this).val() === 'cohort';

            // Toggle visibility of mode-specific content
            $('.stride-cohort-only').toggle(isCohort);
            $('.stride-self-paced-only').toggle(!isCohort);

            // Toggle mode descriptions
            $('.mode-cohort').toggle(isCohort);
            $('.mode-self-paced').toggle(!isCohort);
        });
    }

    /**
     * Course Select2 Dropdowns (Hybrid: Editions + Online Courses)
     */
    function initCourseSelects() {
        if (!window.strideTrajectoryAdmin) {
            return;
        }

        /**
         * Format option with icon for editions vs online courses
         */
        function formatCourseOption(option) {
            if (!option.id) {
                return option.text;
            }

            var icon = 'dashicons-laptop';
            var badgeClass = 'stride-badge-online';
            var badgeText = strideTrajectoryAdmin.i18n.onlineBadge || 'Online';

            if (option.id.indexOf('edition:') === 0) {
                icon = 'dashicons-calendar-alt';
                badgeClass = 'stride-badge-edition';
                badgeText = strideTrajectoryAdmin.i18n.editionBadge || 'Editie';
            }

            var $result = $(
                '<span class="stride-select-option">' +
                '<span class="dashicons ' + icon + '" style="margin-right: 6px; color: #646970;"></span>' +
                '<span>' + escapeHtml(option.text) + '</span>' +
                '<span class="' + badgeClass + '" style="margin-left: 8px; font-size: 10px; padding: 1px 6px; border-radius: 3px;">' + badgeText + '</span>' +
                '</span>'
            );

            return $result;
        }

        /**
         * Initialize a hybrid select (editions + online courses)
         */
        function initHybridSelect($el) {
            $el.select2({
                ajax: {
                    url: strideTrajectoryAdmin.ajaxUrl,
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            action: 'stride_search_courses_editions',
                            nonce: strideTrajectoryAdmin.nonce,
                            search: params.term || ''
                        };
                    },
                    processResults: function(response) {
                        return { results: response.data.results || [] };
                    }
                },
                placeholder: strideTrajectoryAdmin.i18n.searchCourseOrEdition || 'Zoek cursus of editie...',
                minimumInputLength: 0,
                allowClear: true,
                templateResult: formatCourseOption
            });
        }

        /**
         * Initialize a simple course select (online courses only, for backwards compat)
         */
        function initSimpleSelect($el) {
            $el.select2({
                ajax: {
                    url: strideTrajectoryAdmin.ajaxUrl,
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            action: 'stride_search_courses',
                            nonce: strideTrajectoryAdmin.nonce,
                            search: params.term
                        };
                    },
                    processResults: function(response) {
                        return { results: response.data.results || [] };
                    }
                },
                placeholder: strideTrajectoryAdmin.i18n.searchCourse,
                minimumInputLength: 0,
                allowClear: true
            });
        }

        // Init existing selects
        $('.stride-course-select').each(function() {
            var $el = $(this);
            if ($el.hasClass('stride-hybrid-select')) {
                initHybridSelect($el);
            } else {
                initSimpleSelect($el);
            }
        });

        // Re-init when new groups are added
        $(document).on('stride:initCourseSelect', function(e, $el) {
            if ($el.hasClass('stride-hybrid-select')) {
                initHybridSelect($el);
            } else {
                initSimpleSelect($el);
            }
        });
    }

    /**
     * Course Builder (Required + Elective Groups)
     */
    function initCourseBuilder() {
        // Count existing groups
        groupIndex = $('.stride-elective-group').length;

        /**
         * Parse composite ID from hybrid select
         * Format: "edition:editionId:courseId" or "online:courseId"
         * Returns: { type: 'edition'|'online', courseId: number, editionId: number|null }
         */
        function parseCompositeId(compositeId) {
            if (!compositeId) return null;

            var parts = compositeId.split(':');
            var type = parts[0];

            if (type === 'edition' && parts.length >= 3) {
                return {
                    type: 'edition',
                    editionId: parseInt(parts[1], 10),
                    courseId: parseInt(parts[2], 10)
                };
            } else if (type === 'online' && parts.length >= 2) {
                return {
                    type: 'online',
                    editionId: null,
                    courseId: parseInt(parts[1], 10)
                };
            }

            // Fallback for simple course ID (backwards compatibility)
            var numericId = parseInt(compositeId, 10);
            if (!isNaN(numericId)) {
                return {
                    type: 'online',
                    editionId: null,
                    courseId: numericId
                };
            }

            return null;
        }

        /**
         * Build course item HTML with type-specific styling
         */
        function buildCourseItemHtml(parsed, title, inputName) {
            var typeClass = parsed.type === 'edition' ? 'stride-item-edition' : 'stride-item-online';
            var icon = parsed.type === 'edition' ? 'calendar-alt' : 'laptop';
            var badgeText = parsed.type === 'edition'
                ? (strideTrajectoryAdmin.i18n.editionBadge || 'Editie')
                : (strideTrajectoryAdmin.i18n.onlineBadge || 'Online');
            var badgeClass = parsed.type === 'edition' ? 'stride-badge-edition' : 'stride-badge-online';

            // Build JSON data for hidden input
            var jsonData = JSON.stringify({
                type: parsed.type,
                course_id: parsed.courseId,
                edition_id: parsed.editionId
            });

            // Build unique identifier for duplicate checking
            var uniqueId = parsed.type === 'edition'
                ? 'edition-' + parsed.editionId
                : 'online-' + parsed.courseId;

            return '<li class="stride-course-item ' + typeClass + '" data-course-id="' + parsed.courseId + '" data-edition-id="' + (parsed.editionId || '') + '" data-type="' + parsed.type + '" data-unique-id="' + uniqueId + '">' +
                '<span class="item-icon dashicons dashicons-' + icon + '"></span>' +
                '<span class="course-title">' + escapeHtml(title) + '</span>' +
                '<span class="item-badge ' + badgeClass + '">' + badgeText + '</span>' +
                '<span class="remove-course dashicons dashicons-no-alt" title="' + strideTrajectoryAdmin.i18n.remove + '"></span>' +
                '<input type="hidden" name="' + inputName + '" value=\'' + jsonData.replace(/'/g, '&#39;') + '\'>' +
                '</li>';
        }

        // Add required course
        $('#stride-add-required-btn').on('click', function() {
            var $select = $('#stride-add-required-course');
            var compositeId = $select.val();
            var title = $select.find('option:selected').text();

            if (!compositeId) return;

            var parsed = parseCompositeId(compositeId);
            if (!parsed) return;

            // Build unique identifier for duplicate checking
            var uniqueId = parsed.type === 'edition'
                ? 'edition-' + parsed.editionId
                : 'online-' + parsed.courseId;

            // Check if already added
            if ($('#stride-required-courses').find('[data-unique-id="' + uniqueId + '"]').length) {
                return;
            }

            // Remove empty state
            $('#stride-required-courses .stride-no-courses').remove();

            // Add course item
            var html = buildCourseItemHtml(parsed, title, 'ntdst_fields[courses_required][]');
            $('#stride-required-courses').append(html);
            $select.val(null).trigger('change');
        });

        // Add elective group
        $('#stride-add-group-btn').on('click', function() {
            var template = $('#stride-elective-group-template').html();
            template = template.replace(/__INDEX__/g, groupIndex);

            // Remove empty state
            $('#stride-elective-groups .stride-no-courses').remove();

            $('#stride-elective-groups').append(template);

            // Init select2 on new group
            var $newGroup = $('#stride-elective-groups .stride-elective-group').last();
            $(document).trigger('stride:initCourseSelect', [$newGroup.find('.stride-course-select')]);

            groupIndex++;
        });

        // Add course to elective group
        $(document).on('click', '.stride-add-elective-btn', function() {
            var $group = $(this).closest('.stride-elective-group');
            var $select = $group.find('.stride-elective-course-select');
            var compositeId = $select.val();
            var title = $select.find('option:selected').text();
            var groupIdx = $group.data('group-index');

            if (!compositeId) return;

            var parsed = parseCompositeId(compositeId);
            if (!parsed) return;

            // Build unique identifier for duplicate checking
            var uniqueId = parsed.type === 'edition'
                ? 'edition-' + parsed.editionId
                : 'online-' + parsed.courseId;

            // Check if already added
            if ($group.find('[data-unique-id="' + uniqueId + '"]').length) {
                return;
            }

            // Remove empty state
            $group.find('.stride-elective-course-list .stride-no-courses').remove();

            var html = buildCourseItemHtml(parsed, title, 'ntdst_fields[elective_groups][' + groupIdx + '][courses][]');
            $group.find('.stride-elective-course-list').append(html);
            $select.val(null).trigger('change');
            refreshGroupSummary($group);
        });

        // Remove course
        $(document).on('click', '.remove-course', function() {
            var $group = $(this).closest('.stride-elective-group');
            $(this).closest('.stride-course-item').remove();
            if ($group.length) {
                refreshGroupSummary($group);
            }
        });

        // Delete elective group
        $(document).on('click', '.stride-delete-group', function() {
            if (confirm(strideTrajectoryAdmin.i18n.confirmDeleteGroup)) {
                $(this).closest('.stride-elective-group').remove();
            }
        });

        // Expand group (pencil)
        $(document).on('click', '.stride-edit-group', function(e) {
            e.preventDefault();
            $(this).closest('.stride-elective-group').addClass('is-editing')
                .find('.stride-group-edit input[type="text"]').first().focus();
        });

        // Collapse group (Klaar)
        $(document).on('click', '.stride-group-done', function(e) {
            e.preventDefault();
            $(this).closest('.stride-elective-group').removeClass('is-editing');
        });

        // Live-update summary when name / pick_count change inside the edit panel
        $(document).on('change keyup', '.stride-group-edit input[name*="[name]"], .stride-group-edit input[name*="[pick_count]"]', function() {
            refreshGroupSummary($(this).closest('.stride-elective-group'));
        });

    }

    /**
     * Sync the compact summary line on an elective group with its underlying inputs + course list.
     */
    function refreshGroupSummary($group) {
        var $edit = $group.find('.stride-group-edit');
        var name = $edit.find('input[name*="[name]"]').val() || '';
        var pickCount = parseInt($edit.find('input[name*="[pick_count]"]').val(), 10) || 1;
        var courseCount = $group.find('.stride-elective-course-list .stride-course-item').length;

        var $summary = $group.find('.stride-group-summary');
        $summary.find('.stride-group-summary-label').text(name || '(Nieuwe groep)');
        var courseWord = courseCount === 1 ? 'cursus' : 'cursussen';
        $summary.find('.stride-group-summary-meta').text('Kies ' + pickCount + ' · ' + courseCount + ' ' + courseWord);
    }

    /**
     * Enrollment Filters
     */
    function initEnrollmentFilters() {
        var $table = $('#stride-enrollments-table');
        var $search = $('#stride-enrollment-search');
        var $status = $('#stride-enrollment-status-filter');

        function filterTable() {
            var search = $search.val().toLowerCase();
            var status = $status.val();

            $table.find('tbody tr').each(function() {
                var $row = $(this);
                var name = $row.data('name');
                var rowStatus = $row.data('status');

                var matchSearch = !search || name.indexOf(search) > -1;
                var matchStatus = !status || rowStatus === status;

                $row.toggle(matchSearch && matchStatus);
            });
        }

        $search.on('input', filterTable);
        $status.on('change', filterTable);
    }

    /**
     * Escape HTML to prevent XSS
     */
    function escapeHtml(text) {
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }

})(jQuery);
