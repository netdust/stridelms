<?php
/**
 * Badge Status Partial
 *
 * Renders a status badge with appropriate styling and Dutch label.
 *
 * @param array $args {
 *     @type string $status Status key: open, vol, few_spots, cancelled, completed, confirmed, pending
 *     @type int    $spots  Optional spots remaining for auto-detecting "few spots" (≤5)
 * }
 */

defined('ABSPATH') || exit;

$status = $args['status'] ?? 'open';
$spots  = isset($args['spots']) ? (int) $args['spots'] : null;

// Auto-detect few_spots when status is open and spots are low (1-5)
if ($status === 'open' && $spots !== null && $spots > 0 && $spots <= 5) {
    $status = 'few_spots';
}

// Status configuration: class and Dutch label
$status_config = [
    'open'         => ['class' => 'badge-open',      'label' => 'Open voor inschrijving'],
    'announcement' => ['class' => 'badge-few',       'label' => 'Vooraankondiging'],
    'few_spots'    => ['class' => 'badge-few',       'label' => sprintf('Nog %d %s', $spots ?? 0, ($spots === 1) ? 'plaats' : 'plaatsen')],
    'full'         => ['class' => 'badge-full',      'label' => 'Volzet'],
    'vol'          => ['class' => 'badge-full',      'label' => 'Volzet'],
    'in_progress'  => ['class' => 'badge-open',      'label' => 'Lopend'],
    'postponed'    => ['class' => 'badge-cancelled',  'label' => 'Uitgesteld'],
    'cancelled'    => ['class' => 'badge-cancelled', 'label' => 'Geannuleerd'],
    'completed'    => ['class' => 'badge-online',    'label' => 'Afgerond'],
    'archived'     => ['class' => 'badge-cancelled', 'label' => 'Gearchiveerd'],
    'draft'        => ['class' => 'badge-cancelled', 'label' => 'Concept'],
    'confirmed'    => ['class' => 'badge-open',      'label' => 'Bevestigd'],
    'pending'      => ['class' => 'badge-few',       'label' => 'In behandeling'],
    'enrolled'          => ['class' => 'badge-open',      'label' => 'Ingeschreven'],
    'action_required'   => ['class' => 'badge-few',       'label' => 'Voltooi inschrijving'],
    'awaiting_approval' => ['class' => 'badge-few',       'label' => 'In afwachting'],
];

// Fallback for unknown status
$config = $status_config[$status] ?? ['class' => 'badge-cancelled', 'label' => ucfirst($status)];

?>
<span class="<?php echo esc_attr($config['class']); ?>"><?php
if ($status === 'action_required') {
    echo stridence_icon('alert-circle', 'w-3.5 h-3.5 inline-block -mt-0.5 mr-0.5');
}
echo esc_html($config['label']);
?></span>
