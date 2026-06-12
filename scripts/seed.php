<?php
/**
 * Stride LMS - Development Seed Script (declarative feature matrix)
 * Run with: ddev exec wp eval-file scripts/seed.php
 * Matrix:   scripts/seed/matrix.php   Builders: scripts/seed/builders.php
 */
if (!defined('ABSPATH')) {
    echo "This script must be run via WP-CLI: ddev exec wp eval-file scripts/seed.php\n";
    exit(1);
}
if (!defined('WP_ENV') || !in_array(WP_ENV, ['development', 'local'], true)) {
    echo "ERROR: Seed script only allowed in development/local environments!\n";
    exit(1);
}

require __DIR__ . '/seed/builders.php';
require __DIR__ . '/seed/runner.php';
$matrix = require __DIR__ . '/seed/matrix.php';

(new StrideSeedRunner($matrix, new StrideSeedBuilders()))->run();
