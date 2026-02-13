<?php

namespace ntdst\Stride\smartcode;

defined('ABSPATH') || exit;

use ntdst\Stride\sync\UserDataSync;
use ntdst\Stride\core\CourseService;
use ntdst\Stride\core\EditionService;
use ntdst\Stride\core\SessionService;
use ntdst\Stride\core\SubscriberService;
use ntdst\Stride\core\RegistrationRepository;
use ntdst\Stride\invoicing\QuoteService;
use ntdst\Stride\FieldRegistry;

/**
 * SmartCode Service
 *
 * Registers SmartCodes with FluentCRM and FluentForms for dynamic content.
 *
 * Available SmartCode groups:
 * - stride_contact.* - Contact/subscriber data
 * - stride_course.* - Course data (requires context)
 * - stride_quote.* - Quote data (requires context)
 *
 * @package stride\services\smartcode
 */
class SmartCodeService implements \NTDST_Service_Meta
{
    private UserDataSync $dataSync;
    private CourseService $courseService;
    private EditionService $editionService;
    private SessionService $sessionService;
    private SubscriberService $subscriberService;
    private RegistrationRepository $registrationRepo;
    private QuoteService $quoteService;
    private string $dateFormat;

    /** @var int|null Explicit course context */
    private ?int $courseId = null;

    /** @var int|null Explicit edition context */
    private ?int $editionId = null;

    /** @var int|null Explicit quote context */
    private ?int $quoteId = null;

    public static function metadata(): array
    {
        return [
            'name' => 'SmartCode Service',
            'description' => 'SmartCode integration for FluentCRM/FluentForms',
            'admin_only' => false,
            'enabled' => true,
            'priority' => 20,
        ];
    }

    public function __construct(
        ?UserDataSync $dataSync = null,
        ?CourseService $courseService = null,
        ?SubscriberService $subscriberService = null,
        ?QuoteService $quoteService = null,
        ?EditionService $editionService = null,
        ?SessionService $sessionService = null,
        ?RegistrationRepository $registrationRepo = null
    ) {
        $this->dataSync = $dataSync ?? $this->resolveService(UserDataSync::class);
        $this->courseService = $courseService ?? $this->resolveService(CourseService::class);
        $this->editionService = $editionService ?? $this->resolveService(EditionService::class);
        $this->sessionService = $sessionService ?? $this->resolveService(SessionService::class);
        $this->subscriberService = $subscriberService ?? $this->resolveService(SubscriberService::class);
        $this->registrationRepo = $registrationRepo ?? $this->resolveService(RegistrationRepository::class);
        $this->quoteService = $quoteService ?? $this->resolveService(QuoteService::class);
        $this->dateFormat = get_option('date_format', 'd/m/Y');

        add_action('fluent_crm/after_init', [$this, 'registerFluentCRM']);
        add_filter('fluentform/editor_shortcodes', [$this, 'registerFluentFormsEditor']);

        // Register certificate shortcodes
        add_action('init', [$this, 'registerCertificateShortcodes']);

        // Hook into LearnDash certificate to set edition context
        add_filter('learndash_certificate_details', [$this, 'injectCertificateContext'], 10, 3);
    }

    private function resolveService(string $class): object
    {
        if (function_exists('ntdst_get')) {
            try {
                $service = ntdst_get($class);
                if ($service instanceof $class) {
                    return $service;
                }
            } catch (\Exception $e) {
                // Fall through
            }
        }
        return new $class();
    }

    // ========================================
    // FLUENTCRM REGISTRATION
    // ========================================

