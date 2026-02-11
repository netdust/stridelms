<?php

namespace stride\services\smartcode;

defined('ABSPATH') || exit;

use stride\services\smartcode\contracts\SmartCodeContextInterface;

/**
 * SmartCode Context
 *
 * Resolves the "current" course/invoice from multiple sources.
 * Used by SmartCode providers to get contextual data.
 *
 * Resolution order for course:
 * 1. Explicit context (set via setCourseId)
 * 2. Form submission data (FluentForms)
 * 3. URL parameter (?course_id=) - with authorization check
 * 4. FunnelSubscriber source_ref_id (FluentCRM automation)
 * 5. Current queried object (WordPress)
 *
 * SECURITY: URL parameters are validated to ensure the resource is publicly
 * accessible or the current user has permission to view it.
 *
 * PERFORMANCE: Resolution results are cached for the request lifecycle
 * to avoid repeated lookups when multiple SmartCodes use the same context.
 *
 * @package stride\services\smartcode
 */
class SmartCodeContext implements SmartCodeContextInterface
{
    private ?int $courseId = null;
    private ?int $invoiceId = null;

    /**
     * Cached resolved course ID (false = not resolved yet, null = resolved to null)
     */
    private int|null|false $resolvedCourseId = false;

    /**
     * Cached resolved invoice ID (false = not resolved yet, null = resolved to null)
     */
    private int|null|false $resolvedInvoiceId = false;

    /**
     * Get the current course ID from context
     *
     * Results are cached for the request lifecycle.
     *
     * @return int|null
     */
    public function getCourseId(): ?int
    {
        // 1. Explicit context takes precedence (not cached)
        if ($this->courseId !== null) {
            return $this->courseId;
        }

        // Return cached result if already resolved
        if ($this->resolvedCourseId !== false) {
            return $this->resolvedCourseId;
        }

        // Resolve and cache
        $this->resolvedCourseId = $this->resolveCourseId();
        return $this->resolvedCourseId;
    }

    /**
     * Internal course ID resolution
     *
     * @return int|null
     */
    private function resolveCourseId(): ?int
    {
        // 2. Check form submission data (FluentForms) - trusted source
        $courseId = $this->getCourseFromFormSubmission();
        if ($courseId && $this->isValidCourse($courseId)) {
            return $courseId;
        }

        // 3. Check URL parameter - requires authorization check
        $courseId = $this->getCourseFromUrl();
        if ($courseId && $this->canAccessCourse($courseId)) {
            return $courseId;
        }

        // 4. Check FluentCRM FunnelSubscriber - trusted source (automation context)
        $courseId = $this->getCourseFromFunnelSubscriber();
        if ($courseId && $this->isValidCourse($courseId)) {
            return $courseId;
        }

        // 5. Check current queried object - trusted source (WordPress determines access)
        return $this->getCourseFromQueriedObject();
    }

    /**
     * Get the current invoice ID from context
     *
     * Results are cached for the request lifecycle.
     *
     * @return int|null
     */
    public function getInvoiceId(): ?int
    {
        // 1. Explicit context takes precedence (not cached)
        if ($this->invoiceId !== null) {
            return $this->invoiceId;
        }

        // Return cached result if already resolved
        if ($this->resolvedInvoiceId !== false) {
            return $this->resolvedInvoiceId;
        }

        // Resolve and cache
        $this->resolvedInvoiceId = $this->resolveInvoiceId();
        return $this->resolvedInvoiceId;
    }

