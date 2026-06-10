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

    /** @test */
    public function migrationRemovesScaledImageOriginalAndBackupSizesFromPublicDir(): void
    {
        // CR-D2: big-image scaling leaves the full-resolution ORIGINAL
        // (metadata original_image) behind in the public dated dir while
        // _wp_attached_file points at the -scaled copy. Image-edit backups
        // (_wp_attachment_backup_sizes) are the same class. Both must be
        // purged by the migration — the original is the most sensitive copy.
        [, $attachmentId, $scaledPath, $originalPath, $backupPath] = $this->seedLegacyScaledImageProof();

        $protectedDir = trailingslashit((string) wp_upload_dir()['basedir']) . 'stride-proofs';

        $this->forceMigration();

        $newPath = (string) get_attached_file($attachmentId);
        $this->createdFiles[] = $newPath;

        $this->assertStringStartsWith($protectedDir . '/', $newPath, 'Scaled main file must be relocated into stride-proofs/');
        $this->assertFileExists($newPath, 'Relocated scaled file must exist at the new path');
        $this->assertFileDoesNotExist($scaledPath, 'Public scaled copy must be gone');
        $this->assertFileDoesNotExist(
            $originalPath,
            'Full-resolution original_image must NOT survive in the public dir (CR-D2)',
        );
        $this->assertFileDoesNotExist(
            $backupPath,
            'Image-edit backup-size file must NOT survive in the public dir (CR-D2)',
        );

        $meta = wp_get_attachment_metadata($attachmentId);
        $this->assertIsArray($meta);
        $this->assertArrayNotHasKey('original_image', $meta, 'original_image meta must be cleared after purge');
        $this->assertSame(
            '',
            (string) get_post_meta($attachmentId, '_wp_attachment_backup_sizes', true),
            '_wp_attachment_backup_sizes meta must be cleared after purge',
        );
        $this->assertSame(
            CompletionProofStorage::VERSION,
            (int) get_option('stride_proof_storage_version'),
            'Version option must be stamped after a successful run',
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

    /**
     * Seed a pre-D1 LARGE image proof: WordPress big-image scaling stored a
     * `-scaled` copy as the attached file and left the full-resolution
     * original behind (metadata `original_image`), plus an image-edit backup
     * (`_wp_attachment_backup_sizes`) — all in the public dated dir.
     *
     * @return array{0: int, 1: int, 2: string, 3: string, 4: string}
     *         [registration id, attachment id, scaled path, original path, backup path]
     */
    private function seedLegacyScaledImageProof(): array
    {
        $token = 'legacy-photo-' . wp_generate_password(8, false);

        $upload = wp_upload_bits($token . '-scaled.jpg', null, 'JPEG scaled copy');
        $this->assertEmpty($upload['error'] ?? '', 'Fixture upload failed: ' . (string) ($upload['error'] ?? ''));
        $scaledPath = (string) $upload['file'];
        $this->createdFiles[] = $scaledPath;

        $dir = dirname($scaledPath);
        // wp_unique_filename() suffixes uploads ending in `-scaled` (-1, -2…)
        // to protect the big-image convention — strip both for the base name.
        $base = preg_replace('/-scaled(-\d+)?\.jpg$/', '', basename($scaledPath));

        $originalPath = $dir . '/' . $base . '.jpg';
        file_put_contents($originalPath, 'JPEG full-resolution original');
        $this->createdFiles[] = $originalPath;

        $backupPath = $dir . '/' . $base . '-e1700000000-768x512.jpg';
        file_put_contents($backupPath, 'JPEG image-edit backup');
        $this->createdFiles[] = $backupPath;

        $attachmentId = (int) wp_insert_attachment(
            ['post_mime_type' => 'image/jpeg', 'post_title' => 'Legacy photo proof', 'post_status' => 'inherit'],
            $scaledPath,
        );
        $this->assertGreaterThan(0, $attachmentId);
        $this->createdAttachmentIds[] = $attachmentId;

        wp_update_attachment_metadata($attachmentId, [
            'file' => _wp_relative_upload_path($scaledPath),
            'width' => 2560,
            'height' => 1707,
            'sizes' => [],
            'original_image' => basename($originalPath),
        ]);
        update_post_meta($attachmentId, '_wp_attachment_backup_sizes', [
            'full-orig' => ['file' => basename($backupPath), 'width' => 768, 'height' => 512],
        ]);

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

        return [$regId, $attachmentId, $scaledPath, $originalPath, $backupPath];
    }

    private function forceMigration(): void
    {
        delete_option('stride_proof_storage_version');
        CompletionProofStorage::migrate();
    }
}