    public function registerFluentCRM(): void
    {
        if (!function_exists('FluentCrmApi')) {
            return;
        }

        $extender = FluentCrmApi('extender');
        if (!$extender || !method_exists($extender, 'addSmartCode')) {
            return;
        }

        // Contact SmartCodes
        $extender->addSmartCode('stride_contact', __('Stride Contact', 'stride'), [
            'first_name' => __('First Name', 'stride'),
            'last_name' => __('Last Name', 'stride'),
            'full_name' => __('Full Name', 'stride'),
            'phone' => __('Phone', 'stride'),
            'profile_type' => __('Profile Type', 'stride'),
            'is_member' => __('Is Member', 'stride'),
            'invoice_org' => __('Invoice Organization', 'stride'),
            'invoice_address' => __('Invoice Address', 'stride'),
            'invoice_city' => __('Invoice City', 'stride'),
            'invoice_postal' => __('Invoice Postal Code', 'stride'),
            'vat_number' => __('VAT Number', 'stride'),
            'gln_number' => __('GLN Number', 'stride'),
        ], fn($code, $key, $default, $subscriber) =>
            $this->resolveContact($key, $this->getUserId($subscriber)) ?? $default
        );

        // Course SmartCodes
        $extender->addSmartCode('stride_course', __('Stride Course', 'stride'), [
            'title' => __('Course Title', 'stride'),
            'url' => __('Course URL', 'stride'),
            'start_date' => __('Start Date', 'stride'),
            'end_date' => __('End Date', 'stride'),
            'location' => __('Location', 'stride'),
            'available_spots' => __('Available Spots', 'stride'),
            'price' => __('Price', 'stride'),
            'is_online' => __('Is Online', 'stride'),
            'speakers' => __('Speakers', 'stride'),
        ], fn($code, $key, $default, $subscriber) =>
            $this->resolveCourse($key, $this->getCourseContext()) ?? $default
        );

        // Quote SmartCodes
        $extender->addSmartCode('stride_quote', __('Stride Quote', 'stride'), [
            'number' => __('Quote Number', 'stride'),
            'date' => __('Quote Date', 'stride'),
            'total' => __('Total Amount', 'stride'),
            'status' => __('Status', 'stride'),
            'pdf_link' => __('PDF Link', 'stride'),
        ], fn($code, $key, $default, $subscriber) =>
            $this->resolveQuote($key, $this->getQuoteContext()) ?? $default
        );
    }

    // ========================================
    // FLUENTFORMS REGISTRATION
    // ========================================

    public function registerFluentFormsEditor(array $shortcodes): array
    {
        $shortcodes[] = [
            'title' => __('Stride Contact', 'stride'),
            'shortcodes' => [
                '{stride_contact.first_name}' => __('First Name', 'stride'),
                '{stride_contact.last_name}' => __('Last Name', 'stride'),
                '{stride_contact.full_name}' => __('Full Name', 'stride'),
                '{stride_contact.phone}' => __('Phone', 'stride'),
            ],
        ];

        $shortcodes[] = [
            'title' => __('Stride Course', 'stride'),
            'shortcodes' => [
                '{stride_course.title}' => __('Course Title', 'stride'),
                '{stride_course.start_date}' => __('Start Date', 'stride'),
                '{stride_course.location}' => __('Location', 'stride'),
            ],
        ];

        return $shortcodes;
    }

    // ========================================
    // VALUE RESOLUTION
    // ========================================

    private function resolveContact(string $key, ?int $userId): ?string
    {
        if (!$userId) {
            return null;
        }

        $value = match ($key) {
            'first_name' => $this->dataSync->getField($userId, FieldRegistry::FIELD_FIRST_NAME),
            'last_name' => $this->dataSync->getField($userId, FieldRegistry::FIELD_LAST_NAME),
            'full_name' => $this->subscriberService->getFullName($userId),
            'phone' => $this->dataSync->getField($userId, FieldRegistry::FIELD_PHONE),
            'profile_type' => $this->dataSync->getField($userId, FieldRegistry::SUBSCRIBER_PROFILE_TYPE),
            'is_member' => $this->subscriberService->isMember($userId) ? __('yes', 'stride') : __('no', 'stride'),
            'invoice_org' => $this->dataSync->getField($userId, FieldRegistry::SUBSCRIBER_INVOICE_ORG_NAME),
            'invoice_address' => $this->dataSync->getField($userId, FieldRegistry::SUBSCRIBER_INVOICE_ADDRESS),
            'invoice_city' => $this->dataSync->getField($userId, FieldRegistry::SUBSCRIBER_INVOICE_CITY),
            'invoice_postal' => $this->dataSync->getField($userId, FieldRegistry::SUBSCRIBER_INVOICE_POSTAL_CODE),
            'vat_number' => $this->dataSync->getField($userId, FieldRegistry::SUBSCRIBER_VAT_NUMBER),
            'gln_number' => $this->dataSync->getField($userId, FieldRegistry::SUBSCRIBER_GLN_NUMBER),
            default => null,
        };

        return $value !== null && $value !== '' ? esc_html((string) $value) : null;
    }

