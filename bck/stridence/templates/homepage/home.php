<?php
/**
 * Homepage Template - Tailwind + Alpine
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

use Stride\Modules\Edition\EditionService;
use Stride\Modules\Trajectory\TrajectoryService;

get_header();

// Get data via services
$editionService = stride_service(EditionService::class);
$upcomingEditions = $editionService->getUpcomingEditions(6);

$trajectoryService = stride_service(TrajectoryService::class);
$openTrajectories = $trajectoryService->getOpenTrajectories();
$featuredTrajectories = array_slice($openTrajectories, 0, 3);
?>

<!-- Hero -->
<section class="relative bg-gradient-to-br from-primary-600 via-primary-700 to-primary-900 text-white overflow-hidden">
    <div class="absolute inset-0 bg-[url('data:image/svg+xml,...')] opacity-10"></div>
    <div class="container-lg py-20 lg:py-32 relative">
        <div class="max-w-3xl">
            <h1 class="text-4xl lg:text-5xl xl:text-6xl font-bold leading-tight mb-6">
                <?php esc_html_e('Ontwikkel jezelf met professionele trainingen', 'stridence'); ?>
            </h1>
            <p class="text-lg lg:text-xl text-primary-100 mb-8 max-w-2xl">
                <?php esc_html_e('Ontdek ons aanbod aan e-learning en klassikale cursussen voor jouw persoonlijke en professionele groei.', 'stridence'); ?>
            </p>
            <div class="flex flex-col sm:flex-row gap-4">
                <a href="<?php echo esc_url(home_url('/courses/')); ?>" class="btn bg-white text-primary-700 hover:bg-primary-50 btn-lg">
                    <?php esc_html_e('Bekijk cursussen', 'stridence'); ?>
                    <?php stridence_icon('arrow-right', 'w-5 h-5', 20); ?>
                </a>
                <a href="<?php echo esc_url(home_url('/trajecten/')); ?>" class="btn bg-primary-500/20 text-white hover:bg-primary-500/30 border border-primary-400/30 btn-lg">
                    <?php esc_html_e('Ontdek trajecten', 'stridence'); ?>
                </a>
            </div>
        </div>
    </div>
</section>

<!-- Course Types -->
<section class="py-16 lg:py-24 bg-slate-50">
    <div class="container-lg">
        <div class="text-center mb-12">
            <h2 class="text-3xl lg:text-4xl font-bold text-slate-900 mb-4">
                <?php esc_html_e('Kies je leerervaring', 'stridence'); ?>
            </h2>
            <p class="text-lg text-slate-600">
                <?php esc_html_e('Flexibel online leren of interactief in de klas', 'stridence'); ?>
            </p>
        </div>

        <div class="grid md:grid-cols-3 gap-8">
            <!-- E-learning -->
            <div class="card card-hover p-8 text-center">
                <div class="w-16 h-16 bg-blue-100 text-blue-600 rounded-2xl flex items-center justify-center mx-auto mb-6">
                    <?php stridence_icon('laptop', '', 32); ?>
                </div>
                <h3 class="text-xl font-semibold text-slate-900 mb-3"><?php esc_html_e('E-learning', 'stridence'); ?></h3>
                <p class="text-slate-600 mb-6"><?php esc_html_e('Leer waar en wanneer je wilt met onze online cursussen.', 'stridence'); ?></p>
                <ul class="text-left space-y-3 mb-6">
                    <li class="flex items-center gap-2 text-slate-600">
                        <?php stridence_icon('check', 'text-green-500', 18); ?>
                        <?php esc_html_e('Flexibel tempo', 'stridence'); ?>
                    </li>
                    <li class="flex items-center gap-2 text-slate-600">
                        <?php stridence_icon('check', 'text-green-500', 18); ?>
                        <?php esc_html_e('Direct toegang', 'stridence'); ?>
                    </li>
                    <li class="flex items-center gap-2 text-slate-600">
                        <?php stridence_icon('check', 'text-green-500', 18); ?>
                        <?php esc_html_e('Certificaat', 'stridence'); ?>
                    </li>
                </ul>
                <a href="<?php echo esc_url(home_url('/courses/')); ?>" class="btn btn-ghost w-full justify-center">
                    <?php esc_html_e('Bekijk e-learning', 'stridence'); ?>
                    <?php stridence_icon('chevron-right', '', 18); ?>
                </a>
            </div>

            <!-- Classroom -->
            <div class="card card-hover p-8 text-center">
                <div class="w-16 h-16 bg-amber-100 text-amber-600 rounded-2xl flex items-center justify-center mx-auto mb-6">
                    <?php stridence_icon('users', '', 32); ?>
                </div>
                <h3 class="text-xl font-semibold text-slate-900 mb-3"><?php esc_html_e('Klassikaal', 'stridence'); ?></h3>
                <p class="text-slate-600 mb-6"><?php esc_html_e('Interactieve training met een ervaren docent.', 'stridence'); ?></p>
                <ul class="text-left space-y-3 mb-6">
                    <li class="flex items-center gap-2 text-slate-600">
                        <?php stridence_icon('check', 'text-green-500', 18); ?>
                        <?php esc_html_e('Expert begeleiding', 'stridence'); ?>
                    </li>
                    <li class="flex items-center gap-2 text-slate-600">
                        <?php stridence_icon('check', 'text-green-500', 18); ?>
                        <?php esc_html_e('Netwerken', 'stridence'); ?>
                    </li>
                    <li class="flex items-center gap-2 text-slate-600">
                        <?php stridence_icon('check', 'text-green-500', 18); ?>
                        <?php esc_html_e('Praktijkgericht', 'stridence'); ?>
                    </li>
                </ul>
                <a href="<?php echo esc_url(home_url('/courses/')); ?>" class="btn btn-ghost w-full justify-center">
                    <?php esc_html_e('Bekijk klassikaal', 'stridence'); ?>
                    <?php stridence_icon('chevron-right', '', 18); ?>
                </a>
            </div>

            <!-- Trajectories -->
            <div class="card card-hover p-8 text-center">
                <div class="w-16 h-16 bg-purple-100 text-purple-600 rounded-2xl flex items-center justify-center mx-auto mb-6">
                    <?php stridence_icon('academic-cap', '', 32); ?>
                </div>
                <h3 class="text-xl font-semibold text-slate-900 mb-3"><?php esc_html_e('Trajecten', 'stridence'); ?></h3>
                <p class="text-slate-600 mb-6"><?php esc_html_e('Complete leerpaden voor diepgaande expertise.', 'stridence'); ?></p>
                <ul class="text-left space-y-3 mb-6">
                    <li class="flex items-center gap-2 text-slate-600">
                        <?php stridence_icon('check', 'text-green-500', 18); ?>
                        <?php esc_html_e('Gestructureerd pad', 'stridence'); ?>
                    </li>
                    <li class="flex items-center gap-2 text-slate-600">
                        <?php stridence_icon('check', 'text-green-500', 18); ?>
                        <?php esc_html_e('Voordelige bundel', 'stridence'); ?>
                    </li>
                    <li class="flex items-center gap-2 text-slate-600">
                        <?php stridence_icon('check', 'text-green-500', 18); ?>
                        <?php esc_html_e('Extra certificaat', 'stridence'); ?>
                    </li>
                </ul>
                <a href="<?php echo esc_url(home_url('/trajecten/')); ?>" class="btn btn-ghost w-full justify-center">
                    <?php esc_html_e('Bekijk trajecten', 'stridence'); ?>
                    <?php stridence_icon('chevron-right', '', 18); ?>
                </a>
            </div>
        </div>
    </div>
</section>

<!-- Upcoming Editions -->
<?php if (!empty($upcomingEditions)): ?>
<section class="py-16 lg:py-24">
    <div class="container-lg">
        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4 mb-10">
            <div>
                <h2 class="text-3xl lg:text-4xl font-bold text-slate-900 mb-2">
                    <?php esc_html_e('Binnenkort van start', 'stridence'); ?>
                </h2>
                <p class="text-lg text-slate-600">
                    <?php esc_html_e('Schrijf je in voor onze eerstvolgende trainingen', 'stridence'); ?>
                </p>
            </div>
            <a href="<?php echo esc_url(home_url('/courses/')); ?>" class="btn btn-secondary shrink-0">
                <?php esc_html_e('Alle cursussen', 'stridence'); ?>
            </a>
        </div>

        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($upcomingEditions as $edition): ?>
                <?php
                $editionId = $edition['id'] ?? $edition['ID'] ?? 0;
                $courseId = $edition['meta']['course_id'] ?? $edition['course_id'] ?? 0;
                $course = $courseId ? get_post($courseId) : null;
                $title = $course ? $course->post_title : ($edition['title'] ?? $edition['post_title'] ?? '');
                $url = $courseId ? get_permalink($courseId) : get_permalink($editionId);
                $thumbnail = $courseId ? get_the_post_thumbnail_url($courseId, 'medium_large') : null;
                $startDate = $edition['meta']['start_date'] ?? $edition['start_date'] ?? '';
                $location = $edition['meta']['location'] ?? $edition['location'] ?? '';
                $price = (float) ($edition['meta']['price'] ?? $edition['price'] ?? 0);
                ?>
                <article class="card card-hover group">
                    <div class="aspect-video bg-slate-100 relative overflow-hidden">
                        <?php if ($thumbnail): ?>
                            <img src="<?php echo esc_url($thumbnail); ?>" alt="" class="w-full h-full object-cover group-hover:scale-105 transition duration-300">
                        <?php else: ?>
                            <div class="w-full h-full flex items-center justify-center text-slate-300">
                                <?php stridence_icon('book', '', 48); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="p-5">
                        <h3 class="font-semibold text-slate-900 mb-3 line-clamp-2">
                            <a href="<?php echo esc_url($url); ?>" class="hover:text-primary-600 transition">
                                <?php echo esc_html($title); ?>
                            </a>
                        </h3>
                        <div class="flex flex-wrap gap-3 text-sm text-slate-500 mb-4">
                            <?php if ($startDate): ?>
                                <span class="flex items-center gap-1.5 text-primary-600 font-medium">
                                    <?php stridence_icon('calendar', '', 16); ?>
                                    <?php echo esc_html(date_i18n('j M Y', strtotime($startDate))); ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($location): ?>
                                <span class="flex items-center gap-1.5">
                                    <?php stridence_icon('map-pin', '', 16); ?>
                                    <?php echo esc_html($location); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php if ($price > 0): ?>
                            <div class="text-lg font-bold text-primary-600">
                                €<?php echo esc_html(number_format($price, 0, ',', '.')); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Trajectories -->
<?php if (!empty($featuredTrajectories)): ?>
<section class="py-16 lg:py-24 bg-slate-50">
    <div class="container-lg">
        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4 mb-10">
            <div>
                <h2 class="text-3xl lg:text-4xl font-bold text-slate-900 mb-2">
                    <?php esc_html_e('Trajecten', 'stridence'); ?>
                </h2>
                <p class="text-lg text-slate-600">
                    <?php esc_html_e('Complete leerpaden voor specialisatie', 'stridence'); ?>
                </p>
            </div>
            <a href="<?php echo esc_url(home_url('/trajecten/')); ?>" class="btn btn-secondary shrink-0">
                <?php esc_html_e('Alle trajecten', 'stridence'); ?>
            </a>
        </div>

        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($featuredTrajectories as $trajectory): ?>
                <?php
                $thumbnail = get_the_post_thumbnail_url($trajectory['id'], 'medium_large');
                $courseCount = count($trajectory['courses'] ?? []);
                ?>
                <article class="card card-hover group">
                    <div class="aspect-video bg-primary-100 relative overflow-hidden">
                        <?php if ($thumbnail): ?>
                            <img src="<?php echo esc_url($thumbnail); ?>" alt="" class="w-full h-full object-cover group-hover:scale-105 transition duration-300">
                        <?php else: ?>
                            <div class="w-full h-full flex items-center justify-center text-primary-300">
                                <?php stridence_icon('academic-cap', '', 48); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="p-5">
                        <h3 class="font-semibold text-slate-900 mb-2">
                            <a href="<?php echo esc_url(get_permalink($trajectory['id'])); ?>" class="hover:text-primary-600 transition">
                                <?php echo esc_html($trajectory['title']); ?>
                            </a>
                        </h3>
                        <?php if (!empty($trajectory['description'])): ?>
                            <p class="text-sm text-slate-600 mb-4 line-clamp-2">
                                <?php echo esc_html(wp_trim_words($trajectory['description'], 15)); ?>
                            </p>
                        <?php endif; ?>
                        <div class="flex items-center justify-between">
                            <?php if ($courseCount > 0): ?>
                                <span class="flex items-center gap-1.5 text-sm text-slate-500">
                                    <?php stridence_icon('book', '', 16); ?>
                                    <?php printf(_n('%d cursus', '%d cursussen', $courseCount, 'stridence'), $courseCount); ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($trajectory['price'] > 0): ?>
                                <span class="text-lg font-bold text-primary-600">
                                    €<?php echo esc_html(number_format($trajectory['price'], 0, ',', '.')); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Features -->
<section class="py-16 lg:py-24">
    <div class="container-lg">
        <h2 class="text-3xl lg:text-4xl font-bold text-slate-900 text-center mb-12">
            <?php esc_html_e('Waarom kiezen voor ons?', 'stridence'); ?>
        </h2>

        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-8">
            <div class="text-center">
                <div class="w-14 h-14 bg-primary-100 text-primary-600 rounded-xl flex items-center justify-center mx-auto mb-4">
                    <?php stridence_icon('award', '', 28); ?>
                </div>
                <h3 class="font-semibold text-slate-900 mb-2"><?php esc_html_e('Erkende certificaten', 'stridence'); ?></h3>
                <p class="text-sm text-slate-600"><?php esc_html_e('Ontvang erkende certificaten die je carrière een boost geven.', 'stridence'); ?></p>
            </div>
            <div class="text-center">
                <div class="w-14 h-14 bg-primary-100 text-primary-600 rounded-xl flex items-center justify-center mx-auto mb-4">
                    <?php stridence_icon('users', '', 28); ?>
                </div>
                <h3 class="font-semibold text-slate-900 mb-2"><?php esc_html_e('Expert docenten', 'stridence'); ?></h3>
                <p class="text-sm text-slate-600"><?php esc_html_e('Leer van professionals met jarenlange praktijkervaring.', 'stridence'); ?></p>
            </div>
            <div class="text-center">
                <div class="w-14 h-14 bg-primary-100 text-primary-600 rounded-xl flex items-center justify-center mx-auto mb-4">
                    <?php stridence_icon('clock', '', 28); ?>
                </div>
                <h3 class="font-semibold text-slate-900 mb-2"><?php esc_html_e('Flexibel leren', 'stridence'); ?></h3>
                <p class="text-sm text-slate-600"><?php esc_html_e('Kies zelf je tempo en leermoment, online of in de klas.', 'stridence'); ?></p>
            </div>
            <div class="text-center">
                <div class="w-14 h-14 bg-primary-100 text-primary-600 rounded-xl flex items-center justify-center mx-auto mb-4">
                    <?php stridence_icon('check-circle', '', 28); ?>
                </div>
                <h3 class="font-semibold text-slate-900 mb-2"><?php esc_html_e('Praktijkgericht', 'stridence'); ?></h3>
                <p class="text-sm text-slate-600"><?php esc_html_e('Direct toepasbare kennis en vaardigheden voor je werk.', 'stridence'); ?></p>
            </div>
        </div>
    </div>
</section>

<!-- CTA -->
<section class="py-16 lg:py-24 bg-gradient-to-br from-primary-600 to-primary-800 text-white">
    <div class="container-lg text-center">
        <h2 class="text-3xl lg:text-4xl font-bold mb-4"><?php esc_html_e('Klaar om te groeien?', 'stridence'); ?></h2>
        <p class="text-lg text-primary-100 mb-8 max-w-2xl mx-auto">
            <?php esc_html_e('Start vandaag nog met je ontwikkeling en ontdek ons volledige aanbod.', 'stridence'); ?>
        </p>
        <div class="flex flex-col sm:flex-row gap-4 justify-center">
            <a href="<?php echo esc_url(home_url('/courses/')); ?>" class="btn bg-white text-primary-700 hover:bg-primary-50 btn-lg">
                <?php esc_html_e('Bekijk alle cursussen', 'stridence'); ?>
            </a>
            <a href="<?php echo esc_url(home_url('/contact/')); ?>" class="btn bg-primary-500/20 text-white hover:bg-primary-500/30 border border-primary-400/30 btn-lg">
                <?php esc_html_e('Neem contact op', 'stridence'); ?>
            </a>
        </div>
    </div>
</section>

<?php get_footer(); ?>
