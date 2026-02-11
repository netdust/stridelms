<?php

namespace stride\services\smartcode\providers;

defined('ABSPATH') || exit;

use stride\services\smartcode\contracts\SmartCodeProviderInterface;
use stride\services\smartcode\contracts\SmartCodeContextInterface;
use stride\services\core\CourseService;

/**
 * Course SmartCode Provider
 *
 * Provides course data SmartCodes for FluentCRM and FluentForms.
 * Uses CourseService and SmartCodeContext for data retrieval.
 *
 * SECURITY: Output is escaped with esc_html() by default to prevent XSS.
 * Use the 'stride/smartcode/escape_output' filter to customize escaping behavior.
 *
 * PERFORMANCE: Course data is cached at the request level to avoid
 * duplicate queries when multiple SmartCodes reference the same course.
 *
 * Available SmartCodes:
 * - stride_course.title
 * - stride_course.url
 * - stride_course.thumbnail
 * - stride_course.is_online
 * - stride_course.is_in_person
 * - stride_course.has_invoice
 * - stride_course.has_certificate
 * - stride_course.start_date
 * - stride_course.end_date
 * - stride_course.location
 * - stride_course.available_spots
 * - stride_course.price
 *
 * @package stride\services\smartcode\providers
 */
class CourseSmartCodeProvider implements SmartCodeProviderInterface
{
    private CourseService $courseService;
    private string $dateFormat;

    /**
     * Course data cache: [courseId => [key => value]]
     */
    private static array $courseCache = [];

    /**
     * Constructor
     *
     * @param CourseService|null $courseService
     */
    public function __construct(?CourseService $courseService = null)
    {
        $this->courseService = $courseService ?? $this->getOrCreateCourseService();
        $this->dateFormat = get_option('date_format', 'd/m/Y');
    }

    /**
     * Get or create CourseService using DI container if available
     */
    private function getOrCreateCourseService(): CourseService
    {
        if (function_exists('ntdst_get')) {
            try {
                $service = ntdst_get(CourseService::class);
                if ($service instanceof CourseService) {
                    return $service;
                }
            } catch (\Exception $e) {
                // Fall through
            }
        }
        return new CourseService();
    }

    /**
     * Get the unique key for this provider
     *
     * @return string
     */
    public function getKey(): string
    {
        return 'stride_course';
    }

    /**
     * Get the display title for this provider
     *
     * @return string
     */
    public function getTitle(): string
    {
        return __('Stride Course', 'stride');
    }

    /**
     * Get available SmartCodes with labels
     *
     * @return array<string, string>
     */
    public function getShortCodes(): array
    {
        return [
            'title' => __('Course Title', 'stride'),
            'url' => __('Course URL', 'stride'),
            'thumbnail' => __('Course Thumbnail URL', 'stride'),
            'is_online' => __('Is Online (yes/no)', 'stride'),
            'is_in_person' => __('Is In-Person (yes/no)', 'stride'),
            'has_invoice' => __('Has Invoice (yes/no)', 'stride'),
            'has_certificate' => __('Has Certificate (yes/no)', 'stride'),
            'start_date' => __('Start Date', 'stride'),
            'end_date' => __('End Date', 'stride'),
            'all_dates' => __('All Dates', 'stride'),
            'location' => __('Location/Address', 'stride'),
            'available_spots' => __('Available Spots', 'stride'),
            'price' => __('Price', 'stride'),
            'speakers' => __('Speakers/Supervisors', 'stride'),
        ];
    }

    /**
     * Clear course cache
     *
     * @param int|null $courseId Specific course or null for all
     */
    public function clearCache(?int $courseId = null): void
    {
        if ($courseId === null) {
            self::$courseCache = [];
        } else {
            unset(self::$courseCache[$courseId]);
        }
    }