    private function resolveCourse(string $key, ?int $courseId): ?string
    {
        if (!$courseId) {
            return null;
        }

        $value = match ($key) {
            'title' => $this->courseService->getCourseTitle($courseId),
            'url' => get_permalink($courseId),
            'start_date' => $this->formatDate($this->courseService->getStartDate($courseId)),
            'end_date' => $this->formatDate($this->courseService->getEndDate($courseId)),
            'location' => $this->courseService->getCourseAddress($courseId),
            'available_spots' => $this->formatSpots($this->courseService->getAvailableSpots($courseId)),
            'price' => $this->formatPrice($this->courseService->getCoursePrice($courseId)),
            'is_online' => $this->courseService->isOnline($courseId) ? __('yes', 'stride') : __('no', 'stride'),
            'speakers' => $this->formatSpeakers($this->courseService->getCourseSpeakers($courseId)),
            default => null,
        };

        if ($value === null) {
            return null;
        }

        return in_array($key, ['url'], true) ? esc_url($value) : esc_html($value);
    }

    private function resolveQuote(string $key, ?int $quoteId): ?string
    {
        if (!$quoteId) {
            return null;
        }

        $quote = $this->quoteService->getQuote($quoteId);
        if (!$quote) {
            return null;
        }

        $value = match ($key) {
            'number' => $quote['number'] ?? null,
            'date' => $this->formatDate(strtotime($quote['created_at'] ?? '')),
            'total' => $this->formatPrice($quote['total'] ?? 0),
            'status' => $quote['status'] ?? null,
            'pdf_link' => $this->quoteService->getQuoteUrl($quoteId),
            default => null,
        };

        if ($value === null) {
            return null;
        }

        return in_array($key, ['pdf_link'], true) ? esc_url($value) : esc_html($value);
    }

    // ========================================
    // CONTEXT RESOLUTION
    // ========================================

    public function setCourseId(?int $courseId): self
    {
        $this->courseId = $courseId;
        return $this;
    }

    public function setEditionId(?int $editionId): self
    {
        $this->editionId = $editionId;
        return $this;
    }

    public function setQuoteId(?int $quoteId): self
    {
        $this->quoteId = $quoteId;
        return $this;
    }

    // ========================================
    // CERTIFICATE SHORTCODES
    // ========================================

    /**
     * Register certificate shortcodes for LearnDash certificates
     */
    public function registerCertificateShortcodes(): void
    {
        add_shortcode('stride_edition_dates', [$this, 'renderEditionDates']);
        add_shortcode('stride_edition_title', [$this, 'renderEditionTitle']);
        add_shortcode('stride_instructor', [$this, 'renderInstructor']);
        add_shortcode('stride_venue', [$this, 'renderVenue']);
        add_shortcode('stride_hours_attended', [$this, 'renderHoursAttended']);
        add_shortcode('stride_total_hours', [$this, 'renderTotalHours']);
        add_shortcode('stride_attendance_rate', [$this, 'renderAttendanceRate']);
    }

    /**
     * Inject edition context when LearnDash generates a certificate
     *
     * @param array $details Certificate details
     * @param int $userId User ID
     * @param int $courseId Course ID
     * @return array
     */
    public function injectCertificateContext(array $details, int $userId, int $courseId): array
    {
        // Find the user's most recent completed edition for this course
        $editionId = $this->findUserEdition($userId, $courseId);
        if ($editionId) {
            $this->setEditionId($editionId);
        }
        return $details;
    }

