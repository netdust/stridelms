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
     * Provides a clean interface for REST API calls with automatic nonce handling.
     * Uses WordPress REST API endpoint (/wp-json/ntdst/v1/).
     *
     * Usage:
     *   const result = await ntdstAPI.call('stride_validate_voucher', { code: 'ABC123' });
     *
     * On success: Returns the data object from the response
     * On error: Throws an error with the message from the server
     */
    window.ntdstAPI = {
        /**
         * REST API base URL
         */
        baseUrl: '/wp-json/ntdst/v1',

        /**
         * Nonce cache to avoid repeated nonce requests
         */
        nonceCache: {},

        /**
         * Get a nonce for an action
         *
         * @param {string} action - The action name
         * @returns {Promise<string>} - The nonce
         */
        getNonce: async function(action) {
            // Return cached nonce if available
            if (this.nonceCache[action]) {
                return this.nonceCache[action];
            }

            var response = await fetch(this.baseUrl + '/get_nonce', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ action: action })
            });

            var data = await response.json();

            if (!data.success) {
                // Handle WordPress REST errors (data.message) and our custom errors (data.data.message)
                var message = data.message || (data.data && data.data.message) || 'Failed to get nonce';
                var error = new Error(message);
                error.code = data.code || (data.data && data.data.code) || 'nonce_error';
                throw error;
            }

            // Cache the nonce
            this.nonceCache[action] = data.data.nonce;
            return data.data.nonce;
        },

        /**
         * Call a REST API action
         *
         * @param {string} action - The action name
         * @param {object} params - Parameters to send
         * @returns {Promise<object>} - Resolves with data on success, rejects with error on failure
         */
        call: async function(action, params) {
            params = params || {};

            // Get nonce for this action
            var nonce = await this.getNonce(action);

            // Build request body
            var body = Object.assign({}, params, {
                action: action,
                nonce: nonce
            });

            var response = await fetch(this.baseUrl + '/action', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(body)
            });

            var data = await response.json();

            if (!data.success) {
                // Clear cached nonce on error (might be expired)
                delete this.nonceCache[action];

                // Handle WordPress REST errors (data.message) and our custom errors (data.data.message)
                var message = data.message || (data.data && data.data.message) || 'An error occurred';
                var error = new Error(message);
                error.code = data.code || (data.data && data.data.code) || 'unknown_error';
                error.data = data;
                throw error;
            }

            return data.data;
        },

        /**
         * Clear nonce cache (useful after login/logout)
         */
        clearCache: function() {
            this.nonceCache = {};
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
