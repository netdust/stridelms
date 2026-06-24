<?php
/**
 * Admin Workspace — topbar (shared chrome partial).
 *
 * Ported from the duplicated `<header class="ws-topbar">` block in every
 * docs/mockups/admin-workspace/*.html. Lives inside `wsShell()` scope.
 *
 * Cluster A owns ONLY the generic chrome: a breadcrumb that reflects the active
 * surface, the search field (cosmetic for now — wired per-surface in B–G), and
 * a refresh button that dispatches a `ws-refresh` window event each surface can
 * listen to. Per-surface topbar extras (queue badges, filters) are added by the
 * surface's own partial in later clusters.
 *
 * INV-5: icon() binds string literals only.
 *
 * @package Stride\Admin
 */

defined('ABSPATH') || exit;

/**
 * Surface → breadcrumb label. A closed, server-defined map (not user data) —
 * printed once as a JS object literal so the Alpine crumb reads it by key.
 * esc_js() guards each label even though all values are static here.
 *
 * @var array<string,string> $ws_crumb_labels
 */
$ws_crumb_labels = [
    'vandaag'        => __('Vandaag', 'stride'),
    'inschrijvingen' => __('Inschrijvingen', 'stride'),
    'edities'        => __('Edities', 'stride'),
    'offertes'       => __('Offertes', 'stride'),
    'trajecten'      => __('Trajecten', 'stride'),
    'gebruikers'     => __('Gebruikers', 'stride'),
    'dossier'        => __('Dossier', 'stride'),
];
?>
<header class="ws-topbar"
        x-data="<?php echo esc_attr('{ crumbs: ' . wp_json_encode($ws_crumb_labels) . ' }'); ?>">
    <div class="ws-topbar__crumbs">
        <b x-text="crumbs[view] || view"></b>
    </div>
    <div class="ws-topbar__spacer"></div>
    <div class="ws-search">
        <span x-html="icon('search')"></span>
        <input type="text" placeholder="<?php echo esc_attr__('Zoek persoon, editie, organisatie…', 'stride'); ?>" disabled>
        <kbd>⌘K</kbd>
    </div>
    <button class="ws-btn ws-btn--ghost ws-btn--icon"
            title="<?php echo esc_attr__('Vernieuwen', 'stride'); ?>"
            @click="$dispatch('ws-refresh', { view })">
        <span x-html="icon('refresh')"></span>
    </button>
</header>