    /**
     * Find user's edition for a course (most recent completed registration)
     */
    private function findUserEdition(int $userId, int $courseId): ?int
    {
        // Get editions for this course
        $editions = $this->editionService->getEditionsForCourse($courseId);
        if (empty($editions)) {
            return null;
        }

        // Find user's registration
        foreach ($editions as $edition) {
            $reg = $this->registrationRepo->findByUserAndEdition($userId, $edition['id']);
            if ($reg && in_array($reg['status'], [
                RegistrationRepository::STATUS_CONFIRMED,
                RegistrationRepository::STATUS_COMPLETED,
            ], true)) {
                return $edition['id'];
            }
        }

        return null;
    }

    /**
     * Shortcode: [stride_edition_dates]
     * Renders edition date range
     */
    public function renderEditionDates(array $atts): string
    {
        $editionId = $this->getEditionContext();
        if (!$editionId) {
            return '';
        }

        $edition = $this->editionService->getEdition($editionId);
        if (!$edition) {
            return '';
        }

        $format = $atts['format'] ?? $this->dateFormat;
        $startDate = !empty($edition['start_date']) ? wp_date($format, strtotime($edition['start_date'])) : '';
        $endDate = !empty($edition['end_date']) ? wp_date($format, strtotime($edition['end_date'])) : '';

        if ($startDate && $endDate && $startDate !== $endDate) {
            return esc_html(sprintf('%s - %s', $startDate, $endDate));
        }

        return esc_html($startDate ?: $endDate);
    }

    /**
     * Shortcode: [stride_edition_title]
     * Renders edition title
     */
    public function renderEditionTitle(array $atts): string
    {
        $editionId = $this->getEditionContext();
        if (!$editionId) {
            return '';
        }

        return esc_html(get_the_title($editionId) ?: '');
    }

    /**
     * Shortcode: [stride_instructor]
     * Renders edition speakers/instructors
     */
    public function renderInstructor(array $atts): string
    {
        $editionId = $this->getEditionContext();
        if (!$editionId) {
            return '';
        }

        $edition = $this->editionService->getEdition($editionId);
        if (!$edition || empty($edition['speakers'])) {
            return '';
        }

        $speakers = $edition['speakers'];
        if (is_string($speakers)) {
            return esc_html($speakers);
        }

        if (is_array($speakers)) {
            $names = array_column($speakers, 'name');
            return esc_html(implode(', ', array_filter($names)));
        }

        return '';
    }

    /**
     * Shortcode: [stride_venue]
     * Renders edition venue/location
     */
    public function renderVenue(array $atts): string
    {
        $editionId = $this->getEditionContext();
        if (!$editionId) {
            return '';
        }

        $edition = $this->editionService->getEdition($editionId);
        return esc_html($edition['venue'] ?? '');
    }

    /**
     * Shortcode: [stride_hours_attended]
     * Renders hours attended by current user
     */
    public function renderHoursAttended(array $atts): string
    {
        $editionId = $this->getEditionContext();
        $userId = $this->getCurrentCertificateUserId();

        if (!$editionId || !$userId) {
            return '';
        }

        $hours = $this->sessionService->getHoursAttended($userId, $editionId);
        $decimals = isset($atts['decimals']) ? (int) $atts['decimals'] : 1;

        return esc_html(number_format($hours, $decimals, ',', '.'));
    }

    /**
     * Shortcode: [stride_total_hours]
     * Renders total hours for edition
     */
    public function renderTotalHours(array $atts): string
    {
        $editionId = $this->getEditionContext();
        if (!$editionId) {
            return '';
        }

        $hours = $this->sessionService->getTotalHours($editionId);
        $decimals = isset($atts['decimals']) ? (int) $atts['decimals'] : 1;

        return esc_html(number_format($hours, $decimals, ',', '.'));
    }

    /**
     * Shortcode: [stride_attendance_rate]
     * Renders attendance percentage
     */
    public function renderAttendanceRate(array $atts): string
    {
        $editionId = $this->getEditionContext();
        $userId = $this->getCurrentCertificateUserId();

        if (!$editionId || !$userId) {
            return '';
        }

        $rate = $this->sessionService->getAttendanceRate($userId, $editionId);
        return esc_html(round($rate * 100) . '%');
    }

