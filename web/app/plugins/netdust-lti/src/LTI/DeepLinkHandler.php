<?php
declare(strict_types=1);

namespace NetdustLTI\LTI;

use ceLTIc\LTI\Content\Item;
use ceLTIc\LTI\Content\LtiLinkItem;
use ceLTIc\LTI\Content\LineItem;
use ceLTIc\LTI\Platform;
use ceLTIc\LTI\Enum\LtiVersion;
use NetdustLTI\DataConnector\WPDataConnector;

/**
 * Handles LTI Deep Linking course selection flow.
 *
 * When an LMS requests a Deep Link, this handler:
 * 1. Shows a course picker to select a LearnDash course
 * 2. Creates an LTI Resource Link item with the course info
 * 3. Sends the response back to the platform
 */
final class DeepLinkHandler
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'registerPage']);
        add_action('admin_init', [$this, 'handleSubmission']);
    }

    /**
     * Register hidden admin page for course picker.
     */
    public function registerPage(): void
    {
        add_submenu_page(
            '', // Hidden from menu (empty string instead of null for WP 6.9+ compat)
            __('Select Course', 'netdust-lti'),
            __('Select Course', 'netdust-lti'),
            'manage_options',
            'netdust-lti-deep-link',
            [$this, 'renderPicker']
        );
    }

    /**
     * Render the course picker interface.
     */
    public function renderPicker(): void
    {
        $this->ensureSession();

        if (!isset($_SESSION['lti_deep_link'])) {
            wp_die(
                __('Invalid deep link session. Please try again from your LMS.', 'netdust-lti'),
                __('Deep Link Error', 'netdust-lti'),
                ['response' => 400]
            );
        }

        $courses = get_posts([
            'post_type' => 'sfwd-courses',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'post_status' => 'publish',
        ]);

        include dirname(__DIR__, 2) . '/templates/deep-link-picker.php';
    }

    /**
     * Handle course selection form submission.
     */
    public function handleSubmission(): void
    {
        if (!isset($_POST['netdust_lti_deep_link_nonce'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['netdust_lti_deep_link_nonce'], 'netdust_lti_deep_link')) {
            wp_die(
                __('Security check failed. Please try again.', 'netdust-lti'),
                __('Security Error', 'netdust-lti'),
                ['response' => 403]
            );
        }

        if (!current_user_can('manage_options')) {
            wp_die(
                __('You do not have permission to perform this action.', 'netdust-lti'),
                __('Permission Denied', 'netdust-lti'),
                ['response' => 403]
            );
        }

        $this->ensureSession();

        if (!isset($_SESSION['lti_deep_link'])) {
            wp_die(
                __('Invalid deep link session. Please try again from your LMS.', 'netdust-lti'),
                __('Deep Link Error', 'netdust-lti'),
                ['response' => 400]
            );
        }

        $courseId = isset($_POST['course_id']) ? (int) $_POST['course_id'] : 0;
        $course = get_post($courseId);

        if (!$course || $course->post_type !== 'sfwd-courses') {
            wp_die(
                __('Invalid course selected. Please try again.', 'netdust-lti'),
                __('Invalid Course', 'netdust-lti'),
                ['response' => 400]
            );
        }

        $deepLinkData = $_SESSION['lti_deep_link'];
        unset($_SESSION['lti_deep_link']);

        $this->sendResponse($deepLinkData, $course);
    }

    /**
     * Build and send the Deep Link response to the platform.
     */
    private function sendResponse(array $deepLinkData, \WP_Post $course): void
    {
        $dataConnector = new WPDataConnector();
        $platform = Platform::fromRecordId($deepLinkData['platform_id'], $dataConnector);

        if (!$platform || !$platform->getRecordId()) {
            wp_die(
                __('Could not load platform configuration.', 'netdust-lti'),
                __('Platform Error', 'netdust-lti'),
                ['response' => 500]
            );
        }

        // Create LTI Resource Link item
        $item = new LtiLinkItem();
        $item->setTitle($course->post_title);
        $item->setUrl(home_url('/lti/launch'));

        // Set description from excerpt or truncated content
        $description = $course->post_excerpt;
        if (empty($description)) {
            $description = wp_trim_words(wp_strip_all_tags($course->post_content), 30, '...');
        }
        $item->setText($description);

        // Custom parameters for launch - course ID will be passed on each launch
        $item->addCustom('ld_course_id', (string) $course->ID);

        // Line item for gradebook integration (AGS)
        $lineItem = new LineItem(
            $course->post_title . ' - ' . __('Completion', 'netdust-lti'),
            100, // Maximum score
            'course-' . $course->ID, // Resource ID
            'completion' // Tag
        );
        $item->setLineItem($lineItem);

        // Create the tool instance for signing
        $tool = new NetdustLTITool($dataConnector);
        $tool->platform = $platform;

        // Build the form parameters for ContentItemSelection response
        $messageParams = [
            'content_items' => Item::toJson([$item], LtiVersion::V1P3),
        ];

        // Include data parameter if provided by platform
        if (!empty($deepLinkData['data'])) {
            $messageParams['data'] = $deepLinkData['data'];
        }

        ntdst_log('lti')->info('Sending deep link response', [
            'platform_id' => $platform->getRecordId(),
            'course_id' => $course->ID,
            'return_url' => $deepLinkData['return_url'],
        ]);

        // Send the message (generates auto-submit form HTML)
        $html = $tool->sendMessage(
            $deepLinkData['return_url'],
            'ContentItemSelection',
            $messageParams
        );

        // Output the auto-submit form
        echo $html;
        exit;
    }

    /**
     * Ensure PHP session is started with correct settings.
     */
    private function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Cross-site session cookies for LTI in iframe
            if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
                ini_set('session.cookie_samesite', 'None');
                ini_set('session.cookie_secure', '1');
            }
            session_start();
        }
    }
}
