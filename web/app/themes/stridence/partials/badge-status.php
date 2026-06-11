<?php
/**
 * Badge Status Partial — Helder Tij
 *
 * Renders a status badge as a rounded pill: variant colour pair + Dutch label.
 * Status is NEVER colour-alone — the label is always rendered.
 *
 * Variant colours use the Tailwind badge token utilities exposed in
 * tailwind.config.js (bg-badge-*-bg / text-badge-*-text, tokens.css pairs).
 * The pill recipe lives inline here — NOT in components.css.
 *
 * Edition statuses (draft, announcement, open, full, in_progress, postponed,
 * cancelled, completed, archived) source from Stride\Domain\OfferingStatus;
 * its legacy `badge-*` class is translated to a variant below.
 *
 * Registration / UI statuses (confirmed, pending, enrolled, action_required,
 * awaiting_approval, completing) plus the design variants (online, free,
 * trajectory) live in the local map — they aren't part of OfferingStatus.
 *
 * The pseudo-status `few_spots` is auto-detected when status=open + spots ≤ 5.
 * Unknown statuses fall back to the neutral (cancelled) pair.
 *
 * @param array $args {
 *     @type string $status Status key
 *     @type int    $spots  Optional spots remaining for auto-detecting "few spots" (≤5)
 *     @type string $size   Optional 'sm' for the card-size pill (default normal)
 * }
 */

defined('ABSPATH') || exit;

use Stride\Domain\OfferingStatus;

/** @var array $args */
$args   = $args ?? [];
$status = $args['status'] ?? 'open';
$spots  = isset($args['spots']) ? (int) $args['spots'] : null;
$size   = $args['size'] ?? '';

// Variant → colour pair (Tailwind utilities from tailwind.config.js).
$variant_classes = [
    'open'       => 'bg-badge-open-bg text-badge-open-text',
    'few'        => 'bg-badge-few-bg text-badge-few-text',
    'full'       => 'bg-badge-full-bg text-badge-full-text',
    'cancelled'  => 'bg-badge-cancelled-bg text-badge-cancelled-text',
    'online'     => 'bg-badge-online-bg text-badge-online-text',
    'free'       => 'bg-badge-free-bg text-badge-free-text',
    'enrolled'   => 'bg-badge-online-bg text-badge-online-text',
    'trajectory' => 'bg-accent-subtle text-accent-hover',
];

// Legacy class from OfferingStatus::frontendBadgeClass() → variant key.
$legacy_to_variant = [
    'badge-open'      => 'open',
    'badge-few'       => 'few',
    'badge-full'      => 'full',
    'badge-cancelled' => 'cancelled',
    'badge-online'    => 'online',
    'badge-free'      => 'free',
];

$prefix = '';

// Auto-detect few_spots when status is open and spots are low (1-5)
if ($status === 'open' && $spots !== null && $spots > 0 && $spots <= 5) {
    $variant = 'few';
    /* translators: %d: number of spots remaining */
    $label = sprintf(_n('Nog %d plaats', 'Nog %d plaatsen', $spots, 'stridence'), $spots);
} elseif ($offeringStatus = OfferingStatus::tryFrom($status)) {
    // Design override: the Helder Tij sheet renders Geannuleerd on the
    // neutral cancelled pair; frontendBadgeClass() still says badge-full.
    $variant = $offeringStatus === OfferingStatus::Cancelled
        ? 'cancelled'
        : ($legacy_to_variant[$offeringStatus->frontendBadgeClass()] ?? 'cancelled');
    $label   = $offeringStatus->label();
} else {
    // Registration / UI statuses outside OfferingStatus + design variants
    $registration_config = [
        'vol'               => ['variant' => 'full',       'label' => __('Volzet', 'stridence')],
        'confirmed'         => ['variant' => 'open',       'label' => __('Bevestigd', 'stridence')],
        'pending'           => ['variant' => 'few',        'label' => __('In behandeling', 'stridence')],
        'enrolled'          => ['variant' => 'enrolled',   'label' => __('Ingeschreven', 'stridence'), 'prefix' => '✓ '],
        'action_required'   => ['variant' => 'few',        'label' => __('Voltooi inschrijving', 'stridence')],
        'awaiting_approval' => ['variant' => 'few',        'label' => __('In afwachting', 'stridence')],
        'completing'        => ['variant' => 'few',        'label' => __('Rond af', 'stridence')],
        'online'            => ['variant' => 'online',     'label' => __('Online', 'stridence')],
        'free'              => ['variant' => 'free',       'label' => __('Gratis', 'stridence')],
        'trajectory'        => ['variant' => 'trajectory', 'label' => __('Traject', 'stridence')],
    ];
    $config  = $registration_config[$status] ?? ['variant' => 'cancelled', 'label' => ucfirst($status)];
    $variant = $config['variant'];
    $label   = $config['label'];
    $prefix  = $config['prefix'] ?? '';
}

// Canonical pill recipe; 'sm' is the card-size variant.
$recipe = $size === 'sm'
    ? 'text-[11px] font-bold px-[9px] py-[3px] rounded-full inline-flex items-center gap-1'
    : 'text-[12px] font-bold px-[11px] py-1 rounded-full inline-flex items-center gap-1';

$class = $recipe . ' ' . ($variant_classes[$variant] ?? $variant_classes['cancelled']);

?>
<span class="<?php echo esc_attr($class); ?>"><?php
if ($status === 'action_required' || $status === 'completing') {
    echo stridence_icon('alert-circle', 'w-3.5 h-3.5 inline-block -mt-0.5 mr-0.5');
}
if ($prefix !== '') {
    echo esc_html($prefix);
}
echo esc_html($label);
?></span>
