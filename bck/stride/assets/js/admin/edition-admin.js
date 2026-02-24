/**
 * Edition Admin JavaScript
 *
 * Handles edition admin interactions:
 * - Select2 course dropdown
 * - Inline session management (add/edit/delete via AJAX)
 * - Attendance tracking with cycling status
 * - Real-time capacity visualization
 *
 * NOTE: Uses jQuery for WordPress admin compatibility. Consider Alpine.js
 * refactor if building more complex admin UIs - dashboard uses Alpine for
 * reactive state management. Select2 would need jQuery bridge regardless.
 *
 * @package stride
 */

(function($) {
    'use strict';

    var EditionAdmin = {
        /**
         * Initialize the edition admin
         */
        init: function() {
            this.initTabs();
            this.initSelect2();
            this.initSessionManagement();
            this.initAttendanceManagement();
            this.initCapacityVisualization();
            this.initCompletionMode();
            this.initSlotManagement();
            this.initSessionTypeHandling();
            this.initNotesManagement();
        },

        // Cache for loaded lessons
        _lessonsCache: null,

        /**
         * Initialize tab navigation
         */
        initTabs: function() {
            $('.stride-tabs-nav').on('click', '.stride-tab', function(e) {
                e.preventDefault();
                var $tab = $(this);
                var tabId = $tab.data('tab');
                var $container = $tab.closest('.stride-edition-tabs');

                // Update nav
                $container.find('.stride-tab').removeClass('active');
                $tab.addClass('active');

                // Update content
                $container.find('.stride-tab-content').removeClass('active');
                $container.find('.stride-tab-content[data-tab="' + tabId + '"]').addClass('active');
            });
        },

        /**
         * Initialize Select2 on course dropdown
         */
        initSelect2: function() {
            if (!$.fn.select2) return;

            var i18n = strideEditionAdmin.i18n || {};

            $('.stride-select2-course').select2({
                placeholder: i18n.searchCourse || 'Zoek cursus...',
                allowClear: true,
                width: '100%',
                language: {
                    noResults: function() { return 'Geen resultaten gevonden'; },
                    searching: function() { return 'Zoeken...'; }
                }
            });
        },

        // ========================================
        // SESSION MANAGEMENT
        // ========================================

        /**
         * Initialize session management
         */
        initSessionManagement: function() {
            var self = this;

            // Add session button
            $('#stride-add-session-btn').on('click', function(e) {
                e.preventDefault();
                self.showSessionForm();
            });

            // Edit session
            $(document).on('click', '.stride-edit-session', function(e) {
                e.preventDefault();
                var $row = $(this).closest('.session-row');
                self.showSessionForm($row);
            });

            // Delete session
            $(document).on('click', '.stride-delete-session', function(e) {
                e.preventDefault();
                var $row = $(this).closest('.session-row');
                self.deleteSession($row);
            });

            // Save session
            $(document).on('click', '.stride-session-save', function(e) {
                e.preventDefault();
                self.saveSession();
            });

            // Cancel session form
            $(document).on('click', '.stride-session-cancel', function(e) {
                e.preventDefault();
                self.hideSessionForm();
            });
        },

        /**
         * Show the inline session form
         */
        showSessionForm: function($editRow) {
            var self = this;
            var i18n = strideEditionAdmin.i18n || {};

            // Remove any existing form
            this.hideSessionForm();

            // Get the template
            var $template = $('#stride-session-form-template');
            var $formRow = $($template.html());

            // Variables for deferred lesson loading
            var sessionType = 'in_person';
            var lessonId = '';
            var description = '';
            var webinarLink = '';
            var location = '';
            var title = '';

            // If editing, populate with existing data from data attributes
            if ($editRow && $editRow.length) {
                var sessionId = $editRow.data('session-id');
                var sessionSlot = $editRow.data('session-slot') || '';
                sessionType = $editRow.data('session-type') || 'in_person';

                // Read raw values from data attributes
                var date = $editRow.data('date') || '';
                var startTime = $editRow.data('start-time') || '';
                var endTime = $editRow.data('end-time') || '';
                location = $editRow.data('location') || '';
                description = $editRow.data('description') || '';
                webinarLink = $editRow.data('webinar-link') || '';
                title = $editRow.data('title') || '';

                // Get lesson ID (single value now, from lesson_ids)
                var rawLessonIds = $editRow.data('lesson-ids');
                if (rawLessonIds) {
                    var lessonIds = String(rawLessonIds).split(',').filter(function(id) { return id; });
                    lessonId = lessonIds.length > 0 ? lessonIds[0] : '';
                }

                // Populate common form fields
                $formRow.find('input[name="session_id"]').val(sessionId);
                $formRow.find('input[name="session_date"]').val(date);
                $formRow.find('input[name="session_start_time"]').val(startTime);
                $formRow.find('input[name="session_end_time"]').val(endTime);

                // Set slot dropdown if exists
                if (sessionSlot) {
                    $formRow.find('select[name="session_slot"]').val(sessionSlot);
                }

                // Set radio button for session type and update active class
                $formRow.find('.stride-type-option').removeClass('active');
                var $typeOption = $formRow.find('.stride-type-option[data-type="' + sessionType + '"]');
                $typeOption.addClass('active');
                $typeOption.find('input[type="radio"]').prop('checked', true);

                // Insert after the row being edited
                $editRow.after($formRow);
                $editRow.hide();
            } else {
                // Insert at the end of the table body
                var $body = $('#stride-sessions-body');

                // Remove "no sessions" row if present
                $body.find('.no-sessions-row').remove();

                // Default to today's date
                var today = new Date().toISOString().split('T')[0];
                $formRow.find('input[name="session_date"]').val(today);

                // Default times
                $formRow.find('input[name="session_start_time"]').val('09:00');
                $formRow.find('input[name="session_end_time"]').val('17:00');

                // Insert at top
                $body.prepend($formRow);
            }

            // Get the form element after insertion
            var $form = $formRow.find('.stride-session-form');

            // Update field visibility based on session type AFTER inserting into DOM
            this.updateFieldsForSessionType($form, sessionType);

            // Populate type-specific fields based on session type
            var $activePanel = $form.find('.stride-type-panel[data-for-type="' + sessionType + '"]');

            switch (sessionType) {
                case 'in_person':
                    $activePanel.find('input[name="session_title"]').val(title);
                    $activePanel.find('input[name="session_location"]').val(location);
                    $activePanel.find('textarea[name="session_description"]').val(description);
                    break;

                case 'webinar':
                    $activePanel.find('input[name="session_title"]').val(title);
                    $activePanel.find('input[name="session_webinar_link"]').val(webinarLink);
                    $activePanel.find('textarea[name="session_description"]').val(description);
                    break;

                case 'online':
                case 'assignment':
                    // Lessons will be loaded via AJAX, then selected
                    if (lessonId) {
                        // Store lessonId to select after AJAX load
                        $activePanel.data('pending-lesson-id', lessonId);
                    }
                    break;
            }

            // Focus on date field
            $formRow.find('input[name="session_date"]').focus();
        },

        /**
         * Hide the session form
         */
        hideSessionForm: function() {
            // Show any hidden edit rows
            $('#stride-sessions-body .session-row:hidden').show();

            // Remove the form row
            $('.stride-session-form-row').remove();

            // If no sessions left, show the "no sessions" message
            if ($('#stride-sessions-body .session-row').length === 0) {
                var i18n = strideEditionAdmin.i18n || {};
                $('#stride-sessions-body').html(
                    '<tr class="no-sessions-row"><td colspan="6" class="no-sessions">' +
                    (i18n.noSessions || 'Nog geen sessies toegevoegd.') +
                    '</td></tr>'
                );
            }
        },

        /**
         * Save session via AJAX
         */
        saveSession: function() {
            var self = this;
            var $form = $('.stride-session-form');
            var sessionId = $form.find('input[name="session_id"]').val();
            var isNew = !sessionId;

            // Get session type from checked radio button
            var sessionType = $form.find('input[name="session_type"]:checked').val() || 'in_person';

            // Get the visible type panel
            var $activePanel = $form.find('.stride-type-panel[data-for-type="' + sessionType + '"]');

            // Build data object with common fields
            var data = {
                action: isNew ? 'stride_add_session' : 'stride_update_session',
                nonce: strideEditionAdmin.nonce,
                edition_id: strideEditionAdmin.editionId,
                session_id: sessionId,
                date: $form.find('input[name="session_date"]').val(),
                start_time: $form.find('input[name="session_start_time"]').val(),
                end_time: $form.find('input[name="session_end_time"]').val(),
                slot: $form.find('select[name="session_slot"]').val() || '',
                session_type: sessionType
            };

            // Add type-specific fields
            switch (sessionType) {
                case 'in_person':
                    data.title = $activePanel.find('input[name="session_title"]').val() || '';
                    data.location = $activePanel.find('input[name="session_location"]').val() || '';
                    data.description = $activePanel.find('textarea[name="session_description"]').val() || '';
                    break;

                case 'webinar':
                    data.title = $activePanel.find('input[name="session_title"]').val() || '';
                    data.webinar_link = $activePanel.find('input[name="session_webinar_link"]').val() || '';
                    data.description = $activePanel.find('textarea[name="session_description"]').val() || '';
                    break;

                case 'online':
                case 'assignment':
                    data.lesson_id = $activePanel.find('select[name="session_lesson_id"]').val() || '';
                    break;
            }

            // Disable save button
            $form.find('.stride-session-save').prop('disabled', true);

            $.ajax({
                url: strideEditionAdmin.ajaxurl,
                type: 'POST',
                data: data,
                success: function(response) {
                    if (response.success) {
                        self.refreshSessionsTable(response.data.html);
                    } else {
                        alert(response.data.message || strideEditionAdmin.i18n.error);
                        $form.find('.stride-session-save').prop('disabled', false);
                    }
                },
                error: function() {
                    alert(strideEditionAdmin.i18n.error);
                    $form.find('.stride-session-save').prop('disabled', false);
                }
            });
        },

        /**
         * Delete session via AJAX
         */
        deleteSession: function($row) {
            var self = this;
            var sessionId = $row.data('session-id');
            var i18n = strideEditionAdmin.i18n || {};

            if (!confirm(i18n.confirmDelete || 'Weet je zeker dat je deze sessie wilt verwijderen?')) {
                return;
            }

            $.ajax({
                url: strideEditionAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'stride_delete_session',
                    nonce: strideEditionAdmin.nonce,
                    session_id: sessionId
                },
                success: function(response) {
                    if (response.success) {
                        self.refreshSessionsTable(response.data.html);
                    } else {
                        alert(response.data.message || i18n.error);
                    }
                },
                error: function() {
                    alert(i18n.error);
                }
            });
        },

        /**
         * Refresh the sessions table with new HTML
         */
        refreshSessionsTable: function(html) {
            var $body = $('#stride-sessions-body');

            if (html) {
                $body.html(html);
            } else {
                var i18n = strideEditionAdmin.i18n || {};
                $body.html(
                    '<tr class="no-sessions-row"><td colspan="6" class="no-sessions">' +
                    (i18n.noSessions || 'Nog geen sessies toegevoegd.') +
                    '</td></tr>'
                );
            }
        },

        /**
         * Parse displayed date back to YYYY-MM-DD format
         */
        parseDateFromDisplay: function(dateStr) {
            // Map Dutch month abbreviations
            var months = {
                'jan': '01', 'feb': '02', 'mrt': '03', 'apr': '04',
                'mei': '05', 'jun': '06', 'jul': '07', 'aug': '08',
                'sep': '09', 'okt': '10', 'nov': '11', 'dec': '12'
            };

            // Format: "01 jan 2025" or similar
            var parts = dateStr.toLowerCase().split(' ');
            if (parts.length >= 3) {
                var day = parts[0].padStart(2, '0');
                var month = months[parts[1]] || '01';
                var year = parts[2];
                return year + '-' + month + '-' + day;
            }

            return dateStr;
        },

        // ========================================
        // ATTENDANCE MANAGEMENT
        // ========================================

        /**
         * Initialize attendance management
         */
        initAttendanceManagement: function() {
            var self = this;

            // Toggle attendance status (click on button in table cell)
            $(document).on('click', '.stride-attendance-toggle', function(e) {
                e.preventDefault();
                self.cycleAttendanceStatus($(this));
            });

            // Mark all present for a session column
            $(document).on('click', '.stride-mark-all-present', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var sessionId = $(this).closest('th').data('session-id');
                self.markAllPresent(sessionId);
            });
        },

        /**
         * Cycle attendance status: unmarked -> present -> absent -> excused -> present
         */
        cycleAttendanceStatus: function($button) {
            var self = this;

            // Prevent race condition - ignore clicks while processing
            if ($button.hasClass('processing')) {
                return;
            }

            var sessionId = $button.data('session-id');
            var userId = $button.data('user-id');

            // Determine next status
            var currentStatus = this.getAttendanceStatus($button);
            var nextStatus = this.getNextAttendanceStatus(currentStatus);

            // Mark as processing and optimistically update UI
            $button.addClass('processing');
            $button.removeClass('unmarked present absent excused').addClass(nextStatus);

            // Send to server
            $.ajax({
                url: strideEditionAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'stride_mark_attendance',
                    nonce: strideEditionAdmin.nonce,
                    session_id: sessionId,
                    user_id: userId,
                    status: nextStatus
                },
                success: function(response) {
                    $button.removeClass('processing');
                    if (response.success) {
                        self.updateSessionTotals(sessionId, response.data);
                    } else {
                        // Revert on error
                        $button.removeClass('unmarked present absent excused').addClass(currentStatus);
                        alert(response.data.message || strideEditionAdmin.i18n.error);
                    }
                },
                error: function() {
                    // Revert on error
                    $button.removeClass('processing unmarked present absent excused').addClass(currentStatus);
                    alert(strideEditionAdmin.i18n.error);
                }
            });
        },

        /**
         * Get current attendance status from button
         */
        getAttendanceStatus: function($button) {
            if ($button.hasClass('present')) return 'present';
            if ($button.hasClass('absent')) return 'absent';
            if ($button.hasClass('excused')) return 'excused';
            return 'unmarked';
        },

        /**
         * Get next status in the cycle
         */
        getNextAttendanceStatus: function(current) {
            var cycle = {
                'unmarked': 'present',
                'present': 'absent',
                'absent': 'excused',
                'excused': 'present'
            };
            return cycle[current] || 'present';
        },

        /**
         * Mark all users as present for a session
         */
        markAllPresent: function(sessionId) {
            var self = this;

            // Find all buttons for this session
            var $buttons = $('.stride-attendance-toggle[data-session-id="' + sessionId + '"]');

            // Optimistically update UI
            $buttons.removeClass('unmarked absent excused').addClass('present');

            $.ajax({
                url: strideEditionAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'stride_bulk_attendance',
                    nonce: strideEditionAdmin.nonce,
                    session_id: sessionId
                },
                success: function(response) {
                    if (response.success) {
                        self.updateSessionTotals(sessionId, response.data);
                    } else {
                        alert(response.data.message || strideEditionAdmin.i18n.error);
                    }
                },
                error: function() {
                    alert(strideEditionAdmin.i18n.error);
                }
            });
        },

        /**
         * Update session totals in the table footer
         */
        updateSessionTotals: function(sessionId, data) {
            var $cell = $('.stride-attendance-table tfoot .totals-cell[data-session-id="' + sessionId + '"]');
            $cell.find('.attendance-count').text(data.presentCount + '/' + data.totalCount);
        },

        // ========================================
        // CAPACITY VISUALIZATION
        // ========================================

        /**
         * Initialize capacity visualization
         */
        initCapacityVisualization: function() {
            var self = this;

            $('#edition_capacity').on('change input', function() {
                self.updateCapacityBar();
            });

            // Initial update
            this.updateCapacityBar();
        },

        /**
         * Update capacity bar visualization
         */
        updateCapacityBar: function() {
            var capacity = parseInt($('#edition_capacity').val()) || 0;
            var $bar = $('.stride-capacity-bar');
            var $fill = $('.stride-capacity-fill');

            if (capacity <= 0) {
                $bar.hide();
                return;
            }

            $bar.show();

            // Get current registered count from the displayed text
            var $text = $('.stride-capacity-text');
            var currentText = $text.text();
            var match = currentText.match(/^(\d+)/);
            var registered = match ? parseInt(match[1]) : 0;

            var percentage = Math.min(100, Math.round((registered / capacity) * 100));

            $fill.css('width', percentage + '%');

            // Update bar color based on fill level
            $bar.removeClass('warning full');
            if (percentage >= 100) {
                $bar.addClass('full');
            } else if (percentage >= 80) {
                $bar.addClass('warning');
            }
        },

        // ========================================
        // COMPLETION MODE
        // ========================================

        /**
         * Initialize completion mode toggle
         */
        initCompletionMode: function() {
            var $mode = $('.stride-completion-mode');
            var $threshold = $('.stride-completion-threshold');
            var $unit = $threshold.find('.threshold-unit');

            function updateThresholdUnit() {
                var mode = $mode.val();
                if (mode === 'attend_percentage') {
                    $unit.text('%');
                    $threshold.show();
                } else if (mode === 'attend_count') {
                    $unit.text('');
                    $threshold.show();
                } else {
                    $threshold.hide();
                }
            }

            $mode.on('change', updateThresholdUnit);
            updateThresholdUnit();
        },

        // ========================================
        // SLOT MANAGEMENT
        // ========================================

        /**
         * Initialize slot management for session selection configuration
         */
        initSlotManagement: function() {
            var self = this;

            // Add slot button
            $('#stride-add-slot-btn').on('click', function(e) {
                e.preventDefault();
                self.addSlotRow();
            });

            // Remove slot button (delegated)
            $(document).on('click', '.stride-remove-slot', function(e) {
                e.preventDefault();
                self.removeSlotRow($(this));
            });

            // Update session slot dropdown when slot fields change
            $(document).on('change keyup', '#stride-session-slots-list input[name*="[slot]"], #stride-session-slots-list input[name*="[label]"]', function() {
                self.updateSessionSlotDropdown();
            });
        },

        /**
         * Add a new slot row
         */
        addSlotRow: function() {
            var self = this;
            var $template = $('#stride-slot-row-template');
            if (!$template.length) return;

            var $list = $('#stride-session-slots-list');
            var newIndex = $list.find('.stride-slot-row').length;

            // Get template HTML and replace index placeholder
            var html = $template.html().replace(/__INDEX__/g, newIndex);
            var $newRow = $(html);

            // Append to list
            $list.append($newRow);

            // Focus on the first input
            $newRow.find('input[type="text"]').first().focus();

            // Update session slot dropdown
            self.updateSessionSlotDropdown();
        },

        /**
         * Remove a slot row
         */
        removeSlotRow: function($button) {
            var $row = $button.closest('.stride-slot-row');
            $row.remove();

            // Re-index remaining rows
            this.reindexSlotRows();
        },

        /**
         * Re-index slot rows after removal
         */
        reindexSlotRows: function() {
            $('#stride-session-slots-list .stride-slot-row').each(function(index) {
                var $row = $(this);
                $row.attr('data-slot-index', index);

                // Update all input names
                $row.find('input, select').each(function() {
                    var name = $(this).attr('name');
                    if (name) {
                        var newName = name.replace(/\[\d+\]/, '[' + index + ']');
                        $(this).attr('name', newName);
                    }
                });
            });

            // Update the slot dropdown in session form template
            this.updateSessionSlotDropdown();
        },

        /**
         * Update the slot dropdown options based on current configuration
         */
        updateSessionSlotDropdown: function() {
            var $select = $('#stride-session-slot-select');
            if (!$select.length) return;

            var i18n = strideEditionAdmin.i18n || {};
            var currentValue = $select.val();

            // Clear all options except the first (no slot)
            $select.find('option:not(:first)').remove();

            // Add options from current slot configuration
            $('#stride-session-slots-list .stride-slot-row').each(function() {
                var slotId = $(this).find('input[name*="[slot]"]').val();
                var slotLabel = $(this).find('input[name*="[label]"]').val();

                if (slotId) {
                    $select.append(
                        $('<option></option>')
                            .val(slotId)
                            .text(slotLabel || slotId)
                    );
                }
            });

            // Restore previous value if still valid
            if (currentValue && $select.find('option[value="' + currentValue + '"]').length) {
                $select.val(currentValue);
            }
        },

        // ========================================
        // NOTES MANAGEMENT
        // ========================================

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
                    var i18n = strideEditionAdmin.i18n || {};
                    alert(i18n.enterNote || 'Vul een notitie in.');
                    return;
                }

                var type = $('input[name="stride_note_type"]:checked').val() || 'userinfo';
                var currentUser = strideEditionAdmin.currentUser || 'Admin';

                var newNote = {
                    type: type,
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

            // Delete note (delegated)
            $(document).on('click', '.stride-note-delete', function(e) {
                e.preventDefault();
                var index = $(this).closest('.stride-note-item').data('index');
                var i18n = strideEditionAdmin.i18n || {};

                if (confirm(i18n.confirmDelete || 'Notitie verwijderen?')) {
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
            var self = this;
            var $list = $('#stride-notes-list');
            $list.empty();

            var i18n = strideEditionAdmin.i18n || {};

            if (!notes.length) {
                $list.html('<div class="stride-empty-notes">' + (i18n.noNotes || 'Nog geen notities toegevoegd.') + '</div>');
                return;
            }

            var noteTypes = {
                'todo': { label: i18n.todo || 'Todo', icon: 'yes-alt' },
                'email': { label: i18n.email || 'E-mail', icon: 'email' },
                'userinfo': { label: i18n.userinfo || 'Info', icon: 'info-outline' }
            };

            notes.forEach(function(note, index) {
                if (note._deleted) return;

                var type = note.type || 'userinfo';
                var typeConfig = noteTypes[type] || noteTypes['userinfo'];

                // Escape all user-provided data to prevent XSS
                var html = '<div class="stride-note-item" data-index="' + index + '">' +
                    '<div class="stride-note-icon ' + self.escapeHtml(type) + '">' +
                        '<span class="dashicons dashicons-' + self.escapeHtml(typeConfig.icon) + '"></span>' +
                    '</div>' +
                    '<div class="stride-note-body">' +
                        '<div class="stride-note-meta">' +
                            '<span class="author">' + self.escapeHtml(note.author || 'Onbekend') + '</span>' +
                            '<span class="type-badge ' + self.escapeHtml(type) + '">' + self.escapeHtml(typeConfig.label) + '</span>' +
                            '<span class="date">' + self.escapeHtml(note.date || '') + '</span>' +
                        '</div>' +
                        '<div class="stride-note-content">' + self.escapeHtml(note.content) + '</div>' +
                    '</div>' +
                    '<span class="stride-note-delete dashicons dashicons-no-alt" title="' + self.escapeHtml(i18n.remove || 'Verwijderen') + '"></span>' +
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
         * Escape HTML to prevent XSS
         */
        escapeHtml: function(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        // ========================================
        // SESSION TYPE HANDLING (HYBRID SESSIONS)
        // ========================================

        /**
         * Initialize session type handling for hybrid sessions
         */
        initSessionTypeHandling: function() {
            var self = this;

            // Radio button card click handler
            $(document).on('click', '.stride-type-option', function() {
                var $option = $(this);
                var $form = $option.closest('.stride-session-form');
                var type = $option.data('type');

                // Update active state on cards
                $form.find('.stride-type-option').removeClass('active');
                $option.addClass('active');

                // Check the radio button
                $option.find('input[type="radio"]').prop('checked', true);

                // Update field visibility
                self.updateFieldsForSessionType($form, type);
            });

            // Also handle direct radio button changes (for accessibility)
            $(document).on('change', '.stride-type-option input[type="radio"]', function() {
                var $radio = $(this);
                var $option = $radio.closest('.stride-type-option');
                var $form = $radio.closest('.stride-session-form');
                var type = $option.data('type');

                // Update active state on cards
                $form.find('.stride-type-option').removeClass('active');
                $option.addClass('active');

                // Update field visibility
                self.updateFieldsForSessionType($form, type);
            });
        },

        /**
         * Update form fields visibility based on session type
         */
        updateFieldsForSessionType: function($form, type) {
            var self = this;

            // Hide all type-specific panels
            $form.find('.stride-type-panel').hide();

            // Show the panel for the selected type
            var $panel = $form.find('.stride-type-panel[data-for-type="' + type + '"]');
            $panel.show();

            // Load lessons for online/assignment types
            if (type === 'online' || type === 'assignment') {
                self.loadLessonsForSelect($form, type);
            }
        },

        /**
         * Load lessons from the edition's linked course
         */
        loadLessonsForSelect: function($form, type, selectedId) {
            var self = this;

            // Find the correct select based on type
            var $panel = $form.find('.stride-type-panel[data-for-type="' + type + '"]');
            var $select = $panel.find('select[name="session_lesson_id"]');

            if (!$select.length) return;

            // Show loading state
            $select.prop('disabled', true);

            // Use cached lessons if available
            if (self._lessonsCache) {
                self.populateLessonSelect($select, self._lessonsCache, selectedId, type);
                return;
            }

            $.ajax({
                url: strideEditionAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'stride_get_course_lessons',
                    nonce: strideEditionAdmin.nonce,
                    edition_id: strideEditionAdmin.editionId,
                    include_quizzes: type === 'assignment' ? 1 : 0
                },
                success: function(response) {
                    if (response.success && response.data.lessons) {
                        self._lessonsCache = response.data.lessons;
                        self.populateLessonSelect($select, response.data.lessons, selectedId, type);
                    } else {
                        $select.prop('disabled', false);
                    }
                },
                error: function() {
                    $select.prop('disabled', false);
                }
            });
        },

        /**
         * Populate lesson select with options (single-select)
         */
        populateLessonSelect: function($select, lessons, selectedId, type) {
            // Destroy Select2 if already initialized
            if ($select.data('select2')) {
                $select.select2('destroy');
            }

            $select.empty();

            var i18n = strideEditionAdmin.i18n || {};
            var placeholder = type === 'assignment'
                ? (i18n.selectLessonOrQuiz || 'Selecteer les of quiz...')
                : (i18n.selectLesson || 'Selecteer een les...');

            // Check for pending lesson selection from panel data
            var $panel = $select.closest('.stride-type-panel');
            var pendingLessonId = $panel.data('pending-lesson-id');
            if (pendingLessonId && !selectedId) {
                selectedId = pendingLessonId;
                $panel.removeData('pending-lesson-id');
            }

            // Add placeholder option
            $select.append('<option value="">' + placeholder + '</option>');

            if (lessons.length === 0) {
                $select.append('<option value="" disabled>' + (i18n.noLessonsAvailable || 'Geen lessen beschikbaar') + '</option>');
            } else {
                lessons.forEach(function(lesson) {
                    var lessonId = parseInt(lesson.id, 10);
                    var $option = $('<option></option>')
                        .val(lessonId)
                        .text(lesson.title);

                    if (selectedId && parseInt(selectedId, 10) === lessonId) {
                        $option.prop('selected', true);
                    }

                    $select.append($option);
                });
            }

            $select.prop('disabled', false);

            // Initialize Select2 (single-select)
            if ($.fn.select2) {
                $select.select2({
                    placeholder: placeholder,
                    allowClear: true,
                    width: '100%',
                    dropdownParent: $select.closest('.stride-type-panel')
                });
            }
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        // Only initialize on edition edit screens
        if ($('.stride-edition-admin').length || $('.stride-sessions-admin').length) {
            EditionAdmin.init();
        }
    });

    // Expose for external use
    window.StrideEditionAdmin = EditionAdmin;

})(jQuery);
