<?php

/**
 * Plugin Name: NTDST Core Framework
 * Description: DI Container, Bootstrap, and Service System for WordPress
 * Version: 2.0.0
 * Author: Stefan Vandermeulen
 *
 * Architecture:
 * - core/     → Foundation (Container, Bootstrap, Theme, Router)
 * - api/      → Request Flow (Endpoints, Data, Response)
 * - services/ → Built-in services (Logger, Mailer)
 */

defined('ABSPATH') || exit;

// Define plugin constants
define('NTDST_PATH', __DIR__ . '/ntdst-core');
define('NTDST_URL', plugins_url('ntdst-core', __FILE__));

// Load core foundation
require_once NTDST_PATH . '/core/Container.php';
require_once NTDST_PATH . '/core/Router.php';
require_once NTDST_PATH . '/core/Theme.php';
require_once NTDST_PATH . '/core/ServiceInterface.php';
require_once NTDST_PATH . '/core/SectorRegistry.php';
require_once NTDST_PATH . '/core/Bootstrap.php';

// Load API layer (request flow)
require_once NTDST_PATH . '/api/Data.php';
require_once NTDST_PATH . '/api/Response.php';
require_once NTDST_PATH . '/api/CorsPolicy.php';
require_once NTDST_PATH . '/api/RestRegistrar.php';
require_once NTDST_PATH . '/api/MetaboxGenerator.php';

// Load and initialize endpoints system
require_once NTDST_PATH . '/api/Endpoints.php';
ntdst_endpoints(); // Initialize endpoints to register REST routes

// Load services
require_once NTDST_PATH . '/services/Logger.php';
require_once NTDST_PATH . '/services/Mailer.php';
require_once NTDST_PATH . '/services/RelationField.php';

// Register singleton instances that can't be auto-wired
ntdst_set(NTDST_SectorRegistry::class, fn() => ntdst_sectors());

/**
 * Enqueue the shared NTDST admin toolkit CSS.
 *
 * Call from admin_enqueue_scripts (or admin_head) on your plugin's settings page.
 * Uses wp_enqueue_style to prevent duplicates when multiple plugins load it.
 */
function ntdst_enqueue_admin_toolkit(): void
{
    wp_enqueue_style(
        'ntdst-admin-toolkit',
        NTDST_URL . '/assets/css/admin-toolkit.css',
        [],
        '1.0.0',
    );
}

/**
 * Enqueue the shared NTDST API client (window.ntdstAPI).
 *
 * Single source of truth for /wp-json/ntdst/v1/action calls. Use from both
 * frontend (theme) and admin pages. Localizes the wp_rest nonce as
 * window.ntdstAPIConfig.restNonce so the client can authenticate the REST
 * endpoint regardless of context.
 */
function ntdst_enqueue_api_client(): void
{
    $path = NTDST_PATH . '/assets/js/ntdst-api.js';
    wp_enqueue_script(
        'ntdst-api',
        NTDST_URL . '/assets/js/ntdst-api.js',
        [],
        file_exists($path) ? (string) filemtime($path) : '1.0.0',
        false,
    );
    wp_add_inline_script(
        'ntdst-api',
        'window.ntdstAPIConfig = ' . wp_json_encode([
            'restNonce' => wp_create_nonce('wp_rest'),
        ]) . ';',
        'before',
    );
}

if (!function_exists('ntdst_schedule_recurring')) {
    /**
     * Register a recurring WP-Cron job through a single, self-healing seam (INV-10).
     *
     * Reusable primitive: any recurring cron job registers through this
     * function instead of hand-rolling wp_schedule_event directly. Idempotent —
     * calling it repeatedly (e.g. on every page load / plugin init) never
     * double-schedules the event, because it only schedules when nothing is
     * already pending for the hook.
     *
     * Only built-in WP intervals ('hourly', 'twicedaily', 'daily', 'weekly')
     * are supported here — no custom `cron_schedules` interval is registered
     * by this helper.
     *
     * The callback receives no request data: WP-Cron invokes hooks outside
     * any HTTP request context, so no superglobals are threaded through.
     *
     * @param string   $hook     The cron hook name.
     * @param string   $interval A built-in WP-Cron interval ('daily', 'hourly', ...).
     * @param callable $cb       The callback to run when the hook fires.
     */
    function ntdst_schedule_recurring(string $hook, string $interval, callable $cb): void
    {
        if (!wp_next_scheduled($hook)) {
            wp_schedule_event(time(), $interval, $hook);
        }

        add_action($hook, $cb);
    }
}

if (!function_exists('ntdst_clear_recurring')) {
    /**
     * Unschedule a recurring WP-Cron job registered via ntdst_schedule_recurring().
     *
     * @param string $hook The cron hook name.
     */
    function ntdst_clear_recurring(string $hook): void
    {
        wp_clear_scheduled_hook($hook);
    }
}
