<?php
/**
 * Shared admin tool-page chrome: header partial + chrome-loading helper.
 *
 * Use this on any admin page registered under the Stride or Stride Tools
 * top-level menus. Two entry points:
 *
 *   stride_load_tool_chrome()  — call from admin_head on your page hook;
 *                                inlines --sd-* tokens + tool-header CSS.
 *
 *   stride_tool_header(...)    — render the header inside <div class="wrap">.
 *
 * @package stride
 */

defined('ABSPATH') || exit;

if (!function_exists('stride_load_tool_chrome')) {
    /**
     * Inline the design-token CSS + tool-header CSS into <head>.
     * Idempotent: safe to call from multiple pages, only emits once per request.
     */
    function stride_load_tool_chrome(): void
    {
        static $emitted = false;
        if ($emitted) {
            return;
        }
        $emitted = true;

        $coreRoot   = dirname(__DIR__, 2); // stride-core/
        $tokensFile = $coreRoot . '/assets/css/admin-dashboard.css';
        $chromeFile = $coreRoot . '/assets/css/admin/tool-chrome.css';

        if (file_exists($tokensFile)) {
            echo '<style id="stride-tool-tokens">';
            include $tokensFile;
            echo '</style>';
        }

        if (file_exists($chromeFile)) {
            echo '<style id="stride-tool-chrome">';
            include $chromeFile;
            echo '</style>';
        }
    }
}

if (!function_exists('stride_tools_menu_items')) {
    /**
     * Resolve the registered Stride Tools menu items, capability-filtered
     * and alphabetically sorted. Used by both the Stride Tools index page
     * and the dashboard quick-link card.
     *
     * @return array<int, array{slug:string,label:string,description?:string,icon?:string,capability?:string}>
     */
    function stride_tools_menu_items(): array
    {
        $items = apply_filters('stride_tools_menu_items', []);
        if (!is_array($items)) {
            return [];
        }

        $valid = [];
        foreach ($items as $item) {
            if (!is_array($item) || empty($item['slug']) || empty($item['label'])) {
                continue;
            }
            $cap = $item['capability'] ?? 'manage_options';
            if (!current_user_can($cap)) {
                continue;
            }
            $valid[] = $item;
        }

        usort($valid, fn(array $a, array $b) => strcmp($a['label'], $b['label']));

        return $valid;
    }
}

if (!function_exists('stride_tool_header')) {
    /**
     * Render the shared tool-page header.
     *
     * @param string $title    Page title.
     * @param string $subtitle Optional one-line description.
     * @param array  $actions  Optional buttons. Each item:
     *                         ['label' => 'CSV', 'url' => '#', 'primary' => false, 'attrs' => 'x-on:click="..."']
     */
    function stride_tool_header(string $title, string $subtitle = '', array $actions = []): void
    {
        ?>
        <header class="stride-tool-header">
            <div class="stride-tool-header__text">
                <h1 class="stride-tool-header__title"><?php echo esc_html($title); ?></h1>
                <?php if ($subtitle !== ''): ?>
                    <p class="stride-tool-header__subtitle"><?php echo esc_html($subtitle); ?></p>
                <?php endif; ?>
            </div>
            <?php if (!empty($actions)): ?>
                <div class="stride-tool-header__actions">
                    <?php foreach ($actions as $action):
                        $class = !empty($action['primary']) ? 'button button-primary' : 'button';
                        $url   = $action['url'] ?? '#';
                        $label = $action['label'] ?? '';
                        $attrs = $action['attrs'] ?? '';
                        ?>
                        <a class="<?php echo esc_attr($class); ?>"
                           href="<?php echo esc_url($url); ?>"
                           <?php echo $attrs; // raw — caller is trusted?>
                        ><?php echo esc_html($label); ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </header>
        <?php
    }
}
