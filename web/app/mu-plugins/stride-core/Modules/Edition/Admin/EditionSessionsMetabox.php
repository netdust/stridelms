<?php

declare(strict_types=1);

namespace Stride\Modules\Edition\Admin;

use Stride\Domain\SessionType;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\SessionService;
use WP_Post;

/**
 * Edition Sessions Metabox.
 *
 * Renders inline session management:
 * - Sessions table with date, time, type, location
 * - Inline form for adding/editing sessions
 * - Type-specific fields (location, webinar link, lesson selection)
 */
final class EditionSessionsMetabox
{
    public function __construct(
        private readonly SessionService $sessionService,
        private readonly EditionRepository $editionRepository,
    ) {}

    public function render(WP_Post $post): void
    {
        // For new editions, show save prompt
        if ($post->post_status === 'auto-draft') {
            ?>
            <div class="stride-sessions-notice">
                <span class="dashicons dashicons-info"></span>
                <span><?php esc_html_e('Sla de editie eerst op om sessies te kunnen toevoegen.', 'stride'); ?></span>
            </div>
            <?php
            return;
        }

        $sessions = $this->sessionService->getSessionsForEdition($post->ID);
        $sessionSlots = $this->editionRepository->getField($post->ID, 'session_slots', []);
        if (is_string($sessionSlots)) {
            $sessionSlots = json_decode($sessionSlots, true) ?: [];
        }
        ?>
        <div class="stride-sessions-admin">
            <!-- Header with Add button -->
            <div class="stride-sessions-header">
                <button type="button" class="button" id="stride-add-session-btn">
                    <span class="dashicons dashicons-plus-alt2"></span>
                    <?php esc_html_e('Sessie toevoegen', 'stride'); ?>
                </button>
            </div>

            <!-- Sessions Table -->
            <table class="wp-list-table widefat fixed striped stride-sessions-table">
                <thead>
                    <tr>
                        <th class="column-date"><?php esc_html_e('Datum', 'stride'); ?></th>
                        <th class="column-time"><?php esc_html_e('Tijd', 'stride'); ?></th>
                        <th class="column-type"><?php esc_html_e('Type', 'stride'); ?></th>
                        <th class="column-slot"><?php esc_html_e('Slot', 'stride'); ?></th>
                        <th class="column-location"><?php esc_html_e('Locatie', 'stride'); ?></th>
                        <th class="column-actions"><?php esc_html_e('Acties', 'stride'); ?></th>
                    </tr>
                </thead>
                <tbody id="stride-sessions-body">
                    <?php if (empty($sessions)): ?>
                        <tr class="no-sessions-row">
                            <td colspan="6" class="no-sessions">
                                <?php esc_html_e('Nog geen sessies toegevoegd.', 'stride'); ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($sessions as $session): ?>
                            <?php $this->renderSessionRow($session, $sessionSlots); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Session Form Template (hidden, used by JS) -->
        <script type="text/template" id="stride-session-form-template">
            <?php $this->renderSessionFormTemplate($sessionSlots); ?>
        </script>
        <?php
    }

