<?php

declare(strict_types=1);

namespace stridence\services\frontend\hooks;

use NTDST_Theme;

/**
 * Vite asset pipeline: dev-server fork in WP_DEBUG, manifest-driven enqueue
 * in production, and the inline `window.strideConfig` payload.
 *
 * Kept as a closure-wrapping class because the manifest dance and the
 * conditional `script_loader_tag` filter don't fit the data-driven
 * `assets` config that NTDST_Theme reads from theme-config.php.
 */
final class AssetHooks
{
    private const HANDLE = 'stridence-main';

    public function bind(NTDST_Theme $theme): void
    {
        $theme->on('wp_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function enqueueAssets(): void
    {
        if ($this->isViteDev()) {
            $this->enqueueDevServer();
        } else {
            $this->enqueueBuiltAssets();
        }

        wp_add_inline_script(self::HANDLE, 'window.strideConfig = ' . wp_json_encode([
            'restNonce' => wp_create_nonce('wp_rest'),
            'debug'     => defined('WP_DEBUG') && WP_DEBUG,
            'strings'   => [
                'saving'  => __('Opslaan...', 'stridence'),
                'saved'   => __('Opgeslagen', 'stridence'),
                'error'   => __('Er is een fout opgetreden', 'stridence'),
                'confirm' => __('Weet je het zeker?', 'stridence'),
            ],
        ]) . ';', 'before');
    }

    private function enqueueDevServer(): void
    {
        // Register a dummy handle so wp_add_inline_script can attach to it.
        wp_register_script(self::HANDLE, false);
        wp_enqueue_script(self::HANDLE);

        add_action('wp_head', static function (): void {
            echo '<script type="module" src="http://localhost:5173/@vite/client"></script>';
            echo '<script type="module" src="http://localhost:5173/main.js"></script>';
        }, 1);
    }

    private function enqueueBuiltAssets(): void
    {
        $manifest = $this->getManifest();
        if (!isset($manifest['main.js'])) {
            return;
        }

        $entry = $manifest['main.js'];

        if (!empty($entry['css'])) {
            foreach ($entry['css'] as $index => $css_file) {
                wp_enqueue_style(
                    'stridence-' . $index,
                    STRIDENCE_URI . '/dist/' . $css_file,
                    [],
                    STRIDENCE_VERSION,
                );
            }
        }

        wp_enqueue_script(
            self::HANDLE,
            STRIDENCE_URI . '/dist/' . $entry['file'],
            [],
            STRIDENCE_VERSION,
            true,
        );

        add_filter('script_loader_tag', static function (string $tag, string $handle): string {
            if ($handle === self::HANDLE) {
                return str_replace(' src', ' type="module" src', $tag);
            }
            return $tag;
        }, 10, 2);
    }

    private function isViteDev(): bool
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return false;
        }

        // In production the manifest exists; its absence in dev means Vite is serving.
        $manifest_path = STRIDENCE_DIR . '/dist/.vite/manifest.json';
        return !file_exists($manifest_path);
    }

    /**
     * @return array<string, mixed>
     */
    private function getManifest(): array
    {
        static $manifest = null;

        if ($manifest === null) {
            $manifest_path = STRIDENCE_DIR . '/dist/.vite/manifest.json';
            $manifest = file_exists($manifest_path)
                ? (json_decode(file_get_contents($manifest_path), true) ?: [])
                : [];
        }

        return $manifest;
    }
}
