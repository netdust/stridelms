/**
 * Stride LMS - Core JavaScript
 *
 * Namespace setup and shared utilities.
 * Module-specific code is in shell.js
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
     * Utility: Simple AJAX helper
     *
     * @param {string} action - WordPress AJAX action name
     * @param {object} data - Data to send
     * @returns {Promise}
     */
    Stride.ajax = function (action, data) {
        var formData = new FormData();
        formData.append('action', action);
        formData.append('nonce', Stride.config.nonce);

        Object.keys(data || {}).forEach(function (key) {
            formData.append(key, data[key]);
        });

        return fetch(Stride.config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        }).then(function (response) {
            return response.json();
        });
    };

    /**
     * NTDST API Client
     *
     * Provides a clean interface for AJAX calls with automatic error handling.
     * Uses WordPress AJAX endpoint (admin-ajax.php) for compatibility.
     *
     * Usage:
     *   const result = await ntdstAPI.call('stride_validate_voucher', { code: 'ABC123' });
     *
     * On success: Returns the data object from the response
     * On error: Throws an error with the message from the server
     */
    window.ntdstAPI = {
        /**
         * Call an AJAX action
         *
         * @param {string} action - The AJAX action name
         * @param {object} params - Parameters to send
         * @returns {Promise<object>} - Resolves with data on success, rejects with error on failure
         */
        call: async function(action, params) {
            params = params || {};

            // Use nonce from params if provided, otherwise use global config
            var nonce = params.nonce || Stride.config.nonce;

            var formData = new FormData();
            formData.append('action', action);
            formData.append('nonce', nonce);

            Object.keys(params).forEach(function(key) {
                if (key !== 'nonce') { // Don't duplicate nonce
                    formData.append(key, params[key]);
                }
            });

            var response = await fetch(Stride.config.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            });

            var data = await response.json();

            if (!data.success) {
                var error = new Error(data.data?.message || 'An error occurred');
                error.code = data.data?.code || 'unknown_error';
                error.data = data.data;
                throw error;
            }

            return data.data;
        }
    };

    /**
     * Utility: Format date for display
     *
     * @param {Date|string} date
     * @returns {string}
     */
    Stride.formatDate = function (date) {
        if (typeof date === 'string') {
            date = new Date(date);
        }
        return date.toLocaleDateString('nl-NL', {
            day: 'numeric',
            month: 'long',
            year: 'numeric'
        });
    };

    /**
     * Utility: Debounce function
     *
     * @param {Function} func
     * @param {number} wait
     * @returns {Function}
     */
    Stride.debounce = function (func, wait) {
        var timeout;
        return function () {
            var context = this;
            var args = arguments;
            clearTimeout(timeout);
            timeout = setTimeout(function () {
                func.apply(context, args);
            }, wait);
        };
    };

})();
