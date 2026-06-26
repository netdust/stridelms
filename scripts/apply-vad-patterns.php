<?php

/**
 * Apply VAD client patterns to the seeded pages.
 *
 * Renders each pattern PHP into block markup, splices VAD's DB content
 * into the VAD_BODY_PLACEHOLDER, then UPDATEs the target page's
 * post_content + sets the page template.
 *
 * Run: ddev exec wp eval-file scripts/apply-vad-patterns.php --path=web/wp
 */

if (!defined('ABSPATH')) {
    die('Run via wp eval-file');
}

$pluginDir = WP_CONTENT_DIR . '/mu-plugins/stride-client-vad';

/**
 * Map: target page slug => [pattern file, source post_name for body, optional source post ID hint]
 * Pattern is rendered, then VAD_BODY_PLACEHOLDER is replaced with the
 * source page's post_content (wrapped in Gutenberg classic-block markup).
 * Pages with no source post (contact, agenda) get the pattern as-is.
 */
$plan = [
    'over-ons'     => ['about.php',   'over-platform'],
    'faq'          => ['faq.php',     'veelgestelde-vragen'],
    'privacy'      => ['privacy.php', 'privacy-verklaring'],
    'voorwaarden'  => ['terms.php',   'disclaimer'],
    'contact'      => ['contact.php', null],
    'agenda'       => ['agenda.php',  null],
];

$results = [];

foreach ($plan as $targetSlug => [$patternFile, $sourceSlug]) {
    // Render the pattern (it's a PHP file that echoes block markup)
    $patternPath = $pluginDir . '/patterns/' . $patternFile;
    if (!file_exists($patternPath)) {
        $results[$targetSlug] = 'FAIL: pattern missing';
        continue;
    }
    ob_start();
    include $patternPath;
    $patternMarkup = (string) ob_get_clean();

    // Splice body content if a source is configured
    if ($sourceSlug !== null) {
        $sourcePost = get_page_by_path($sourceSlug);
        if (!$sourcePost) {
            $results[$targetSlug] = "FAIL: source post '$sourceSlug' not found";
            continue;
        }
        // Wrap source content in a classic block so Gutenberg renders it cleanly
        $body = trim($sourcePost->post_content);
        $bodyBlock = "<!-- wp:freeform -->\n" . $body . "\n<!-- /wp:freeform -->";
        $patternMarkup = str_replace(
            '<!-- VAD_BODY_PLACEHOLDER -->',
            $bodyBlock,
            $patternMarkup,
        );
    } else {
        // No source body — strip the placeholder if it exists (most stubs don't have one)
        $patternMarkup = str_replace('<!-- VAD_BODY_PLACEHOLDER -->', '', $patternMarkup);
    }

    // Find target page
    $target = get_page_by_path($targetSlug);
    if (!$target) {
        $results[$targetSlug] = "FAIL: target page '$targetSlug' not found";
        continue;
    }

    // Update content + template
    $updateResult = wp_update_post([
        'ID'           => $target->ID,
        'post_content' => $patternMarkup,
    ], true);

    if (is_wp_error($updateResult)) {
        $results[$targetSlug] = 'FAIL update: ' . $updateResult->get_error_message();
        continue;
    }

    update_post_meta($target->ID, '_wp_page_template', 'vad-page-stub.php');

    $bytes = strlen($patternMarkup);
    $results[$targetSlug] = sprintf('OK (id=%d, %s bytes)', $target->ID, number_format($bytes));
}

echo "VAD pattern application:\n";
foreach ($results as $slug => $msg) {
    printf("  %-15s %s\n", $slug, $msg);
}
