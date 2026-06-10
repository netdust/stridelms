<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Enrollment\CompletionProofStorage;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Integration tests for the one-off completion-proof storage migration
 * (audit M-2 / task D1, threat-model M1: "existing already-uploaded proofs
 * are migrated by the same task").
 *
 * Contract (version-gated migration pattern per RegistrationTable::migrate):
 *  - Attachments referenced by completion_tasks files move from the public
 *    dated dirs into uploads/stride-proofs/ with _wp_attached_file +
 *    registration meta updated; the original public file is GONE.
 *  - File-count parity: every referenced file is accounted for (moved or
 *    explicitly skipped as missing) — nothing silently dropped.
 *  - Idempotent: forced re-run changes nothing and errors nothing.
 *  - Missing-on-disk files are skipped with meta stamped (nothing public
 *    remains, so they must not block the version stamp forever).
 *
 * Run: ddev exec "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter CompletionProofMigration"
 */
final class CompletionProofMigrationTest extends IntegrationTestCase
{
    private RegistrationRepository $repo;

    /** @var array<int> */
    private array $createdRegistrationIds = [];

    /** @var array<int> */
    private array $createdAttachmentIds = [];

    /** @var array<string> */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = ntdst_get(RegistrationRepository::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdAttachmentIds as $attachmentId) {
            wp_delete_attachment($attachmentId, true);
        }
        $this->createdAttachmentIds = [];

        foreach ($this->createdRegistrationIds as $regId) {
            $this->deleteTestRegistration($regId);
        }
        $this->createdRegistrationIds = [];

        foreach ($this->createdFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        $this->createdFiles = [];

        // Leave the install stamped at the current version regardless of
        // forced re-runs inside tests.
        update_option('stride_proof_storage_version', CompletionProofStorage::VERSION);

        parent::tearDown();
    }

    /** @test */
    public function migrationMovesPublicProofIntoProtectedDirAndStampsMeta(): void
    {
        [$regId, $attachmentId, $publicPath] = $this->seedLegacyPublicProof();

        $protectedDir = trailingslashit((string) wp_upload_dir()['basedir']) . 'stride-proofs';
        $this->assertStringStartsNotWith($protectedDir . '/', $publicPath, 'Fixture must start in the PUBLIC uploads dir');

        $this->forceMigration();

        $newPath = (string) get_attached_file($attachmentId);
        $this->createdFiles[] = $newPath;

        $this->assertStringStartsWith($protectedDir . '/', $newPath, 'Migrated proof must live under stride-proofs/ (M1)');
        $this->assertFileExists($newPath, 'Moved file must exist at the new path');
        $this->assertFileDoesNotExist($publicPath, 'Original public copy must be gone (file-count parity)');
        $this->assertSame(
            file_get_contents($newPath),
            '%PDF-1.4 legacy proof',
            'File content must survive the move byte-identical',
        );
        $this->assertSame(
            $regId,
            (int) get_post_meta($attachmentId, '_stride_proof_registration_id', true),
            'Migration must stamp the registration link meta',
        );
        $this->assertSame(
            CompletionProofStorage::VERSION,
            (int) get_option('stride_proof_storage_version'),
            'Version option must be stamped after a successful run',
        );
    }

    /** @test */
    public function migrationIsIdempotentAcrossForcedReRuns(): void
    {
        [, $attachmentId] = $this->seedLegacyPublicProof();

        $this->forceMigration();
        $pathAfterFirst = (string) get_attached_file($attachmentId);
        $this->createdFiles[] = $pathAfterFirst;
        $this->assertFileExists($pathAfterFirst);

        // Guarded re-run: version stamped → no-op.
        CompletionProofStorage::migrate();
        $this->assertSame($pathAfterFirst, (string) get_attached_file($attachmentId));

        // Forced re-run: already-protected attachments must not move again
        // (no proof-2.pdf duplicates) and must keep their meta.
        $this->forceMigration();

        $this->assertSame(
            $pathAfterFirst,
            (string) get_attached_file($attachmentId),
            'Forced re-run must not relocate an already-protected proof',
        );
        $this->assertFileExists($pathAfterFirst);
        $this->assertSame(
            CompletionProofStorage::VERSION,
            (int) get_option('stride_proof_storage_version'),
        );
    }

    /** @test */
    public function migrationSkipsFilesMissingOnDiskWithoutBlockingTheStamp(): void
    {
        [$regId, $attachmentId, $publicPath] = $this->seedLegacyPublicProof();
        unlink($publicPath);

        $this->forceMigration();

        $this->assertSame(
            CompletionProofStorage::VERSION,
            (int) get_option('stride_proof_storage_version'),
            'A file already gone from disk leaves nothing public — must not block the stamp',
        );
        $this->assertSame(
            $regId,
            (int) get_post_meta($attachmentId, '_stride_proof_registration_id', true),
            'Meta must still be stamped so the handler can authorize future lookups',
        );
    }

    // === Helpers ===

    /**
     * Seed the pre-D1 world: a proof uploaded as a standard PUBLIC attachment
     * (dated uploads dir), referenced by a registration's completion_tasks.
     *
     * @return array{0: int, 1: int, 2: string} [registration id, attachment id, public path]
     */
    private function seedLegacyPublicProof(): array
    {
        $upload = wp_upload_bits('legacy-proof-' . wp_generate_password(8, false) . '.pdf', null, '%PDF-1.4 legacy proof');
        $this->assertEmpty($upload['error'] ?? '', 'Fixture upload failed: ' . (string) ($upload['error'] ?? ''));
        $publicPath = (string) $upload['file'];
        $this->createdFiles[] = $publicPath;

        $attachmentId = (int) wp_insert_attachment(
            ['post_mime_type' => 'application/pdf', 'post_title' => 'Legacy proof', 'post_status' => 'inherit'],
            $publicPath,
        );
        $this->assertGreaterThan(0, $attachmentId);
        $this->createdAttachmentIds[] = $attachmentId;

        $edition = $this->createTestEdition();
        $regId = $this->repo->create([
            'user_id' => self::$testUserId,
            'edition_id' => $edition,
            'status' => 'confirmed',
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
        ]);
        $this->assertIsInt($regId);
        $this->createdRegistrationIds[] = $regId;

        $this->repo->updateCompletionTasks($regId, [
            'documents' => [
                'status' => 'completed',
                'phase' => 'enrollment',
                'data' => ['files' => [$attachmentId]],
            ],
        ]);

        return [$regId, $attachmentId, $publicPath];
    }

    private function forceMigration(): void
    {
        delete_option('stride_proof_storage_version');
        CompletionProofStorage::migrate();
    }
}
