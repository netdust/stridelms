/**
 * Stride LMS - Frontend JavaScript
 *
 * Dashboard interactions, AJAX forms, filtering
 *
 * @package stride
 */

(function () {
    'use strict';

    /**
     * Stride namespace
     */
    window.Stride = window.Stride || {};

    /**
     * Configuration (injected via wp_localize_script)
     */
    Stride.config = window.strideConfig || {
        ajaxUrl: '/wp-admin/admin-ajax.php',
        nonce: '',
        strings: {
            saving: 'Opslaan...',
            saved: 'Opgeslagen',
            error: 'Er is een fout opgetreden',
            confirm: 'Weet je het zeker?'
        }
    };

    /**
     * Initialize all modules when DOM is ready
     */
    document.addEventListener('DOMContentLoaded', function () {
        Stride.Dashboard.init();
        Stride.Filters.init();
        Stride.Forms.init();
        Stride.Calendar.init();
    });

    /**
     * Dashboard Module
     */
    Stride.Dashboard = {
        init: function () {
            this.bindEvents();
        },

        bindEvents: function () {
            // Quick link hover effects are handled by CSS
            // Add any dashboard-specific JS here
        }
    };

    /**
     * Filter Tabs Module
     */
    Stride.Filters = {
        init: function () {
            this.bindFilterTabs();
        },

        bindFilterTabs: function () {
            var filterTabs = document.querySelectorAll('.stride-filter-tab');
            var courseGrids = document.querySelectorAll('.stride-courses-grid');

            filterTabs.forEach(function (tab) {
                tab.addEventListener('click', function (e) {
                    e.preventDefault();
                    var filter = this.getAttribute('data-filter');

                    // Update active state
                    filterTabs.forEach(function (t) {
                        t.classList.remove('active');
                    });
                    this.classList.add('active');

                    // Filter courses
                    Stride.Filters.filterCourses(filter);
                });
            });
        },

        filterCourses: function (filter) {
            var courses = document.querySelectorAll('.stride-course-card[data-type]');

            courses.forEach(function (course) {
                var parent = course.closest('[data-course-item]') || course.parentElement;
                var type = course.getAttribute('data-type');
                var status = course.getAttribute('data-status');

                var show = filter === 'all' ||
                    filter === type ||
                    filter === status;

                if (parent) {
                    parent.style.display = show ? '' : 'none';
                }
            });

            // Update empty state visibility
            this.updateEmptyState();
        },

        updateEmptyState: function () {
            var visibleCourses = document.querySelectorAll('.stride-course-card[data-type]:not([style*="display: none"])');
            var emptyState = document.querySelector('.stride-empty-state');

            if (emptyState) {
                emptyState.style.display = visibleCourses.length === 0 ? 'block' : 'none';
            }
        }
    };

    /**
     * Forms Module - AJAX form handling
     */
    Stride.Forms = {
        init: function () {
            this.bindProfileForm();
        },

        bindProfileForm: function () {
            var profileForm = document.querySelector('.stride-profile-form');
            if (!profileForm) return;

            profileForm.addEventListener('submit', function (e) {
                e.preventDefault();
                Stride.Forms.submitProfileForm(this);
            });
        },

        submitProfileForm: function (form) {
            var submitBtn = form.querySelector('[type="submit"]');
            var originalText = submitBtn.textContent;
            var formData = new FormData(form);

            // Add action and nonce
            formData.append('action', 'stride_update_profile');
            formData.append('nonce', Stride.config.nonce);

            // Show loading state
            submitBtn.disabled = true;
            submitBtn.textContent = Stride.config.strings.saving;

            fetch(Stride.config.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    if (data.success) {
                        Stride.Forms.showNotification(Stride.config.strings.saved, 'success');
                    } else {
                        Stride.Forms.showNotification(data.message || Stride.config.strings.error, 'danger');
                    }
                })
                .catch(function (error) {
                    console.error('Profile update error:', error);
                    Stride.Forms.showNotification(Stride.config.strings.error, 'danger');
                })
                .finally(function () {
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalText;
                });
        },

        showNotification: function (message, type) {
            // Use UIkit notification if available
            if (typeof UIkit !== 'undefined' && UIkit.notification) {
                UIkit.notification({
                    message: message,
                    status: type,
                    pos: 'top-right',
                    timeout: 3000
                });
            } else {
                alert(message);
            }
        }
    };

    /**
     * Calendar Module - iCal downloads
     */
    Stride.Calendar = {
        init: function () {
            this.bindIcalDownloads();
        },

        bindIcalDownloads: function () {
            var icalLinks = document.querySelectorAll('[data-ical-download]');

            icalLinks.forEach(function (link) {
                link.addEventListener('click', function (e) {
                    e.preventDefault();
                    var courseId = this.getAttribute('data-ical-download');
                    Stride.Calendar.downloadIcal(courseId);
                });
            });
        },

        downloadIcal: function (courseId) {
            var url = Stride.config.ajaxUrl + '?action=stride_download_ical&course_id=' + courseId + '&nonce=' + Stride.config.nonce;

            // Create a temporary link and click it
            var link = document.createElement('a');
            link.href = url;
            link.download = 'course-' + courseId + '.ics';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    };

    /**
     * Utility functions
     */
    Stride.Utils = {
        /**
         * Debounce function
         */
        debounce: function (func, wait) {
            var timeout;
            return function executedFunction() {
                var context = this;
                var args = arguments;
                var later = function () {
                    timeout = null;
                    func.apply(context, args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        },

        /**
         * Format date for display
         */
        formatDate: function (dateString) {
            var date = new Date(dateString);
            var options = { day: 'numeric', month: 'long', year: 'numeric' };
            return date.toLocaleDateString('nl-BE', options);
        },

        /**
         * Escape HTML
         */
        escapeHtml: function (text) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(text));
            return div.innerHTML;
        }
    };

})();
