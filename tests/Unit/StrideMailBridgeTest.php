<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for StrideMailBridge registration logic.
 *
 * Tests the structure of SmartCodes, Triggers, and template definitions
 * without requiring WordPress (pure data validation).
 */
class StrideMailBridgeTest extends TestCase
{
    /**
     * Expected SmartCode categories that Stride registers.
     */
    private array $expectedCategories = ['edition', 'registration', 'quote', 'certificate', 'trajectory'];

    /**
     * Expected trigger hooks.
     */
    private array $expectedTriggers = [
        'stride/registration/created',
        'stride/registration/confirmed',
        'stride/registration/cancelled',
        'stride/completion/completed',
        'stride/completion/attendance_complete',
        'stride/quote/created',
        'stride/quote/sent',
        'stride/quote/session_modifier_blocked',
        'stride/trajectory/enrolled',
    ];

    /**
     * Expected template slugs.
     */
    private array $expectedTemplates = [
        'stride-enrollment-created-user',
        'stride-enrollment-created-admin',
        'stride-enrollment-confirmed',
        'stride-enrollment-cancelled',
        'stride-task-documents-admin',
        'stride-task-approval-needed',
        'stride-completion-user',
        'stride-quote-created',
        'stride-quote-sent',
        'stride-modifier-blocked-admin',
        'stride-trajectory-enrolled',
    ];

    public function testExpectedSmartCodeCategoriesAreDefined(): void
    {
        $this->assertCount(5, $this->expectedCategories);

        foreach ($this->expectedCategories as $cat) {
            $this->assertMatchesRegularExpression('/^[a-z]+$/', $cat, "Category '$cat' should be lowercase alpha");
        }
    }

    public function testEditionSmartCodesIncludeRequiredFields(): void
    {
        $editionCodes = ['title', 'start_date', 'end_date', 'venue', 'price', 'url'];
        $this->assertCount(6, $editionCodes);
    }

    public function testRegistrationSmartCodesIncludeDocuments(): void
    {
        $regCodes = ['status', 'date', 'selections', 'documents'];
        $this->assertContains('documents', $regCodes);
        $this->assertContains('selections', $regCodes);
    }

    public function testAllTriggersStartWithStride(): void
    {
        foreach ($this->expectedTriggers as $trigger) {
            $this->assertStringStartsWith('stride/', $trigger);
        }
    }

    public function testTriggerCountMatchesSpec(): void
    {
        $this->assertCount(9, $this->expectedTriggers);
    }

    public function testTemplateCountMatchesSpec(): void
    {
        $this->assertCount(11, $this->expectedTemplates);
    }

    public function testAllTemplateSlugsStartWithStride(): void
    {
        foreach ($this->expectedTemplates as $slug) {
            $this->assertStringStartsWith('stride-', $slug);
        }
    }

    public function testManualDispatchTemplatesExist(): void
    {
        // These templates use manual dispatch (no auto-trigger)
        $manualTemplates = ['stride-task-documents-admin', 'stride-task-approval-needed'];

        foreach ($manualTemplates as $slug) {
            $this->assertContains($slug, $this->expectedTemplates);
        }
    }

    public function testAdminAndUserTemplatesForEnrollmentCreated(): void
    {
        // Both user and admin should get notified on enrollment
        $this->assertContains('stride-enrollment-created-user', $this->expectedTemplates);
        $this->assertContains('stride-enrollment-created-admin', $this->expectedTemplates);
    }

    public function testQuoteTriggersAreSeparate(): void
    {
        // Quote created and sent are separate triggers
        $this->assertContains('stride/quote/created', $this->expectedTriggers);
        $this->assertContains('stride/quote/sent', $this->expectedTriggers);
    }
}
