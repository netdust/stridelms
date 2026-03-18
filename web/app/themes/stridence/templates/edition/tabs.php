<?php
/**
 * Edition Tabs Template Part
 *
 * Sticky tab navigation for edition sections.
 *
 * @param array $args {
 *     @type bool $has_sessions Whether edition has sessions
 * }
 */

defined('ABSPATH') || exit;

$has_sessions = $args['has_sessions'] ?? false;

?>
<div class="sticky top-16 lg:top-20 bg-surface border-b border-border z-30" x-data="editionDetailTabs()">
    <div class="container">
        <nav class="flex gap-6 overflow-x-auto -mb-px scrollbar-hide" aria-label="<?php esc_attr_e('Editie secties', 'stridence'); ?>">
            <a href="#overzicht"
               class="tab-link"
               :class="{ 'tab-active': activeTab === 'overzicht' }"
               @click.prevent="scrollTo('overzicht')">
                <?php esc_html_e('Overzicht', 'stridence'); ?>
            </a>
            <?php if ($has_sessions) : ?>
                <a href="#sessies"
                   class="tab-link"
                   :class="{ 'tab-active': activeTab === 'sessies' }"
                   @click.prevent="scrollTo('sessies')">
                    <?php esc_html_e('Sessies', 'stridence'); ?>
                </a>
            <?php endif; ?>
            <a href="#sprekers"
               class="tab-link"
               :class="{ 'tab-active': activeTab === 'sprekers' }"
               @click.prevent="scrollTo('sprekers')">
                <?php esc_html_e('Sprekers', 'stridence'); ?>
            </a>
            <a href="#praktisch"
               class="tab-link"
               :class="{ 'tab-active': activeTab === 'praktisch' }"
               @click.prevent="scrollTo('praktisch')">
                <?php esc_html_e('Praktisch', 'stridence'); ?>
            </a>
        </nav>
    </div>
</div>
