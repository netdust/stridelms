<?php
/**
 * Stridence Footer
 *
 * @package stridence
 */

defined('ABSPATH') || exit;
?>

    </main><!-- #content -->

    <footer class="str-footer">
        <div class="str-container">
            <div class="str-footer__inner">
                <div class="str-footer__brand">
                    <span class="str-footer__name"><?php bloginfo('name'); ?></span>
                    <p class="str-footer__tagline"><?php bloginfo('description'); ?></p>
                </div>

                <?php if (has_nav_menu('footer')): ?>
                <nav class="str-footer__nav" aria-label="<?php esc_attr_e('Footernavigatie', 'stridence'); ?>">
                    <?php
                    wp_nav_menu([
                        'theme_location' => 'footer',
                        'container' => false,
                        'menu_class' => 'str-footer__menu',
                        'fallback_cb' => false,
                        'depth' => 1,
                    ]);
                    ?>
                </nav>
                <?php endif; ?>
            </div>

            <div class="str-footer__bottom">
                <p class="str-footer__copy">
                    &copy; <?php echo date('Y'); ?> <?php bloginfo('name'); ?>
                </p>
            </div>
        </div>
    </footer>

</div><!-- #page -->

<?php wp_footer(); ?>

</body>
</html>