    /**
     * Internal invoice ID resolution
     *
     * @return int|null
     */
    private function resolveInvoiceId(): ?int
    {
        // Check URL parameter - requires authorization check
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Context resolution for SmartCodes, authorization checked below
        if (isset($_GET['invoice_id'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $invoiceId = absint($_GET['invoice_id']);
            if ($invoiceId > 0 && $this->canAccessInvoice($invoiceId)) {
                return $invoiceId;
            }
        }

        return null;
    }

    /**
     * Set explicit course ID
     *
     * @param int|null $courseId
     * @return self
     */
    public function setCourseId(?int $courseId): self
    {
        $this->courseId = $courseId;
        return $this;
    }

    /**
     * Set explicit invoice ID
     *
     * @param int|null $invoiceId
     * @return self
     */
    public function setInvoiceId(?int $invoiceId): self
    {
        $this->invoiceId = $invoiceId;
        return $this;
    }

    /**
     * Reset context to defaults
     *
     * Clears both explicit and cached values.
     *
     * @return self
     */
    public function reset(): self
    {
        $this->courseId = null;
        $this->invoiceId = null;
        $this->resolvedCourseId = false;
        $this->resolvedInvoiceId = false;
        return $this;
    }

    /**
     * Check if a course ID is valid (exists and is a course)
     *
     * @param int $courseId
     * @return bool
     */
    private function isValidCourse(int $courseId): bool
    {
        $post = get_post($courseId);
        return $post && $post->post_type === 'sfwd-courses';
    }

    /**
     * Check if current user can access a course
     *
     * For SmartCode context, we allow access if:
     * - Course is published (publicly accessible)
     * - User is enrolled in the course
     * - User has admin capabilities
     *
     * @param int $courseId
     * @return bool
     */
    private function canAccessCourse(int $courseId): bool
    {
        $post = get_post($courseId);

        if (!$post || $post->post_type !== 'sfwd-courses') {
            return false;
        }

        // Published courses are publicly accessible for context resolution
        if ($post->post_status === 'publish') {
            return true;
        }

        // Admins can access any course
        if (current_user_can('manage_options') || current_user_can('edit_others_courses')) {
            return true;
        }

        // Check if user is enrolled (LearnDash function)
        $userId = get_current_user_id();
        if ($userId > 0 && function_exists('sfwd_lms_has_access')) {
            return sfwd_lms_has_access($courseId, $userId);
        }

        return false;
    }

    /**
     * Check if current user can access an invoice
     *
     * For SmartCode context, we allow access if:
     * - Invoice belongs to current user
     * - User has admin capabilities
     *
     * @param int $invoiceId
     * @return bool
     */
    private function canAccessInvoice(int $invoiceId): bool
    {
        // Admins can access any invoice
        if (current_user_can('manage_options')) {
            return true;
        }

        $userId = get_current_user_id();
        if (!$userId) {
            return false;
        }

        // Check invoice post author or custom meta
        $post = get_post($invoiceId);
        if ($post) {
            // If invoice is a post type, check author
            if ((int) $post->post_author === $userId) {
                return true;
            }

            // Check custom user meta
            $invoiceUserId = get_post_meta($invoiceId, '_user_id', true);
            if ($invoiceUserId && (int) $invoiceUserId === $userId) {
                return true;
            }
        }

        // Allow filter for custom invoice access logic
        return apply_filters('stride/smartcode/can_access_invoice', false, $invoiceId, $userId);
    }

    /**
     * Get course ID from FluentForms submission data
     *
     * Checks both global form data and specific field names.
     * Form submissions are trusted (already validated by FluentForms).
     *
     * @return int|null
     */
    private function getCourseFromFormSubmission(): ?int
    {
        // Check FluentForms submission globals
        global $fluentform_submission;

        if (!empty($fluentform_submission)) {
            $data = $fluentform_submission;

            // Check for course_id field
            if (!empty($data['course_id'])) {
                return absint($data['course_id']);
            }

            // Check for hidden course field
            if (!empty($data['hidden_course_id'])) {
                return absint($data['hidden_course_id']);
            }
        }

        return null;
    }

    /**
     * Get course ID from URL parameter
     *
     * Note: Authorization is checked by canAccessCourse() before using this value.
     *
     * @return int|null
     */
    private function getCourseFromUrl(): ?int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Context resolution for SmartCodes, authorization checked by canAccessCourse()
        if (isset($_GET['course_id'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $courseId = absint($_GET['course_id']);
            if ($courseId > 0) {
                return $courseId;
            }
        }

        // Check for LearnDash course parameter
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Context resolution for SmartCodes, authorization checked by canAccessCourse()
        if (isset($_GET['course'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $courseId = absint($_GET['course']);
            if ($courseId > 0) {
                return $courseId;
            }
        }

        return null;
    }

    /**
     * Get course ID from FluentCRM FunnelSubscriber
     *
     * When running in a FluentCRM automation, the FunnelSubscriber
     * may contain the source course ID in source_ref_id.
     * Automation context is trusted (system-initiated).
     *
     * @return int|null
     */
    private function getCourseFromFunnelSubscriber(): ?int
    {
        // Check if we're in FluentCRM automation context
        global $fluentcrm_funnel_subscriber;

        if (!empty($fluentcrm_funnel_subscriber)) {
            // source_ref_id typically contains the triggering object ID
            $sourceRefId = $fluentcrm_funnel_subscriber->source_ref_id ?? null;

            if ($sourceRefId) {
                // Verify it's actually a course
                if (get_post_type($sourceRefId) === 'sfwd-courses') {
                    return absint($sourceRefId);
                }
            }
        }

        return null;
    }

    /**
     * Get course ID from current WordPress queried object
     *
     * WordPress determines access to the page, so this is trusted.
     * Results are cached by WordPress.
     *
     * @return int|null
     */
    private function getCourseFromQueriedObject(): ?int
    {
        $queriedObject = get_queried_object();

        if (!$queriedObject || !($queriedObject instanceof \WP_Post)) {
            return null;
        }

        // Direct course page
        if ($queriedObject->post_type === 'sfwd-courses') {
            return $queriedObject->ID;
        }

        // Lesson/topic/quiz page - get parent course
        $ldPostTypes = ['sfwd-lessons', 'sfwd-topic', 'sfwd-quiz'];

        if (in_array($queriedObject->post_type, $ldPostTypes, true)) {
            // Try to get course from LearnDash
            if (function_exists('learndash_get_course_id')) {
                $courseId = learndash_get_course_id($queriedObject->ID);
                if ($courseId > 0) {
                    return $courseId;
                }
            }
        }

        return null;
    }
}
