<?php
/**
 * Regenerate tc_index.html wrapper files for SCORM modules
 *
 * Run with: ddev exec wp eval-file scripts/regenerate-scorm-wrapper.php
 */

$scorm_modules = [151, 170];

foreach ($scorm_modules as $module_id) {
    echo "\n=== Processing SCORM module {$module_id} ===\n";

    // Get module info from database
    global $wpdb;
    $module_info = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$wpdb->prefix}snc_file_info WHERE ID = %d", $module_id),
        ARRAY_A
    );

    if (!$module_info) {
        echo "ERROR: Module {$module_id} not found in database\n";
        continue;
    }

    echo "Module name: {$module_info['file_name']}\n";
    echo "Type: {$module_info['type']}\n";
    echo "Version: {$module_info['version']}\n";
    echo "Current URL: {$module_info['url']}\n";

    // Get target directory
    $upload_dir = wp_upload_dir();
    $target_dir = $upload_dir['basedir'] . '/uncanny-snc/' . $module_id;

    if (!is_dir($target_dir)) {
        echo "ERROR: Directory does not exist: {$target_dir}\n";
        continue;
    }

    echo "Target directory: {$target_dir}\n";

    // Find imsmanifest.xml
    $imsmanifest_file = $target_dir . '/imsmanifest.xml';
    if (!file_exists($imsmanifest_file)) {
        echo "ERROR: imsmanifest.xml not found\n";
        continue;
    }

    echo "Found imsmanifest.xml\n";

    // Parse imsmanifest.xml to get SCORM version and launch file
    $xml = simplexml_load_file($imsmanifest_file);
    if (!$xml) {
        echo "ERROR: Could not parse imsmanifest.xml\n";
        continue;
    }

    // Register namespaces
    $xml->registerXPathNamespace('imscp', 'http://www.imsglobal.org/xsd/imscp_v1p1');
    $xml->registerXPathNamespace('adlcp', 'http://www.adlnet.org/xsd/adlcp_v1p3');

    // Determine SCORM version
    $scorm_version = 'scorm1.2';
    $schema = (string)$xml->metadata->schemaversion;
    if (strpos($schema, '2004') !== false || $schema === 'CAM 1.3') {
        $scorm_version = 'scorm2004';
    }
    echo "SCORM Version: {$scorm_version}\n";

    // Get launch file from resources
    $launch_file = null;
    $launch_title = 'SCORM MODULE';

    // Try to get from resources
    if (isset($xml->resources->resource)) {
        foreach ($xml->resources->resource as $resource) {
            $attrs = $resource->attributes();
            if (isset($attrs['href'])) {
                $launch_file = (string)$attrs['href'];
                break;
            }
        }
    }

    // Also try to get title from organization
    if (isset($xml->organizations->organization->title)) {
        $launch_title = (string)$xml->organizations->organization->title;
    }

    if (!$launch_file) {
        echo "ERROR: Could not find launch file in manifest\n";
        continue;
    }

    echo "Launch file: {$launch_file}\n";
    echo "Title: {$launch_title}\n";

    // Get plugin version
    $plugin_version = defined('UNCANNY_REPORTING_VERSION') ? UNCANNY_REPORTING_VERSION : '1.0.0';

    // Get SCORM driver URL
    $scorm_driver_url = plugins_url('/src/assets/dist/scripts/scormdriver.js', WP_PLUGIN_DIR . '/tin-canny-learndash-reporting/tin-canny-learndash-reporting.php');

    // Build the target URL for the launch file
    $target_url = $upload_dir['baseurl'] . '/uncanny-snc/' . $module_id;
    $launch_url = $target_url . '/' . $launch_file;

    echo "Launch URL: {$launch_url}\n";

    // Generate tc_index.html content
    $sv_param = ($scorm_version === 'scorm2004') ? '2004' : '1.2';
    $terminate_api = ($scorm_version === 'scorm2004') ? 'API_1484_11' : 'API';
    $terminate_method = ($scorm_version === 'scorm2004') ? 'Terminate' : 'LMSFinish';

    $tc_index_content = <<<HTML
<html><head>
<script type="text/javascript">
    var TC_COURSE_ID, TC_COURSE_NAME, TC_COURSE_DESC, TC_RECORD_STORES;
    var getUrl = window.location;
    var baseUrl = getUrl.protocol + "//" + getUrl.host + getUrl.pathname;
    TC_COURSE_ID = baseUrl;
    TC_COURSE_NAME = {
        "en-US": "{$launch_title}"
    };
    TC_COURSE_DESC = {
        "en-US": "Course Description."
    };
    TC_RECORD_STORES = [];
</script>
<script src="{$scorm_driver_url}?sv={$sv_param}&v={$plugin_version}" type="text/javascript"></script>
</head>
<body>
<iframe src="{$launch_url}" width="100%" style="border: none; width: 100%; height: 100%" height="100%" name="course" onbeforeunload="{$terminate_api}.{$terminate_method}('');" onunload="{$terminate_api}.{$terminate_method}('');"></iframe>
</body></html>
HTML;

    // Write tc_index.html
    $tc_index_path = $target_dir . '/tc_index.html';
    $result = file_put_contents($tc_index_path, $tc_index_content);

    if ($result === false) {
        echo "ERROR: Could not write tc_index.html\n";
        continue;
    }

    echo "Generated tc_index.html ({$result} bytes)\n";

    // Update database URL to include tc_index.html
    $new_url = '/app/uploads/uncanny-snc/' . $module_id . '/tc_index.html';
    $wpdb->update(
        $wpdb->prefix . 'snc_file_info',
        ['url' => $new_url],
        ['ID' => $module_id]
    );

    echo "Updated database URL to: {$new_url}\n";
    echo "SUCCESS: Module {$module_id} regenerated\n";
}

echo "\n=== Done ===\n";
echo "Test the lessons in your browser to verify SCORM content loads.\n";
