<?php

declare(strict_types=1);

namespace Stride\Modules\User;

use Stride\Modules\Enrollment\EnrollmentService;
use WP_CLI;
use WP_Error;
use WP_User;

/**
 * GDPR-compliant user anonymisation.
 *
 * Belgian training records have a 7–10 year retention requirement. Hard-deleting
 * a user via wp_delete_user() orphans every registration / quote / certificate
 * that referenced them, which breaks both retention and the admin UI.
 *
 * This service replaces the default "Verwijderen" Users-list row action with
 * "Anonimiseer", which strips PII but keeps the wp_users row + every foreign-key
 * reference intact. The standard wp_delete_user() remains as the admin's nuclear
 * option for spam/test accounts.
 */
class UserLifecycleService implements \NTDST_Service_Meta
{
    public const META_ANONYMISED_AT = '_stride_anonymised_at';

    /**
     * Identity meta keys cleared on anonymisation, in addition to those mapped
     * from the enrollment form via EnrollmentService::getUserMetaMapping().
     *
     * These don't appear in the form but contain PII or personal preferences.
     */
    private const EXTRA_PII_META_KEYS = [
        'first_name',
        'last_name',
        'nickname',
        'description',
        '_stride_profile_type',
        '_stride_notifications_read',
        'stride_phone',
        'stride_communication_language',
        'stride_notify_reminders',
        'stride_notify_new_courses',
        'stride_notify_newsletter',
    ];

