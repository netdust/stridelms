    <footer id="colophon" class="stride-footer">
        <div class="stride-footer-inner">
            <nav class="stride-footer-navigation">
                <?php
                wp_nav_menu([
                    'theme_location' => 'footer',
                    'menu_id' => 'footer-menu',
                    'container_class' => 'stride-footer-menu-container',
                    'fallback_cb' => false,
                    'depth' => 1,
                ]);
                ?>
            </nav>

            <div class="stride-copyright">
                &copy; <?php echo date('Y'); ?> <?php bloginfo('name'); ?>
            </div>
        </div>
    </footer>
</div><!-- #page -->

<?php wp_footer(); ?>
</body>
</html>
