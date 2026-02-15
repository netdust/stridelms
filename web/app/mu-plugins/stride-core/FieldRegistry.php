<?php

namespace ntdst\Stride;

// Polyfill translation function for non-WordPress contexts (testing)
if (!function_exists('__')) {
    function __($text, $domain = 'default')
    {
        return $text;
    }
}

/**
 * Field Registry
 *
 * Central registry for all custom field names used across Stride services.
 * Provides clean, consistent naming with backward compatibility for V3 legacy fields.
 *
 * Usage:
 *   FieldRegistry::SUBSCRIBER_VAT_NUMBER // Returns 'vat_number'
 *   FieldRegistry::legacyToNew('btw_ondernemingsnummer') // Returns 'vat_number'
 *   FieldRegistry::newToLegacy('vat_number', 'subscriber') // Returns 'btw_ondernemingsnummer'
 *
 * @package stride
 */
final class FieldRegistry
{
    // ========================================
    // STANDARD FIELDS (WordPress + FluentCRM core)
    // ========================================

    /** Email address */
    public const FIELD_EMAIL = 'email';

    /** First name */
    public const FIELD_FIRST_NAME = 'first_name';

    /** Last name */
    public const FIELD_LAST_NAME = 'last_name';

    /** Display name */
    public const FIELD_DISPLAY_NAME = 'display_name';

    /** Phone number */
    public const FIELD_PHONE = 'phone';

    /** Primary address line */
    public const FIELD_ADDRESS = 'address_line_1';

    /** Secondary address line */
    public const FIELD_ADDRESS_2 = 'address_line_2';

    /** City */
    public const FIELD_CITY = 'city';

    /** Postal/ZIP code */
    public const FIELD_POSTAL_CODE = 'postal_code';

    /** Country code */
    public const FIELD_COUNTRY = 'country';

    /** State/Province */
    public const FIELD_STATE = 'state';

    /** User managed by (email of manager) */
    public const FIELD_MANAGED_BY = 'managed_by';

    // ========================================
    // SUBSCRIBER CUSTOM FIELDS (FluentCRM Contact)
    // ========================================

    /** Organization name for invoice */
    public const SUBSCRIBER_INVOICE_ORG_NAME = 'invoice_organization_name';

    /** Invoice address line */
    public const SUBSCRIBER_INVOICE_ADDRESS = 'invoice_address';

    /** Invoice city */
    public const SUBSCRIBER_INVOICE_CITY = 'invoice_city';

    /** Invoice postal code */
    public const SUBSCRIBER_INVOICE_POSTAL_CODE = 'invoice_postal_code';

    /** Invoice email address */
    public const SUBSCRIBER_INVOICE_EMAIL = 'invoice_email';

    /** VAT/BTW number */
    public const SUBSCRIBER_VAT_NUMBER = 'vat_number';

    /** GLN/Peppol number for e-invoicing */
    public const SUBSCRIBER_GLN_NUMBER = 'gln_number';

    /** Department/division name */
    public const SUBSCRIBER_DEPARTMENT = 'department';

    /** Accounting export ID (Winbooks) */
    public const SUBSCRIBER_EXPORT_ID = 'export_id';

    /** Profile/member type */
    public const SUBSCRIBER_PROFILE_TYPE = 'profile_type';

    // ========================================
    // COMPANY CUSTOM FIELDS (FluentCRM Company)
    // ========================================

    /** Company invoice name (may differ from company name) */
    public const COMPANY_INVOICE_NAME = 'invoice_name';

    /** Company invoice address */
    public const COMPANY_INVOICE_ADDRESS = 'invoice_address';

    /** Company invoice city */
    public const COMPANY_INVOICE_CITY = 'invoice_city';

    /** Company invoice postal code */
    public const COMPANY_INVOICE_POSTAL_CODE = 'invoice_postal_code';

    /** Company invoice email */
    public const COMPANY_INVOICE_EMAIL = 'invoice_email';

    /** Company VAT number */
    public const COMPANY_VAT_NUMBER = 'vat_number';

    /** Company GLN/Peppol number */
    public const COMPANY_GLN_NUMBER = 'gln_number';

