<?php

declare(strict_types=1);

namespace Stride\Modules\Edition\Admin;

use Stride\Domain\SessionType;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionService;
use WP_Post;

/**
 * Edition Attendance Metabox.
 *
 * Renders attendance tracking grid:
 * - Rows: Registered users
 * - Columns: Sessions (only in_person/webinar types)
 * - Cells: Toggle buttons for attendance status
 */
final class EditionAttendanceMetabox
{
    public function __construct(
        private readonly SessionService $sessionService,
        private readonly EditionService $editionService,
    ) {}

    public function render(WP_Post $post): void
    {
        // For new editions, show save prompt
        if ($post->post_status === 'auto-draft') {
            ?>
            <div class="stride-sessions-notice">
                <span class="dashicons dashicons-info"></span>
                <span><?php esc_html_e('Sla de editie eerst op om aanwezigheid te kunnen bijhouden.', 'stride'); ?></span>
            </div>
            <?php
            return;
        }

        // Get sessions that require attendance marking (in_person, webinar)
        $allSessions = $this->sessionService->getSessionsForEdition($post->ID);
        $sessions = array_filter($allSessions, function ($session) {
            $type = SessionType::tryFrom($session['type']) ?? SessionType::InPerson;
            return $type->requiresAttendanceMarking();
        });

        if (empty($sessions)) {
            ?>
            <div class="stride-sessions-notice">
                <span class="dashicons dashicons-info"></span>
                <span><?php esc_html_e('Voeg eerst fysieke sessies of webinars toe om aanwezigheid bij te houden.', 'stride'); ?></span>
            </div>
            <?php
            return;
        }

        // Get registered users
        $registrations = $this->getEditionRegistrations($post->ID);

        if (empty($registrations)) {
            ?>
            <div class="stride-sessions-notice">
                <span class="dashicons dashicons-info"></span>
                <span><?php esc_html_e('Er zijn nog geen inschrijvingen voor deze editie.', 'stride'); ?></span>
            </div>
            <?php
            return;
        }

        // Re-index sessions array for consistent iteration
        $sessions = array_values($sessions);
        ?>
        <div class="stride-attendance-admin">
            <div class="stride-attendance-table-wrapper">
                <table class="stride-attendance-table">
                    <thead>
                        <tr>
                            <th class="column-name"><?php esc_html_e('Naam', 'stride'); ?></th>
                            <th class="column-email"><?php esc_html_e('E-mail', 'stride'); ?></th>
                            <th class="column-org"><?php esc_html_e('Organisatie', 'stride'); ?></th>
                            <?php foreach ($sessions as $session): ?>
                                <th class="column-session" data-session-id="<?php echo esc_attr($session['id']); ?>">
                                    <div class="session-header">
                                        <span class="session-date"><?php echo esc_html(date_i18n('d M', strtotime($session['date']))); ?></span>
                                        <?php if (!empty($session['start_time'])): ?>
                                            <span class="session-time"><?php echo esc_html($session['start_time']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <button type="button" class="stride-mark-all-present" title="<?php esc_attr_e('Allen aanwezig', 'stride'); ?>">
                                        <span class="dashicons dashicons-yes-alt"></span>
                                    </button>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($registrations as $registration): ?>
                            <?php
                            $userId = $registration['user_id'];
                            $user = get_userdata($userId);
                            if (!$user) continue;

                            $organisation = get_user_meta($userId, 'organisation', true) ?: '';
                            ?>
                            <tr data-user-id="<?php echo esc_attr($userId); ?>">
                                <td class="column-name"><?php echo esc_html($user->display_name); ?></td>
                                <td class="column-email"><?php echo esc_html($user->user_email); ?></td>
                                <td class="column-org"><?php echo esc_html($organisation); ?></td>
                                <?php foreach ($sessions as $session): ?>
                                    <?php
                                    $status = get_user_meta($userId, "session_attendance_{$session['id']}", true) ?: 'unmarked';
                                    ?>
                                    <td class="column-session">
                                        <button type="button"
                                                class="stride-attendance-toggle <?php echo esc_attr($status); ?>"
                                                data-session-id="<?php echo esc_attr($session['id']); ?>"
                                                data-user-id="<?php echo esc_attr($userId); ?>"
                                                title="<?php echo esc_attr($this->getStatusLabel($status)); ?>">
                                            <span class="status-icon"></span>
                                        </button>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="attendance-totals">
                            <td colspan="3" class="totals-label"><?php esc_html_e('Aanwezig', 'stride'); ?></td>
                            <?php foreach ($sessions as $session): ?>
                                <?php
                                $totals = $this->getSessionAttendanceTotals($session['id'], $registrations);
                                ?>
                                <td class="totals-cell" data-session-id="<?php echo esc_attr($session['id']); ?>">
                                    <span class="attendance-count"><?php echo esc_html($totals['present'] . '/' . $totals['total']); ?></span>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Legend -->
            <div class="stride-attendance-legend">
                <div class="legend-item present">
                    <span class="status-icon"></span>
                    <span><?php esc_html_e('Aanwezig', 'stride'); ?></span>
                </div>
                <div class="legend-item absent">
                    <span class="status-icon"></span>
                    <span><?php esc_html_e('Afwezig', 'stride'); ?></span>
                </div>
                <div class="legend-item excused">
                    <span class="status-icon"></span>
                    <span><?php esc_html_e('Verontschuldigd', 'stride'); ?></span>
                </div>
            </div>
        </div>
        <?php
    }

    private function getEditionRegistrations(int $editionId): array
    {
        global $wpdb;

        $table = $wpdb->prefix . EditionAdminController::REGISTRATIONS_TABLE;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT user_id FROM {$table} WHERE edition_id = %d AND status = 'confirmed' ORDER BY user_id ASC",
            $editionId
        ), ARRAY_A) ?: [];
    }

    private function getSessionAttendanceTotals(int $sessionId, array $registrations): array
    {
        $present = 0;
        $total = count($registrations);

        foreach ($registrations as $registration) {
            $status = get_user_meta($registration['user_id'], "session_attendance_{$sessionId}", true);
            if ($status === 'present') {
                $present++;
            }
        }

        return [
            'present' => $present,
            'total' => $total,
        ];
    }

    private function getStatusLabel(string $status): string
    {
        return match ($status) {
            'present' => __('Aanwezig', 'stride'),
            'absent' => __('Afwezig', 'stride'),
            'excused' => __('Verontschuldigd', 'stride'),
            default => __('Niet gemarkeerd', 'stride'),
        };
    }
}
