<?php
/**
 * Test handler instantiation
 */

if (!defined('ABSPATH')) {
    echo "Run via: ddev exec wp eval-file scripts/test-handlers.php\n";
    exit(1);
}

echo "=== Handler Tests ===\n\n";

$handlers = [
    \Stride\Handlers\EnrollmentQuoteHandler::class,
    \Stride\Handlers\EnrollmentFormHandler::class,
    \Stride\Handlers\QuoteUpdateHandler::class,
];

foreach ($handlers as $handlerClass) {
    $shortName = substr($handlerClass, strrpos($handlerClass, '\\') + 1);
    echo "{$shortName}: ";
    try {
        $handler = ntdst_get($handlerClass);
        echo $handler instanceof $handlerClass ? "[PASS]" : "[FAIL]";
    } catch (Exception $e) {
        echo "[ERROR] " . $e->getMessage();
    }
    echo "\n";
}

// Verify AJAX actions are registered
echo "\nAJAX Actions:\n";
global $wp_filter;
$actions = [
    'wp_ajax_stride_submit_enrollment',
    'wp_ajax_stride_validate_voucher',
    'wp_ajax_stride_save_session_selection',
    'wp_ajax_stride_update_quote',
    'wp_ajax_stride_apply_quote_voucher',
];
foreach ($actions as $action) {
    $registered = isset($wp_filter[$action]) && count($wp_filter[$action]->callbacks) > 0;
    echo "  {$action}: " . ($registered ? "[PASS]" : "[FAIL]") . "\n";
}

echo "\n=== Done ===\n";
