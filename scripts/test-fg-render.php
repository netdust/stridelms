<?php
try {
    $service = ntdst_get(\Stride\Admin\FieldGroupSettingsPage::class);
    echo 'FieldGroupSettingsPage loaded OK' . PHP_EOL;

    $reflection = new ReflectionClass($service);
    $constants = $reflection->getConstants();
    echo 'Constants: ' . implode(', ', array_keys($constants)) . PHP_EOL;

    // Directly test hasLegacyData path
    echo 'Testing hasLegacyData...' . PHP_EOL;
    $method = $reflection->getMethod('hasLegacyData');
    $method->setAccessible(true);
    $result = $method->invoke($service);
    echo 'hasLegacyData: ' . var_export($result, true) . PHP_EOL;

} catch (\Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
    echo 'File: ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
}
