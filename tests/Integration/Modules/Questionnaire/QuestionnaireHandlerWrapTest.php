<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Modules\Questionnaire;

use IntegrationTestCase;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Questionnaire\QuestionnaireHandler;

final class QuestionnaireHandlerWrapTest extends IntegrationTestCase
{
    private RegistrationRepository $repo;
    private array $createdRegistrations = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->repo = ntdst_get(RegistrationRepository::class);
    }

    public function tearDown(): void
    {
        global $wpdb;
        foreach ($this->createdRegistrations as $id) {
            $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $id]);
        }
        $this->createdRegistrations = [];
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function testInterestSubmissionPersistsWrappedShape(): void
    {
        // Interest is an Announcement affordance — the handler enforces the
        // effective-status gate server-side (security hardening 2026-07-14).
        $editionId = $this->createTestEdition(['meta' => ['_ntdst_status' => 'announcement']]);
        $handler = ntdst_get(QuestionnaireHandler::class);

        $result = $handler->handleSubmitInterest(null, [
            'edition_id' => $editionId,
            'name' => 'Jan',
            'email' => 'jan@example.com',
            'extra_fields' => [],
        ]);

        $this->assertIsArray($result, 'expected success array, got: ' . (is_wp_error($result) ? $result->get_error_message() : 'unknown'));

        $found = $this->repo->findAnonymousForEmailAndEdition('jan@example.com', $editionId);
        $this->assertNotNull($found, 'interest row should be findable by email');
        $this->createdRegistrations[] = (int) $found->id;

        $row = $this->repo->find((int) $found->id);
        $this->assertNotNull($row);

        $interest = $row->enrollment_data['interest'];
        $this->assertArrayHasKey('submitted_at', $interest);
        $this->assertArrayHasKey('submitted_by', $interest);
        $this->assertArrayHasKey('data', $interest);
        $this->assertNull($interest['submitted_by'], 'anonymous interest submission stores null actor');
        $this->assertSame('Jan', $interest['data']['name']);
        $this->assertSame('jan@example.com', $interest['data']['email']);
    }

    public function testWaitlistSubmissionPersistsWrappedShape(): void
    {
        // Waitlist is a Full-edition affordance (same server-side gate).
        $editionId = $this->createTestEdition(['meta' => ['_ntdst_status' => 'full']]);
        $handler = ntdst_get(QuestionnaireHandler::class);

        // The waitlist path requires the native offer/invoice fields by default
        // (feat: native offer/invoice fields on the waitlist form) — company,
        // vat_number and invoice_email are enforced in handleSubmitWaitlist and
        // map to billing_* usermeta on promote. Send them as the real form does.
        $result = $handler->handleSubmitWaitlist(null, [
            'edition_id' => $editionId,
            'name' => 'Mia',
            'email' => 'mia@example.com',
            'extra_fields' => [
                'company' => 'Mia BV',
                'vat_number' => 'BE0123456789',
                'invoice_email' => 'facturatie@mia.example.com',
            ],
        ]);

        $this->assertIsArray($result, 'expected success array, got: ' . (is_wp_error($result) ? $result->get_error_message() : 'unknown'));

        $found = $this->repo->findAnonymousForEmailAndEdition('mia@example.com', $editionId);
        $this->assertNotNull($found, 'waitlist row should be findable by email');
        $this->createdRegistrations[] = (int) $found->id;

        $row = $this->repo->find((int) $found->id);
        $this->assertNotNull($row);

        $waitlist = $row->enrollment_data['waitlist'];
        $this->assertArrayHasKey('submitted_at', $waitlist);
        $this->assertArrayHasKey('submitted_by', $waitlist);
        $this->assertArrayHasKey('data', $waitlist);
        $this->assertNull($waitlist['submitted_by'], 'anonymous waitlist submission stores null actor');
        $this->assertSame('Mia', $waitlist['data']['name']);
        $this->assertSame('mia@example.com', $waitlist['data']['email']);
    }

    public function testIntakeSubmissionPersistsWrappedShapeWithActor(): void
    {
        $editionId = $this->createTestEdition();
        $userId = wp_create_user(
            'wrap_test_' . uniqid(),
            'testpass123',
            'wrap_' . uniqid() . '@test.local'
        );
        $this->assertIsInt($userId);
        wp_set_current_user($userId);

        $regId = $this->repo->create([
            'user_id' => $userId,
            'edition_id' => $editionId,
            'status' => 'confirmed',
        ]);
        $this->assertIsInt($regId);
        $this->createdRegistrations[] = $regId;

        // Trigger via apply_filters so current_filter() returns 'ntdst/api_data/stride_submit_intake'
        $result = apply_filters('ntdst/api_data/stride_submit_intake', null, [
            'edition_id' => $editionId,
            'extra_fields' => ['profession' => 'doctor'],
        ]);

        $this->assertIsArray($result, 'expected success array, got: ' . (is_wp_error($result) ? $result->get_error_message() : 'unknown'));

        $row = $this->repo->find($regId);
        $this->assertNotNull($row);

        $stages = array_intersect_key(
            $row->enrollment_data,
            array_flip(['intake', 'evaluation'])
        );
        $this->assertNotEmpty($stages, 'intake or evaluation stage should be written');
        $stage = reset($stages);
        $this->assertArrayHasKey('submitted_at', $stage);
        $this->assertArrayHasKey('submitted_by', $stage);
        $this->assertArrayHasKey('data', $stage);
        $this->assertSame($userId, $stage['submitted_by']);
        $this->assertSame('doctor', $stage['data']['profession']);

        wp_delete_user($userId);
    }
}
