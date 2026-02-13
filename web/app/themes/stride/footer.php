    </main><!-- #content -->

    <footer id="colophon" class="stride-footer">
        <div class="uk-container">
            <div uk-grid class="uk-child-width-1-2@s uk-child-width-1-4@m">
                <!-- About -->
                <div>
                    <h4 class="stride-footer-title"><?php bloginfo('name'); ?></h4>
                    <p class="uk-text-small">
                        <?php echo esc_html(get_bloginfo('description')); ?>
                    </p>
                </div>

                <!-- Quick Links -->
                <div>
                    <h4 class="stride-footer-title"><?php esc_html_e('Snel Naar', 'stride'); ?></h4>
                    <ul class="stride-footer-nav">
                        <li><a href="<?php echo esc_url(home_url('/cursussen/')); ?>"><?php esc_html_e('Cursussen', 'stride'); ?></a></li>
                        <li><a href="<?php echo esc_url(home_url('/trajecten/')); ?>"><?php esc_html_e('Trajecten', 'stride'); ?></a></li>
                        <li><a href="<?php echo esc_url(home_url('/over-ons/')); ?>"><?php esc_html_e('Over Ons', 'stride'); ?></a></li>
                        <li><a href="<?php echo esc_url(home_url('/contact/')); ?>"><?php esc_html_e('Contact', 'stride'); ?></a></li>
                    </ul>
                </div>

                <!-- My Account -->
                <div>
                    <h4 class="stride-footer-title"><?php esc_html_e('Mijn Account', 'stride'); ?></h4>
                    <ul class="stride-footer-nav">
                        <?php if (is_user_logged_in()) : ?>
                            <li><a href="<?php echo esc_url(home_url('/mijn-account/')); ?>"><?php esc_html_e('Dashboard', 'stride'); ?></a></li>
                            <li><a href="<?php echo esc_url(home_url('/mijn-account/cursussen/')); ?>"><?php esc_html_e('Mijn Cursussen', 'stride'); ?></a></li>
                            <li><a href="<?php echo esc_url(home_url('/mijn-account/profiel/')); ?>"><?php esc_html_e('Profiel', 'stride'); ?></a></li>
                        <?php else : ?>
                            <li><a href="<?php echo esc_url(wp_login_url()); ?>"><?php esc_html_e('Inloggen', 'stride'); ?></a></li>
                            <li><a href="<?php echo esc_url(wp_registration_url()); ?>"><?php esc_html_e('Registreren', 'stride'); ?></a></li>
                        <?php endif; ?>
                    </ul>
                </div>

                <!-- Contact -->
                <div>
                    <h4 class="stride-footer-title"><?php esc_html_e('Contact', 'stride'); ?></h4>
                    <ul class="stride-footer-nav">
                        <li>
                            <span uk-icon="icon: mail; ratio: 0.8"></span>
                            <a href="mailto:info@example.com">info@example.com</a>
                        </li>
                        <li>
                            <span uk-icon="icon: phone; ratio: 0.8"></span>
                            <a href="tel:+31123456789">+31 (0)12 345 6789</a>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Footer Bottom -->
            <div class="stride-footer-bottom uk-flex uk-flex-between uk-flex-middle uk-flex-wrap">
                <div>
                    &copy; <?php echo date('Y'); ?> <?php bloginfo('name'); ?>. <?php esc_html_e('Alle rechten voorbehouden.', 'stride'); ?>
                </div>
                <div>
                    <?php
                    wp_nav_menu([
                        'theme_location' => 'footer',
                        'menu_id' => 'footer-menu',
                        'container' => false,
                        'menu_class' => 'uk-subnav uk-subnav-divider uk-margin-remove',
                        'fallback_cb' => false,
                        'depth' => 1,
                    ]);
                    ?>
                </div>
            </div>
        </div>
    </footer>
</div><!-- #page -->

<?php wp_footer(); ?>
</body>
</html>