    /** Company department */
    public const COMPANY_DEPARTMENT = 'department';

    /** Company accounting export ID */
    public const COMPANY_EXPORT_ID = 'export_id';

    // ========================================
    // COURSE SETTINGS (LearnDash Meta)
    // ========================================

    /** Course dates array */
    public const COURSE_DATES = 'course_days';

    /** Course cancelled status */
    public const COURSE_STATUS_CANCELLED = 'course_status_cancelled';

    /** Course postponed status */
    public const COURSE_STATUS_POSTPONED = 'course_status_postponed';

    /** Course full status */
    public const COURSE_STATUS_FULL = 'course_status_full';

    /** Course announcement status */
    public const COURSE_STATUS_ANNOUNCEMENT = 'course_status_announcement';

    /** Maximum participants */
    public const COURSE_MAX_PARTICIPANTS = 'course_max_participants';

    /** Course speakers/supervisors */
    public const COURSE_SPEAKERS = 'course_supervisors';

    /** Course modules (trajectory) */
    public const COURSE_MODULES = 'course_modules_select';

    /** Is this a module course */
    public const COURSE_MODULES_ENABLED = 'course_modules_enabled';

    /** Course price */
    public const COURSE_PRICE = 'course_price';

    /** Invoice item ID */
    public const COURSE_INVOICE_ITEM = 'course_invoice_item';

    /** Invoice enabled flag */
    public const COURSE_INVOICE_ENABLED = 'course_invoice_enabled';

    /** Certificate enabled flag */
    public const COURSE_CERTIFICATE_ENABLED = 'course_certificate_enabled';

    /** Custom enrollment form */
    public const COURSE_CUSTOM_FORM = 'course_custom_form';

    /** Course location/address */
    public const COURSE_ADDRESS = 'course_address';

    // ========================================
    // EDITION FIELDS (vad_edition CPT)
    // ========================================

    /** Linked LearnDash course ID */
    public const EDITION_COURSE_ID = 'course_id';

    /** Edition start date (YYYY-MM-DD) */
    public const EDITION_START_DATE = 'start_date';

    /** Edition end date (YYYY-MM-DD) */
    public const EDITION_END_DATE = 'end_date';

    /** Maximum participants */
    public const EDITION_CAPACITY = 'capacity';

    /** Price for members */
    public const EDITION_PRICE = 'price';

    /** Price for non-members */
    public const EDITION_PRICE_NON_MEMBER = 'price_non_member';

    /** Venue/location */
    public const EDITION_VENUE = 'venue';

    /** Speakers (comma-separated or JSON) */
    public const EDITION_SPEAKERS = 'speakers';

    /** Edition status */
    public const EDITION_STATUS = 'status';

    /** Invoice item code for Exact Online */
    public const EDITION_INVOICE_ITEM = 'invoice_item';

    /** Invoice enabled flag */
    public const EDITION_INVOICE_ENABLED = 'invoice_enabled';

    /** Certificate enabled flag */
    public const EDITION_CERTIFICATE_ENABLED = 'certificate_enabled';

    /** Custom enrollment form name */
    public const EDITION_CUSTOM_FORM = 'custom_form';

    /** Multi-year training flag (tweejarige opleiding) */
    public const EDITION_IS_MULTI_YEAR = 'is_multi_year_training';

    /** Completion mode (attend_all, attend_percentage, attend_count) */
    public const EDITION_COMPLETION_MODE = 'completion_mode';

    /** Completion threshold (percentage or count based on mode) */
    public const EDITION_COMPLETION_THRESHOLD = 'completion_threshold';

    /** Is this the default edition for the course */
    public const EDITION_IS_DEFAULT = 'is_default';

    // Edition status values
    public const EDITION_STATUS_OPEN = 'open';
    public const EDITION_STATUS_FULL = 'full';
    public const EDITION_STATUS_CANCELLED = 'cancelled';
    public const EDITION_STATUS_POSTPONED = 'postponed';
    public const EDITION_STATUS_ANNOUNCEMENT = 'announcement';
    public const EDITION_STATUS_COMPLETED = 'completed';

    // ========================================
    // SESSION FIELDS (vad_session CPT)
    // ========================================

