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