    /**
     * Get current edition context
     *
     * Only returns edition if user has a registration or is admin/editor.
     */
    private function getEditionContext(): ?int
    {
        if ($this->editionId) {
            return $this->editionId;
        }

        // Check URL parameter
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['edition_id'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $editionId = absint($_GET['edition_id']);

            // Verify user has access to this edition
            if ($editionId > 0) {
                // Admins/editors can view any edition
                if (current_user_can('manage_options') || current_user_can('edit_others_posts')) {
                    return $editionId;
                }

                // Regular users need a registration
                $userId = get_current_user_id();
                if ($userId && $this->registrationRepo->findByUserAndEdition($userId, $editionId)) {
                    return $editionId;
                }
            }

            return null;
        }

        return null;
    }

    /**
     * Get user ID for certificate context
     *
     * Only allows viewing other users' data if current user has admin/editor capabilities.
     */
    private function getCurrentCertificateUserId(): ?int
    {
        $currentUserId = get_current_user_id();

        // Check URL parameter (LearnDash certificate URLs)
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['user'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $requestedUserId = absint($_GET['user']);

            // Only allow viewing other users if admin/editor
            if ($requestedUserId !== $currentUserId) {
                if (!current_user_can('manage_options') && !current_user_can('edit_others_posts')) {
                    return $currentUserId ?: null;
                }
            }

            return $requestedUserId;
        }

        return $currentUserId ?: null;
    }

    private function getCourseContext(): ?int
    {
        if ($this->courseId) {
            return $this->courseId;
        }

        // Check URL parameter
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['course_id'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $courseId = absint($_GET['course_id']);
            if ($courseId > 0 && $this->courseService->getCourse($courseId)) {
                return $courseId;
            }
        }

        // Check queried object
        $queried = get_queried_object();
        if ($queried instanceof \WP_Post && $queried->post_type === 'sfwd-courses') {
            return $queried->ID;
        }

        return null;
    }

    private function getQuoteContext(): ?int
    {
        if ($this->quoteId) {
            return $this->quoteId;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['quote_id'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return absint($_GET['quote_id']);
        }

        return null;
    }

    // ========================================
    // HELPERS
    // ========================================

    private function getUserId(mixed $subscriber): ?int
    {
        if (is_object($subscriber)) {
            return isset($subscriber->user_id) ? absint($subscriber->user_id) : null;
        }
        if (is_array($subscriber)) {
            return isset($subscriber['user_id']) ? absint($subscriber['user_id']) : null;
        }
        return null;
    }

    private function formatDate(?int $timestamp): ?string
    {
        return $timestamp ? wp_date($this->dateFormat, $timestamp) : null;
    }

    private function formatPrice(?float $price): ?string
    {
        return $price !== null ? sprintf('€ %.2f', $price) : null;
    }

    private function formatSpots(?int $spots): string
    {
        return $spots === null ? __('Unlimited', 'stride') : (string) $spots;
    }

    private function formatSpeakers(array $speakers): ?string
    {
        if (empty($speakers)) {
            return null;
        }
        return implode(', ', array_column($speakers, 'name'));
    }

    /**
     * Programmatic SmartCode value resolution
     *
     * @param string $fullCode Full SmartCode (e.g., 'stride_contact.first_name')
     * @param int|null $userId User ID (null for current user)
     * @param int|null $courseId Course ID (null for context resolution)
     * @return string|null Resolved value
     */
    public function getValue(string $fullCode, ?int $userId = null, ?int $courseId = null): ?string
    {
        $parts = explode('.', $fullCode, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$group, $key] = $parts;

        return match ($group) {
            'stride_contact' => $this->resolveContact($key, $userId ?? get_current_user_id()),
            'stride_course' => $this->resolveCourse($key, $courseId ?? $this->getCourseContext()),
            'stride_quote' => $this->resolveQuote($key, $this->getQuoteContext()),
            default => null,
        };
    }
}
