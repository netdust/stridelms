<?php
/**
 * Trajectory Catalog Archive
 *
 * Displays trajectories in an explanatory, full-width layout.
 * No filters needed - trajectories are few and require explanation.
 *
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// Query all published trajectories
$model = ntdst_data()->get('vad_trajectory');
$trajectories = $model->where('post_status', 'publish')
                      ->orderBy('menu_order', 'ASC')
                      ->orderBy('post_title', 'ASC')
                      ->withMeta()
                      ->get();

get_header();
?>

<!-- Page Header -->
<div class="bg-primary text-text-inverse">
    <div class="container py-12 lg:py-16">
        <h1 class="text-3xl lg:text-4xl font-heading font-bold mb-4">
            <?php esc_html_e('Leertrajecten', 'stridence'); ?>
        </h1>
        <p class="text-xl text-text-inverse/80 max-w-2xl">
            <?php esc_html_e('Onze trajecten zijn langlopende opleidingsprogramma\'s voor professionals die zich willen specialiseren. Een traject combineert meerdere cursussen, praktijkopdrachten en begeleiding tot een samenhangend geheel.', 'stridence'); ?>
        </p>
    </div>
</div>

<!-- What is a trajectory -->
<div class="bg-surface-alt border-b border-border">
    <div class="container py-8">
        <div class="grid md:grid-cols-3 gap-6 text-center">
            <div class="flex flex-col items-center">
                <div class="w-12 h-12 rounded-full bg-primary/10 flex items-center justify-center mb-3">
                    <?php echo stridence_icon('calendar', 'w-6 h-6 text-primary'); ?>
                </div>
                <h3 class="font-semibold text-text mb-1"><?php esc_html_e('Langlopend', 'stridence'); ?></h3>
                <p class="text-sm text-text-muted"><?php esc_html_e('Maanden tot een jaar, met vaste momenten', 'stridence'); ?></p>
            </div>
            <div class="flex flex-col items-center">
                <div class="w-12 h-12 rounded-full bg-accent/10 flex items-center justify-center mb-3">
                    <?php echo stridence_icon('layers', 'w-6 h-6 text-accent'); ?>
                </div>
                <h3 class="font-semibold text-text mb-1"><?php esc_html_e('Samengesteld', 'stridence'); ?></h3>
                <p class="text-sm text-text-muted"><?php esc_html_e('Cursussen, opdrachten en begeleiding', 'stridence'); ?></p>
            </div>
            <div class="flex flex-col items-center">
                <div class="w-12 h-12 rounded-full bg-success/10 flex items-center justify-center mb-3">
                    <?php echo stridence_icon('award', 'w-6 h-6 text-success'); ?>
                </div>
                <h3 class="font-semibold text-text mb-1"><?php esc_html_e('Erkend', 'stridence'); ?></h3>
                <p class="text-sm text-text-muted"><?php esc_html_e('Geaccrediteerd en erkend door beroepsorganisaties', 'stridence'); ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Trajectories List -->
<div class="container py-12 lg:py-16">
    <?php if (!empty($trajectories)) : ?>
        <div class="space-y-8">
            <?php foreach ($trajectories as $trajectory) :
                // Extract trajectory data
                $id = (int) ($trajectory['id'] ?? $trajectory['ID'] ?? 0);
                $title = $trajectory['title'] ?? $trajectory['post_title'] ?? '';
                $content = $trajectory['content'] ?? $trajectory['post_content'] ?? '';

                // Get excerpt - try multiple sources
                $excerpt = $trajectory['excerpt'] ?? $trajectory['post_excerpt'] ?? '';
                if (empty($excerpt)) {
                    // Try getting it directly from the post
                    $post_obj = get_post($id);
                    $excerpt = $post_obj ? $post_obj->post_excerpt : '';
                }
                if (empty($excerpt)) {
                    // Fall back to trimmed content
                    $excerpt = wp_trim_words(wp_strip_all_tags($content), 40, '...');
                }
                $permalink = get_permalink($id);

                // Meta fields
                $meta = $trajectory['meta'] ?? [];
                $status = $meta['status'] ?? $meta['_ntdst_status'] ?? 'open';
                $course_count = (int) ($meta['course_count'] ?? $meta['_ntdst_course_count'] ?? 0);
                $deadline = $meta['enrollment_deadline'] ?? $meta['_ntdst_enrollment_deadline'] ?? '';
                $duration = $meta['duration'] ?? $meta['_ntdst_duration'] ?? '';

                // Status display
                $status_labels = [
                    'open' => __('Open voor inschrijving', 'stridence'),
                    'ongoing' => __('Lopend', 'stridence'),
                    'completed' => __('Afgerond', 'stridence'),
                    'closed' => __('Gesloten', 'stridence'),
                ];
                $status_label = $status_labels[$status] ?? $status_labels['open'];

                $status_colors = [
                    'open' => 'bg-success/10 text-success',
                    'ongoing' => 'bg-accent/10 text-accent',
                    'completed' => 'bg-text-muted/10 text-text-muted',
                    'closed' => 'bg-error/10 text-error',
                ];
                $status_color = $status_colors[$status] ?? $status_colors['open'];

                // Get thumbnail
                $thumbnail = get_the_post_thumbnail($id, 'medium_large', ['class' => 'w-full h-full object-cover']);

                // Get tagline/subtitle if available
                $tagline = $meta['tagline'] ?? $meta['_ntdst_tagline'] ?? '';

                // Target audience
                $target_audience = $meta['target_audience'] ?? $meta['_ntdst_target_audience'] ?? '';
                ?>
                <article class="card overflow-hidden flex flex-col md:flex-row">
                    <!-- Media Column -->
                    <div class="hidden md:block w-36 shrink-0 bg-gradient-to-br from-primary/5 to-accent/5 border-r border-border">
                        <div class="w-full h-full flex items-center justify-center min-h-[180px]">
                            <?php if ($thumbnail) : ?>
                                <?php echo $thumbnail; ?>
                            <?php else : ?>
                                <?php echo stridence_icon('layers', 'w-10 h-10 text-primary/30'); ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Content Column -->
                    <div class="flex-1 p-6">
                            <!-- Header with status -->
                            <div class="flex items-start justify-between gap-4 mb-2">
                                <h2 class="font-heading font-bold text-xl text-text">
                                    <a href="<?php echo esc_url($permalink); ?>" class="hover:text-primary transition-colors">
                                        <?php echo esc_html($title); ?>
                                    </a>
                                </h2>
                                <span class="shrink-0 inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium <?php echo esc_attr($status_color); ?>">
                                    <?php echo esc_html($status_label); ?>
                                </span>
                            </div>

                            <!-- Tagline / What is this -->
                            <?php if ($tagline) : ?>
                                <p class="text-primary font-medium text-sm mb-3">
                                    <?php echo esc_html($tagline); ?>
                                </p>
                            <?php endif; ?>

                            <!-- Description -->
                            <div class="mb-4">
                                <h3 class="text-xs font-semibold text-text-muted uppercase tracking-wide mb-1">
                                    <?php esc_html_e('Wat leer je?', 'stridence'); ?>
                                </h3>
                                <p class="text-text-muted text-sm">
                                    <?php echo esc_html($excerpt); ?>
                                </p>
                            </div>

                            <!-- Why this trajectory -->
                            <?php if ($target_audience) : ?>
                            <div class="mb-4">
                                <h3 class="text-xs font-semibold text-text-muted uppercase tracking-wide mb-1">
                                    <?php esc_html_e('Voor wie?', 'stridence'); ?>
                                </h3>
                                <p class="text-text-muted text-sm">
                                    <?php echo esc_html($target_audience); ?>
                                </p>
                            </div>
                            <?php endif; ?>

                            <!-- Meta info -->
                            <div class="flex flex-wrap gap-4 text-sm text-text-muted mb-4 pt-4 border-t border-border">
                                <?php if ($course_count > 0) : ?>
                                    <div class="flex items-center gap-1.5">
                                        <?php echo stridence_icon('book-open', 'w-4 h-4'); ?>
                                        <span>
                                            <?php
                                                printf(
                                                    esc_html(_n('%d cursus', '%d cursussen', $course_count, 'stridence')),
                                                    $course_count,
                                                );
                                    ?>
                                        </span>
                                    </div>
                                <?php endif; ?>

                                <?php if ($duration) : ?>
                                    <div class="flex items-center gap-1.5">
                                        <?php echo stridence_icon('clock', 'w-4 h-4'); ?>
                                        <span><?php echo esc_html($duration); ?></span>
                                    </div>
                                <?php endif; ?>

                                <?php if ($deadline && $status === 'open') : ?>
                                    <div class="flex items-center gap-1.5">
                                        <?php echo stridence_icon('calendar', 'w-4 h-4'); ?>
                                        <span><?php esc_html_e('Deadline:', 'stridence'); ?> <?php echo esc_html(stride_format_date($deadline)); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>

                        <!-- CTA -->
                        <a href="<?php echo esc_url($permalink); ?>" class="btn-primary inline-flex items-center gap-2">
                            <?php esc_html_e('Bekijk traject', 'stridence'); ?>
                            <?php echo stridence_icon('arrow-right', 'w-4 h-4'); ?>
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

    <?php else : ?>
        <!-- Empty State -->
        <div class="text-center py-16">
            <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-surface-alt flex items-center justify-center">
                <?php echo stridence_icon('layers', 'w-8 h-8 text-text-muted'); ?>
            </div>
            <h2 class="font-heading font-semibold text-xl text-text mb-2">
                <?php esc_html_e('Geen trajecten beschikbaar', 'stridence'); ?>
            </h2>
            <p class="text-text-muted max-w-md mx-auto">
                <?php esc_html_e('Er zijn momenteel geen leertrajecten beschikbaar. Bekijk ons aanbod aan klassikale en online opleidingen.', 'stridence'); ?>
            </p>
            <div class="mt-6 flex justify-center gap-4">
                <a href="<?php echo esc_url(home_url('/klassikaal/')); ?>" class="btn-primary">
                    <?php esc_html_e('Klassikale opleidingen', 'stridence'); ?>
                </a>
                <a href="<?php echo esc_url(home_url('/online/')); ?>" class="btn-ghost">
                    <?php esc_html_e('Online leren', 'stridence'); ?>
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- CTA Section -->
<?php if (!empty($trajectories)) : ?>
<div class="bg-surface-alt border-t border-border">
    <div class="container py-12 text-center">
        <h2 class="font-heading font-bold text-xl text-text mb-2">
            <?php esc_html_e('Niet gevonden wat je zoekt?', 'stridence'); ?>
        </h2>
        <p class="text-text-muted mb-6">
            <?php esc_html_e('Bekijk ook ons aanbod aan losse cursussen en e-learning modules.', 'stridence'); ?>
        </p>
        <div class="flex justify-center gap-4">
            <a href="<?php echo esc_url(home_url('/klassikaal/')); ?>" class="btn-ghost">
                <?php esc_html_e('Klassikaal', 'stridence'); ?>
            </a>
            <a href="<?php echo esc_url(home_url('/online/')); ?>" class="btn-ghost">
                <?php esc_html_e('Online', 'stridence'); ?>
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<?php get_footer(); ?>
