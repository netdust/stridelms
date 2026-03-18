<?php
/**
 * Course Tabs Template Part
 *
 * Sticky tab navigation for course sections.
 *
 * @param array $args {
 *     @type bool $is_online Whether course is online
 * }
 */

defined('ABSPATH') || exit;

$is_online = $args['is_online'] ?? false;

?>
<div class="sticky top-16 lg:top-20 bg-surface border-b border-border z-30" x-data="courseDetailTabs()">
    <div class="container">
        <nav class="flex gap-6 overflow-x-auto -mb-px scrollbar-hide" aria-label="<?php esc_attr_e('Cursus secties', 'stridence'); ?>">
            <a href="#overzicht"
               class="tab-link"
               :class="{ 'tab-active': activeTab === 'overzicht' }"
               @click.prevent="scrollTo('overzicht')">
                <?php esc_html_e('Overzicht', 'stridence'); ?>
            </a>
            <a href="#programma"
               class="tab-link"
               :class="{ 'tab-active': activeTab === 'programma' }"
               @click.prevent="scrollTo('programma')">
                <?php esc_html_e('Programma', 'stridence'); ?>
            </a>
            <?php if (!$is_online) : ?>
                <a href="#sprekers"
                   class="tab-link"
                   :class="{ 'tab-active': activeTab === 'sprekers' }"
                   @click.prevent="scrollTo('sprekers')">
                    <?php esc_html_e('Sprekers', 'stridence'); ?>
                </a>
            <?php endif; ?>
            <a href="#praktisch"
               class="tab-link"
               :class="{ 'tab-active': activeTab === 'praktisch' }"
               @click.prevent="scrollTo('praktisch')">
                <?php esc_html_e('Praktisch', 'stridence'); ?>
            </a>
        </nav>
    </div>
</div>
