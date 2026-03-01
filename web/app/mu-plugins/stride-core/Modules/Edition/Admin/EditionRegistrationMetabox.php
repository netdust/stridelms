<?php

declare(strict_types=1);

namespace Stride\Modules\Edition\Admin;

use Stride\Domain\RegistrationStatus;
use Stride\Domain\SessionType;
use Stride\Infrastructure\BatchQueryHelper;
use Stride\Integrations\LearnDash\LearnDashHelper;
use Stride\Modules\Attendance\AttendanceRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionService;
use WP_Post;

/**
 * Edition Registration & Attendance Metabox.
 *
 * Two-tab metabox replacing the standalone attendance metabox:
 * - Deelnemers: All registrations with status badges, actions, expandable detail rows
 * - Aanwezigheid: Attendance grid for confirmed users (unchanged behavior)
 */
final class EditionRegistrationMetabox
{
    private int $currentEditionId = 0;

    public function __construct(
        private readonly SessionService $sessionService,
        private readonly EditionService $editionService,
        private readonly AttendanceRepository $attendanceRepository,
    ) {}

    public function render(WP_Post $post): void
    {
        if ($post->post_status === 'auto-draft') {
            ?>
            <div class="stride-sessions-notice">
                <span class="dashicons dashicons-info"></span>
                <span><?php esc_html_e('Sla de editie eerst op om deelnemers te beheren.', 'stride'); ?></span>
            </div>
            <?php
            return;
        }

        $this->currentEditionId = $post->ID;

        $isOnline = $this->editionService->isOnline($post->ID);
        $hasForm = $this->editionService->hasEnrollmentForm($post->ID);
        $showDirectEnrollments = $isOnline && !$hasForm;

        if ($showDirectEnrollments) {
            $this->renderDirectEnrollments($post);
            return;
        }

        // Fetch ALL registrations (not just confirmed)
        $registrations = $this->getAllRegistrations($post->ID);

        // Batch fetch users
        $userIds = array_map(fn($r) => (int) $r['user_id'], $registrations);
        $userIds = array_unique($userIds);
        $users = BatchQueryHelper::batchGetUsers($userIds);

        // Batch fetch user meta (organisation, phone, billing, vat)
        $userMeta = $this->batchGetUserMeta($userIds);

        ?>
        <?php $exportNonce = wp_create_nonce('stride_edition_admin'); ?>
        <div class="stride-edition-admin stride-registration-metabox">
            <div class="stride-edition-tabs">
                <div class="stride-tabs-nav">
                    <button type="button" class="stride-tab active" data-tab="deelnemers">
                        <?php esc_html_e('Deelnemers', 'stride'); ?>
                        <?php if (!empty($registrations)): ?>
                            <span class="stride-tab-count"><?php echo count($registrations); ?></span>
                        <?php endif; ?>
                    </button>
                    <button type="button" class="stride-tab" data-tab="aanwezigheid">
                        <?php esc_html_e('Aanwezigheid', 'stride'); ?>
                    </button>

                    <?php if (!empty($registrations)): ?>
                        <div class="stride-export-dropdown">
                            <button type="button" class="button stride-export-toggle">
                                <span class="dashicons dashicons-download"></span>
                                <?php esc_html_e('Exporteer', 'stride'); ?>
                                <span class="dashicons dashicons-arrow-down-alt2 stride-export-caret"></span>
                            </button>
                            <div class="stride-export-menu">
                                <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=stride_export_registrations&type=excel&edition_id=' . $this->currentEditionId . '&nonce=' . $exportNonce)); ?>">
                                    <span class="dashicons dashicons-media-spreadsheet"></span>
                                    <?php esc_html_e('Volledig Excel', 'stride'); ?>
                                </a>
                                <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=stride_export_registrations&type=namecards&edition_id=' . $this->currentEditionId . '&nonce=' . $exportNonce)); ?>">
                                    <span class="dashicons dashicons-id-alt"></span>
                                    <?php esc_html_e('Naamkaartjes (Word)', 'stride'); ?>
                                </a>
                                <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=stride_export_registrations&type=attendance&edition_id=' . $this->currentEditionId . '&nonce=' . $exportNonce)); ?>">
                                    <span class="dashicons dashicons-clipboard"></span>
                                    <?php esc_html_e('Presentielijst (Word)', 'stride'); ?>
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="stride-tab-content active" data-tab="deelnemers">
                    <?php $this->renderRegistrationsTab($registrations, $users, $userMeta); ?>
                </div>

                <div class="stride-tab-content" data-tab="aanwezigheid">
                    <?php $this->renderAttendanceTab($post, $registrations, $users, $userMeta); ?>
                </div>
            </div>
        </div>
        <?php
    }