    private function renderSessionRow(array $session, array $sessionSlots = []): void
    {
        $type = SessionType::tryFrom($session['type']) ?? SessionType::InPerson;
        $dateFormatted = !empty($session['date']) ? date_i18n('d M Y', strtotime($session['date'])) : '-';
        $timeFormatted = '';
        if (!empty($session['start_time'])) {
            $timeFormatted = $session['start_time'];
            if (!empty($session['end_time'])) {
                $timeFormatted .= ' - ' . $session['end_time'];
            }
        }

        // Get slot label
        $slotValue = $session['slot'] ?? '';
        $slotLabel = '';
        if ($slotValue) {
            foreach ($sessionSlots as $slot) {
                if ($slot['slot'] === $slotValue) {
                    $slotLabel = $slot['label'] ?: $slotValue;
                    break;
                }
            }
            if (!$slotLabel) {
                $slotLabel = $slotValue; // Fallback to slot key if label not found
            }
        }
        ?>
        <tr class="session-row"
            data-session-id="<?php echo esc_attr($session['id']); ?>"
            data-date="<?php echo esc_attr($session['date']); ?>"
            data-start-time="<?php echo esc_attr($session['start_time']); ?>"
            data-end-time="<?php echo esc_attr($session['end_time']); ?>"
            data-location="<?php echo esc_attr($session['location'] ?? ''); ?>"
            data-session-type="<?php echo esc_attr($session['type']); ?>"
            data-session-slot="<?php echo esc_attr($session['slot'] ?? ''); ?>">
            <td class="column-date"><?php echo esc_html($dateFormatted); ?></td>
            <td class="column-time"><?php echo esc_html($timeFormatted ?: '-'); ?></td>
            <td class="column-type">
                <span class="session-type-badge session-type-<?php echo esc_attr($session['type']); ?>">
                    <?php echo esc_html($type->label()); ?>
                </span>
            </td>
            <td class="column-slot"><?php echo esc_html($slotLabel ?: '-'); ?></td>
            <td class="column-location"><?php echo esc_html($session['location'] ?: '-'); ?></td>
            <td class="column-actions">
                <button type="button" class="button-link stride-edit-session" title="<?php esc_attr_e('Bewerken', 'stride'); ?>">
                    <span class="dashicons dashicons-edit"></span>
                </button>
                <button type="button" class="button-link stride-delete-session" title="<?php esc_attr_e('Verwijderen', 'stride'); ?>">
                    <span class="dashicons dashicons-trash"></span>
                </button>
            </td>
        </tr>
        <?php
    }