    /**
     * Get the value for a specific SmartCode
     *
     * SECURITY: Output is escaped by default. Use filter to customize.
     *
     * @param string $valueKey
     * @param mixed $subscriber FluentCRM subscriber object or array
     * @param SmartCodeContextInterface $context
     * @return string|null
     */
    public function getValue(string $valueKey, mixed $subscriber, SmartCodeContextInterface $context): ?string
    {
        $courseId = $context->getCourseId();

        if (!$courseId) {
            return null;
        }

        // Verify course exists (cached)
        if (!$this->isValidCourse($courseId)) {
            return null;
        }

        $rawValue = match ($valueKey) {
            'title' => $this->getCourseTitle($courseId),
            'url' => $this->getUrl($courseId),
            'thumbnail' => $this->getThumbnail($courseId),
            'is_online' => $this->getIsOnline($courseId),
            'is_in_person' => $this->getIsInPerson($courseId),
            'has_invoice' => $this->getHasInvoice($courseId),
            'has_certificate' => $this->getHasCertificate($courseId),
            'start_date' => $this->getStartDate($courseId),
            'end_date' => $this->getEndDate($courseId),
            'all_dates' => $this->getAllDates($courseId),
            'location' => $this->getLocation($courseId),
            'available_spots' => $this->getAvailableSpots($courseId),
            'price' => $this->getPrice($courseId),
            'speakers' => $this->getSpeakers($courseId),
            default => null,
        };

        // Apply output escaping
        return $this->escapeOutput($rawValue, $valueKey);
    }

    /**
     * Check if course is valid (exists and is a course post type)
     *
     * PERFORMANCE: Result is cached for the request lifecycle.
     *
     * @param int $courseId
     * @return bool
     */
    private function isValidCourse(int $courseId): bool
    {
        $cacheKey = 'valid';

        if (isset(self::$courseCache[$courseId][$cacheKey])) {
            return self::$courseCache[$courseId][$cacheKey];
        }

        $post = get_post($courseId);
        $isValid = $post && $post->post_type === 'sfwd-courses';

        $this->setCacheValue($courseId, $cacheKey, $isValid);

        return $isValid;
    }

    /**
     * Set a cached value for a course
     *
     * @param int $courseId
     * @param string $key
     * @param mixed $value
     */
    private function setCacheValue(int $courseId, string $key, mixed $value): void
    {
        if (!isset(self::$courseCache[$courseId])) {
            self::$courseCache[$courseId] = [];
        }
        self::$courseCache[$courseId][$key] = $value;
    }

    /**
     * Get a cached value for a course
     *
     * @param int $courseId
     * @param string $key
     * @return mixed|null
     */
    private function getCacheValue(int $courseId, string $key): mixed
    {
        return self::$courseCache[$courseId][$key] ?? null;
    }

    /**
     * Escape output value for safe display
     *
     * SECURITY: Applies esc_html() by default. URLs get esc_url().
     * Use 'stride/smartcode/escape_output' filter to customize.
     *
     * @param string|null $value Raw value
     * @param string $valueKey SmartCode key (for context-specific escaping)
     * @return string|null Escaped value
     */
    private function escapeOutput(?string $value, string $valueKey): ?string
    {
        if ($value === null) {
            return null;
        }

        // Allow filter to bypass or customize escaping
        $escapeEnabled = apply_filters('stride/smartcode/escape_output', true, $valueKey, 'course');

        if (!$escapeEnabled) {
            return $value;
        }

        // URL fields use esc_url()
        if (in_array($valueKey, ['url', 'thumbnail'], true)) {
            return esc_url($value);
        }

        // Default: escape for HTML context
        return esc_html($value);
    }

    /**
     * Get course title
     *
     * @param int $courseId
     * @return string|null
     */
    private function getCourseTitle(int $courseId): ?string
    {
        return get_the_title($courseId) ?: null;
    }

    /**
     * Get course URL
     *
     * @param int $courseId
     * @return string|null
     */
    private function getUrl(int $courseId): ?string
    {
        return get_permalink($courseId) ?: null;
    }

    /**
     * Get course thumbnail URL
     *
     * @param int $courseId
     * @return string|null
     */
    private function getThumbnail(int $courseId): ?string
    {
        $thumbnailId = get_post_thumbnail_id($courseId);

        if (!$thumbnailId) {
            return null;
        }

        $imageUrl = wp_get_attachment_image_url($thumbnailId, 'medium');

        return $imageUrl ?: null;
    }

