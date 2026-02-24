<?php
/**
 * Form Confirmation/Success Template
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

get_header();

// Determine confirmation type from URL
$type = isset($_GET['type']) ? sanitize_key($_GET['type']) : 'enrollment';

$configs = [
    'enrollment' => [
        'icon' => 'check-circle',
        'title' => __('Inschrijving ontvangen!', 'stridence'),
        'message' => __('Bedankt voor je inschrijving. Je ontvangt binnen enkele minuten een bevestiging per e-mail met alle details en een offerte.', 'stridence'),
        'cta_label' => __('Naar mijn cursussen', 'stridence'),
        'cta_url' => home_url('/mijn-account/cursussen/'),
    ],
    'interest' => [
        'icon' => 'check-circle',
        'title' => __('Interesse gemeld!', 'stridence'),
        'message' => __('Bedankt voor je interesse. We houden je op de hoogte zodra er nieuwe data beschikbaar zijn.', 'stridence'),
        'cta_label' => __('Bekijk andere cursussen', 'stridence'),
        'cta_url' => home_url('/cursussen/'),
    ],
    'profile' => [
        'icon' => 'check-circle',
        'title' => __('Profiel bijgewerkt!', 'stridence'),
        'message' => __('Je profielgegevens zijn succesvol opgeslagen.', 'stridence'),
        'cta_label' => __('Terug naar profiel', 'stridence'),
        'cta_url' => home_url('/mijn-account/profiel/'),
    ],
];

$config = $configs[$type] ?? $configs['enrollment'];
?>

<main class="str-main str-bg-light">
    <div class="str-container">
        <div class="str-confirmation">
            <div class="str-confirmation__card">
                <div class="str-confirmation__icon str-confirmation__icon--success">
                    <?php stridence_icon($config['icon'], '', 48); ?>
                </div>

                <h1 class="str-confirmation__title"><?php echo esc_html($config['title']); ?></h1>

                <p class="str-confirmation__message"><?php echo esc_html($config['message']); ?></p>

                <div class="str-confirmation__actions">
                    <a href="<?php echo esc_url($config['cta_url']); ?>" class="str-btn str-btn--primary str-btn--lg">
                        <?php echo esc_html($config['cta_label']); ?>
                    </a>
                    <a href="<?php echo esc_url(home_url('/')); ?>" class="str-btn str-btn--ghost">
                        <?php esc_html_e('Naar homepage', 'stridence'); ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
</main>

<?php get_footer(); ?>
