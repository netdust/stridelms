<?php
/**
 * Trajectory Tabs Template Part
 *
 * Sticky tab navigation for trajectory sections.
 *
 * @param array $args {
 *     @type bool $has_courses Whether trajectory has courses configured
 * }
 */

defined('ABSPATH') || exit;

$has_courses = $args['has_courses'] ?? false;

?>
<div class="sticky top-16 lg:top-20 bg-surface border-b border-border z-30" x-data="trajectoryDetailTabs()">
    <div class="container">
        <nav class="flex gap-6 overflow-x-auto -mb-px scrollbar-hide" aria-label="<?php esc_attr_e('Traject secties', 'stridence'); ?>">
            <a href="#overzicht"
               class="tab-link"
               :class="{ 'tab-active': activeTab === 'overzicht' }"
               @click.prevent="scrollTo('overzicht')">
                <?php esc_html_e('Overzicht', 'stridence'); ?>
            </a>
            <?php if ($has_courses) : ?>
                <a href="#cursussen"
                   class="tab-link"
                   :class="{ 'tab-active': activeTab === 'cursussen' }"
                   @click.prevent="scrollTo('cursussen')">
                    <?php esc_html_e('Cursussen', 'stridence'); ?>
                </a>
            <?php endif; ?>
            <a href="#praktisch"
               class="tab-link"
               :class="{ 'tab-active': activeTab === 'praktisch' }"
               @click.prevent="scrollTo('praktisch')">
                <?php esc_html_e('Praktisch', 'stridence'); ?>
            </a>
            <a href="#faq"
               class="tab-link"
               :class="{ 'tab-active': activeTab === 'faq' }"
               @click.prevent="scrollTo('faq')">
                <?php esc_html_e('Veelgestelde vragen', 'stridence'); ?>
            </a>
        </nav>
    </div>
</div>
