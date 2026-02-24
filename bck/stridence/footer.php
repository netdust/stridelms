<?php
/**
 * Stridence Footer - Tailwind
 *
 * @package stridence
 */

defined('ABSPATH') || exit;
?>
    </main>

    <!-- Footer -->
    <footer class="bg-slate-900 text-slate-300">
        <div class="container-lg py-12 lg:py-16">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8 lg:gap-12">
                <!-- Brand -->
                <div class="lg:col-span-2">
                    <a href="<?php echo esc_url(home_url('/')); ?>" class="inline-block text-white text-xl font-bold mb-4">
                        <?php bloginfo('name'); ?>
                    </a>
                    <p class="text-slate-400 max-w-md">
                        <?php echo esc_html(get_bloginfo('description')); ?>
                    </p>
                </div>

                <!-- Quick Links -->
                <div>
                    <h3 class="text-white font-semibold mb-4"><?php esc_html_e('Navigatie', 'stridence'); ?></h3>
                    <?php
                    wp_nav_menu([
                        'theme_location' => 'footer',
                        'container' => false,
                        'menu_class' => 'space-y-3',
                        'fallback_cb' => false,
                        'items_wrap' => '<ul class="%2$s">%3$s</ul>',
                        'walker' => new class extends Walker_Nav_Menu {
                            public function start_el(&$output, $item, $depth = 0, $args = null, $id = 0): void {
                                $output .= '<li><a href="' . esc_url($item->url) . '" class="text-slate-400 hover:text-white transition">' . esc_html($item->title) . '</a></li>';
                            }
                        },
                    ]);
                    ?>
                </div>

                <!-- Contact -->
                <div>
                    <h3 class="text-white font-semibold mb-4"><?php esc_html_e('Contact', 'stridence'); ?></h3>
                    <ul class="space-y-3 text-slate-400">
                        <li>
                            <a href="mailto:info@example.com" class="hover:text-white transition">info@example.com</a>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Bottom -->
            <div class="mt-12 pt-8 border-t border-slate-800 flex flex-col sm:flex-row justify-between items-center gap-4">
                <p class="text-slate-500 text-sm">
                    &copy; <?php echo date('Y'); ?> <?php bloginfo('name'); ?>. <?php esc_html_e('Alle rechten voorbehouden.', 'stridence'); ?>
                </p>
            </div>
        </div>
    </footer>
</div>

<?php wp_footer(); ?>
</body>
</html>
