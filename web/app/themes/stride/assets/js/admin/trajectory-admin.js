/**
 * Trajectory Admin JavaScript
 *
 * Handles trajectory admin interactions:
 * - Tab navigation
 * - Mode switching (self-paced / cohort)
 * - Conditional field visibility based on mode
 *
 * @package stride
 */

(function($) {
    'use strict';

    var TrajectoryAdmin = {
        /**
         * Initialize the trajectory admin
         */
        init: function() {
            this.initTabs();
            this.initModeSwitch();
        },

        /**
         * Initialize tab navigation
         */
        initTabs: function() {
            $('.stride-trajectory-tabs').on('click', '.stride-tab:not(.hidden)', function(e) {
                e.preventDefault();
                var $tab = $(this);
                var tabId = $tab.data('tab');
                var $container = $tab.closest('.stride-trajectory-tabs');

                // Update nav
                $container.find('.stride-tab').removeClass('active');
                $tab.addClass('active');

                // Update content
                $container.find('.stride-tab-content').removeClass('active');
                $container.find('.stride-tab-content[data-tab="' + tabId + '"]').addClass('active');
            });
        },

        /**
         * Initialize mode switching
         */
        initModeSwitch: function() {
            var self = this;

            $('#trajectory_mode').on('change', function() {
                self.updateModeVisibility($(this).val());
            });

            // Initialize on page load
            this.updateModeVisibility($('#trajectory_mode').val());
        },

        /**
         * Update visibility of mode-specific elements
         *
         * @param {string} mode - Current mode (self_paced or cohort)
         */
        updateModeVisibility: function(mode) {
            var isCohort = mode === 'cohort';

            // Toggle cohort-only elements
            $('.stride-cohort-only').toggleClass('hidden', !isCohort);
            $('.stride-cohort-only-tab').toggleClass('hidden', !isCohort);

            // Toggle self-paced-only elements
            $('.stride-self-paced-only').toggleClass('hidden', isCohort);

            // Toggle mode descriptions
            $('.mode-description.mode-self-paced').toggleClass('hidden', isCohort);
            $('.mode-description.mode-cohort').toggleClass('hidden', !isCohort);

            // If cohort tab was active but mode changed to self-paced,
            // switch back to general tab
            if (!isCohort && $('.stride-tab.active').data('tab') === 'cohort') {
                $('.stride-tab[data-tab="algemeen"]').trigger('click');
            }
        }
    };

    /**
     * Initialize when DOM is ready
     */
    $(document).ready(function() {
        TrajectoryAdmin.init();
    });

})(jQuery);
