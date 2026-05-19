<?php
/**
 * Badge Status Partial
 *
 * Renders a status badge with appropriate Tailwind class + Dutch label.
 *
 * Edition statuses (draft, announcement, open, full, in_progress, postponed,
 * cancelled, completed, archived) source from Stride\Domain\OfferingStatus.
 *
 * Registration / UI statuses (confirmed, pending, enrolled, action_required,
 * awaiting_approval, completing) remain in a local map below — they aren't
 * part of OfferingStatus.
 *
 * The pseudo-status `few_spots` is auto-detected when status=open + spots ≤ 5.
 *
 * @param array $args {
 *     @type string $status Status key
 *     @type int    $spots  Optional spots remaining for auto-detecting "few spots" (≤5)
 * }
 */

defined('ABSPATH') || exit;

use Stride\Domain\OfferingStatus;

/** @var array $args */
$args   = $args ?? [];
$status = $args['status'] ?? 'open';
$spots  = isset($args['spots']) ? (int) $args['spots'] : null;

// Auto-detect few_spots when status is open and spots are low (1-5)
if ($status === 'open' && $spots !== null && $spots > 0 && $spots <= 5) {
    $class = 'badge-few';
    $label = sprintf('Nog %d %s', $spots, $spots === 1 ? 'plaats' : 'plaatsen');
} elseif ($offeringStatus = OfferingStatus::tryFrom($status)) {
    $class = $offeringStatus->frontendBadgeClass();
    $label = $offeringStatus->label();
} else {
    // Registration / UI statuses outside OfferingStatus
    $registration_config = [
        'vol'               => ['class' => 'badge-full',      'label' => 'Volzet'],
        'confirmed'         => ['class' => 'badge-open',      'label' => 'Bevestigd'],
        'pending'           => ['class' => 'badge-few',       'label' => 'In behandeling'],
        'enrolled'          => ['class' => 'badge-open',      'label' => 'Ingeschreven'],
        'action_required'   => ['class' => 'badge-few',       'label' => 'Voltooi inschrijving'],
        'awaiting_approval' => ['class' => 'badge-few',       'label' => 'In afwachting'],
        'completing'        => ['class' => 'badge-few',       'label' => 'Rond af'],
    ];
    $config = $registration_config[$status] ?? ['class' => 'badge-cancelled', 'label' => ucfirst($status)];
    $class = $config['class'];
    $label = $config['label'];
}

?>
<span class="<?php echo esc_attr($class); ?>"><?php
if ($status === 'action_required' || $status === 'completing') {
    echo stridence_icon('alert-circle', 'w-3.5 h-3.5 inline-block -mt-0.5 mr-0.5');
}
echo esc_html($label);
?></span>
