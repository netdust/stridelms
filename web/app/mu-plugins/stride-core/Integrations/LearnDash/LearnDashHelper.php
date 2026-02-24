<?php

declare(strict_types=1);

namespace Stride\Integrations\LearnDash;

use Stride\Contracts\LMSAdapterInterface;

/**
 * LearnDash Template Helper
 *
 * Presentation logic for LearnDash course templates.
 * Handles course access modes, CTAs, progress display, and lesson lists.
 *
 * For business operations (grant/revoke access, completion), use LMSAdapterInterface.
 *
 * @see https://developers.learndash.com/function/sfwd_lms_has_access/
 * @see https://developers.learndash.com/function/learndash_get_setting/
 */
final class LearnDashHelper
{
    /**
     * Course access modes.
     */
    public const MODE_OPEN = 'open';
    public const MODE_FREE = 'free';
    public const MODE_PAYNOW = 'paynow';
    public const MODE_SUBSCRIBE = 'subscribe';
    public const MODE_CLOSED = 'closed';

    /**
     * Check if LearnDash is active.
     */
    public static function isActive(): bool
    {
        return defined('LEARNDASH_VERSION') && function_exists('sfwd_lms_has_access');
    }

    /**
     * Check if user has access to course.
     */
    public static function hasAccess(int $courseId, ?int $userId = null): bool
    {
        if (!self::isActive()) {
            return false;
        }

        $userId = $userId ?? get_current_user_id();

        // Open courses - everyone has access
        if (self::getAccessMode($courseId) === self::MODE_OPEN) {
            return true;
        }

        if (!$userId) {
            return false;
        }

        return sfwd_lms_has_access($courseId, $userId);
    }

    /**
     * Get course access mode.
     *
     * @return string One of: open, free, paynow, subscribe, closed
     */
    public static function getAccessMode(int $courseId): string
    {
        if (!self::isActive()) {
            return self::MODE_CLOSED;
        }

        $mode = learndash_get_setting($courseId, 'course_price_type');

        return in_array($mode, [
            self::MODE_OPEN,
            self::MODE_FREE,
            self::MODE_PAYNOW,
            self::MODE_SUBSCRIBE,
            self::MODE_CLOSED,
        ], true) ? $mode : self::MODE_FREE;
    }

    /**
     * Get course price info.
     *
     * @return array{type: string, price: string, currency: string, billing_cycle: string}
     */
    public static function getCoursePrice(int $courseId): array
    {
        if (!self::isActive() || !function_exists('learndash_get_course_price')) {
            return [
                'type' => self::MODE_FREE,
                'price' => '',
                'currency' => '',
                'billing_cycle' => '',
            ];
        }

        $price = learndash_get_course_price($courseId);

        return [
            'type' => $price['type'] ?? self::MODE_FREE,
            'price' => $price['price'] ?? '',
            'currency' => $price['currency'] ?? get_option('learndash_settings_paypal_currency', 'EUR'),
            'billing_cycle' => $price['pricing_billing_p3'] ?? '',
        ];
    }

    /**
     * Get the closed course button URL.
     */
    public static function getClosedButtonUrl(int $courseId): string
    {
        if (!self::isActive()) {
            return '';
        }

        return learndash_get_setting($courseId, 'custom_button_url') ?: '';
    }

    /**
     * Determine what CTA (call-to-action) to show for a course.
     *
     * @return array{action: string, label: string, url: string, show_login: bool}
     */
    public static function getCourseAction(int $courseId, ?int $userId = null): array
    {
        $userId = $userId ?? get_current_user_id();
        $isLoggedIn = $userId > 0;
        $mode = self::getAccessMode($courseId);
        $hasAccess = self::hasAccess($courseId, $userId);

        // User has access - show start/continue/view
        if ($hasAccess) {
            $progress = self::getProgress($courseId, $userId);

            if ($progress >= 100) {
                return [
                    'action' => 'view',
                    'label' => __('Bekijk Cursus', 'stride'),
                    'url' => self::getResumeUrl($courseId, $userId),
                    'show_login' => false,
                ];
            }

            if ($progress > 0) {
                return [
                    'action' => 'continue',
                    'label' => __('Doorgaan', 'stride'),
                    'url' => self::getResumeUrl($courseId, $userId),
                    'show_login' => false,
                ];
            }

            return [
                'action' => 'start',
                'label' => __('Start Cursus', 'stride'),
                'url' => self::getFirstLessonUrl($courseId),
                'show_login' => false,
            ];
        }

        // No access - determine enrollment action based on mode
        switch ($mode) {
            case self::MODE_OPEN:
                return [
                    'action' => 'start',
                    'label' => __('Start Cursus', 'stride'),
                    'url' => self::getFirstLessonUrl($courseId),
                    'show_login' => false,
                ];

            case self::MODE_FREE:
                if (!$isLoggedIn) {
                    return [
                        'action' => 'login',
                        'label' => __('Log in om in te schrijven', 'stride'),
                        'url' => wp_login_url(get_permalink($courseId)),
                        'show_login' => true,
                    ];
                }
                // Logged in but not enrolled - show enroll button
                return [
                    'action' => 'enroll_free',
                    'label' => __('Gratis Inschrijven', 'stride'),
                    'url' => self::getEnrollUrl($courseId),
                    'show_login' => false,
                ];

            case self::MODE_PAYNOW:
                $price = self::getCoursePrice($courseId);
                return [
                    'action' => 'buy',
                    'label' => sprintf(__('Kopen - %s', 'stride'), self::formatPrice($price)),
                    'url' => self::getEnrollUrl($courseId),
                    'show_login' => !$isLoggedIn,
                ];

            case self::MODE_SUBSCRIBE:
                $price = self::getCoursePrice($courseId);
                return [
                    'action' => 'subscribe',
                    'label' => sprintf(__('Abonneren - %s', 'stride'), self::formatPrice($price)),
                    'url' => self::getEnrollUrl($courseId),
                    'show_login' => !$isLoggedIn,
                ];

            case self::MODE_CLOSED:
                $buttonUrl = self::getClosedButtonUrl($courseId);
                return [
                    'action' => 'closed',
                    'label' => __('Inschrijven', 'stride'),
                    'url' => $buttonUrl ?: get_permalink($courseId),
                    'show_login' => !$isLoggedIn && empty($buttonUrl),
                ];
        }

        return [
            'action' => 'none',
            'label' => '',
            'url' => '',
            'show_login' => false,
        ];
    }