    public static function metadata(): array
    {
        return [
            'name' => 'User Lifecycle Service',
            'description' => 'GDPR-compliant user anonymisation',
            'priority' => 4,
        ];
    }

    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        if (is_admin()) {
            add_filter('user_row_actions', [$this, 'filterRowActions'], 10, 2);
            add_action('admin_post_stride_anonymise_user', [$this, 'handleAdminAnonymisePost']);
            add_action('admin_notices', [$this, 'renderAdminNotices']);
            add_action('edit_user_profile', [$this, 'renderUserEditAnonymiseSection']);
        }
        add_action('delete_user', [$this, 'auditHardDelete'], 10, 3);

        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('stride anonymise-orphans', [$this, 'cliAnonymiseOrphans']);
        }
    }

    /**
     * Show a success/error toast on users.php and user-edit.php after the
     * anonymise action redirects back.
     */
    public function renderAdminNotices(): void
    {
        if (isset($_GET['stride_anonymised'])) {
            $id = (int) $_GET['stride_anonymised'];
            $msg = sprintf(__('Gebruiker #%d is geanonimiseerd. Inschrijvingen blijven bewaard.', 'stride'), $id);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($msg) . '</p></div>';
        }
        if (isset($_GET['stride_anonymise_error'])) {
            $err = sanitize_text_field((string) $_GET['stride_anonymise_error']);
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Anonimisering mislukt: ', 'stride') . esc_html($err) . '</p></div>';
        }
    }

    /**
     * Render the Anonimiseer panel on the user-edit page (wp-admin/user-edit.php).
     */
    public function renderUserEditAnonymiseSection(\WP_User $user): void
    {
        if (!current_user_can('edit_user', $user->ID)) {
            return;
        }
        if ($user->ID === get_current_user_id()) {
            return;
        }

        if ($this->isAnonymised($user->ID)) {
            $at = (int) get_user_meta($user->ID, self::META_ANONYMISED_AT, true);
            ?>
            <h2><?php esc_html_e('Anonimisering', 'stride'); ?></h2>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e('Status', 'stride'); ?></th>
                    <td>
                        <span style="color:#646970;">
                            <?php echo esc_html(sprintf(__('Geanonimiseerd op %s', 'stride'), date_i18n('d M Y H:i', $at))); ?>
                        </span>
                    </td>
                </tr>
            </table>
            <?php
            return;
        }

        $url = wp_nonce_url(
            admin_url('admin-post.php?action=stride_anonymise_user&user=' . $user->ID),
            'stride_anonymise_user_' . $user->ID
        );
        $confirm = esc_attr__('Gebruiker anonimiseren? PII wordt verwijderd; inschrijvingen blijven bewaard.', 'stride');
        ?>
        <h2><?php esc_html_e('Anonimisering (GDPR)', 'stride'); ?></h2>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e('Acties', 'stride'); ?></th>
                <td>
                    <a href="<?php echo esc_url($url); ?>"
                       class="button"
                       onclick="return confirm('<?php echo $confirm; ?>');">
                        <?php esc_html_e('Anonimiseer gebruiker', 'stride'); ?>
                    </a>
                    <p class="description" style="margin-top:8px;">
                        <?php esc_html_e('Verwijdert persoonlijke gegevens (naam, e-mail, telefoon, adres, RRN, etc.) maar bewaart het account en alle inschrijvingen voor wettelijke retentie. Eenmaal geanonimiseerd kan deze actie niet ongedaan worden gemaakt.', 'stride'); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function isAnonymised(int $userId): bool
    {
        return (bool) get_user_meta($userId, self::META_ANONYMISED_AT, true);
    }

    /**
     * Anonymise a user: strip PII, keep the wp_users row.
     *
     * Idempotent — second call is a no-op.
     */
    public function anonymise(int $userId): bool|WP_Error
    {
        $user = get_userdata($userId);
        if (!$user) {
            return new WP_Error('user_not_found', sprintf('User %d does not exist', $userId));
        }

        if ($this->isAnonymised($userId)) {
            return true;
        }

        if (user_can($user, 'manage_options')) {
            return new WP_Error('cannot_anonymise_admin', 'Refusing to anonymise an administrator');
        }

        // Also refuse Stride staff (stride_coordinator). Their accounts are
        // operational, not customer PII, and anonymising one rotates passwords
        // / blanks display names — destructive and irreversible.
        if (user_can($user, 'stride_manage')) {
            return new WP_Error('cannot_anonymise_staff', 'Refusing to anonymise a Stride staff account');
        }

        // Suppress WP email-change / password-change notifications
        add_filter('send_email_change_email', '__return_false');
        add_filter('send_password_change_email', '__return_false');

        // Strip wp_users row fields
        $update = wp_update_user([
            'ID' => $userId,
            'user_email' => sprintf('anonymised+%d@deleted.local', $userId),
            'user_url' => '',
            'display_name' => sprintf('Verwijderde gebruiker #%d', $userId),
            'nickname' => sprintf('anonymised-%d', $userId),
            'first_name' => '',
            'last_name' => '',
            'description' => '',
            'user_pass' => wp_generate_password(64),
        ]);

        remove_filter('send_email_change_email', '__return_false');
        remove_filter('send_password_change_email', '__return_false');

        if (is_wp_error($update)) {
            return $update;
        }

        // Rename user_login (wp_update_user can't change it; raw DB write)
        global $wpdb;
        $wpdb->update(
            $wpdb->users,
            ['user_login' => sprintf('anonymised_%d', $userId), 'user_activation_key' => ''],
            ['ID' => $userId],
            ['%s', '%s'],
            ['%d']
        );
        clean_user_cache($userId);

        // Strip mapped user meta
        $mapping = EnrollmentService::getUserMetaMapping();
        foreach (array_values($mapping) as $metaKey) {
            delete_user_meta($userId, $metaKey);
        }
        foreach (self::EXTRA_PII_META_KEYS as $metaKey) {
            delete_user_meta($userId, $metaKey);
        }

        // Demote to subscriber so any cached cap checks don't return privileged roles
        $user->set_role('subscriber');

        // Mark anonymised
        update_user_meta($userId, self::META_ANONYMISED_AT, time());

        do_action('stride/user/anonymised', $userId);

        return true;
    }

    /**
     * Replace WP's "Verwijderen" row action with "Anonimiseer" (default) +
     * keep the WP delete as the admin nuclear option.
     *
     * @param array<string, string> $actions
     */
    public function filterRowActions(array $actions, WP_User $user): array
    {
        if (!current_user_can('edit_user', $user->ID)) {
            return $actions;
        }
        if ($user->ID === get_current_user_id()) {
            return $actions;
        }

        if ($this->isAnonymised($user->ID)) {
            $at = (int) get_user_meta($user->ID, self::META_ANONYMISED_AT, true);
            $actions = ['stride_anonymised' => sprintf(
                '<span style="color:#646970;">%s</span>',
                esc_html(sprintf(__('Geanonimiseerd op %s', 'stride'), date_i18n('d M Y', $at)))
            )];
            return $actions;
        }

        $url = wp_nonce_url(
            admin_url('admin-post.php?action=stride_anonymise_user&user=' . $user->ID),
            'stride_anonymise_user_' . $user->ID
        );
        $confirm = esc_attr__('Gebruiker anonimiseren? PII wordt verwijderd; inschrijvingen blijven bewaard.', 'stride');
        $anonAction = sprintf(
            '<a href="%s" onclick="return confirm(\'%s\');">%s</a>',
            esc_url($url),
            $confirm,
            esc_html__('Anonimiseer', 'stride')
        );

        // Insert before any 'delete' action (WP delete stays as nuclear option for caps)
        $newActions = [];
        foreach ($actions as $key => $val) {
            if ($key === 'delete') {
                $newActions['stride_anonymise'] = $anonAction;
            }
            $newActions[$key] = $val;
        }
        if (!isset($newActions['stride_anonymise'])) {
            $newActions['stride_anonymise'] = $anonAction;
        }
        return $newActions;
    }

    /**
     * Handle the GET request from the admin row action.
     */
    public function handleAdminAnonymisePost(): void
    {
        $userId = isset($_GET['user']) ? (int) $_GET['user'] : 0;
        // Require BOTH edit_user (WP-core) AND stride_manage. The edit_user
        // gate alone is mapped to edit_users on most installs — a third-party
        // plugin granting edit_users to a non-Stride role would otherwise let
        // that role wipe any user's PII irreversibly.
        if (!$userId || !current_user_can('edit_user', $userId) || !current_user_can('stride_manage')) {
            wp_die(__('Geen toestemming.', 'stride'));
        }
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce((string) $_GET['_wpnonce'], 'stride_anonymise_user_' . $userId)) {
            wp_die(__('Ongeldige beveiligingstoken.', 'stride'));
        }

        $result = $this->anonymise($userId);
        $redirect = admin_url('users.php');

        if (is_wp_error($result)) {
            $redirect = add_query_arg('stride_anonymise_error', urlencode($result->get_error_message()), $redirect);
        } else {
            $redirect = add_query_arg('stride_anonymised', $userId, $redirect);
        }

        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Audit hard-deletes (admin chose the nuclear option). Does NOT block —
     * but logs so the orphan can be tracked via the CLI cleanup.
     */
    public function auditHardDelete(int $userId, ?int $reassign, WP_User $user): void
    {
        if ($this->isAnonymised($userId)) {
            // Already anonymised, going for hard delete now — log but don't worry
            ntdst_log('user-lifecycle')->info('Hard-deleting an already-anonymised user', [
                'user_id' => $userId,
            ]);
            return;
        }

        $regsTable = $GLOBALS['wpdb']->prefix . 'vad_registrations';
        $attTable = $GLOBALS['wpdb']->prefix . 'vad_attendance';
        $regsCount = (int) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare(
            "SELECT COUNT(*) FROM {$regsTable} WHERE user_id = %d",
            $userId
        ));
        $attCount = (int) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare(
            "SELECT COUNT(*) FROM {$attTable} WHERE user_id = %d",
            $userId
        ));

        ntdst_log('user-lifecycle')->warning('Hard-delete of user with active records', [
            'user_id' => $userId,
            'display_name' => $user->display_name,
            'email_hash' => hash('sha256', $user->user_email),
            'orphaning_registrations' => $regsCount,
            'orphaning_attendance_rows' => $attCount,
        ]);
    }

    /**
     * WP-CLI: scan stride_vad_registrations + stride_vad_attendance for
     * user_id values that no longer resolve in wp_users.
     *
     * Usage:
     *   wp stride anonymise-orphans              (dry-run)
     *   wp stride anonymise-orphans --commit     (flag orphan rows)
     *
     * @param array<int, string> $args
     * @param array<string, string> $assocArgs
     */
    public function cliAnonymiseOrphans(array $args, array $assocArgs): void
    {
        global $wpdb;
        $commit = isset($assocArgs['commit']);

        $regsTable = $wpdb->prefix . 'vad_registrations';
        $attTable = $wpdb->prefix . 'vad_attendance';
        $usersTable = $wpdb->users;

        $orphanRegs = $wpdb->get_results(
            "SELECT r.id, r.user_id FROM {$regsTable} r
             LEFT JOIN {$usersTable} u ON u.ID = r.user_id
             WHERE r.user_id IS NOT NULL AND u.ID IS NULL"
        );
        $orphanAtt = $wpdb->get_results(
            "SELECT a.id, a.user_id FROM {$attTable} a
             LEFT JOIN {$usersTable} u ON u.ID = a.user_id
             WHERE a.user_id IS NOT NULL AND u.ID IS NULL"
        );

        $missingUserIds = array_unique(array_merge(
            array_map(static fn($r) => (int) $r->user_id, $orphanRegs),
            array_map(static fn($a) => (int) $a->user_id, $orphanAtt)
        ));

        if (empty($missingUserIds)) {
            WP_CLI::success('No orphan references found.');
            return;
        }

        WP_CLI::log(sprintf(
            'Found %d registration row(s) and %d attendance row(s) referencing %d deleted user(s).',
            count($orphanRegs),
            count($orphanAtt),
            count($missingUserIds)
        ));
        foreach ($missingUserIds as $uid) {
            WP_CLI::log(sprintf('  deleted user_id=%d', $uid));
        }

        if (!$commit) {
            WP_CLI::log('Re-run with --commit to flag these rows as orphaned.');
            return;
        }

        $marker = sprintf('orphan: user deleted (flagged %s)', current_time('mysql'));
        $touchedRegs = 0;
        foreach ($orphanRegs as $row) {
            $existingNotes = $wpdb->get_var($wpdb->prepare(
                "SELECT notes FROM {$regsTable} WHERE id = %d",
                $row->id
            ));
            $newNotes = $existingNotes ? trim($existingNotes . "\n" . $marker) : $marker;
            $wpdb->update($regsTable, ['notes' => $newNotes], ['id' => $row->id], ['%s'], ['%d']);
            $touchedRegs++;
        }

        WP_CLI::success(sprintf('Flagged %d registration row(s). Attendance rows left as-is (no notes column).', $touchedRegs));
    }
}