    /** Linked edition ID */
    public const SESSION_EDITION_ID = 'edition_id';

    /** Session date (YYYY-MM-DD) */
    public const SESSION_DATE = 'date';

    /** Start time (HH:MM) */
    public const SESSION_START_TIME = 'start_time';

    /** End time (HH:MM) */
    public const SESSION_END_TIME = 'end_time';

    /** Location (may differ from edition venue) */
    public const SESSION_LOCATION = 'location';

    /** Attendees array (user IDs) */
    public const SESSION_ATTENDEES = 'attendees';

    /** Session slot identifier for grouping (e.g., "morning", "afternoon") */
    public const SESSION_SLOT = 'slot';

    /** Session slot display label (e.g., "Voormiddag", "Namiddag") */
    public const SESSION_SLOT_LABEL = 'slot_label';

    /** Session type (in_person, webinar, online, assignment) */
    public const SESSION_TYPE = 'type';

    /** Linked LearnDash lesson IDs (for online/assignment sessions) */
    public const SESSION_LESSON_IDS = 'lesson_ids';

    /** Session sort order */
    public const SESSION_SORT_ORDER = 'sort_order';

    /** Session title (for fysiek/webinar sessions) */
    public const SESSION_TITLE = 'title';

    /** Session description */
    public const SESSION_DESCRIPTION = 'description';

    /** Webinar link (for webinar sessions) */
    public const SESSION_WEBINAR_LINK = 'webinar_link';

    /** Session deadline (for online/assignment sessions) */
    public const SESSION_DEADLINE = 'deadline';

    // Session type values
    public const SESSION_TYPE_IN_PERSON = 'in_person';
    public const SESSION_TYPE_WEBINAR = 'webinar';
    public const SESSION_TYPE_ONLINE = 'online';
    public const SESSION_TYPE_ASSIGNMENT = 'assignment';

    // ========================================
    // TRAJECTORY FIELDS (vad_trajectory CPT)
    // ========================================

    /** Trajectory description */
    public const TRAJECTORY_DESCRIPTION = 'description';

    /** Trajectory status (open, closed, archived) */
    public const TRAJECTORY_STATUS = 'status';

    /** Number of months to complete trajectory */
    public const TRAJECTORY_DEADLINE_MONTHS = 'deadline_months';

    /** Course requirements JSON array */
    public const TRAJECTORY_COURSES = 'courses';

    // Trajectory status values
    public const TRAJECTORY_STATUS_OPEN = 'open';
    public const TRAJECTORY_STATUS_CLOSED = 'closed';
    public const TRAJECTORY_STATUS_ARCHIVED = 'archived';

    // ========================================
    // TRAJECTORY MODE FIELDS
    // ========================================

    /** Trajectory mode: self_paced or cohort */
    public const TRAJECTORY_MODE = 'mode';

    /** Mode value: self-paced (user picks own editions at own pace) */
    public const TRAJECTORY_MODE_SELF_PACED = 'self_paced';

    /** Mode value: cohort (pre-linked editions, group moves together) */
    public const TRAJECTORY_MODE_COHORT = 'cohort';

    /** Cohort: enrollment closes after this date (YYYY-MM-DD) */
    public const TRAJECTORY_ENROLLMENT_DEADLINE = 'enrollment_deadline';

    /** Cohort: elective choices become available after this date (YYYY-MM-DD) */
    public const TRAJECTORY_CHOICE_AVAILABLE = 'choice_available_date';

    /** Cohort: deadline for elective choices (YYYY-MM-DD) */
    public const TRAJECTORY_CHOICE_DEADLINE = 'choice_deadline';

    /** Cohort: linked editions per course (JSON: [{course_id, edition_id}, ...]) */
    public const TRAJECTORY_LINKED_EDITIONS = 'linked_editions';

    /** Trajectory price (optional fixed price, if not set = sum of editions) */
    public const TRAJECTORY_PRICE = 'price';

    // ========================================
    // EDITION SESSION SLOTS (Edition Meta)
    // ========================================

    /** Session slot configuration for edition */
    public const EDITION_SESSION_SLOTS = 'session_slots';

