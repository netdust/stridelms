<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Modules\Questionnaire;

use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Questionnaire\QuestionnaireHandler;
use Stride\Modules\Questionnaire\QuestionnaireValidator;
use Stride\Tests\TestCase;

/**
 * Unit tests for the native offer/invoice required-field validation added to
 * QuestionnaireHandler::handleSubmitWaitlist (Task 8).
 *
 * The native offer fields (company, vat_number, invoice_email, ...) are NOT
 * declared in any questionnaire group, so QuestionnaireValidator does not see
 * them. The handler enforces the offer essentials itself AFTER the validator
 * passes. These tests pin that contract:
 *   (a) missing `company`        → WP_Error(validation_error), not persisted
 *   (b) invalid `invoice_email`  → WP_Error(validation_error), not persisted
 *   (c) all native required present + valid → reaches persist (repo create)
 */
class WaitlistNativeFieldsValidationTest extends TestCase
{
    private QuestionnaireHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        // QuestionnaireValidator passes — we are testing the NATIVE check that
        // runs after it, not the group-declared validation.
        $validator = $this->createMock(QuestionnaireValidator::class);
        $validator->method('validate')->willReturn(true);
        ntdst_set(QuestionnaireValidator::class, $validator);

        $this->handler = new QuestionnaireHandler();
    }

    private function validParams(array $extraOverride = []): array
    {
        return [
            'edition_id' => 42,
            'name' => 'Jan Jansen',
            'email' => 'jan@example.com',
            'extra_fields' => array_merge([
                'company' => 'Acme NV',
                'vat_number' => 'BE0123456789',
                'invoice_email' => 'facturen@acme.test',
            ], $extraOverride),
        ];
    }

    /** (a) missing required `company` → WP_Error, repo never touched. */
    public function test_missing_company_returns_validation_error_and_does_not_persist(): void
    {
        $repo = $this->createMock(RegistrationRepository::class);
        $repo->expects($this->never())->method('create');
        $repo->expects($this->never())->method('update');
        ntdst_set(RegistrationRepository::class, $repo);

        $result = $this->handler->handleSubmitWaitlist(null, $this->validParams(['company' => '']));

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('validation_error', $result->get_error_code());
    }

    /** (b) invalid `invoice_email` → WP_Error, repo never touched. */
    public function test_invalid_invoice_email_returns_validation_error_and_does_not_persist(): void
    {
        $repo = $this->createMock(RegistrationRepository::class);
        $repo->expects($this->never())->method('create');
        $repo->expects($this->never())->method('update');
        ntdst_set(RegistrationRepository::class, $repo);

        $result = $this->handler->handleSubmitWaitlist(null, $this->validParams(['invoice_email' => 'not-an-email']));

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('validation_error', $result->get_error_code());
    }

    /** (c) all native required present + valid → reaches persist (repo create). */
    public function test_all_native_required_present_reaches_persist(): void
    {
        $repo = $this->createMock(RegistrationRepository::class);
        $repo->method('findAnonymousForEmailAndEdition')->willReturn(null);
        $repo->expects($this->once())->method('create')->willReturn(777);
        ntdst_set(RegistrationRepository::class, $repo);

        $result = $this->handler->handleSubmitWaitlist(null, $this->validParams());

        $this->assertIsArray($result, 'expected success array, got: ' . (is_wp_error($result) ? $result->get_error_message() : 'unknown'));
        $this->assertTrue($result['success'] ?? false);
    }
}
