<?php

declare(strict_types=1);

namespace stridence\services\frontend;

use WP_Error;

/**
 * Catalog pagination endpoint (Task G1 / audit 2.2).
 *
 * Serves server-rendered card slices for the /klassikaal and /online
 * catalog pages — "Toon meer" and the theme filter fetch the next slice
 * through `ntdstAPI.call('stride_catalog_page', ...)` instead of loading
 * 200 editions up front and paging client-side.
 *
 * Registered via the `ntdst/api_data/*` filter (INV-2): the framework's
 * REST layer owns the nonce — never re-verify it here. Public read-only
 * surface: the catalog is public, anonymous nonces work, and the response
 * contains only what the catalog pages already render. Rendering lives in
 * the theme because the cards are theme partials (a stride-core handler
 * calling theme helpers would invert the plugin→theme arrow).
 */
final class CatalogEndpoint
{
    public const CATALOGS = ['klassikaal', 'online'];

    public function register(): void
    {
        add_filter('ntdst/api_data/stride_catalog_page', [$this, 'handleCatalogPage'], 10, 2);
    }

    /**
     * @param array<string, mixed> $data   Filter accumulator (unused)
     * @param array<string, mixed> $params Request params: catalog, page, theme
     * @return array<string, mixed>|WP_Error
     */
    public function handleCatalogPage(array $data, array $params): array|WP_Error
    {
        $catalog = sanitize_key((string) ($params['catalog'] ?? ''));
        if (!in_array($catalog, self::CATALOGS, true)) {
            return new WP_Error('invalid_catalog', __('Onbekende catalogus.', 'stridence'));
        }

        $page  = max(1, absint($params['page'] ?? 1));
        $theme = sanitize_key((string) ($params['theme'] ?? ''));

        $items = stridence_catalog_items($catalog);
        if ($theme !== '') {
            $items = array_values(array_filter(
                $items,
                static fn(array $item): bool => in_array($theme, $item['themes'] ?? [], true),
            ));
        }

        $per_page = STRIDENCE_CATALOG_PER_PAGE;
        $slice = array_slice($items, ($page - 1) * $per_page, $per_page);

        return [
            'html'     => stridence_catalog_render_cards($slice, get_current_user_id() ?: null),
            'count'    => count($slice),
            'total'    => count($items),
            'page'     => $page,
            'per_page' => $per_page,
            'has_more' => ($page * $per_page) < count($items),
        ];
    }
}