    /** Session selection deadline (users can change until this date) */
    public const EDITION_SELECTION_DEADLINE = 'selection_deadline';

    // ========================================
    // EDITION INFORMATIONAL FIELDS
    // ========================================

    /** Target audience description */
    public const EDITION_TARGET_GROUP = 'target_group';

    /** Prerequisites/prior education */
    public const EDITION_PREREQUISITES = 'prerequisites';

    /** Organization guides/supervisors (not speakers) */
    public const EDITION_TRAINERS = 'trainers';

    /** Accreditation information */
    public const EDITION_ACCREDITATION = 'accreditation';

    // ========================================
    // CATEGORY NAMES
    // ========================================

    /** In-person course category */
    public const CATEGORY_IN_PERSON = 'In-person';

    // ========================================
    // COMPANY TYPES
    // ========================================

    /** Member/partner organization type */
    public const COMPANY_TYPE_PARTNER = 'partner';

    /** Standard company type */
    public const COMPANY_TYPE_COMPANY = 'company';

    // ========================================
    // LEGACY MAPPINGS (V3 → V4)
    // ========================================

    /**
     * Legacy field mappings for subscriber custom fields
     * Maps V3 Dutch/truncated names to V4 clean names
     */
    private const SUBSCRIBER_LEGACY_MAP = [
        // V3 field => V4 field
        'facturatie_naam_organisat' => self::SUBSCRIBER_INVOICE_ORG_NAME,
        'facturatie_adres' => self::SUBSCRIBER_INVOICE_ADDRESS,
        'facturatie_stad' => self::SUBSCRIBER_INVOICE_CITY,
        'facturatie_postcode' => self::SUBSCRIBER_INVOICE_POSTAL_CODE,
        'facturatie_email' => self::SUBSCRIBER_INVOICE_EMAIL,
        'btw_ondernemingsnummer' => self::SUBSCRIBER_VAT_NUMBER,
        'gln_nummer' => self::SUBSCRIBER_GLN_NUMBER,
        'afdeling_organisatie' => self::SUBSCRIBER_DEPARTMENT,
        'winbooks_id' => self::SUBSCRIBER_EXPORT_ID,
        'profile_type' => self::SUBSCRIBER_PROFILE_TYPE, // Already clean
    ];

    /**
     * Legacy field mappings for company custom fields
     */
    private const COMPANY_LEGACY_MAP = [
        // V3 field => V4 field
        'naam_organisatie_fac' => self::COMPANY_INVOICE_NAME,
        'adres_organisatie_fac' => self::COMPANY_INVOICE_ADDRESS,
        'stad_organisatie_fac' => self::COMPANY_INVOICE_CITY,
        'postcode_organisatie_fac' => self::COMPANY_INVOICE_POSTAL_CODE,
        'email_organisatie_fac' => self::COMPANY_INVOICE_EMAIL,
        'btw_organisatie_fac' => self::COMPANY_VAT_NUMBER,
        'gln_nummer' => self::COMPANY_GLN_NUMBER,
        'afdeling_organisatie' => self::COMPANY_DEPARTMENT,
        'export_id' => self::COMPANY_EXPORT_ID, // Already clean
    ];

    /**
     * Legacy field mappings for course settings
     */
    private const COURSE_LEGACY_MAP = [
        // V3 field => V4 field (mostly already clean)
        'course_price_type_vad_invoice_item' => self::COURSE_INVOICE_ITEM,
        'course_extraform_item' => self::COURSE_CUSTOM_FORM,
        'course_price_type_vad_custom_form' => self::COURSE_CUSTOM_FORM,
    ];

    // ========================================
    // CONVERSION METHODS
    // ========================================

    /**
     * Convert legacy field name to new field name
     *
     * @param string $legacyField The V3 field name
     * @param string $context 'subscriber', 'company', or 'course'
     * @return string The V4 field name (or original if no mapping)
     */
    public static function legacyToNew(string $legacyField, string $context = 'subscriber'): string
    {
        $map = match ($context) {
            'subscriber' => self::SUBSCRIBER_LEGACY_MAP,
            'company' => self::COMPANY_LEGACY_MAP,
            'course' => self::COURSE_LEGACY_MAP,
            default => [],
        };

        return $map[$legacyField] ?? $legacyField;
    }

