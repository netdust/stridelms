<?php
/**
 * Admin Workspace — shell host.
 *
 * The single `<div class="ws-shell">` host for the rebuilt admin workspace
 * (Phase 2, cluster A). Replaces the abandoned 1,642-line sd-/ws- franken
 * template. Structure is ported from docs/mockups/admin-workspace (which
 * duplicated the chrome on every page — here it is one shell + two partials).
 *
 * Architecture:
 *   - the shell owns the `wsShell()` Alpine component (assets/js/admin/shell.js):
 *     active `view`, nav switching + ?view= URL state, the shared `api()` helper,
 *     and the constant icon() lookup the rail/topbar render.
 *   - `_ws-rail.php` + `_ws-topbar.php` are the shared chrome.
 *   - `<main class="ws-main">` hosts one container PER SURFACE. Each container is
 *     shown via `x-show="view==='<surface>'"`. Cluster A stubs each container as
 *     a labelled empty section so navigation works and the shell is structurally
 *     complete; clusters B–G replace each stub with the surface's own partial +
 *     mount its own Alpine factory inside the container.
 *
 * Escaping: every server value printed here uses esc_html/esc_attr; Alpine
 * x-text auto-escapes data; x-html binds ONLY constant icon names (INV-5).
 *
 * @var string $admin_url Base admin URL.
 * @var \WP_User $user Current user.
 * @var string $user_name Current user display name.
 * @package Stride\Admin
 */

defined('ABSPATH') || exit;
?>
<div class="ws-shell" x-data="wsShell()" x-init="init()" x-cloak>

    <?php require __DIR__ . '/_ws-rail.php'; ?>

    <main class="ws-main">

        <?php require __DIR__ . '/_ws-topbar.php'; ?>

        <?php // Vandaag — its own per-surface Alpine factory owns ALL its data (cluster B).
        require __DIR__ . '/dashboard/vandaag.php'; ?>

        <?php // Inschrijvingen — the registration grid, its own factory owns its data (cluster C).
        require __DIR__ . '/dashboard/inschrijvingen.php'; ?>

        <?php // Dossier — the per-person case view, its own factory owns its data (cluster D).
        require __DIR__ . '/dashboard/dossier.php'; ?>

        <?php // Trajecten — read-only overview + detail slide-over, its own factory owns its data (cluster E).
        require __DIR__ . '/dashboard/trajecten.php'; ?>

        <?php // The functional list surfaces (cluster F). Each is its own
              // per-surface Alpine factory that owns ALL of its own data
              // (init/load/loading/empty/error) — never a shared loader. Edit
              // actions deep-link to the existing WP edit screens.?>
        <?php require __DIR__ . '/dashboard/edities.php'; ?>
        <?php require __DIR__ . '/dashboard/offertes.php'; ?>
        <?php require __DIR__ . '/dashboard/gebruikers.php'; ?>

        <?php // Cohort lens (cluster G) — a right-anchored slideover that OVERLAYS
              // the current surface (not a view switch). Its own per-surface Alpine
              // factory owns the roster; it opens via the `ws-cohort-open` window
              // event the Edities row dispatches. Rendered last so it layers above.?>
        <?php require __DIR__ . '/dashboard/_cohort-lens.php'; ?>

        <?php // Global search palette (⌘K, Phase 3c / F-S1) — an overlay like
              // the cohort lens, opened via ⌘K/Ctrl+K or the topbar search box
              // (ws-gsearch-open event). Rendered last so it layers above. ?>
        <?php require __DIR__ . '/dashboard/_gsearch.php'; ?>

    </main>
</div>
