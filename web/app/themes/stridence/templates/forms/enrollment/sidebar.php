<?php
/**
 * Enrollment Form — Sidebar Summary
 *
 * @var array $args {
 *     @type array  $edition_data  Edition details (title, start_date, venue, price, sessions)
 *     @type array  $course_data   Course details (id, title, excerpt, url)
 * }
 */

$edition_data = $args['edition_data'] ?? [];
$course_data  = $args['course_data'] ?? [];
?>
<aside class="card p-6 sticky top-24">
    <h3 class="font-heading font-semibold text-lg mb-4">Samenvatting</h3>

    <?php if (!empty($edition_data['title'])) : ?>
        <!-- Course/Edition Info -->
        <div class="mb-6">
            <h4 class="font-medium text-text mb-2"><?= esc_html($edition_data['title']) ?></h4>

            <div class="text-sm text-text-muted space-y-2">
                <?php if (!empty($edition_data['start_date'])) : ?>
                    <p class="flex items-center gap-2">
                        <?= stridence_icon('calendar', 'w-4 h-4 shrink-0') ?>
                        <span><?= esc_html(stride_format_date($edition_data['start_date'])) ?></span>
                    </p>
                <?php endif; ?>

                <?php if (!empty($edition_data['venue'])) : ?>
                    <p class="flex items-center gap-2">
                        <?= stridence_icon('map-pin', 'w-4 h-4 shrink-0') ?>
                        <span><?= esc_html($edition_data['venue']) ?></span>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Sessions -->
        <?php if (!empty($edition_data['sessions'])) : ?>
            <div class="mb-6 pt-4 border-t border-border">
                <h4 class="text-sm font-medium text-text mb-3">Sessies</h4>
                <ul class="text-sm text-text-muted space-y-2">
                    <?php foreach ($edition_data['sessions'] as $session) : ?>
                        <li class="flex items-center gap-2">
                            <?= stridence_icon('clock', 'w-4 h-4 shrink-0') ?>
                            <span>
                                <?php if (!empty($session['date'])) : ?>
                                    <?= esc_html(stride_format_date($session['date'])) ?>
                                <?php endif; ?>
                                <?php if (!empty($session['start_time']) && !empty($session['end_time'])) : ?>
                                    <span class="text-text-muted">
                                        (<?= esc_html($session['start_time']) ?> - <?= esc_html($session['end_time']) ?>)
                                    </span>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Price -->
        <?php if (!empty($edition_data['price'])) : ?>
            <div class="pt-4 border-t border-border">
                <div class="flex justify-between items-center">
                    <span class="text-text-muted">Prijs</span>
                    <span class="text-xl font-bold text-text"><?= esc_html($edition_data['price']) ?></span>
                </div>
                <p class="text-xs text-text-muted mt-1">Excl. BTW</p>
            </div>
        <?php endif; ?>

    <?php else : ?>
        <p class="text-sm text-text-muted">
            Selecteer een opleiding om in te schrijven.
        </p>
    <?php endif; ?>

    <!-- Back to course link -->
    <?php if (!empty($course_data['url'])) : ?>
        <div class="mt-6 pt-4 border-t border-border">
            <a href="<?= esc_url($course_data['url']) ?>" class="text-sm text-primary hover:underline flex items-center gap-1">
                <?= stridence_icon('arrow-left', 'w-4 h-4') ?>
                Terug naar opleiding
            </a>
        </div>
    <?php endif; ?>
</aside>
