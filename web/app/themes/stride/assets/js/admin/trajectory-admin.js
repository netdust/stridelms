/**
 * Trajectory Admin Scripts
 *
 * Handles trajectory admin interactions:
 * - Tab navigation
 * - Mode switching (self-paced / cohort)
 * - Course builder (required + elective groups)
 * - Edition linking
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
        initEditionSelects();
        initEnrollmentFilters();
    });

    /**
     * Tab Navigation
     */
    function initTabs() {
        $('.stride-tabs-nav .stride-tab').on('click', function(e) {
            e.preventDefault();
            var tab = $(this).data('tab');

            // Update active tab
            $('.stride-tabs-nav .stride-tab').removeClass('active');
            $(this).addClass('active');

            // Show tab content
            $('.stride-tab-content').removeClass('active');
            $('.stride-tab-content[data-tab="' + tab + '"]').addClass('active');
        });
    }

    /**
     * Mode Toggle (Cohort/Self-paced)
     */
    function initModeToggle() {
        $('#trajectory_mode').on('change', function() {
            var isCohort = $(this).val() === 'cohort';

            // Toggle visibility using CSS classes
            $('.stride-cohort-only').toggle(isCohort);
            $('.stride-self-paced-only').toggle(!isCohort);

            // Toggle mode descriptions
            $('.mode-cohort').toggle(isCohort);
            $('.mode-self-paced').toggle(!isCohort);
        });
    }

    /**
     * Course Select2 Dropdowns
     */
    function initCourseSelects() {
        function initSelect($el) {
            if (!window.strideTrajectoryAdmin) {
                return;
            }

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
            initSelect($(this));
        });

        // Re-init when new groups are added
        $(document).on('stride:initCourseSelect', function(e, $el) {
            initSelect($el);
        });
    }

    /**
     * Course Builder (Required + Elective Groups)
     */
    function initCourseBuilder() {
        // Count existing groups
        groupIndex = $('.stride-elective-group').length;

        // Add required course
        $('#stride-add-required-btn').on('click', function() {
            var $select = $('#stride-add-required-course');
            var courseId = $select.val();
            var courseTitle = $select.find('option:selected').text();

            if (!courseId) return;

            // Check if already added
            if ($('#stride-required-courses').find('[data-course-id="' + courseId + '"]').length) {
                return;
            }

            // Remove empty state
            $('#stride-required-courses .stride-no-courses').remove();

            // Add course item
            var html = '<li class="stride-course-item" data-course-id="' + courseId + '">' +
                '<span class="course-title">' + escapeHtml(courseTitle) + '</span>' +
                '<span class="remove-course dashicons dashicons-no-alt" title="' + strideTrajectoryAdmin.i18n.remove + '"></span>' +
                '<input type="hidden" name="ntdst_fields[courses_required][]" value="' + courseId + '">' +
                '</li>';

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
            var courseId = $select.val();
            var courseTitle = $select.find('option:selected').text();
            var groupIdx = $group.data('group-index');

            if (!courseId) return;

            // Check if already added
            if ($group.find('[data-course-id="' + courseId + '"]').length) {
                return;
            }

            // Remove empty state
            $group.find('.stride-elective-course-list .stride-no-courses').remove();

            var html = '<li class="stride-course-item" data-course-id="' + courseId + '">' +
                '<span class="course-title">' + escapeHtml(courseTitle) + '</span>' +
                '<span class="remove-course dashicons dashicons-no-alt" title="' + strideTrajectoryAdmin.i18n.remove + '"></span>' +
                '<input type="hidden" name="ntdst_fields[elective_groups][' + groupIdx + '][courses][]" value="' + courseId + '">' +
                '</li>';

            $group.find('.stride-elective-course-list').append(html);
            $select.val(null).trigger('change');
        });

        // Remove course
        $(document).on('click', '.remove-course', function() {
            $(this).closest('.stride-course-item').remove();
        });

        // Delete elective group
        $(document).on('click', '.stride-delete-group', function() {
            if (confirm(strideTrajectoryAdmin.i18n.confirmDeleteGroup)) {
                $(this).closest('.stride-elective-group').remove();
            }
        });
    }

    /**
     * Edition Select2 for Cohort Linking
     */
    function initEditionSelects() {
        if (!window.strideTrajectoryAdmin) {
            return;
        }

        $('.stride-edition-select').each(function() {
            var $select = $(this);
            var courseId = $select.data('course-id');

            $select.select2({
                ajax: {
                    url: strideTrajectoryAdmin.ajaxUrl,
                    dataType: 'json',
                    data: function() {
                        return {
                            action: 'stride_get_course_editions',
                            nonce: strideTrajectoryAdmin.nonce,
                            course_id: courseId
                        };
                    },
                    processResults: function(response) {
                        return { results: response.data.results || [] };
                    }
                },
                placeholder: strideTrajectoryAdmin.i18n.selectEdition,
                allowClear: true
            });
        });
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
