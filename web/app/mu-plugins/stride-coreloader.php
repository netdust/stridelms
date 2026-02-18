<?php

/**
 * Plugin Name: Stride Core Loader
 * Description: Loads the Stride Core plugin from subdirectory
 * Version: 1.0.0
 * Author: NTDST
 *
 * This is a simple loader for mu-plugins that live in subdirectories.
 * The actual plugin code is in stride-core/stride-core.php
 */

defined('ABSPATH') || exit;

// Load the actual plugin
require_once __DIR__ . '/stride-core/stride-core.php';