    private function renderSessionFormTemplate(array $sessionSlots): void
    {
        ?>
        <tr class="stride-session-form-row">
            <td colspan="6">
                <div class="stride-session-form">
                    <input type="hidden" name="session_id" value="">

                    <!-- Date/Time Section -->
                    <div class="stride-form-section stride-datetime-section">
                        <div class="stride-field">
                            <label><?php esc_html_e('Datum', 'stride'); ?></label>
                            <input type="date" name="session_date" required>
                        </div>
                        <div class="stride-field">
                            <label><?php esc_html_e('Starttijd', 'stride'); ?></label>
                            <input type="time" name="session_start_time">
                        </div>
                        <div class="stride-field">
                            <label><?php esc_html_e('Eindtijd', 'stride'); ?></label>
                            <input type="time" name="session_end_time">
                        </div>
                        <?php if (!empty($sessionSlots)): ?>
                            <div class="stride-field stride-slot-field">
                                <label><?php esc_html_e('Slot', 'stride'); ?></label>
                                <select name="session_slot" id="stride-session-slot-select">
                                    <option value=""><?php esc_html_e('Geen slot', 'stride'); ?></option>
                                    <?php foreach ($sessionSlots as $slot): ?>
                                        <option value="<?php echo esc_attr($slot['slot']); ?>">
                                            <?php echo esc_html($slot['label'] ?: $slot['slot']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Session Type Section -->
                    <div class="stride-form-section stride-type-section">
                        <span class="stride-type-label"><?php esc_html_e('Type sessie', 'stride'); ?></span>
                        <div class="stride-type-buttons">
                            <?php foreach (SessionType::cases() as $type): ?>
                                <label class="stride-type-option <?php echo $type === SessionType::InPerson ? 'active' : ''; ?>" data-type="<?php echo esc_attr($type->value); ?>">
                                    <input type="radio" name="session_type" value="<?php echo esc_attr($type->value); ?>"
                                           <?php echo $type === SessionType::InPerson ? 'checked' : ''; ?>>
                                    <span class="dashicons <?php echo esc_attr($this->getTypeIcon($type)); ?>"></span>
                                    <span class="stride-type-name"><?php echo esc_html($type->label()); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Type-specific Fields -->
                    <div class="stride-form-section stride-type-fields">
                        <!-- In Person Panel -->
                        <div class="stride-type-panel" data-for-type="in_person" style="display: block;">
                            <div class="stride-field">
                                <label><?php esc_html_e('Titel', 'stride'); ?> <span class="optional">(<?php esc_html_e('optioneel', 'stride'); ?>)</span></label>
                                <input type="text" name="session_title" placeholder="<?php esc_attr_e('bijv. Ochtendprogramma', 'stride'); ?>">
                            </div>
                            <div class="stride-field">
                                <label><?php esc_html_e('Locatie', 'stride'); ?></label>
                                <input type="text" name="session_location" placeholder="<?php esc_attr_e('bijv. Vergaderzaal A', 'stride'); ?>">
                            </div>
                            <div class="stride-field">
                                <label><?php esc_html_e('Beschrijving', 'stride'); ?> <span class="optional">(<?php esc_html_e('optioneel', 'stride'); ?>)</span></label>
                                <textarea name="session_description" rows="2" placeholder="<?php esc_attr_e('Extra informatie over deze sessie...', 'stride'); ?>"></textarea>
                            </div>
                        </div>

                        <!-- Webinar Panel -->
                        <div class="stride-type-panel" data-for-type="webinar" style="display: none;">
                            <div class="stride-field">
                                <label><?php esc_html_e('Titel', 'stride'); ?> <span class="optional">(<?php esc_html_e('optioneel', 'stride'); ?>)</span></label>
                                <input type="text" name="session_title" placeholder="<?php esc_attr_e('bijv. Live Q&A Sessie', 'stride'); ?>">
                            </div>
                            <div class="stride-field">
                                <label><?php esc_html_e('Webinar link', 'stride'); ?></label>
                                <input type="url" name="session_webinar_link" placeholder="<?php esc_attr_e('https://zoom.us/...', 'stride'); ?>">
                            </div>
                            <div class="stride-field">
                                <label><?php esc_html_e('Beschrijving', 'stride'); ?> <span class="optional">(<?php esc_html_e('optioneel', 'stride'); ?>)</span></label>
                                <textarea name="session_description" rows="2" placeholder="<?php esc_attr_e('Extra informatie over deze webinar...', 'stride'); ?>"></textarea>
                            </div>
                        </div>

                        <!-- Online Panel -->
                        <div class="stride-type-panel" data-for-type="online" style="display: none;">
                            <div class="stride-field stride-lesson-field">
                                <label><?php esc_html_e('Les', 'stride'); ?></label>
                                <select name="session_lesson_id">
                                    <option value=""><?php esc_html_e('Selecteer een les...', 'stride'); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e('Voltooiing wordt automatisch bijgehouden via LearnDash.', 'stride'); ?></p>
                            </div>
                        </div>

                        <!-- Assignment Panel -->
                        <div class="stride-type-panel" data-for-type="assignment" style="display: none;">
                            <div class="stride-field stride-lesson-field">
                                <label><?php esc_html_e('Les of quiz', 'stride'); ?></label>
                                <select name="session_lesson_id">
                                    <option value=""><?php esc_html_e('Selecteer les of quiz...', 'stride'); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e('Voltooiing wordt automatisch bijgehouden via LearnDash.', 'stride'); ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="stride-session-form-actions">
                        <button type="button" class="button button-primary stride-session-save">
                            <?php esc_html_e('Opslaan', 'stride'); ?>
                        </button>
                        <button type="button" class="button stride-session-cancel">
                            <?php esc_html_e('Annuleren', 'stride'); ?>
                        </button>
                    </div>
                </div>
            </td>
        </tr>
        <?php
    }

    private function getTypeIcon(SessionType $type): string
    {
        return match ($type) {
            SessionType::InPerson => 'dashicons-groups',
            SessionType::Webinar => 'dashicons-video-alt2',
            SessionType::Online => 'dashicons-laptop',
            SessionType::Assignment => 'dashicons-welcome-write-blog',
        };
    }
}