    /**
     * Get user progress for a course.
     */
    public static function getProgress(int $courseId, ?int $userId = null): int
    {
        if (!self::isActive()) {
            return 0;
        }

        $userId = $userId ?? get_current_user_id();
        if (!$userId) {
            return 0;
        }

        $progress = learndash_course_progress([
            'user_id' => $userId,
            'course_id' => $courseId,
            'array' => true,
        ]);

        return (int) ($progress['percentage'] ?? 0);
    }

    /**
     * Get URL to resume course (next incomplete lesson).
     */
    public static function getResumeUrl(int $courseId, ?int $userId = null): string
    {
        if (!self::isActive()) {
            return get_permalink($courseId);
        }

        $userId = $userId ?? get_current_user_id();

        // Try to get last activity
        if (function_exists('learndash_user_progress_get_first_incomplete_step')) {
            $step = learndash_user_progress_get_first_incomplete_step($userId, $courseId);
            if ($step && isset($step['post']->ID)) {
                return get_permalink($step['post']->ID);
            }
        }

        return self::getFirstLessonUrl($courseId);
    }

    /**
     * Get URL to first lesson.
     */
    public static function getFirstLessonUrl(int $courseId): string
    {
        if (!self::isActive()) {
            return get_permalink($courseId);
        }

        $lessons = learndash_get_course_lessons_list($courseId);

        if (!empty($lessons)) {
            $firstLesson = reset($lessons);
            $lessonId = $firstLesson['post']->ID ?? $firstLesson->ID ?? null;
            if ($lessonId) {
                return get_permalink($lessonId);
            }
        }

        return get_permalink($courseId);
    }

    /**
     * Get enrollment URL for course.
     */
    public static function getEnrollUrl(int $courseId): string
    {
        // LearnDash handles enrollment via course page with payment buttons
        return get_permalink($courseId);
    }

    /**
     * Format price for display.
     */
    public static function formatPrice(array $priceInfo): string
    {
        if (empty($priceInfo['price'])) {
            return __('Gratis', 'stride');
        }

        $currency = $priceInfo['currency'] ?: 'EUR';
        $price = $priceInfo['price'];

        // Format with currency symbol
        $symbols = [
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
        ];

        $symbol = $symbols[$currency] ?? $currency . ' ';

        return $symbol . number_format((float) $price, 2, ',', '.');
    }

    /**
     * Get course lessons with completion status.
     *
     * @return array<int, array{id: int, title: string, url: string, completed: bool}>
     */
    public static function getLessons(int $courseId, ?int $userId = null): array
    {
        if (!self::isActive()) {
            return [];
        }

        $userId = $userId ?? get_current_user_id();
        $lessons = learndash_get_course_lessons_list($courseId);
        $result = [];

        foreach ($lessons as $lesson) {
            $lessonPost = $lesson['post'] ?? $lesson;
            $lessonId = $lessonPost->ID ?? $lessonPost;

            $result[] = [
                'id' => $lessonId,
                'title' => is_object($lessonPost) ? $lessonPost->post_title : get_the_title($lessonId),
                'url' => get_permalink($lessonId),
                'completed' => $userId && learndash_is_lesson_complete($userId, $lessonId, $courseId),
            ];
        }

        return $result;
    }

    /**
     * Get certificate link if user completed course.
     *
     * Delegates to LMSAdapterInterface for the actual link retrieval.
     */
    public static function getCertificateLink(int $courseId, ?int $userId = null): string
    {
        $userId = $userId ?? get_current_user_id();

        if (!$userId || self::getProgress($courseId, $userId) < 100) {
            return '';
        }

        $adapter = self::getAdapter();
        if (!$adapter) {
            return '';
        }

        return $adapter->getCertificateLink($userId, $courseId) ?? '';
    }

    /**
     * Get the LMS adapter instance.
     */
    private static function getAdapter(): ?LMSAdapterInterface
    {
        if (!function_exists('ntdst_get')) {
            return null;
        }

        try {
            return ntdst_get(LMSAdapterInterface::class);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Check if course has a certificate configured.
     */
    public static function hasCertificate(int $courseId): bool
    {
        if (!self::isActive()) {
            return false;
        }

        $settings = get_post_meta($courseId, '_sfwd-courses', true);
        return !empty($settings['sfwd-courses_certificate']);
    }

    /**
     * Get course materials/what you'll learn.
     */
    public static function getCourseMaterials(int $courseId): string
    {
        if (!self::isActive()) {
            return '';
        }

        $materials = learndash_get_setting($courseId, 'course_materials');
        return $materials ?: '';
    }
}
