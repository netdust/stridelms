    <?php
    // Full-width templates handle their own layout
    $full_width_templates = is_singular(['vad_edition', 'vad_trajectory']);

    if (!$full_width_templates):
    ?>
        </div><!-- .uk-container -->
    </main><!-- #content -->
    <?php endif; ?>

    <!-- Desktop Footer (hidden on mobile) -->
    <footer id="colophon" class="stride-footer uk-visible@m">
        <div class="uk-container">
            <div class="uk-flex uk-flex-between uk-flex-middle uk-flex-wrap uk-padding-small">
                <div class="uk-text-small uk-text-muted">
                    &copy; <?php echo esc_html(date('Y')); ?> <?php bloginfo('name'); ?>. <?php esc_html_e('Alle rechten voorbehouden.', 'stride'); ?>
                </div>
                <div>
                    <?php
                    wp_nav_menu([
                        'theme_location' => 'footer',
                        'menu_id'        => 'footer-menu',
                        'container'      => false,
                        'menu_class'     => 'uk-subnav uk-subnav-divider uk-margin-remove',
                        'fallback_cb'    => false,
                        'depth'          => 1,
                    ]);
                    ?>
                </div>
            </div>
        </div>
    </footer>

    <?php
    // Mobile bottom navigation (only for logged-in users, hidden on desktop via CSS)
    get_template_part('templates/shell/bottom-nav');
    ?>
</div><!-- #page -->

<?php wp_footer(); ?>
</body>
</html>
