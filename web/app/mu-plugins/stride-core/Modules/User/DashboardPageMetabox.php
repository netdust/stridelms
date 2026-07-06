<?php

declare(strict_types=1);

namespace Stride\Modules\User;

/**
 * T10 — Dashboard "Voor jou" curated-link metabox on the WP `page` CPT
 * (concept C: additive dashboard curation, NOT access control).
 *
 * Adds a "Toon op dashboard voor profieltypes" metabox to `page`: a set of
 * profile-type slug checkboxes sourced from ProfileTypeService::getTypes().
 * On save_post_page the chosen slugs persist to the page meta
 * `_stride_dashboard_profiletypes` (a hand-chosen literal key — the `page` CPT
 * is NOT ntdst_data-registered, so no `_ntdst_` prefix applies; the SAME literal
 * is used on this save side (T10) and the dashboard read side (T11)).
 *
 * SECURITY (threat model M5, flow H): the save is a WP-security surface —
 * nonce (check_admin_referer) + cap (current_user_can('edit_page', $id)) +
 * slug-allowlist sanitize (unknown slugs dropped; sanitize_key on each). The
 * meta is registered via register_post_meta('page', ...) so it round-trips in
 * the block editor.
 *
 * ⚠️ SIGNATURE SHELL ONLY (test-author, RED-first). The declarations exist so the
 * T10 RED test fails for a BEHAVIORAL reason (meta not persisted / guard not
 * enforced) rather than "class not found". The bodies are sentinels — NO save
 * logic, NO nonce/cap/sanitize, NO register_post_meta wiring. The implementer
 * fills these to green DashboardPageMetaboxTest without weakening it.
 */
final class DashboardPageMetabox
{
    /** Nonce action + field for the page dashboard-profiletypes metabox save. */
    public const NONCE_SAVE = 'stride_save_dashboard_profiletypes';
    public const NONCE_FIELD = 'stride_dashboard_profiletypes_nonce';

    /** The hand-chosen page-meta key. NO `_ntdst_` prefix (page is not ntdst_data). */
    public const META_KEY = '_stride_dashboard_profiletypes';

    public function __construct(
        private readonly ProfileTypeService $profileTypes,
    ) {
    }

    /**
     * save_post_page handler — persist the allowlisted profile-type slugs to
     * `_stride_dashboard_profiletypes`. Nonce + edit_page cap + slug allowlist.
     *
     * @param int      $postId The page being saved.
     * @param \WP_Post $post   The page post object.
     */
    public function handleSave(int $postId, \WP_Post $post): void
    {
        throw new \RuntimeException('not implemented: DashboardPageMetabox::handleSave');
    }

    /**
     * register_post_meta('page', '_stride_dashboard_profiletypes', ...) so the
     * key round-trips in the block editor and is protected appropriately.
     */
    public function registerMeta(): void
    {
        throw new \RuntimeException('not implemented: DashboardPageMetabox::registerMeta');
    }
}