    private function renderRegistrationsTab(array $registrations, array $users, array $userMeta): void
    {
        if (empty($registrations)) {
            ?>
            <div class="stride-sessions-notice">
                <span class="dashicons dashicons-info"></span>
                <span><?php esc_html_e('Er zijn nog geen inschrijvingen voor deze editie.', 'stride'); ?></span>
            </div>
            <?php
            return;
        }

        ?>
        <table class="wp-list-table widefat fixed striped stride-registration-table">
            <thead>
                <tr>
                    <th class="column-name"><?php esc_html_e('Naam', 'stride'); ?></th>
                    <th class="column-email"><?php esc_html_e('E-mail', 'stride'); ?></th>
                    <th class="column-org"><?php esc_html_e('Organisatie', 'stride'); ?></th>
                    <th class="column-status"><?php esc_html_e('Status', 'stride'); ?></th>
                    <th class="column-date"><?php esc_html_e('Datum', 'stride'); ?></th>
                    <th class="column-actions"><?php esc_html_e('Acties', 'stride'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($registrations as $registration): ?>
                    <?php
                    $userId = (int) $registration['user_id'];
                    $user = $users[$userId] ?? null;
                    if (!$user) {
                        continue;
                    }

                    $regId = (int) $registration['id'];
                    $status = RegistrationStatus::tryFrom($registration['status'] ?? '') ?? RegistrationStatus::Pending;
                    $registeredAt = $registration['registered_at'] ?? '';
                    $completionTasks = $this->getCompletionTasks($registration);
                    $meta = $userMeta[$userId] ?? [];
                    $organisation = $meta['organisation'] ?? $meta['company'] ?? '';
                    ?>
                    <tr class="registration-row stride-toggle-detail" data-reg-id="<?php echo esc_attr((string) $regId); ?>">
                        <td class="column-name">
                            <span class="dashicons dashicons-arrow-right-alt2 stride-detail-arrow"></span>
                            <?php echo esc_html($user->display_name); ?>
                        </td>
                        <td class="column-email"><?php echo esc_html($user->user_email); ?></td>
                        <td class="column-org"><?php echo esc_html($organisation); ?></td>
                        <td class="column-status">
                            <span class="stride-status-badge <?php echo esc_attr($status->value); ?>">
                                <?php echo esc_html($status->label()); ?>
                            </span>
                        </td>
                        <td class="column-date">
                            <?php echo $registeredAt ? esc_html(date_i18n('j M Y', strtotime($registeredAt))) : '&mdash;'; ?>
                        </td>
                        <td class="column-actions">
                            <?php if ($status === RegistrationStatus::Pending): ?>
                                <button type="button" class="button-link stride-confirm-reg" title="<?php esc_attr_e('Goedkeuren', 'stride'); ?>">
                                    <span class="dashicons dashicons-yes-alt"></span>
                                </button>
                                <button type="button" class="button-link stride-reject-reg" title="<?php esc_attr_e('Afwijzen', 'stride'); ?>">
                                    <span class="dashicons dashicons-dismiss"></span>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr class="registration-detail" data-reg-id="<?php echo esc_attr((string) $regId); ?>" style="display:none">
                        <td colspan="6">
                            <?php $this->renderDetailRow($user, $meta, $registration, $completionTasks); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function renderDetailRow(\WP_User $user, array $meta, array $registration, array $completionTasks): void
    {
        $phone = $meta['phone'] ?? '';
        $company = $meta['company'] ?? $meta['organisation'] ?? '';
        $vatNumber = $meta['vat_number'] ?? '';
        $notes = $registration['notes'] ?? '';
        ?>
        <div class="stride-detail-panels">
            <dl class="stride-detail-dl">
                <?php if ($phone): ?>
                    <dt><?php esc_html_e('Telefoon', 'stride'); ?></dt>
                    <dd><?php echo esc_html($phone); ?></dd>
                <?php endif; ?>
                <?php if ($company): ?>
                    <dt><?php esc_html_e('Organisatie', 'stride'); ?></dt>
                    <dd><?php echo esc_html($company); ?></dd>
                <?php endif; ?>
                <?php if ($vatNumber): ?>
                    <dt><?php esc_html_e('BTW-nummer', 'stride'); ?></dt>
                    <dd><?php echo esc_html($vatNumber); ?></dd>
                <?php endif; ?>
                <?php if ($notes): ?>
                    <dt><?php esc_html_e('Opmerking', 'stride'); ?></dt>
                    <dd><?php echo esc_html($notes); ?></dd>
                <?php endif; ?>
                <?php if (!$phone && !$company && !$vatNumber && !$notes && empty($completionTasks)): ?>
                    <dd class="stride-detail-empty"><?php esc_html_e('Geen aanvullende gegevens.', 'stride'); ?></dd>
                <?php endif; ?>
            </dl>

            <?php if (!empty($completionTasks)): ?>
                <ul class="stride-task-list">
                    <?php foreach ($completionTasks as $task): ?>
                        <?php
                        $taskStatus = $task['status'] ?? 'pending';
                        $taskLabel = $task['label'] ?? $task['type'] ?? '';
                        $taskData = $task['data'] ?? [];
                        ?>
                        <li class="stride-task-item <?php echo esc_attr($taskStatus); ?>">
                            <span class="dashicons <?php echo $taskStatus === 'completed' ? 'dashicons-yes-alt' : 'dashicons-clock'; ?>"></span>
                            <span class="task-label"><?php echo esc_html($taskLabel); ?></span>

                            <?php if (!empty($taskData['files']) && is_array($taskData['files'])): ?>
                                <div class="task-files">
                                    <?php foreach ($taskData['files'] as $fileId): ?>
                                        <?php
                                        $url = wp_get_attachment_url((int) $fileId);
                                        $filename = basename(get_attached_file((int) $fileId) ?: '');
                                        if (!$url) {
                                            continue;
                                        }
                                        ?>
                                        <a href="<?php echo esc_url($url); ?>" target="_blank" class="task-file-link">
                                            <span class="dashicons dashicons-media-default"></span>
                                            <?php echo esc_html($filename ?: __('Bestand', 'stride')); ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php
    }

    private function renderAttendanceTab(WP_Post $post, array $allRegistrations, array $users, array $userMeta): void
    {
        // Get sessions that require attendance marking (in_person, webinar)
        $allSessions = $this->sessionService->getSessionsForEdition($post->ID);
        $sessions = array_values(array_filter($allSessions, function ($session) {
            $type = SessionType::tryFrom($session['type']) ?? SessionType::InPerson;
            return $type->requiresAttendanceMarking();
        }));

        if (empty($sessions)) {
            ?>
            <div class="stride-sessions-notice">
                <span class="dashicons dashicons-info"></span>
                <span><?php esc_html_e('Voeg eerst fysieke sessies of webinars toe om aanwezigheid bij te houden.', 'stride'); ?></span>
            </div>
            <?php
            return;
        }

        // Filter to confirmed-only for attendance
        $confirmedRegistrations = array_filter($allRegistrations, function ($r) {
            $status = RegistrationStatus::tryFrom($r['status'] ?? '');
            return $status === RegistrationStatus::Confirmed || $status === RegistrationStatus::Completed;
        });
        $confirmedRegistrations = array_values($confirmedRegistrations);

        if (empty($confirmedRegistrations)) {
            ?>
            <div class="stride-sessions-notice">
                <span class="dashicons dashicons-info"></span>
                <span><?php esc_html_e('Er zijn nog geen bevestigde inschrijvingen voor deze editie.', 'stride'); ?></span>
            </div>
            <?php
            return;
        }

        // Batch fetch attendance
        $attendanceByUser = BatchQueryHelper::batchGetAttendance($post->ID);

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
                        <?php foreach ($confirmedRegistrations as $registration): ?>
                            <?php
                            $userId = (int) $registration['user_id'];
                            $user = $users[$userId] ?? null;
                            if (!$user) {
                                continue;
                            }
                            $organisation = $userMeta[$userId]['organisation'] ?? $userMeta[$userId]['company'] ?? '';
                            ?>
                            <tr data-user-id="<?php echo esc_attr((string) $userId); ?>">
                                <td class="column-name"><?php echo esc_html($user->display_name); ?></td>
                                <td class="column-email"><?php echo esc_html($user->user_email); ?></td>
                                <td class="column-org"><?php echo esc_html($organisation); ?></td>
                                <?php foreach ($sessions as $session): ?>
                                    <?php
                                    $sessionId = (int) $session['id'];
                                    $status = $attendanceByUser[$userId][$sessionId] ?? 'unmarked';
                                    ?>
                                    <td class="column-session">
                                        <button type="button"
                                                class="stride-attendance-toggle <?php echo esc_attr($status); ?>"
                                                data-session-id="<?php echo esc_attr($session['id']); ?>"
                                                data-user-id="<?php echo esc_attr((string) $userId); ?>"
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
                                <?php $totals = $this->getSessionAttendanceTotals((int) $session['id'], $confirmedRegistrations, $attendanceByUser); ?>
                                <td class="totals-cell" data-session-id="<?php echo esc_attr($session['id']); ?>">
                                    <span class="attendance-count"><?php echo esc_html($totals['present'] . '/' . $totals['total']); ?></span>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    </tfoot>
                </table>
            </div>

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

    private function renderDirectEnrollments(WP_Post $post): void
    {
        $courseId = $this->editionService->getCourseId($post->ID);

        if (!$courseId || !LearnDashHelper::isActive()) {
            ?>
            <div class="stride-sessions-notice">
                <span class="dashicons dashicons-info"></span>
                <span><?php esc_html_e('Geen cursus gekoppeld.', 'stride'); ?></span>
            </div>
            <?php
            return;
        }

        $enrolledUserIds = learndash_get_users_for_course($courseId, [], true);

        // learndash_get_users_for_course returns WP_User_Query or array of IDs
        if ($enrolledUserIds instanceof \WP_User_Query) {
            $enrolledUserIds = $enrolledUserIds->get_results();
        }
        $enrolledUserIds = array_map('intval', array_filter((array) $enrolledUserIds));

        if (empty($enrolledUserIds)) {
            ?>
            <div class="stride-sessions-notice">
                <span class="dashicons dashicons-info"></span>
                <span><?php esc_html_e('Er zijn nog geen ingeschreven deelnemers voor deze cursus.', 'stride'); ?></span>
            </div>
            <?php
            return;
        }

        $users = BatchQueryHelper::batchGetUsers($enrolledUserIds);

        ?>
        <div class="stride-edition-admin stride-registration-metabox">
            <p class="description" style="margin: 0 0 8px; font-size: 11px; color: #646970;">
                <?php echo esc_html(sprintf(
                    __('%d deelnemer(s) direct ingeschreven via LearnDash.', 'stride'),
                    count($users)
                )); ?>
            </p>
            <table class="wp-list-table widefat fixed striped stride-registration-table">
                <thead>
                    <tr>
                        <th class="column-name"><?php esc_html_e('Naam', 'stride'); ?></th>
                        <th class="column-email"><?php esc_html_e('E-mail', 'stride'); ?></th>
                        <th class="column-progress"><?php esc_html_e('Voortgang', 'stride'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <?php $progress = LearnDashHelper::getProgress($courseId, $user->ID); ?>
                        <tr>
                            <td class="column-name">
                                <a href="<?php echo esc_url(get_edit_user_link($user->ID)); ?>">
                                    <?php echo esc_html($user->display_name); ?>
                                </a>
                            </td>
                            <td class="column-email"><?php echo esc_html($user->user_email); ?></td>
                            <td class="column-progress">
                                <div class="stride-progress-inline">
                                    <div class="stride-progress-bar-sm">
                                        <div class="stride-progress-fill-sm" style="width: <?php echo esc_attr((string) $progress); ?>%;"></div>
                                    </div>
                                    <span class="stride-progress-pct"><?php echo esc_html($progress . '%'); ?></span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // === Private helpers ===

    private function getAllRegistrations(int $editionId): array
    {
        global $wpdb;
        $table = $wpdb->prefix . EditionAdminController::REGISTRATIONS_TABLE;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE edition_id = %d ORDER BY registered_at DESC",
            $editionId
        ), ARRAY_A) ?: [];
    }

    /**
     * Batch fetch user meta for personal + billing info.
     *
     * @param array<int> $userIds
     * @return array<int, array<string, string>> userId => [key => value]
     */
    private function batchGetUserMeta(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        global $wpdb;
        $userIds = array_map('intval', array_unique($userIds));
        $metaKeys = ['organisation', 'company', 'phone', 'billing_address', 'vat_number'];

        $userPlaceholders = implode(',', array_fill(0, count($userIds), '%d'));
        $keyPlaceholders = implode(',', array_fill(0, count($metaKeys), '%s'));

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta}
             WHERE user_id IN ({$userPlaceholders}) AND meta_key IN ({$keyPlaceholders})",
            ...array_merge($userIds, $metaKeys)
        ));

        $meta = [];
        foreach ($results as $row) {
            $meta[(int) $row->user_id][$row->meta_key] = $row->meta_value;
        }
        return $meta;
    }

    private function getCompletionTasks(array $registration): array
    {
        $tasks = $registration['completion_tasks'] ?? '';
        if (is_string($tasks) && $tasks !== '') {
            $decoded = json_decode($tasks, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($tasks) ? $tasks : [];
    }

    /**
     * @param array<array{user_id: int|string}> $registrations
     * @param array<int, array<int, string>> $attendanceByUser
     * @return array{present: int, total: int}
     */
    private function getSessionAttendanceTotals(int $sessionId, array $registrations, array $attendanceByUser): array
    {
        $present = 0;
        $total = count($registrations);

        foreach ($registrations as $registration) {
            $userId = (int) $registration['user_id'];
            $status = $attendanceByUser[$userId][$sessionId] ?? null;
            if ($status === 'present') {
                $present++;
            }
        }

        return ['present' => $present, 'total' => $total];
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
