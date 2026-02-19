<?php
/**
 * Calendar Template
 *
 * Displays user's upcoming sessions and events.
 *
 * @package stride
 */

defined('ABSPATH') || exit;

use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Edition\SessionService;

$userId = get_current_user_id();

// Get services
$enrollmentService = ntdst_get(EnrollmentService::class);
$sessionService = ntdst_get(SessionService::class);

// Get upcoming sessions for enrolled editions
$enrollments = $enrollmentService->getUserEnrollments($userId);
$upcomingSessions = [];

foreach ($enrollments as $enrollment) {
    if (!empty($enrollment['edition_id'])) {
        $sessions = $sessionService->getSessionsForEdition($enrollment['edition_id']);
        foreach ($sessions as $session) {
            // Only include future sessions
            $sessionDate = strtotime($session['date'] ?? '');
            if ($sessionDate && $sessionDate >= strtotime('today')) {
                $session['course_title'] = $enrollment['course_title'] ?? '';
                $session['edition_id'] = $enrollment['edition_id'];
                $upcomingSessions[] = $session;
            }
        }
    }
}

// Sort by date
usort($upcomingSessions, function ($a, $b) {
    return strtotime($a['date'] ?? '') - strtotime($b['date'] ?? '');
});
?>

<div class="stride-dashboard-calendar">
    <!-- Page Header -->
    <div class="stride-page-header uk-margin-medium-bottom">
        <h1 class="uk-heading-medium"><?php esc_html_e('Mijn Agenda', 'stride'); ?></h1>
        <p class="uk-text-meta">
            <?php esc_html_e('Bekijk je aankomende sessies en evenementen.', 'stride'); ?>
        </p>
    </div>

    <?php if (empty($upcomingSessions)) : ?>
        <!-- Empty State -->
        <div class="uk-card uk-card-default uk-card-body uk-text-center uk-padding-large">
            <span uk-icon="icon: calendar; ratio: 3" class="uk-text-muted"></span>
            <h3 class="uk-margin-small-top"><?php esc_html_e('Geen aankomende sessies', 'stride'); ?></h3>
            <p class="uk-text-muted">
                <?php esc_html_e('Je hebt momenteel geen geplande sessies.', 'stride'); ?>
            </p>
            <a href="<?php echo esc_url(home_url('/cursussen/')); ?>" class="uk-button uk-button-primary uk-margin-top">
                <?php esc_html_e('Bekijk cursussen', 'stride'); ?>
            </a>
        </div>
    <?php else : ?>
        <!-- Sessions List -->
        <div class="uk-card uk-card-default">
            <div class="uk-card-body uk-padding-remove">
                <table class="uk-table uk-table-hover uk-table-divider uk-margin-remove">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Datum', 'stride'); ?></th>
                            <th><?php esc_html_e('Tijd', 'stride'); ?></th>
                            <th class="uk-visible@m"><?php esc_html_e('Cursus', 'stride'); ?></th>
                            <th class="uk-visible@s"><?php esc_html_e('Locatie', 'stride'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($upcomingSessions as $session) : ?>
                            <tr>
                                <td>
                                    <span class="uk-text-bold">
                                        <?php echo esc_html(date_i18n('d M Y', strtotime($session['date'] ?? ''))); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $startTime = $session['start_time'] ?? '';
                                    $endTime = $session['end_time'] ?? '';
                                    if ($startTime && $endTime) {
                                        echo esc_html($startTime . ' - ' . $endTime);
                                    } elseif ($startTime) {
                                        echo esc_html($startTime);
                                    } else {
                                        esc_html_e('TBD', 'stride');
                                    }
                                    ?>
                                </td>
                                <td class="uk-visible@m">
                                    <?php echo esc_html($session['course_title'] ?? ''); ?>
                                </td>
                                <td class="uk-visible@s">
                                    <?php
                                    $location = $session['location'] ?? '';
                                    if ($location) {
                                        echo esc_html($location);
                                    } else {
                                        echo '<span class="uk-text-muted">' . esc_html__('Online', 'stride') . '</span>';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
