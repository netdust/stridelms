<?php
/**
 * Test shortcode registration
 */

if (!defined('ABSPATH')) {
    echo "Run via: ddev exec wp eval-file scripts/test-shortcodes.php\n";
    exit(1);
}

echo "DashboardShortcodes class exists: " . (class_exists('\stride\services\frontend\DashboardShortcodes') ? 'yes' : 'no') . PHP_EOL;

$ds = null;
try {
    $ds = ntdst_get('\stride\services\frontend\DashboardShortcodes');
    echo "DashboardShortcodes instantiated: yes\n";
} catch (Exception $e) {
    echo "DashboardShortcodes instantiated: no - " . $e->getMessage() . PHP_EOL;
}

if ($ds) {
    echo "Method registerShortcodes exists: " . (method_exists($ds, 'registerShortcodes') ? 'yes' : 'no') . PHP_EOL;
}

// Check what shortcodes exist
global $shortcode_tags;
$stride_shortcodes = array_filter(array_keys($shortcode_tags), function($sc) {
    return strpos($sc, 'stride_') === 0;
});
echo "Stride shortcodes registered: " . count($stride_shortcodes) . PHP_EOL;
if (count($stride_shortcodes) > 0) {
    foreach ($stride_shortcodes as $sc) {
        echo "  - {$sc}\n";
    }
}

// Check if init already ran
echo "Did init run: " . (did_action('init') ? 'yes' : 'no') . PHP_EOL;