    /**
     * Get whether course is online
     *
     * PERFORMANCE: Caches result and reuses for is_in_person.
     *
     * @param int $courseId
     * @return string
     */
    private function getIsOnline(int $courseId): string
    {
        $cacheKey = 'is_in_person';

        // Check cache first
        $cachedInPerson = $this->getCacheValue($courseId, $cacheKey);

        if ($cachedInPerson === null) {
            $cachedInPerson = $this->courseService->isInPerson($courseId);
            $this->setCacheValue($courseId, $cacheKey, $cachedInPerson);
        }

        return !$cachedInPerson ? __('yes', 'stride') : __('no', 'stride');
    }

    /**
     * Get whether course is in-person
     *
     * PERFORMANCE: Caches result and reuses for is_online.
     *
     * @param int $courseId
     * @return string
     */
    private function getIsInPerson(int $courseId): string
    {
        $cacheKey = 'is_in_person';

        // Check cache first
        $cachedInPerson = $this->getCacheValue($courseId, $cacheKey);

        if ($cachedInPerson === null) {
            $cachedInPerson = $this->courseService->isInPerson($courseId);
            $this->setCacheValue($courseId, $cacheKey, $cachedInPerson);
        }

        return $cachedInPerson ? __('yes', 'stride') : __('no', 'stride');
    }

    /**
     * Get whether course has invoicing
     *
     * @param int $courseId
     * @return string
     */
    private function getHasInvoice(int $courseId): string
    {
        return $this->courseService->isInvoiceEnabled($courseId)
            ? __('yes', 'stride')
            : __('no', 'stride');
    }

    /**
     * Get whether course has certificate
     *
     * @param int $courseId
     * @return string
     */
    private function getHasCertificate(int $courseId): string
    {
        return $this->courseService->isCertificateEnabled($courseId)
            ? __('yes', 'stride')
            : __('no', 'stride');
    }

    /**
     * Get formatted start date
     *
     * @param int $courseId
     * @return string|null
     */
    private function getStartDate(int $courseId): ?string
    {
        $startDate = $this->courseService->getStartDate($courseId);

        if (!$startDate) {
            return null;
        }

        return wp_date($this->dateFormat, $startDate);
    }

    /**
     * Get formatted end date
     *
     * @param int $courseId
     * @return string|null
     */
    private function getEndDate(int $courseId): ?string
    {
        $endDate = $this->courseService->getEndDate($courseId);

        if (!$endDate) {
            return null;
        }

        return wp_date($this->dateFormat, $endDate);
    }

    /**
     * Get all course dates formatted
     *
     * @param int $courseId
     * @return string|null
     */
    private function getAllDates(int $courseId): ?string
    {
        $dates = $this->courseService->getCourseDates($courseId);

        if (empty($dates)) {
            return null;
        }

        $formatted = array_map(
            fn($timestamp) => wp_date($this->dateFormat, $timestamp),
            $dates
        );

        return implode(', ', $formatted);
    }

    /**
     * Get course location
     *
     * @param int $courseId
     * @return string|null
     */
    private function getLocation(int $courseId): ?string
    {
        return $this->courseService->getCourseAddress($courseId);
    }

    /**
     * Get available spots
     *
     * @param int $courseId
     * @return string|null
     */
    private function getAvailableSpots(int $courseId): ?string
    {
        $spots = $this->courseService->getAvailableSpots($courseId);

        if ($spots === null) {
            return __('Unlimited', 'stride');
        }

        return (string) $spots;
    }

    /**
     * Get formatted price
     *
     * @param int $courseId
     * @return string|null
     */
    private function getPrice(int $courseId): ?string
    {
        $price = $this->courseService->getCoursePrice($courseId);

        if ($price === null) {
            return null;
        }

        // Format with currency
        $currencySymbol = apply_filters('stride/currency_symbol', '€');

        return sprintf('%s %.2f', $currencySymbol, $price);
    }

    /**
     * Get speakers/supervisors as comma-separated string
     *
     * @param int $courseId
     * @return string|null
     */
    private function getSpeakers(int $courseId): ?string
    {
        $speakers = $this->courseService->getCourseSpeakers($courseId);

        if (empty($speakers)) {
            return null;
        }

        $names = array_map(
            fn($speaker) => $speaker['name'],
            $speakers
        );

        return implode(', ', $names);
    }
}