    /**
     * Convert new field name to legacy field name (for database compatibility)
     *
     * @param string $newField The V4 field name
     * @param string $context 'subscriber', 'company', or 'course'
     * @return string The V3 field name for database storage
     */
    public static function newToLegacy(string $newField, string $context = 'subscriber'): string
    {
        $map = match ($context) {
            'subscriber' => array_flip(self::SUBSCRIBER_LEGACY_MAP),
            'company' => array_flip(self::COMPANY_LEGACY_MAP),
            'course' => array_flip(self::COURSE_LEGACY_MAP),
            default => [],
        };

        return $map[$newField] ?? $newField;
    }

    /**
     * Get all standard field names (shared between WP and FluentCRM)
     *
     * @return array<string, string> Field constant name => field value
     */
    public static function getStandardFields(): array
    {
        return [
            'EMAIL' => self::FIELD_EMAIL,
            'FIRST_NAME' => self::FIELD_FIRST_NAME,
            'LAST_NAME' => self::FIELD_LAST_NAME,
            'DISPLAY_NAME' => self::FIELD_DISPLAY_NAME,
            'PHONE' => self::FIELD_PHONE,
            'ADDRESS' => self::FIELD_ADDRESS,
            'ADDRESS_2' => self::FIELD_ADDRESS_2,
            'CITY' => self::FIELD_CITY,
            'POSTAL_CODE' => self::FIELD_POSTAL_CODE,
            'COUNTRY' => self::FIELD_COUNTRY,
            'STATE' => self::FIELD_STATE,
            'MANAGED_BY' => self::FIELD_MANAGED_BY,
        ];
    }

    /**
     * Get all subscriber custom field names (new names)
     *
     * @return array<string, string> Field constant name => field value
     */
    public static function getSubscriberFields(): array
    {
        return [
            'INVOICE_ORG_NAME' => self::SUBSCRIBER_INVOICE_ORG_NAME,
            'INVOICE_ADDRESS' => self::SUBSCRIBER_INVOICE_ADDRESS,
            'INVOICE_CITY' => self::SUBSCRIBER_INVOICE_CITY,
            'INVOICE_POSTAL_CODE' => self::SUBSCRIBER_INVOICE_POSTAL_CODE,
            'INVOICE_EMAIL' => self::SUBSCRIBER_INVOICE_EMAIL,
            'VAT_NUMBER' => self::SUBSCRIBER_VAT_NUMBER,
            'GLN_NUMBER' => self::SUBSCRIBER_GLN_NUMBER,
            'DEPARTMENT' => self::SUBSCRIBER_DEPARTMENT,
            'EXPORT_ID' => self::SUBSCRIBER_EXPORT_ID,
            'PROFILE_TYPE' => self::SUBSCRIBER_PROFILE_TYPE,
        ];
    }

    /**
     * Get all subscriber fields including standard fields
     *
     * @return array<string, string>
     */
    public static function getAllSubscriberFields(): array
    {
        return array_merge(self::getStandardFields(), self::getSubscriberFields());
    }

    /**
     * Get all company field names (new names)
     *
     * @return array<string, string> Field constant name => field value
     */
    public static function getCompanyFields(): array
    {
        return [
            'INVOICE_NAME' => self::COMPANY_INVOICE_NAME,
            'INVOICE_ADDRESS' => self::COMPANY_INVOICE_ADDRESS,
            'INVOICE_CITY' => self::COMPANY_INVOICE_CITY,
            'INVOICE_POSTAL_CODE' => self::COMPANY_INVOICE_POSTAL_CODE,
            'INVOICE_EMAIL' => self::COMPANY_INVOICE_EMAIL,
            'VAT_NUMBER' => self::COMPANY_VAT_NUMBER,
            'GLN_NUMBER' => self::COMPANY_GLN_NUMBER,
            'DEPARTMENT' => self::COMPANY_DEPARTMENT,
            'EXPORT_ID' => self::COMPANY_EXPORT_ID,
        ];
    }

