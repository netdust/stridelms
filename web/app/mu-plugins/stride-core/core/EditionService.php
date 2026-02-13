<?php

namespace ntdst\Stride\core;

defined('ABSPATH') || exit;

use ntdst\Stride\FieldRegistry;
use WP_Error;

/**
 * Edition Service
 *
 * Manages scheduled course offerings (editions) - instances of LearnDash courses
 * with specific dates, capacity, venue, pricing, and status.
 *
 * The Edition Model separates:
 * - Course (LearnDash) = Content template with lessons, quizzes, certificates
 * - Edition (vad_edition) = Scheduled offering with dates, capacity, pricing
 * - Session (vad_session) = Individual meeting days for attendance tracking
 *
 * Available hooks:
 * - stride/edition/created (action) - After edition creation
 * - stride/edition/updated (action) - After edition update
 * - stride/edition/status_changed (action) - After status change
 *
 * @package stride\services\core
 */
class EditionService implements \NTDST_Service_Meta
{
    public const POST_TYPE = 'vad_edition';

    private ?RegistrationRepository $registrationRepo = null;

    /**
     * Service metadata for NTDST Bootstrap
     */
    public static function metadata(): array
    {
        return [
            'name' => 'Edition Service',
            'description' => 'Scheduled course offerings with dates, capacity, pricing',
            'admin_only' => false,
            'enabled' => true,
            'priority' => 5, // Before CourseService (10) for model registration
        ];
    }

    /**
     * Constructor
     */
    public function __construct(?RegistrationRepository $registrationRepo = null)
    {
        $this->registrationRepo = $registrationRepo;

        // Register CPT via DataManager
        add_action('init', [$this, 'registerModel'], 5);

        // Auto-update edition status when registrations change
        add_action('stride/registration/created', [$this, 'onRegistrationCreated'], 10, 2);
        add_action('stride/registration/cancelled', [$this, 'onRegistrationCancelled'], 10, 1);
    }

    /**
     * Get registration repository (lazy load)
     */
    private function getRegistrationRepo(): RegistrationRepository
    {
        if ($this->registrationRepo === null) {
            $this->registrationRepo = $this->resolveService(RegistrationRepository::class);
        }
        return $this->registrationRepo;
    }

    /**
     * Resolve service from DI container or create new instance
     */
    private function resolveService(string $class): object
    {
        if (function_exists('ntdst_get')) {
            try {
                $service = ntdst_get($class);
                if ($service instanceof $class) {
                    return $service;
                }
            } catch (\Exception $e) {
                // Fall through to create new instance
            }
        }
        return new $class();
    }

    // ========================================
    // CPT REGISTRATION
    // ========================================

