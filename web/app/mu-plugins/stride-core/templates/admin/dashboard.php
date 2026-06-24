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

/**
 * The per-surface stub containers cluster A ships. Each later cluster replaces
 * one stub with the real surface partial. Key = view slug (matches the rail +
 * wsShell VIEWS whitelist); value = the placeholder heading.
 *
 * @var array<string,string> $ws_surfaces
 */
$ws_surfaces = [
    'edities'        => __('Edities', 'stride'),
    'sessies'        => __('Sessies', 'stride'),
    'offertes'       => __('Offertes', 'stride'),
    'trajecten'      => __('Trajecten', 'stride'),
    'gebruikers'     => __('Gebruikers', 'stride'),
];
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

        <?php foreach ($ws_surfaces as $ws_view => $ws_label) : ?>
            <section class="ws-content" x-show="view === '<?php echo esc_attr($ws_view); ?>'" x-cloak>
                <div class="ws-stagger">
                    <div class="ws-page-head">
                        <div>
                            <span class="ws-eyebrow"><?php echo esc_html__('Werkbank', 'stride'); ?></span>
                            <h1><?php echo esc_html($ws_label); ?></h1>
                            <p><?php echo esc_html__('Deze werkbank wordt opgebouwd.', 'stride'); ?></p>
                        </div>
                    </div>
                </div>
            </section>
        <?php endforeach; ?>

    </main>
</div>
