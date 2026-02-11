<?php

namespace stride\services\smartcode\contracts;

defined('ABSPATH') || exit;

/**
 * SmartCode Context Interface
 *
 * Contract for resolving contextual data (current course, invoice, etc.)
 * when processing SmartCodes.
 *
 * @package stride\services\smartcode
 */
interface SmartCodeContextInterface
{
    /**
     * Get the current course ID from context
     *
     * Resolves from:
     * 1. Explicit context (form submission)
     * 2. URL parameter (?course_id=)
     * 3. FunnelSubscriber source_ref_id
     * 4. Current queried object
     *
     * @return int|null Course ID or null if not determinable
     */
    public function getCourseId(): ?int;

    /**
     * Get the current invoice ID from context
     *
     * @return int|null Invoice ID or null if not determinable
     */
    public function getInvoiceId(): ?int;

    /**
     * Set explicit course ID
     *
     * Used when processing form submissions or automations
     * where the course is known.
     *
     * @param int|null $courseId
     * @return self
     */
    public function setCourseId(?int $courseId): self;

    /**
     * Set explicit invoice ID
     *
     * @param int|null $invoiceId
     * @return self
     */
    public function setInvoiceId(?int $invoiceId): self;

    /**
     * Reset context to defaults
     *
     * @return self
     */
    public function reset(): self;
}