    /**
     * Register vad_edition model via NTDST DataManager
     */
    public function registerModel(): void
    {
        if (!function_exists('ntdst_data')) {
            $this->registerPostTypeFallback();
            return;
        }

        ntdst_data()->register(self::POST_TYPE, [
            'label' => __('Edities', 'stride'),
            'labels' => [
                'name' => __('Edities', 'stride'),
                'singular_name' => __('Editie', 'stride'),
                'menu_name' => __('Edities', 'stride'),
                'add_new' => __('Nieuwe editie', 'stride'),
                'add_new_item' => __('Nieuwe editie toevoegen', 'stride'),
                'edit_item' => __('Editie bewerken', 'stride'),
                'view_item' => __('Editie bekijken', 'stride'),
                'all_items' => __('Alle edities', 'stride'),
                'search_items' => __('Edities zoeken', 'stride'),
                'not_found' => __('Geen edities gevonden', 'stride'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'stride-admin',
            'show_in_rest' => false,
            'supports' => ['title'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'has_archive' => false,
            'menu_icon' => 'dashicons-calendar-alt',

            // Field schema for ORM
            'fields' => [
                FieldRegistry::EDITION_COURSE_ID => ['type' => 'integer', 'required' => true],
                FieldRegistry::EDITION_START_DATE => ['type' => 'text', 'required' => true],
                FieldRegistry::EDITION_END_DATE => ['type' => 'text'],
                FieldRegistry::EDITION_CAPACITY => ['type' => 'integer', 'min' => 0],
                FieldRegistry::EDITION_PRICE => ['type' => 'float', 'min' => 0],
                FieldRegistry::EDITION_PRICE_NON_MEMBER => ['type' => 'float', 'min' => 0],
                FieldRegistry::EDITION_VENUE => ['type' => 'text'],
                FieldRegistry::EDITION_SPEAKERS => ['type' => 'text'],
                FieldRegistry::EDITION_STATUS => ['type' => 'text', 'default' => FieldRegistry::EDITION_STATUS_OPEN],
                FieldRegistry::EDITION_INVOICE_ITEM => ['type' => 'text'],
                FieldRegistry::EDITION_INVOICE_ENABLED => ['type' => 'boolean', 'default' => true],
                FieldRegistry::EDITION_CERTIFICATE_ENABLED => ['type' => 'boolean', 'default' => false],
                FieldRegistry::EDITION_CUSTOM_FORM => ['type' => 'text'],
            ],
            'auto_metabox' => true,
        ]);
    }

    /**
     * Fallback CPT registration if DataManager not available
     */
    private function registerPostTypeFallback(): void
    {
        register_post_type(self::POST_TYPE, [
            'label' => __('Edities', 'stride'),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'stride-admin',
            'supports' => ['title'],
        ]);
    }

    /**
     * Get the DataManager model
     */
    private function getModel(): ?\NTDST_Data_Model
    {
        if (!function_exists('ntdst_data')) {
            return null;
        }
        return ntdst_data()->get(self::POST_TYPE);
    }

    // ========================================
    // CORE QUERIES
    // ========================================

    /**
     * Get edition by ID
     *
     * @param int $editionId Edition post ID
     * @return array|null Edition data or null if not found
     */
    public function getEdition(int $editionId): ?array
    {
        $model = $this->getModel();
        if (!$model) {
            return null;
        }

        $post = $model->find($editionId);
        if (is_wp_error($post)) {
            return null;
        }

        return $this->formatEdition($post);
    }

    /**
     * Get all editions for a course
     *
     * @param int $courseId LearnDash course ID
     * @return array Array of edition data
     */
    public function getEditionsForCourse(int $courseId): array
    {
        $model = $this->getModel();
        if (!$model) {
            return [];
        }

        $posts = $model
            ->where(FieldRegistry::EDITION_COURSE_ID, $courseId)
            ->orderBy(FieldRegistry::EDITION_START_DATE, 'ASC')
            ->get();

        return array_map([$this, 'formatEditionFromArray'], $posts);
    }

    /**
     * Get upcoming editions (not started, not cancelled)
     *
     * @param int $limit Maximum number of editions
     * @return array Array of edition data
     */
    public function getUpcomingEditions(int $limit = 20): array
    {
        $model = $this->getModel();
        if (!$model) {
            return [];
        }

        $today = wp_date('Y-m-d');

        $posts = $model
            ->where(FieldRegistry::EDITION_START_DATE, ['>=', $today])
            ->whereNot(FieldRegistry::EDITION_STATUS, FieldRegistry::EDITION_STATUS_CANCELLED)
            ->orderBy(FieldRegistry::EDITION_START_DATE, 'ASC')
            ->limit($limit)
            ->get();

        return array_map([$this, 'formatEditionFromArray'], $posts);
    }

    /**
     * Get upcoming editions for a specific course
     *
     * @param int $courseId LearnDash course ID
     * @param int $limit Maximum number of editions
     * @return array Array of edition data
     */
    public function getUpcomingEditionsForCourse(int $courseId, int $limit = 5): array
    {
        $model = $this->getModel();
        if (!$model) {
            return [];
        }

        $today = wp_date('Y-m-d');

        $posts = $model
            ->where(FieldRegistry::EDITION_COURSE_ID, $courseId)
            ->where(FieldRegistry::EDITION_START_DATE, ['>=', $today])
            ->whereNot(FieldRegistry::EDITION_STATUS, FieldRegistry::EDITION_STATUS_CANCELLED)
            ->orderBy(FieldRegistry::EDITION_START_DATE, 'ASC')
            ->limit($limit)
            ->get();

        return array_map([$this, 'formatEditionFromArray'], $posts);
    }

    /**
     * Get linked course ID for an edition
     *
     * @param int $editionId Edition post ID
     * @return int|null LearnDash course ID or null
     */
    public function getLinkedCourseId(int $editionId): ?int
    {
        $model = $this->getModel();
        if (!$model) {
            return null;
        }

        $courseId = $model->getMeta($editionId, FieldRegistry::EDITION_COURSE_ID);
        return $courseId ? (int) $courseId : null;
    }

    /**
     * Get course ID for an edition (alias for getLinkedCourseId)
     *
     * @param int $editionId Edition post ID
     * @return int|null LearnDash course ID or null
     */
    public function getCourseId(int $editionId): ?int
    {
        return $this->getLinkedCourseId($editionId);
    }

    // ========================================
    // DATE METHODS
    // ========================================

    /**
     * Get edition start date
     *
     * @param int $editionId Edition post ID
     * @return string|null Date in YYYY-MM-DD format or null
     */
    public function getStartDate(int $editionId): ?string
    {
        $model = $this->getModel();
        if (!$model) {
            return null;
        }

        $date = $model->getMeta($editionId, FieldRegistry::EDITION_START_DATE);
        return $date ?: null;
    }

    /**
     * Get edition end date
     *
     * @param int $editionId Edition post ID
     * @return string|null Date in YYYY-MM-DD format or null
     */
    public function getEndDate(int $editionId): ?string
    {
        $model = $this->getModel();
        if (!$model) {
            return null;
        }

        $date = $model->getMeta($editionId, FieldRegistry::EDITION_END_DATE);
        return $date ?: null;
    }

    /**
     * Check if edition has started
     *
     * @param int $editionId Edition post ID
     * @return bool
     */
    public function hasStarted(int $editionId): bool
    {
        $startDate = $this->getStartDate($editionId);
        if (!$startDate) {
            return false;
        }

        return strtotime($startDate) <= strtotime(wp_date('Y-m-d'));
    }

    /**
     * Check if edition has ended
     *
     * @param int $editionId Edition post ID
     * @return bool
     */
    public function hasEnded(int $editionId): bool
    {
        $endDate = $this->getEndDate($editionId);
        if (!$endDate) {
            // If no end date, use start date
            $endDate = $this->getStartDate($editionId);
        }
        if (!$endDate) {
            return false;
        }

        return strtotime($endDate) < strtotime(wp_date('Y-m-d'));
    }

    // ========================================
    // STATUS METHODS
    // ========================================

    /**
     * Get edition status
     *
     * @param int $editionId Edition post ID
     * @return string Status value
     */
    public function getStatus(int $editionId): string
    {
        $model = $this->getModel();
        if (!$model) {
            return FieldRegistry::EDITION_STATUS_OPEN;
        }

        $status = $model->getMeta($editionId, FieldRegistry::EDITION_STATUS);
        return $status ?: FieldRegistry::EDITION_STATUS_OPEN;
    }

    /**
     * Check if edition is cancelled
     */
    public function isCancelled(int $editionId): bool
    {
        return $this->getStatus($editionId) === FieldRegistry::EDITION_STATUS_CANCELLED;
    }

    /**
     * Check if edition is postponed
     */
    public function isPostponed(int $editionId): bool
    {
        return $this->getStatus($editionId) === FieldRegistry::EDITION_STATUS_POSTPONED;
    }

    /**
     * Check if edition is full
     */
    public function isFull(int $editionId): bool
    {
        return $this->getStatus($editionId) === FieldRegistry::EDITION_STATUS_FULL;
    }

    /**
     * Check if edition is announcement only
     */
    public function isAnnouncement(int $editionId): bool
    {
        return $this->getStatus($editionId) === FieldRegistry::EDITION_STATUS_ANNOUNCEMENT;
    }

    /**
     * Check if edition is upcoming (not started, not cancelled)
     */
    public function isUpcoming(int $editionId): bool
    {
        if ($this->isCancelled($editionId)) {
            return false;
        }
        return !$this->hasStarted($editionId);
    }

    /**
     * Check if enrollment is open for this edition
     */
    public function isEnrollmentOpen(int $editionId): bool
    {
        // Not cancelled
        if ($this->isCancelled($editionId)) {
            return false;
        }

        // Not postponed
        if ($this->isPostponed($editionId)) {
            return false;
        }

        // Not ended
        if ($this->hasEnded($editionId)) {
            return false;
        }

        // Not announcement only
        if ($this->isAnnouncement($editionId)) {
            return false;
        }

        // Not full (check capacity)
        if (!$this->hasAvailableSpots($editionId)) {
            return false;
        }

        return true;
    }

    // ========================================
    // CAPACITY METHODS
    // ========================================

    /**
     * Get edition capacity
     *
     * @param int $editionId Edition post ID
     * @return int|null Maximum participants or null (unlimited)
     */
    public function getCapacity(int $editionId): ?int
    {
        $model = $this->getModel();
        if (!$model) {
            return null;
        }

        $capacity = $model->getMeta($editionId, FieldRegistry::EDITION_CAPACITY);
        return $capacity ? (int) $capacity : null;
    }

    /**
     * Get count of registered users (confirmed status)
     *
     * @param int $editionId Edition post ID
     * @return int Number of confirmed registrations
     */
    public function getRegisteredCount(int $editionId): int
    {
        return $this->getRegistrationRepo()->countByEdition($editionId, 'confirmed');
    }

    /**
     * Check if edition has available spots
     *
     * @param int $editionId Edition post ID
     * @return bool True if spots available or capacity unlimited
     */
    public function hasAvailableSpots(int $editionId): bool
    {
        $capacity = $this->getCapacity($editionId);

        // No capacity limit
        if ($capacity === null || $capacity <= 0) {
            return true;
        }

        return $this->getRegisteredCount($editionId) < $capacity;
    }

    /**
     * Get number of available spots
     *
     * @param int $editionId Edition post ID
     * @return int Number of available spots (-1 = unlimited)
     */
    public function getAvailableSpots(int $editionId): int
    {
        $capacity = $this->getCapacity($editionId);

        // No capacity limit
        if ($capacity === null || $capacity <= 0) {
            return -1;
        }

        $registered = $this->getRegisteredCount($editionId);
        return max(0, $capacity - $registered);
    }

    // ========================================
    // PRICING METHODS
    // ========================================

    /**
     * Get member price
     *
     * @param int $editionId Edition post ID
     * @return float|null Price or null if free
     */
    public function getPrice(int $editionId): ?float
    {
        $model = $this->getModel();
        if (!$model) {
            return null;
        }

        $price = $model->getMeta($editionId, FieldRegistry::EDITION_PRICE);
        return $price !== null && $price !== '' ? (float) $price : null;
    }

    /**
     * Get non-member price
     *
     * @param int $editionId Edition post ID
     * @return float|null Price or null (uses member price)
     */
    public function getPriceNonMember(int $editionId): ?float
    {
        $model = $this->getModel();
        if (!$model) {
            return null;
        }

        $price = $model->getMeta($editionId, FieldRegistry::EDITION_PRICE_NON_MEMBER);
        return $price !== null && $price !== '' ? (float) $price : null;
    }

    /**
     * Get invoice item code
     *
     * @param int $editionId Edition post ID
     * @return string|null Invoice item code or null
     */
    public function getInvoiceItem(int $editionId): ?string
    {
        $model = $this->getModel();
        if (!$model) {
            return null;
        }

        $item = $model->getMeta($editionId, FieldRegistry::EDITION_INVOICE_ITEM);
        return $item ?: null;
    }

    /**
     * Check if invoicing is enabled
     *
     * @param int $editionId Edition post ID
     * @return bool
     */
    public function isInvoiceEnabled(int $editionId): bool
    {
        $model = $this->getModel();
        if (!$model) {
            return true; // Default to enabled
        }

        $enabled = $model->getMeta($editionId, FieldRegistry::EDITION_INVOICE_ENABLED, true);
        return (bool) $enabled;
    }

    /**
     * Check if certificate is enabled
     *
     * @param int $editionId Edition post ID
     * @return bool
     */
    public function isCertificateEnabled(int $editionId): bool
    {
        $model = $this->getModel();
        if (!$model) {
            return false;
        }

        return (bool) $model->getMeta($editionId, FieldRegistry::EDITION_CERTIFICATE_ENABLED, false);
    }

    // ========================================
    // SPEAKERS & VENUE
    // ========================================

    /**
     * Get speakers for edition
     *
     * @param int $editionId Edition post ID
     * @return array Array of speaker data: [{name, role}]
     */
    public function getSpeakers(int $editionId): array
    {
        $model = $this->getModel();
        if (!$model) {
            return [];
        }

        $speakers = $model->getMeta($editionId, FieldRegistry::EDITION_SPEAKERS);
        if (!$speakers) {
            return [];
        }

        // Parse speakers string: "Jan Peeters, trainer; An Claes, gastspreker"
        $result = [];
        $parts = array_filter(array_map('trim', explode(';', $speakers)));

        foreach ($parts as $part) {
            $nameRole = array_map('trim', explode(',', $part, 2));
            $result[] = [
                'name' => $nameRole[0] ?? '',
                'role' => $nameRole[1] ?? '',
            ];
        }

        return $result;
    }

    /**
     * Get venue/location
     *
     * @param int $editionId Edition post ID
     * @return string|null Venue or null
     */
    public function getVenue(int $editionId): ?string
    {
        $model = $this->getModel();
        if (!$model) {
            return null;
        }

        $venue = $model->getMeta($editionId, FieldRegistry::EDITION_VENUE);
        return $venue ?: null;
    }

    /**
     * Get custom enrollment form name
     *
     * @param int $editionId Edition post ID
     * @return string|null Form name or null
     */
    public function getCustomForm(int $editionId): ?string
    {
        $model = $this->getModel();
        if (!$model) {
            return null;
        }

        $form = $model->getMeta($editionId, FieldRegistry::EDITION_CUSTOM_FORM);
        return $form ?: null;
    }

    // ========================================
    // ENROLLMENT VALIDATION
    // ========================================

    /**
     * Check if a user can enroll in an edition
     *
     * @param int $userId WordPress user ID
     * @param int $editionId Edition post ID
     * @return true|WP_Error True if can enroll, WP_Error with reason if not
     */
    public function canUserEnroll(int $userId, int $editionId): true|WP_Error
    {
        // Edition must exist
        $edition = $this->getEdition($editionId);
        if (!$edition) {
            return new WP_Error('invalid_edition', __('Editie niet gevonden.', 'stride'));
        }

        // Not already registered
        $existing = $this->getRegistrationRepo()->findByUserAndEdition($userId, $editionId);
        if ($existing && $existing['status'] === 'confirmed') {
            return new WP_Error('already_enrolled', __('Je bent al ingeschreven voor deze editie.', 'stride'));
        }

        // Not cancelled
        if ($this->isCancelled($editionId)) {
            return new WP_Error('edition_cancelled', __('Deze editie is geannuleerd.', 'stride'));
        }

        // Not ended
        if ($this->hasEnded($editionId)) {
            return new WP_Error('edition_ended', __('Deze editie is al afgelopen.', 'stride'));
        }

        // Not announcement only
        if ($this->isAnnouncement($editionId)) {
            return new WP_Error('edition_announcement', __('Inschrijvingen voor deze editie zijn nog niet open.', 'stride'));
        }

        // Capacity available
        if (!$this->hasAvailableSpots($editionId)) {
            return new WP_Error('edition_full', __('Deze editie is volzet.', 'stride'));
        }

        // Allow external filters to block enrollment
        $canEnroll = apply_filters('stride/edition/can_enroll', true, $userId, $editionId);
        if (is_wp_error($canEnroll)) {
            return $canEnroll;
        }
        if ($canEnroll !== true) {
            return new WP_Error('enrollment_blocked', __('Inschrijving niet toegestaan.', 'stride'));
        }

        return true;
    }

    // ========================================
    // CRUD OPERATIONS
    // ========================================

    /**
     * Validate edition data against business rules
     *
     * @param array $data Edition data
     * @param bool $isUpdate Whether this is an update (relaxed validation)
     * @return true|WP_Error
     */
    private function validateEditionData(array $data, bool $isUpdate = false): true|WP_Error
    {
        // Validate date range
        $startDate = $data[FieldRegistry::EDITION_START_DATE] ?? $data['start_date'] ?? '';
        $endDate = $data[FieldRegistry::EDITION_END_DATE] ?? $data['end_date'] ?? '';

        if ($startDate && $endDate) {
            $start = strtotime($startDate);
            $end = strtotime($endDate);

            if ($start !== false && $end !== false && $end < $start) {
                return new WP_Error(
                    'invalid_date_range',
                    __('Einddatum moet na startdatum liggen.', 'stride')
                );
            }
        }

        // Validate price is non-negative
        $price = $data[FieldRegistry::EDITION_PRICE] ?? $data['price'] ?? null;
        if ($price !== null && (float) $price < 0) {
            return new WP_Error(
                'invalid_price',
                __('Prijs kan niet negatief zijn.', 'stride')
            );
        }

        $priceNonMember = $data[FieldRegistry::EDITION_PRICE_NON_MEMBER] ?? $data['price_non_member'] ?? null;
        if ($priceNonMember !== null && (float) $priceNonMember < 0) {
            return new WP_Error(
                'invalid_price',
                __('Prijs niet-leden kan niet negatief zijn.', 'stride')
            );
        }

        // Validate capacity is non-negative
        $capacity = $data[FieldRegistry::EDITION_CAPACITY] ?? $data['capacity'] ?? null;
        if ($capacity !== null && (int) $capacity < 0) {
            return new WP_Error(
                'invalid_capacity',
                __('Capaciteit kan niet negatief zijn.', 'stride')
            );
        }

        // Validate course_id exists (on create only)
        if (!$isUpdate) {
            $courseId = $data[FieldRegistry::EDITION_COURSE_ID] ?? $data['course_id'] ?? null;
            if ($courseId && !get_post($courseId)) {
                return new WP_Error(
                    'invalid_course',
                    __('Gekoppelde cursus niet gevonden.', 'stride')
                );
            }
        }

        return true;
    }

    /**
     * Create a new edition
     *
     * @param array $data Edition data
     * @return int|WP_Error Edition ID or error
     */
    public function createEdition(array $data): int|WP_Error
    {
        $model = $this->getModel();
        if (!$model) {
            return new WP_Error('no_model', 'DataManager not available');
        }

        // Validate business rules
        $validation = $this->validateEditionData($data);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Generate title from course name + date
        $courseTitle = '';
        if (!empty($data[FieldRegistry::EDITION_COURSE_ID])) {
            $courseTitle = get_the_title($data[FieldRegistry::EDITION_COURSE_ID]) ?: '';
        }
        $startDate = $data[FieldRegistry::EDITION_START_DATE] ?? '';
        $title = trim($courseTitle . ' - ' . $startDate);

        $createData = array_merge($data, ['title' => $title]);
        $result = $model->create($createData);

        if (is_wp_error($result)) {
            return $result;
        }

        do_action('stride/edition/created', $result->ID, $data);

        return $result->ID;
    }

    /**
     * Update an edition
     *
     * @param int $editionId Edition ID
     * @param array $data Data to update
     * @return true|WP_Error
     */
    public function updateEdition(int $editionId, array $data): true|WP_Error
    {
        $model = $this->getModel();
        if (!$model) {
            return new WP_Error('no_model', 'DataManager not available');
        }

        // For updates, merge with existing data for validation
        $existing = $this->getEdition($editionId);
        $mergedData = $existing ? array_merge($existing, $data) : $data;

        // Validate business rules
        $validation = $this->validateEditionData($mergedData, true);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $oldStatus = $this->getStatus($editionId);

        $result = $model->update($editionId, $data);
        if (is_wp_error($result)) {
            return $result;
        }

        // Fire status change hook if status changed
        if (isset($data[FieldRegistry::EDITION_STATUS]) && $data[FieldRegistry::EDITION_STATUS] !== $oldStatus) {
            do_action('stride/edition/status_changed', $editionId, $data[FieldRegistry::EDITION_STATUS], $oldStatus);
        }

        do_action('stride/edition/updated', $editionId, $data);

        return true;
    }

    // ========================================
    // CAPACITY STATUS MANAGEMENT
    // ========================================

    /**
     * Handle registration creation - auto-set to full if capacity reached
     *
     * @param int $registrationId Registration ID
     * @param array $data Registration data
     */
    public function onRegistrationCreated(int $registrationId, array $data): void
    {
        $editionId = $data['edition_id'] ?? 0;
        if (!$editionId) {
            return;
        }

        // Only update status if registration is confirmed
        $status = $data['status'] ?? '';
        if ($status !== RegistrationRepository::STATUS_CONFIRMED) {
            return;
        }

        $this->updateCapacityStatus($editionId);
    }

    /**
     * Handle registration cancellation - re-open if was full
     *
     * @param int $registrationId Registration ID
     */
    public function onRegistrationCancelled(int $registrationId): void
    {
        $registration = $this->getRegistrationRepo()->get($registrationId);
        if (!$registration) {
            return;
        }

        $this->updateCapacityStatus($registration['edition_id']);
    }

    /**
     * Update edition status based on capacity
     *
     * Sets status to 'full' when capacity is reached, or
     * back to 'open' when spots become available (if previously full).
     *
     * @param int $editionId Edition post ID
     */
    public function updateCapacityStatus(int $editionId): void
    {
        $currentStatus = $this->getStatus($editionId);

        // Don't change cancelled, postponed, announcement, or completed status
        $unchangeableStatuses = [
            FieldRegistry::EDITION_STATUS_CANCELLED,
            FieldRegistry::EDITION_STATUS_POSTPONED,
            FieldRegistry::EDITION_STATUS_ANNOUNCEMENT,
            FieldRegistry::EDITION_STATUS_COMPLETED,
        ];

        if (in_array($currentStatus, $unchangeableStatuses, true)) {
            return;
        }

        $hasSpots = $this->hasAvailableSpots($editionId);

        // Edition is full - set status
        if (!$hasSpots && $currentStatus !== FieldRegistry::EDITION_STATUS_FULL) {
            $this->updateEdition($editionId, [
                FieldRegistry::EDITION_STATUS => FieldRegistry::EDITION_STATUS_FULL,
            ]);
            return;
        }

        // Spots became available - revert to open
        if ($hasSpots && $currentStatus === FieldRegistry::EDITION_STATUS_FULL) {
            $this->updateEdition($editionId, [
                FieldRegistry::EDITION_STATUS => FieldRegistry::EDITION_STATUS_OPEN,
            ]);
        }
    }

    // ========================================
    // FORMATTING
    // ========================================

    /**
     * Format edition post to array
     */
    private function formatEdition(\WP_Post $post): array
    {
        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'course_id' => (int) ($post->fields[FieldRegistry::EDITION_COURSE_ID] ?? 0),
            'start_date' => $post->fields[FieldRegistry::EDITION_START_DATE] ?? '',
            'end_date' => $post->fields[FieldRegistry::EDITION_END_DATE] ?? '',
            'capacity' => (int) ($post->fields[FieldRegistry::EDITION_CAPACITY] ?? 0) ?: null,
            'price' => (float) ($post->fields[FieldRegistry::EDITION_PRICE] ?? 0),
            'price_non_member' => (float) ($post->fields[FieldRegistry::EDITION_PRICE_NON_MEMBER] ?? 0),
            'venue' => $post->fields[FieldRegistry::EDITION_VENUE] ?? '',
            'speakers' => $post->fields[FieldRegistry::EDITION_SPEAKERS] ?? '',
            'status' => $post->fields[FieldRegistry::EDITION_STATUS] ?? FieldRegistry::EDITION_STATUS_OPEN,
            'invoice_item' => $post->fields[FieldRegistry::EDITION_INVOICE_ITEM] ?? '',
            'invoice_enabled' => (bool) ($post->fields[FieldRegistry::EDITION_INVOICE_ENABLED] ?? true),
            'certificate_enabled' => (bool) ($post->fields[FieldRegistry::EDITION_CERTIFICATE_ENABLED] ?? false),
            'custom_form' => $post->fields[FieldRegistry::EDITION_CUSTOM_FORM] ?? '',
        ];
    }

    /**
     * Format edition from array (from DataManager query)
     */
    private function formatEditionFromArray(array $data): array
    {
        $meta = $data['meta'] ?? [];

        return [
            'id' => $data['id'] ?? 0,
            'title' => $data['title'] ?? '',
            'course_id' => (int) ($meta[FieldRegistry::EDITION_COURSE_ID] ?? 0),
            'start_date' => $meta[FieldRegistry::EDITION_START_DATE] ?? '',
            'end_date' => $meta[FieldRegistry::EDITION_END_DATE] ?? '',
            'capacity' => (int) ($meta[FieldRegistry::EDITION_CAPACITY] ?? 0) ?: null,
            'price' => (float) ($meta[FieldRegistry::EDITION_PRICE] ?? 0),
            'price_non_member' => (float) ($meta[FieldRegistry::EDITION_PRICE_NON_MEMBER] ?? 0),
            'venue' => $meta[FieldRegistry::EDITION_VENUE] ?? '',
            'speakers' => $meta[FieldRegistry::EDITION_SPEAKERS] ?? '',
            'status' => $meta[FieldRegistry::EDITION_STATUS] ?? FieldRegistry::EDITION_STATUS_OPEN,
            'invoice_item' => $meta[FieldRegistry::EDITION_INVOICE_ITEM] ?? '',
            'invoice_enabled' => (bool) ($meta[FieldRegistry::EDITION_INVOICE_ENABLED] ?? true),
            'certificate_enabled' => (bool) ($meta[FieldRegistry::EDITION_CERTIFICATE_ENABLED] ?? false),
            'custom_form' => $meta[FieldRegistry::EDITION_CUSTOM_FORM] ?? '',
        ];
    }
}
