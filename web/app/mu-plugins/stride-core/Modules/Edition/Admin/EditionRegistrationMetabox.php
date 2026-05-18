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

        // Batch fetch quotes for this edition (indexed by registration_id)
        $quotes = $this->batchGetQuotes($post->ID);

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
                    <?php $this->renderRegistrationsTab($registrations, $users, $userMeta, $quotes); ?>
                </div>

                <div class="stride-tab-content" data-tab="aanwezigheid">
                    <?php $this->renderAttendanceTab($post, $registrations, $users, $userMeta); ?>
                </div>
            </div>

            <div id="stride-registration-modal" class="stride-modal" hidden aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="stride-registration-modal-title">
                <div class="stride-modal-backdrop" data-stride-modal-close></div>
                <div class="stride-modal-dialog">
                    <header class="stride-modal-header">
                        <h2 id="stride-registration-modal-title" class="stride-modal-title"></h2>
                        <button type="button" class="stride-modal-close" data-stride-modal-close aria-label="<?php esc_attr_e('Sluiten', 'stride'); ?>">
                            <span class="dashicons dashicons-no-alt"></span>
                        </button>
                    </header>
                    <div class="stride-modal-content"></div>
                    <div class="stride-modal-skeleton" hidden>
                        <p><?php esc_html_e('Laden…', 'stride'); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function renderRegistrationsTab(array $registrations, array $users, array $userMeta, array $quotes = []): void
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
                    $regId = (int) $registration['id'];
                    $registeredAt = $registration['registered_at'] ?? '';

                    // Anonymised or hard-deleted user: faded row, no actions
                    $anonymisedAt = $user ? (int) get_user_meta($userId, '_stride_anonymised_at', true) : 0;
                    if (!$user || $anonymisedAt > 0) {
                        $displayName = $user
                            ? $user->display_name
                            : sprintf(__('Gebruiker #%d (verwijderd)', 'stride'), $userId);
                        $subtitle = $anonymisedAt > 0
                            ? sprintf(__('Geanonimiseerd op %s', 'stride'), date_i18n('j M Y', $anonymisedAt))
                            : __('Account verwijderd', 'stride');
                        ?>
                        <tr class="registration-row stride-row-anonymised" style="color:#646970;">
                            <td class="column-name">
                                <span style="font-style:italic;"><?php echo esc_html($displayName); ?></span>
                                <div style="font-size:11px;color:#8c8f94;"><?php echo esc_html($subtitle); ?></div>
                            </td>
                            <td class="column-email">&mdash;</td>
                            <td class="column-org">&mdash;</td>
                            <td class="column-status">
                                <span class="stride-status-badge"><?php esc_html_e('Inactief', 'stride'); ?></span>
                            </td>
                            <td class="column-date">
                                <?php echo $registeredAt ? esc_html(date_i18n('j M Y', strtotime($registeredAt))) : '&mdash;'; ?>
                            </td>
                            <td class="column-actions">&mdash;</td>
                        </tr>
                        <?php
                        continue;
                    }

                    $status = RegistrationStatus::tryFrom($registration['status'] ?? '') ?? RegistrationStatus::Pending;
                    $completionTasks = $this->getCompletionTasks($registration);
                    $meta = $userMeta[$userId] ?? [];
                    $enrollmentData = $this->getEnrollmentData($registration);
                    $organisation = $meta['organisation'] ?? '';

                    // Determine admin-facing badge and actions
                    $badgeLabel = $status->label();
                    $badgeClass = $status->value;
                    $showApproveReject = false;
                    $showPostApprove = false;

                    if ($status === RegistrationStatus::Pending && !empty($completionTasks)) {
                        $hasIncompleteUserTasks = false;
                        foreach ($completionTasks as $type => $task) {
                            if ($type === 'approval' || $type === 'post_approval') {
                                continue;
                            }
                            if (($task['status'] ?? 'pending') !== 'completed') {
                                $hasIncompleteUserTasks = true;
                                break;
                            }
                        }

                        if ($hasIncompleteUserTasks) {
                            $badgeLabel = __('Taken openstaand', 'stride');
                        } elseif (isset($completionTasks['approval'])) {
                            // User tasks done, waiting on admin approval
                            $showApproveReject = true;
                        } else {
                            // All tasks done, no approval required — shouldn't stay pending long
                            $badgeLabel = __('Wordt verwerkt', 'stride');
                        }
                    } elseif ($status === RegistrationStatus::Pending) {
                        // No completion tasks — edition uses manual approval
                        $showApproveReject = true;
                    }

                    // Post-course approval: confirmed registration with pending post_approval
                    if ($status === RegistrationStatus::Confirmed && !empty($completionTasks['post_approval'])) {
                        $postApprovalStatus = $completionTasks['post_approval']['status'] ?? 'pending';
                        if ($postApprovalStatus !== 'completed') {
                            // Check if post user tasks are done
                            $postUserDone = true;
                            foreach (['post_evaluation', 'post_documents'] as $pt) {
                                if (isset($completionTasks[$pt]) && ($completionTasks[$pt]['status'] ?? 'pending') !== 'completed') {
                                    $postUserDone = false;
                                    break;
                                }
                            }
                            if ($postUserDone) {
                                $showPostApprove = true;
                                $badgeLabel = __('Aftekenen vereist', 'stride');
                                $badgeClass = 'confirmed';
                            }
                        }
                    }
                    ?>
                    <tr class="registration-row stride-toggle-detail" data-reg-id="<?php echo esc_attr((string) $regId); ?>">
                        <td class="column-name">
                            <span class="dashicons dashicons-arrow-right-alt2 stride-detail-arrow"></span>
                            <?php echo esc_html($user->display_name); ?>
                        </td>
                        <td class="column-email"><?php echo esc_html($user->user_email); ?></td>
                        <td class="column-org"><?php echo esc_html($organisation); ?></td>
                        <td class="column-status">
                            <span class="stride-status-badge <?php echo esc_attr($badgeClass); ?>">
                                <?php echo esc_html($badgeLabel); ?>
                            </span>
                        </td>
                        <td class="column-date">
                            <?php echo $registeredAt ? esc_html(date_i18n('j M Y', strtotime($registeredAt))) : '&mdash;'; ?>
                        </td>
                        <td class="column-actions">
                            <?php if ($showApproveReject): ?>
                                <button type="button" class="button-link stride-confirm-reg" title="<?php esc_attr_e('Goedkeuren', 'stride'); ?>">
                                    <span class="dashicons dashicons-yes-alt"></span>
                                </button>
                                <button type="button" class="button-link stride-reject-reg" title="<?php esc_attr_e('Afwijzen', 'stride'); ?>">
                                    <span class="dashicons dashicons-dismiss"></span>
                                </button>
                            <?php endif; ?>
                            <?php if ($showPostApprove): ?>
                                <button type="button" class="button-link stride-approve-post-course" title="<?php esc_attr_e('Aftekenen', 'stride'); ?>">
                                    <span class="dashicons dashicons-yes-alt" style="color: #2271b1;"></span>
                                </button>
                            <?php endif; ?>

                            <span class="stride-action-divider" aria-hidden="true"></span>

                            <button type="button"
                                    class="button-link stride-view-enrollment"
                                    data-reg-id="<?php echo esc_attr((string) $regId); ?>"
                                    title="<?php esc_attr_e('Inschrijvingsgegevens bekijken', 'stride'); ?>">
                                <span class="dashicons dashicons-clipboard"></span>
                            </button>
                            <button type="button"
                                    class="button-link stride-view-completion"
                                    data-reg-id="<?php echo esc_attr((string) $regId); ?>"
                                    title="<?php esc_attr_e('Voltooiingsdata bekijken', 'stride'); ?>">
                                <span class="dashicons dashicons-yes"></span>
                            </button>
                            <?php if (!empty($quotes[$regId]['id'])): ?>
                                <a href="<?php echo esc_url((string) get_edit_post_link((int) $quotes[$regId]['id'])); ?>"
                                   class="button-link stride-view-quote"
                                   title="<?php esc_attr_e('Offerte bekijken', 'stride'); ?>"
                                   target="_blank" rel="noopener">
                                    <span class="dashicons dashicons-media-text"></span>
                                </a>
                            <?php else: ?>
                                <span class="button-link stride-view-quote disabled"
                                      title="<?php esc_attr_e('Geen offerte', 'stride'); ?>"
                                      aria-disabled="true">
                                    <span class="dashicons dashicons-media-text"></span>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr class="registration-detail" data-reg-id="<?php echo esc_attr((string) $regId); ?>" style="display:none">
                        <td colspan="6">
                            <?php $this->renderDetailRow($user, $meta, $registration, $completionTasks, $quotes[$regId] ?? null); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function renderDetailRow(\WP_User $user, array $meta, array $registration, array $completionTasks, ?array $quote = null): void
    {
        $phone = $meta['phone'] ?? '';
        $organisation = $meta['organisation'] ?? '';
        $department = $meta['department'] ?? '';
        $company = $meta['billing_company'] ?? '';
        $vatNumber = $meta['billing_vat'] ?? '';
        $notes = $registration['notes'] ?? '';

        $hasContent = ($phone || $organisation || $department || $vatNumber || $notes
            || ($company && $company !== $organisation));
        ?>
        <div class="stride-detail-panels">
            <dl class="stride-detail-dl">
                <?php if ($phone): ?>
                    <dt><?php esc_html_e('Telefoon', 'stride'); ?></dt>
                    <dd><?php echo esc_html($phone); ?></dd>
                <?php endif; ?>
                <?php if ($organisation): ?>
                    <dt><?php esc_html_e('Organisatie', 'stride'); ?></dt>
                    <dd><?php echo esc_html($organisation); ?></dd>
                <?php endif; ?>
                <?php if ($department): ?>
                    <dt><?php esc_html_e('Afdeling', 'stride'); ?></dt>
                    <dd><?php echo esc_html($department); ?></dd>
                <?php endif; ?>
                <?php if ($company && $company !== $organisation): ?>
                    <dt><?php esc_html_e('Facturatie bedrijf', 'stride'); ?></dt>
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
                <?php if (!$hasContent): ?>
                    <dd class="stride-detail-empty"><?php esc_html_e('Geen aanvullende gegevens.', 'stride'); ?></dd>
                <?php endif; ?>
            </dl>
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
                            $organisation = $userMeta[$userId]['organisation'] ?? '';
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
        $metaKeys = ['phone', 'organisation', 'billing_company', 'billing_vat', 'billing_address_1', 'department'];

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

    private function getEnrollmentData(array $registration): array
    {
        $data = $registration['enrollment_data'] ?? '';
        if (is_string($data) && $data !== '') {
            $decoded = json_decode($data, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($data) ? $data : [];
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

    /**
     * Batch fetch quotes for an edition, indexed by registration_id.
     *
     * @return array<int, array{id: int, quote_number: string, status: string, total: int}>
     */
    private function batchGetQuotes(int $editionId): array
    {
        $results = ntdst_data()->get('vad_quote')
            ->where('edition_id', $editionId)
            ->where('post_status', 'publish')
            ->withMeta()
            ->get();

        $indexed = [];
        foreach ($results as $quote) {
            $regId = (int) ($quote['meta']['registration_id'] ?? 0);
            if ($regId > 0) {
                $indexed[$regId] = [
                    'id' => (int) $quote['id'],
                    'quote_number' => $quote['meta']['quote_number'] ?? '',
                    'status' => $quote['meta']['status'] ?? '',
                    'total' => (int) ($quote['meta']['total'] ?? 0),
                ];
            }
        }

        return $indexed;
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
