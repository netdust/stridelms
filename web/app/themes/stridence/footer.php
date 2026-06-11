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
    <footer class="bg-surface-card mt-auto border-t border-border-soft <?php echo is_page_template('page-mijn-account.php') ? 'hidden lg:block' : ''; ?>">
        <div class="max-w-[1080px] mx-auto px-5 py-10 lg:py-12">

            <!-- Main footer row: brand left, link groups right -->
            <div class="flex flex-wrap justify-between gap-8 items-start">

                <!-- Brand / Address column -->
                <div class="flex flex-col gap-2.5">
                    <!-- Wordmark -->
                    <div class="text-lg font-extrabold tracking-tight text-text">
                        <?php echo esc_html(get_bloginfo('name')); ?><span class="text-primary">.</span>
                    </div>
                    <!-- Address / contact info from site description -->
                    <div class="text-[13px] text-text-faint leading-relaxed">
                        <?php echo esc_html(get_bloginfo('description')); ?>
                    </div>
                </div>

                <!-- Link groups -->
                <div class="flex flex-wrap gap-x-10 lg:gap-x-14 gap-y-8">

                    <!-- Group 1: Aanbod -->
                    <div class="flex flex-col gap-2 text-[13px]">
                        <span class="font-bold text-text"><?php esc_html_e('Aanbod', 'stridence'); ?></span>
                        <a href="<?php echo esc_url(home_url('/trajecten/')); ?>" class="text-text-muted hover:text-text"><?php esc_html_e('Trajecten', 'stridence'); ?></a>
                        <a href="<?php echo esc_url(home_url('/opleidingen/')); ?>" class="text-text-muted hover:text-text"><?php esc_html_e('Klassikaal', 'stridence'); ?></a>
                        <a href="<?php echo esc_url(home_url('/online/')); ?>" class="text-text-muted hover:text-text"><?php esc_html_e('Online', 'stridence'); ?></a>
                        <a href="<?php echo esc_url(home_url('/agenda/')); ?>" class="text-text-muted hover:text-text"><?php esc_html_e('Agenda', 'stridence'); ?></a>
                    </div>

                    <!-- Group 2: Over (site name) -->
                    <div class="flex flex-col gap-2 text-[13px]">
                        <span class="font-bold text-text"><?php echo esc_html(get_bloginfo('name')); ?></span>
                        <a href="<?php echo esc_url(home_url('/over-ons/')); ?>" class="text-text-muted hover:text-text"><?php esc_html_e('Over ons', 'stridence'); ?></a>
                        <a href="<?php echo esc_url(home_url('/contact/')); ?>" class="text-text-muted hover:text-text"><?php esc_html_e('Contact', 'stridence'); ?></a>
                        <a href="<?php echo esc_url(home_url('/faq/')); ?>" class="text-text-muted hover:text-text"><?php esc_html_e('Veelgestelde vragen', 'stridence'); ?></a>
                        <a href="<?php echo esc_url(home_url('/mijn-account/')); ?>" class="text-text-muted hover:text-text"><?php esc_html_e('Mijn account', 'stridence'); ?></a>
                    </div>

                </div><!-- /link groups -->
            </div><!-- /main footer row -->

            <!-- Bottom bar: copyright + legal links -->
            <div class="mt-8 pt-6 border-t border-border-soft flex flex-wrap items-center justify-between gap-3 text-[13px] text-text-faint">
                <span>&copy; <?php echo esc_html(date('Y')); ?> <?php echo esc_html(get_bloginfo('name')); ?></span>
                <div class="flex flex-wrap gap-4">
                    <a href="<?php echo esc_url(home_url('/privacy/')); ?>" class="hover:text-text"><?php esc_html_e('Privacybeleid', 'stridence'); ?></a>
                    <a href="<?php echo esc_url(home_url('/voorwaarden/')); ?>" class="hover:text-text"><?php esc_html_e('Algemene voorwaarden', 'stridence'); ?></a>
                </div>
            </div>

        </div>
    </footer>

</div><!-- #page -->

<!-- Toast Notification (design card, back-compatible payload) -->
<div x-data="toastStore()"
     @toast.window="show($event.detail)"
     x-show="visible"
     x-cloak
     x-transition:enter="transition ease-out duration-normal"
     x-transition:enter-start="opacity-0 translate-y-2"
     x-transition:enter-end="opacity-100 translate-y-0"
     x-transition:leave="transition ease-in duration-fast"
     x-transition:leave-start="opacity-100 translate-y-0"
     x-transition:leave-end="opacity-0 translate-y-2"
     class="fixed bottom-6 right-6 z-50 w-[340px] max-w-[calc(100vw-3rem)] rounded-[12px] bg-surface-card shadow-overlay p-3.5 px-4 flex items-start gap-3"
     role="alert">
    <div class="w-7 h-7 rounded-full grid place-items-center text-sm font-extrabold shrink-0"
         :class="type === 'error' ? 'bg-badge-full-bg text-badge-full-text' : 'bg-badge-open-bg text-badge-open-text'"
         x-text="type === 'error' ? '!' : '✓'"
         aria-hidden="true"></div>
    <div class="flex-1 min-w-0">
        <div class="text-sm font-bold text-text" x-text="title"></div>
        <div x-show="sub" class="text-[13px] text-text-muted mt-0.5" x-text="sub"></div>
    </div>
    <button type="button"
            @click="close()"
            class="text-base leading-none text-text-faint hover:text-text cursor-pointer shrink-0"
            aria-label="<?php esc_attr_e('Melding sluiten', 'stridence'); ?>">&times;</button>
</div>

<?php wp_footer(); ?>
</body>
</html>
