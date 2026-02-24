<?php
/**
 * Stridence Header - Tailwind + Alpine
 *
 * @package stridence
 */

defined('ABSPATH') || exit;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?> class="scroll-smooth">
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>

<body <?php body_class('font-sans antialiased text-slate-900 bg-white'); ?>>
<?php wp_body_open(); ?>

<div id="app" class="min-h-screen flex flex-col">
    <!-- Header -->
    <header x-data="mobileNav" class="sticky top-0 z-50 bg-white/95 backdrop-blur border-b border-slate-200">
        <div class="container-lg">
            <div class="flex items-center justify-between h-16 lg:h-20">
                <!-- Logo -->
                <a href="<?php echo esc_url(home_url('/')); ?>" class="flex-shrink-0">
                    <?php if (has_custom_logo()): ?>
                        <?php the_custom_logo(); ?>
                    <?php else: ?>
                        <span class="text-xl font-bold text-primary-600"><?php bloginfo('name'); ?></span>
                    <?php endif; ?>
                </a>

                <!-- Desktop Nav -->
                <nav class="hidden lg:flex items-center gap-8">
                    <?php
                    wp_nav_menu([
                        'theme_location' => 'primary',
                        'container' => false,
                        'menu_class' => 'flex items-center gap-8',
                        'fallback_cb' => false,
                        'items_wrap' => '<ul class="%2$s">%3$s</ul>',
                        'walker' => new class extends Walker_Nav_Menu {
                            public function start_el(&$output, $item, $depth = 0, $args = null, $id = 0): void {
                                $classes = in_array('current-menu-item', $item->classes ?? []) ? 'text-primary-600' : 'text-slate-700 hover:text-primary-600';
                                $output .= '<li><a href="' . esc_url($item->url) . '" class="font-medium transition ' . $classes . '">' . esc_html($item->title) . '</a></li>';
                            }
                        },
                    ]);
                    ?>
                </nav>

                <!-- Desktop Actions -->
                <div class="hidden lg:flex items-center gap-4">
                    <?php if (is_user_logged_in()): ?>
                        <?php $user = wp_get_current_user(); ?>
                        <a href="<?php echo esc_url(home_url('/mijn-account/')); ?>" class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-slate-100 transition">
                            <img src="<?php echo esc_url(get_avatar_url($user->ID, ['size' => 32])); ?>" alt="" class="w-8 h-8 rounded-full">
                            <span class="font-medium text-slate-700"><?php echo esc_html($user->display_name); ?></span>
                        </a>
                    <?php else: ?>
                        <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>" class="btn btn-primary">
                            <?php esc_html_e('Inloggen', 'stridence'); ?>
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Mobile Menu Button -->
                <button
                    @click="toggle()"
                    class="lg:hidden p-2 -mr-2 text-slate-700 hover:text-slate-900"
                    :aria-expanded="open"
                    aria-label="<?php esc_attr_e('Menu', 'stridence'); ?>"
                >
                    <template x-if="!open"><?php stridence_icon('menu', '', 24); ?></template>
                    <template x-if="open"><?php stridence_icon('x', '', 24); ?></template>
                </button>
            </div>
        </div>

        <!-- Mobile Menu -->
        <div
            x-show="open"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 -translate-y-2"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 -translate-y-2"
            @click.away="close()"
            @keydown.escape.window="close()"
            class="lg:hidden border-t border-slate-200 bg-white"
            x-cloak
        >
            <nav class="container-lg py-4">
                <?php
                wp_nav_menu([
                    'theme_location' => 'primary',
                    'container' => false,
                    'menu_class' => 'space-y-1',
                    'fallback_cb' => false,
                    'items_wrap' => '<ul class="%2$s">%3$s</ul>',
                    'walker' => new class extends Walker_Nav_Menu {
                        public function start_el(&$output, $item, $depth = 0, $args = null, $id = 0): void {
                            $classes = in_array('current-menu-item', $item->classes ?? []) ? 'bg-primary-50 text-primary-600' : 'text-slate-700 hover:bg-slate-50';
                            $output .= '<li><a href="' . esc_url($item->url) . '" class="block px-4 py-3 rounded-lg font-medium transition ' . $classes . '">' . esc_html($item->title) . '</a></li>';
                        }
                    },
                ]);
                ?>

                <?php if (is_user_logged_in()): ?>
                    <?php $user = wp_get_current_user(); ?>
                    <div class="mt-4 pt-4 border-t border-slate-200">
                        <a href="<?php echo esc_url(home_url('/mijn-account/')); ?>" class="flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-slate-50 transition">
                            <img src="<?php echo esc_url(get_avatar_url($user->ID, ['size' => 40])); ?>" alt="" class="w-10 h-10 rounded-full">
                            <div>
                                <div class="font-medium text-slate-900"><?php echo esc_html($user->display_name); ?></div>
                                <div class="text-sm text-slate-500"><?php esc_html_e('Bekijk profiel', 'stridence'); ?></div>
                            </div>
                        </a>
                    </div>
                <?php else: ?>
                    <div class="mt-4 pt-4 border-t border-slate-200">
                        <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>" class="btn btn-primary w-full justify-center">
                            <?php esc_html_e('Inloggen', 'stridence'); ?>
                        </a>
                    </div>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <main class="flex-1">