    /**
     * Get display name for a field (for admin UI)
     *
     * @param string $field Field name (new format)
     * @param string $context 'subscriber' or 'company'
     * @return string Human-readable label
     */
    public static function getFieldLabel(string $field, string $context = 'subscriber'): string
    {
        $labels = [
            // Subscriber fields
            self::SUBSCRIBER_INVOICE_ORG_NAME => __('Invoice Organization Name', 'stride'),
            self::SUBSCRIBER_INVOICE_ADDRESS => __('Invoice Address', 'stride'),
            self::SUBSCRIBER_INVOICE_CITY => __('Invoice City', 'stride'),
            self::SUBSCRIBER_INVOICE_POSTAL_CODE => __('Invoice Postal Code', 'stride'),
            self::SUBSCRIBER_INVOICE_EMAIL => __('Invoice Email', 'stride'),
            self::SUBSCRIBER_VAT_NUMBER => __('VAT Number', 'stride'),
            self::SUBSCRIBER_GLN_NUMBER => __('GLN/Peppol Number', 'stride'),
            self::SUBSCRIBER_DEPARTMENT => __('Department', 'stride'),
            self::SUBSCRIBER_EXPORT_ID => __('Accounting ID', 'stride'),
            self::SUBSCRIBER_PROFILE_TYPE => __('Profile Type', 'stride'),

            // Company fields
            self::COMPANY_INVOICE_NAME => __('Invoice Name', 'stride'),
            self::COMPANY_INVOICE_ADDRESS => __('Invoice Address', 'stride'),
            self::COMPANY_INVOICE_CITY => __('Invoice City', 'stride'),
            self::COMPANY_INVOICE_POSTAL_CODE => __('Invoice Postal Code', 'stride'),
            self::COMPANY_INVOICE_EMAIL => __('Invoice Email', 'stride'),
            self::COMPANY_VAT_NUMBER => __('VAT Number', 'stride'),
            self::COMPANY_GLN_NUMBER => __('GLN/Peppol Number', 'stride'),
            self::COMPANY_DEPARTMENT => __('Department', 'stride'),
            self::COMPANY_EXPORT_ID => __('Accounting ID', 'stride'),
        ];

        return $labels[$field] ?? ucfirst(str_replace('_', ' ', $field));
    }

    /**
     * Check if we're in legacy database mode
     * When true, services should use legacy field names for database operations
     *
     * @return bool
     */
    public static function useLegacyFieldNames(): bool
    {
        // Check if WordPress is available
        if (!function_exists('apply_filters')) {
            return true; // Default to legacy mode outside WordPress
        }

        return apply_filters('stride/use_legacy_field_names', true);
    }

    /**
     * Get the database field name (legacy or new based on mode)
     *
     * @param string $newField The V4 field name
     * @param string $context 'subscriber', 'company', or 'course'
     * @return string The field name to use for database operations
     */
    public static function getDbFieldName(string $newField, string $context = 'subscriber'): string
    {
        if (self::useLegacyFieldNames()) {
            return self::newToLegacy($newField, $context);
        }

        return $newField;
    }

    /**
     * Convert array keys from legacy to new field names
     *
     * @param array $data Data with legacy field names as keys
     * @param string $context 'subscriber', 'company', or 'course'
     * @return array Data with new field names as keys
     */
    public static function convertLegacyData(array $data, string $context = 'subscriber'): array
    {
        $converted = [];

        foreach ($data as $key => $value) {
            $newKey = self::legacyToNew($key, $context);
            $converted[$newKey] = $value;
        }

        return $converted;
    }

    /**
     * Convert array keys from new to legacy field names (for database storage)
     *
     * @param array $data Data with new field names as keys
     * @param string $context 'subscriber', 'company', or 'course'
     * @return array Data with legacy field names as keys
     */
    public static function convertToLegacyData(array $data, string $context = 'subscriber'): array
    {
        if (!self::useLegacyFieldNames()) {
            return $data;
        }

        $converted = [];

        foreach ($data as $key => $value) {
            $legacyKey = self::newToLegacy($key, $context);
            $converted[$legacyKey] = $value;
        }

        return $converted;
    }
}
