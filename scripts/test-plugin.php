<?php
/**
 * Test script to verify plugin extraction
 */

echo "=== Stride Core Plugin Test ===\n\n";

// Test class existence
$classes = [
    'ntdst\Stride\core\EditionService',
    'ntdst\Stride\core\SessionService',
    'ntdst\Stride\core\CourseService',
    'ntdst\Stride\core\RegistrationRepository',
    'ntdst\Stride\core\SubscriberService',
    'ntdst\Stride\core\OrganizationService',
    'ntdst\Stride\enrollment\EnrollmentService',
    'ntdst\Stride\invoicing\QuoteService',
    'ntdst\Stride\invoicing\VoucherService',
    'ntdst\Stride\handlers\EnrollmentQuoteHandler',
    'ntdst\Stride\FieldRegistry',
];

echo "Class Existence:\n";
foreach ($classes as $class) {
    $exists = class_exists($class) ? 'OK' : 'FAIL';
    $shortName = substr($class, strrpos($class, '\\') + 1);
    echo "  {$shortName}: {$exists}\n";
}

echo "\nService Resolution:\n";
foreach ($classes as $class) {
    try {
        $instance = ntdst_get($class);
        $status = is_object($instance) ? 'OK' : 'FAIL';
    } catch (Exception $e) {
        $status = 'ERROR: ' . $e->getMessage();
    }
    $shortName = substr($class, strrpos($class, '\\') + 1);
    echo "  {$shortName}: {$status}\n";
}

echo "\n=== Test Complete ===\n";
