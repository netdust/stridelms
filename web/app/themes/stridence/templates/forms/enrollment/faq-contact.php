<?php
/**
 * Enrollment Form — Contact CTA
 *
 * Full-width section below the FAQ. Rendered outside the container.
 */
?>
<div class="bg-surface-alt py-12 lg:py-16 text-center">
    <div class="container">
        <p class="font-heading text-lg font-semibold text-text mb-2">Nog vragen over je inschrijving?</p>
        <p class="text-text-muted mb-6">Ons team helpt je graag verder.</p>
        <a href="<?= esc_url(home_url('/contact/')) ?>" class="btn-primary">
            <?= stridence_icon('mail', 'w-4 h-4 mr-2') ?>
            Neem contact op
        </a>
    </div>
</div>
