<?php
/**
 * Session Selection Template
 *
 * Allows users to select sessions for editions with slot groups.
 *
 * @var int $user_id
 * @var int $registration_id
 * @var array $status - from SessionSelectionService::getSelectionStatus()
 * @var array $edition
 * @var string $course_title
 *
 * @package stride
 */

defined('ABSPATH') || exit;

$slots = $status['slots'] ?? [];
$currentSelections = $status['current_selections'] ?? [];
$isComplete = $status['is_complete'] ?? false;
$canChange = $status['can_change'] ?? false;
$deadline = $status['deadline'] ?? null;
$editionId = $status['edition_id'] ?? 0;
?>

<div class="stride-dashboard">
    <div class="uk-container">
        <!-- Page Header -->
        <div class="stride-dashboard-header uk-margin-medium-bottom">
            <nav class="uk-margin-bottom">
                <ul class="uk-breadcrumb">
                    <li><a href="<?php echo esc_url(home_url('/mijn-account/')); ?>"><?php esc_html_e('Dashboard', 'stride'); ?></a></li>
                    <li><a href="<?php echo esc_url(home_url('/mijn-account/cursussen/')); ?>"><?php esc_html_e('Mijn Cursussen', 'stride'); ?></a></li>
                    <li><span><?php esc_html_e('Sessiekeuze', 'stride'); ?></span></li>
                </ul>
            </nav>

            <h1 class="uk-h2 uk-margin-remove-bottom">
                <?php esc_html_e('Sessiekeuze', 'stride'); ?>
            </h1>
            <p class="uk-text-muted uk-margin-small-top">
                <?php echo esc_html($course_title); ?>
                <?php if ($edition): ?>
                    - <?php echo esc_html($edition['title']); ?>
                <?php endif; ?>
            </p>
        </div>

        <?php if (!$canChange): ?>
            <!-- Selection Closed -->
            <div class="uk-alert uk-alert-warning">
                <span uk-icon="icon: warning"></span>
                <?php esc_html_e('De selectieperiode is gesloten. Je kunt je keuzes niet meer wijzigen.', 'stride'); ?>
            </div>
        <?php endif; ?>

        <?php if ($deadline && $canChange): ?>
            <!-- Deadline Notice -->
            <div class="uk-alert uk-alert-primary">
                <span uk-icon="icon: clock"></span>
                <?php printf(
                    esc_html__('Maak je keuze voor %s.', 'stride'),
                    date_i18n('j F Y', strtotime($deadline))
                ); ?>
            </div>
        <?php endif; ?>

        <!-- Selection Form -->
        <form id="session-selection-form" class="stride-session-selection-form">
            <input type="hidden" name="registration_id" value="<?php echo esc_attr($registration_id); ?>">
            <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('stride_session_selection')); ?>">

            <?php if (empty($slots)): ?>
                <div class="stride-card">
                    <div class="stride-empty-state">
                        <span uk-icon="icon: calendar; ratio: 2"></span>
                        <h3><?php esc_html_e('Geen sessiekeuze nodig', 'stride'); ?></h3>
                        <p><?php esc_html_e('Voor deze editie hoef je geen sessies te selecteren.', 'stride'); ?></p>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($slots as $slotName => $slot): ?>
                    <?php
                    $sessions = $slot['sessions'] ?? [];
                    $pickCount = $slot['pick_count'] ?? 1;
                    $required = $slot['required'] ?? true;
                    $label = $slot['label'] ?? $slotName;
                    $selectedInSlot = $currentSelections[$slotName] ?? [];
                    $selectedIds = array_column($selectedInSlot, 'id');
                    ?>
                    <div class="stride-card uk-margin-bottom" data-slot="<?php echo esc_attr($slotName); ?>">
                        <div class="stride-card-header">
                            <h2 class="stride-card-title">
                                <span uk-icon="icon: clock"></span>
                                <?php echo esc_html($label); ?>
                            </h2>
                            <p class="uk-text-muted uk-margin-remove">
                                <?php if ($pickCount > 1): ?>
                                    <?php printf(esc_html__('Selecteer %d sessies', 'stride'), $pickCount); ?>
                                <?php else: ?>
                                    <?php esc_html_e('Selecteer 1 sessie', 'stride'); ?>
                                <?php endif; ?>
                                <?php if ($required): ?>
                                    <span class="uk-text-danger">*</span>
                                <?php endif; ?>
                            </p>
                        </div>

                        <?php if (empty($sessions)): ?>
                            <p class="uk-text-muted"><?php esc_html_e('Geen sessies beschikbaar in dit tijdslot.', 'stride'); ?></p>
                        <?php else: ?>
                            <div class="stride-session-options">
                                <?php foreach ($sessions as $session): ?>
                                    <?php
                                    $sessionId = $session['id'];
                                    $isSelected = in_array($sessionId, $selectedIds);
                                    $inputType = $pickCount === 1 ? 'radio' : 'checkbox';
                                    $inputName = $pickCount === 1
                                        ? "sessions[{$slotName}]"
                                        : "sessions[{$slotName}][]";
                                    ?>
                                    <label class="stride-session-option <?php echo $isSelected ? 'selected' : ''; ?> <?php echo !$canChange ? 'disabled' : ''; ?>">
                                        <input type="<?php echo esc_attr($inputType); ?>"
                                               name="<?php echo esc_attr($inputName); ?>"
                                               value="<?php echo esc_attr($sessionId); ?>"
                                               <?php checked($isSelected); ?>
                                               <?php disabled(!$canChange); ?>>

                                        <div class="stride-session-option-content">
                                            <div class="stride-session-option-main">
                                                <strong><?php echo esc_html($session['title'] ?? $session['date']); ?></strong>
                                                <?php if (!empty($session['date'])): ?>
                                                    <div class="uk-text-muted uk-text-small">
                                                        <span uk-icon="icon: calendar; ratio: 0.7"></span>
                                                        <?php echo esc_html(date_i18n('l j F Y', strtotime($session['date']))); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($session['start_time'])): ?>
                                                    <div class="uk-text-muted uk-text-small">
                                                        <span uk-icon="icon: clock; ratio: 0.7"></span>
                                                        <?php echo esc_html($session['start_time']); ?>
                                                        <?php if (!empty($session['end_time'])): ?>
                                                            - <?php echo esc_html($session['end_time']); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($session['venue'])): ?>
                                                    <div class="uk-text-muted uk-text-small">
                                                        <span uk-icon="icon: location; ratio: 0.7"></span>
                                                        <?php echo esc_html($session['venue']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="stride-session-option-check">
                                                <span uk-icon="icon: check"></span>
                                            </div>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <!-- Submit Button -->
                <?php if ($canChange): ?>
                    <div class="uk-margin-top">
                        <button type="submit" class="uk-button uk-button-primary uk-button-large">
                            <span uk-icon="icon: check"></span>
                            <?php esc_html_e('Keuze opslaan', 'stride'); ?>
                        </button>

                        <?php if ($isComplete): ?>
                            <span class="uk-text-success uk-margin-left">
                                <span uk-icon="icon: check"></span>
                                <?php esc_html_e('Je selectie is compleet', 'stride'); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </form>

        <!-- Current Selection Summary -->
        <?php if (!empty($currentSelections) && $isComplete): ?>
            <div class="stride-card uk-margin-large-top">
                <div class="stride-card-header">
                    <h2 class="stride-card-title">
                        <span uk-icon="icon: check"></span>
                        <?php esc_html_e('Jouw geselecteerde sessies', 'stride'); ?>
                    </h2>
                </div>
                <ul class="uk-list uk-list-divider">
                    <?php foreach ($currentSelections as $slotName => $sessions): ?>
                        <?php foreach ($sessions as $session): ?>
                            <li>
                                <div class="uk-flex uk-flex-between uk-flex-middle">
                                    <div>
                                        <strong><?php echo esc_html($session['title'] ?? $session['date']); ?></strong>
                                        <?php if (!empty($session['date'])): ?>
                                            <div class="uk-text-muted uk-text-small">
                                                <?php echo esc_html(date_i18n('l j F Y', strtotime($session['date']))); ?>
                                                <?php if (!empty($session['start_time'])): ?>
                                                    - <?php echo esc_html($session['start_time']); ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <span class="stride-badge stride-badge-enrolled"><?php echo esc_html($slots[$slotName]['label'] ?? $slotName); ?></span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Back Link -->
        <div class="uk-margin-large-top">
            <a href="<?php echo esc_url(home_url('/mijn-account/cursussen/')); ?>" class="uk-link-muted">
                <span uk-icon="icon: arrow-left; ratio: 0.8"></span>
                <?php esc_html_e('Terug naar mijn cursussen', 'stride'); ?>
            </a>
        </div>
    </div>
</div>

<style>
.stride-session-options {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.stride-session-option {
    display: block;
    padding: 16px;
    border: 2px solid var(--stride-border, #e5e5e5);
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s ease;
}

.stride-session-option:hover:not(.disabled) {
    border-color: var(--stride-primary, #1e87f0);
    background: rgba(30, 135, 240, 0.05);
}

.stride-session-option.selected {
    border-color: var(--stride-primary, #1e87f0);
    background: rgba(30, 135, 240, 0.1);
}

.stride-session-option.disabled {
    opacity: 0.7;
    cursor: not-allowed;
}

.stride-session-option input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.stride-session-option-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.stride-session-option-main {
    flex: 1;
}

.stride-session-option-check {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: var(--stride-border, #e5e5e5);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    transition: all 0.2s ease;
}

.stride-session-option.selected .stride-session-option-check {
    background: var(--stride-primary, #1e87f0);
}

.stride-session-option:not(.selected) .stride-session-option-check span {
    opacity: 0;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('session-selection-form');
    if (!form) return;

    // Update visual state on selection change
    form.querySelectorAll('input[type="radio"], input[type="checkbox"]').forEach(input => {
        input.addEventListener('change', function() {
            const slot = this.closest('[data-slot]');
            if (!slot) return;

            // For radio buttons, deselect all in slot first
            if (this.type === 'radio') {
                slot.querySelectorAll('.stride-session-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
            }

            // Toggle selected class
            const option = this.closest('.stride-session-option');
            if (option) {
                option.classList.toggle('selected', this.checked);
            }
        });
    });

    // Form submission
    form.addEventListener('submit', function(e) {
        e.preventDefault();

        const formData = new FormData(form);
        const data = {
            action: 'stride_save_session_selection',
            registration_id: formData.get('registration_id'),
            nonce: formData.get('nonce'),
            sessions: []
        };

        // Collect all selected sessions
        form.querySelectorAll('input[type="radio"]:checked, input[type="checkbox"]:checked').forEach(input => {
            data.sessions.push(parseInt(input.value));
        });

        const submitBtn = form.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<span uk-spinner="ratio: 0.6"></span> ' + (window.strideConfig?.strings?.saving || 'Opslaan...');
        submitBtn.disabled = true;

        fetch(window.strideConfig?.ajaxUrl || '/wp-admin/admin-ajax.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: data.action,
                registration_id: data.registration_id,
                nonce: data.nonce,
                sessions: JSON.stringify(data.sessions)
            })
        })
        .then(response => response.json())
        .then(result => {
            if (result.success) {
                UIkit.notification({
                    message: result.data?.message || 'Keuze opgeslagen!',
                    status: 'success',
                    pos: 'top-center'
                });
                // Optionally reload to show updated state
                if (result.data?.reload) {
                    window.location.reload();
                }
            } else {
                UIkit.notification({
                    message: result.data?.message || 'Er ging iets mis.',
                    status: 'danger',
                    pos: 'top-center'
                });
            }
        })
        .catch(error => {
            console.error('Error:', error);
            UIkit.notification({
                message: window.strideConfig?.strings?.error || 'Er ging iets mis.',
                status: 'danger',
                pos: 'top-center'
            });
        })
        .finally(() => {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        });
    });
});
</script>
