    <?php
    use Stride\Admin\StrideSettingsService;

    // Get configurable URL slugs
    $trajectorySlug = StrideSettingsService::getTrajectorySlug();
    $editionSlug = StrideSettingsService::getEditionSlug();

    // Full-width templates handle their own layout
    // Check for: front page, edition/trajectory singles, and dashboard page templates
    $page_template = is_page() ? get_page_template_slug() : '';
    $custom_layout_templates = [
        'page-mijn-account.php',
        'page-mijn-cursussen.php',
        'page-offertes.php',
        'page-profiel.php',
        'page-inschrijven.php',
        'page-offerte.php',
    ];

    $full_width_templates = is_front_page()
        || is_singular(['vad_edition', 'vad_trajectory'])
        || in_array($page_template, $custom_layout_templates, true);

    if (!$full_width_templates):
    ?>
        </div><!-- .uk-container -->
    </main><!-- #content -->
    <?php endif; ?>

    <?php if (is_front_page()) : ?>
    <!-- Landing Page Footer -->
    <footer id="colophon" class="stride-footer">
        <div class="uk-container">
            <div class="stride-footer__top">
                <div class="stride-footer__brand">
                    <img src="<?php echo esc_url(get_stylesheet_directory_uri() . '/assets/img/logo-white.svg'); ?>"
                         alt="<?php bloginfo('name'); ?>"
                         class="stride-footer__logo"
                         onerror="this.outerHTML='<span style=\'color:#fff;font-size:1.5rem;font-weight:700;\'><?php bloginfo('name'); ?></span>';">
                    <p class="stride-footer__description">
                        <?php esc_html_e('Hoogwaardige trainingen voor professionals in de verslavingszorg. VAD-erkend en praktijkgericht.', 'stride'); ?>
                    </p>
                    <div class="stride-footer__social">
                        <a href="#" class="stride-footer__social-link" aria-label="LinkedIn">
                            <span uk-icon="icon: linkedin; ratio: 0.9"></span>
                        </a>
                        <a href="#" class="stride-footer__social-link" aria-label="Facebook">
                            <span uk-icon="icon: facebook; ratio: 0.9"></span>
                        </a>
                        <a href="#" class="stride-footer__social-link" aria-label="Twitter">
                            <span uk-icon="icon: twitter; ratio: 0.9"></span>
                        </a>
                    </div>
                </div>

                <div class="stride-footer__column">
                    <h4 class="stride-footer__column-title"><?php esc_html_e('Cursussen', 'stride'); ?></h4>
                    <ul class="stride-footer__links">
                        <li><a href="<?php echo esc_url(home_url('/' . $editionSlug . '/')); ?>"><?php esc_html_e('Alle cursussen', 'stride'); ?></a></li>
                        <li><a href="<?php echo esc_url(home_url('/' . $editionSlug . '/?type=classroom')); ?>"><?php esc_html_e('Klassikaal', 'stride'); ?></a></li>
                        <li><a href="<?php echo esc_url(home_url('/' . $editionSlug . '/?type=online')); ?>"><?php esc_html_e('Online', 'stride'); ?></a></li>
                        <li><a href="<?php echo esc_url(home_url('/' . $trajectorySlug . '/')); ?>"><?php esc_html_e('Leertrajecten', 'stride'); ?></a></li>
                    </ul>
                </div>

                <div class="stride-footer__column">
                    <h4 class="stride-footer__column-title"><?php esc_html_e('Account', 'stride'); ?></h4>
                    <ul class="stride-footer__links">
                        <?php if (is_user_logged_in()) : ?>
                            <li><a href="<?php echo esc_url(home_url('/mijn-account/')); ?>"><?php esc_html_e('Mijn dashboard', 'stride'); ?></a></li>
                            <li><a href="<?php echo esc_url(home_url('/mijn-cursussen/')); ?>"><?php esc_html_e('Mijn cursussen', 'stride'); ?></a></li>
                            <li><a href="<?php echo esc_url(home_url('/profiel/')); ?>"><?php esc_html_e('Profiel', 'stride'); ?></a></li>
                        <?php else : ?>
                            <li><a href="<?php echo esc_url(wp_login_url()); ?>"><?php esc_html_e('Inloggen', 'stride'); ?></a></li>
                            <li><a href="<?php echo esc_url(wp_registration_url()); ?>"><?php esc_html_e('Registreren', 'stride'); ?></a></li>
                        <?php endif; ?>
                    </ul>
                </div>

                <div class="stride-footer__column">
                    <h4 class="stride-footer__column-title"><?php esc_html_e('Contact', 'stride'); ?></h4>
                    <ul class="stride-footer__links">
                        <li><a href="mailto:info@vad.be"><?php esc_html_e('info@vad.be', 'stride'); ?></a></li>
                        <li><a href="tel:+3226318051">+32 2 631 80 51</a></li>
                        <li><a href="#"><?php esc_html_e('Contactformulier', 'stride'); ?></a></li>
                    </ul>
                </div>
            </div>

            <div class="stride-footer__bottom">
                <p class="stride-footer__copyright">
                    &copy; <?php echo esc_html(date('Y')); ?> <?php bloginfo('name'); ?>. <?php esc_html_e('Alle rechten voorbehouden.', 'stride'); ?>
                </p>
                <div class="stride-footer__legal">
                    <a href="#"><?php esc_html_e('Privacybeleid', 'stride'); ?></a>
                    <a href="#"><?php esc_html_e('Algemene voorwaarden', 'stride'); ?></a>
                    <a href="#"><?php esc_html_e('Cookiebeleid', 'stride'); ?></a>
                </div>
            </div>
        </div>
    </footer>
    <?php else : ?>
    <!-- Standard Footer (hidden on mobile) -->
    <footer id="colophon" class="stride-footer uk-visible@m">
        <div class="uk-container">
            <div class="uk-flex uk-flex-between uk-flex-middle uk-flex-wrap uk-padding-small">
                <div class="uk-text-small" style="color: rgba(255,255,255,0.6);">
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
    <?php endif; ?>

    <?php
    // Mobile bottom navigation (only for logged-in users, hidden on desktop via CSS)
    get_template_part('templates/shell/bottom-nav');
    ?>
</div><!-- #page -->

<?php wp_footer(); ?>
</body>
</html>
