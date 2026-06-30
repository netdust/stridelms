<?php
/**
 * Course Tabs Template Part
 *
 * Sticky tab navigation for course sections.
 *
 * @param array $args {
 *     @type bool $is_online           Whether course is online
 *     @type bool $is_edition_overview Whether this is an edition-overview surface
 *                                     (course has active editions) — only the
 *                                     sections that render get a tab.
 * }
 */

defined('ABSPATH') || exit;

$is_online           = $args['is_online'] ?? false;
$is_edition_overview = $args['is_edition_overview'] ?? false;

// Tabs must mirror the sections content.php actually renders, or they scroll to
// nothing. An edition overview shows only Overzicht + Edities; otherwise the
// full set (Sprekers is klassikaal-only).
if ($is_edition_overview) {
    $tabs = [
        'overzicht' => __('Overzicht', 'stridence'),
        'edities'   => __('Edities', 'stridence'),
    ];
} else {
    $tabs = ['overzicht' => __('Overzicht', 'stridence')];
    $tabs['programma'] = __('Programma', 'stridence');
    if (!$is_online) {
        $tabs['sprekers'] = __('Sprekers', 'stridence');
    }
    $tabs['praktisch'] = __('Praktisch', 'stridence');
}

?>
<div class="sticky top-16 lg:top-20 bg-surface border-b border-border z-30" x-data="courseDetailTabs()">
    <div class="container">
        <nav class="flex gap-6 overflow-x-auto -mb-px scrollbar-hide" aria-label="<?php esc_attr_e('Cursus secties', 'stridence'); ?>">
            <?php foreach ($tabs as $anchor => $label) : ?>
                <a href="#<?php echo esc_attr($anchor); ?>"
                   class="tab-link"
                   :class="{ 'tab-active': activeTab === '<?php echo esc_js($anchor); ?>' }"
                   @click.prevent="scrollTo('<?php echo esc_js($anchor); ?>')">
                    <?php echo esc_html($label); ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>
</div>
