<?php
/**
 * Theme Footer
 *
 * @package stridence
 */

defined('ABSPATH') || exit;
?>
    </main><!-- #main -->

    <!-- Footer (hidden on mobile for dashboard pages with bottom nav) -->
    <footer class="bg-surface-alt mt-auto <?php echo is_page_template('page-mijn-account.php') ? 'hidden lg:block' : ''; ?>">
        <div class="container py-12 lg:py-16">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8 lg:gap-12">

                <!-- Brand Column -->
                <div class="lg:col-span-1">
                    <?php if (has_custom_logo()) : ?>
                        <?php the_custom_logo(); ?>
                    <?php else : ?>
                        <span class="text-xl font-serif italic font-semibold text-accent">
                            <?php bloginfo('name'); ?>
                        </span>
                    <?php endif; ?>
                    <p class="mt-4 text-sm text-text-muted">
                        <?php bloginfo('description'); ?>
                    </p>
                </div>

                <!-- Quick Links -->
                <div>
                    <h4 class="font-label font-bold text-[10px] uppercase tracking-widest text-text-muted/60 mb-5">
                        <?php esc_html_e('Opleidingen', 'stridence'); ?>
                    </h4>
                    <ul class="space-y-2 text-sm">
                        <li><a href="<?php echo esc_url(home_url('/opleidingen/')); ?>" class="text-text hover:text-accent"><?php esc_html_e('Alle opleidingen', 'stridence'); ?></a></li>
                        <li><a href="<?php echo esc_url(home_url('/trajecten/')); ?>" class="text-text hover:text-accent"><?php esc_html_e('Trajecten', 'stridence'); ?></a></li>
                        <li><a href="<?php echo esc_url(home_url('/agenda/')); ?>" class="text-text hover:text-accent"><?php esc_html_e('Agenda', 'stridence'); ?></a></li>
                    </ul>
                </div>

                <!-- Support -->
                <div>
                    <h4 class="font-label font-bold text-[10px] uppercase tracking-widest text-text-muted/60 mb-5">
                        <?php esc_html_e('Ondersteuning', 'stridence'); ?>
                    </h4>
                    <ul class="space-y-2 text-sm">
                        <li><a href="<?php echo esc_url(home_url('/contact/')); ?>" class="text-text hover:text-accent"><?php esc_html_e('Contact', 'stridence'); ?></a></li>
                        <li><a href="<?php echo esc_url(home_url('/faq/')); ?>" class="text-text hover:text-accent"><?php esc_html_e('Veelgestelde vragen', 'stridence'); ?></a></li>
                        <li><a href="<?php echo esc_url(home_url('/over-ons/')); ?>" class="text-text hover:text-accent"><?php esc_html_e('Over ons', 'stridence'); ?></a></li>
                    </ul>
                </div>

                <!-- Legal -->
                <div>
                    <h4 class="font-label font-bold text-[10px] uppercase tracking-widest text-text-muted/60 mb-5">
                        <?php esc_html_e('Juridisch', 'stridence'); ?>
                    </h4>
                    <ul class="space-y-2 text-sm">
                        <li><a href="<?php echo esc_url(home_url('/privacy/')); ?>" class="text-text hover:text-accent"><?php esc_html_e('Privacybeleid', 'stridence'); ?></a></li>
                        <li><a href="<?php echo esc_url(home_url('/voorwaarden/')); ?>" class="text-text hover:text-accent"><?php esc_html_e('Algemene voorwaarden', 'stridence'); ?></a></li>
                    </ul>
                </div>
            </div>

            <!-- Bottom Bar -->
            <div class="mt-16 pt-8 flex flex-col sm:flex-row justify-between items-center gap-4">
                <p class="text-sm text-text-muted">
                    &copy; <?php echo esc_html(date('Y')); ?> <?php bloginfo('name'); ?>. <?php esc_html_e('Alle rechten voorbehouden.', 'stridence'); ?>
                </p>
            </div>
        </div>
    </footer>

</div><!-- #page -->

<!-- Toast Notification Container -->
<div x-data="toastStore()"
     @toast.window="show($event.detail)"
     class="fixed bottom-20 lg:bottom-6 inset-x-0 flex justify-center z-50 pointer-events-none px-4">
    <div x-show="visible"
         x-transition:enter="transition ease-out duration-normal"
         x-transition:enter-start="opacity-0 translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-fast"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 translate-y-2"
         :class="type === 'error' ? 'bg-error' : 'bg-primary-dark'"
         class="text-text-inverse text-sm px-5 py-3 rounded-lg shadow-overlay pointer-events-auto max-w-sm text-center"
         x-text="message"
         role="alert">
    </div>
</div>

<?php wp_footer(); ?>
</body>
</html>
