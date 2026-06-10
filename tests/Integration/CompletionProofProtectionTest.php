<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Handlers\CompletionTaskHandler;
use Stride\Modules\Enrollment\CompletionProofStorage;
use Stride\Modules\Enrollment\RegistrationRepository;
use WP_Error;

/**
 * Integration tests for protected completion-proof storage + authenticated
 * download (audit M-2 / task D1). Threat-model M1–M4 + M9 are the spec.
 *
 * Seam: the `ntdst/api_data/stride_download_proof` filter is exercised through
 * the REAL mounted chain (the handler instance constructed by EnrollmentService)
 * via apply_filters — no mocks. The owner happy path asserts through
 * resolveProofDownload() because the full filter ends in
 * ntdst_response()->download() which exits the process; the M4 headers are
 * unit-asserted on NTDST_Response::fileHeaders (ResponseTest).
 *
 * The M1 acceptance ("unauthenticated fetch of direct URL → 403/404") is
 * asserted for REAL over HTTP: DDEV's nginx config is taken over
 * (.ddev/nginx_full/nginx-site.conf) and carries the same
 * `location ^~ /app/uploads/stride-proofs/ { deny all; }` rule production
 * (Ploi nginx) must carry — see site.yml notes. The bundled .htaccess remains
 * Apache-only defense-in-depth.
 *
 * Run: ddev exec "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter CompletionProofProtection"
 */
final class CompletionProofProtectionTest extends IntegrationTestCase
{
    private RegistrationRepository $repo;

    /** @var array<int> */
    private array $createdRegistrationIds = [];

    /** @var array<int> */
    private array $createdAttachmentIds = [];

    /** @var array<int> */
    private array $createdUserIds = [];

    /** @var array<string> */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = ntdst_get(RegistrationRepository::class);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        foreach ($this->createdAttachmentIds as $attachmentId) {
            wp_delete_attachment($attachmentId, true);
        }
        $this->createdAttachmentIds = [];

        foreach ($this->createdRegistrationIds as $regId) {
            $this->deleteTestRegistration($regId);
        }
        $this->createdRegistrationIds = [];

        foreach ($this->createdUserIds as $userId) {
            wp_delete_user($userId);
        }
        $this->createdUserIds = [];

        foreach ($this->createdFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        $this->createdFiles = [];

        parent::tearDown();
    }

    // -------------------------------------------------------------------
    // M1 — upload lands in the protected dir, never the public library
    // -------------------------------------------------------------------

    /** @test */
    public function uploadedProofLandsInProtectedDirNotPublicUploads(): void
    {
        $regId = $this->createRegistrationWithDocumentsTask();
        $this->actingAs(self::$testUserId);

        $tmp = (string) tempnam(sys_get_temp_dir(), 'proof');
        file_put_contents($tmp, "%PDF-1.4\n%fake stride test proof\n");
        $this->createdFiles[] = $tmp;

        $result = apply_filters('ntdst/api_data/stride_upload_completion_documents', [], [
            'registration_id' => $regId,
            'task_type' => 'documents',
            '_files' => [
                'documents' => [
                    'name' => 'certificate.pdf',
                    'type' => 'application/pdf',
                    'tmp_name' => $tmp,
                    'error' => 0,
                    'size' => (int) filesize($tmp),
                ],
            ],
        ]);

        $this->assertFalse(
            is_wp_error($result),
            'Upload must succeed — got: ' . (is_wp_error($result) ? $result->get_error_message() : ''),
        );
        $attachmentId = (int) ($result['attachment_ids'][0] ?? 0);
        $this->assertGreaterThan(0, $attachmentId, 'Upload must return the new attachment id');
        $this->createdAttachmentIds[] = $attachmentId;

        $path = (string) get_attached_file($attachmentId);
        $protectedDir = trailingslashit((string) wp_upload_dir()['basedir']) . 'stride-proofs';

        $this->assertStringStartsWith(
            $protectedDir . '/',
            $path,
            "Proof must be stored under uploads/stride-proofs/ (M1) — stored at: {$path}",
        );
        $this->assertFileExists($path);
        $this->assertFileExists(
            $protectedDir . '/.htaccess',
            'Protected dir must carry the deny .htaccess (M1 defense-in-depth)',
        );
        $this->assertSame(
            $regId,
            (int) get_post_meta($attachmentId, '_stride_proof_registration_id', true),
            'Attachment must be stamped with its registration id (M3 resolution anchor)',
        );
        $this->assertSame(
            1,
            (int) get_post_meta($attachmentId, '_stride_protected_proof', true),
            'Attachment must carry the protected-proof marker meta (M1)',
        );
    }

    // -------------------------------------------------------------------
    // M1 — the web server actually denies the direct URL (CR-D1).
    // Real un-mocked HTTP against the running nginx: this is the assertion
    // the threat model names ("unauthenticated fetch of direct URL → 403/404").
    // -------------------------------------------------------------------

    /** @test */
    public function unauthenticatedHttpFetchOfProofDirectUrlIsDeniedByWebServer(): void
    {
        $regId = $this->createRegistrationWithDocumentsTask();
        $this->actingAs(self::$testUserId);

        $tmp = (string) tempnam(sys_get_temp_dir(), 'proof');
        file_put_contents($tmp, "%PDF-1.4\n%fake stride test proof\n");
        $this->createdFiles[] = $tmp;

        $result = apply_filters('ntdst/api_data/stride_upload_completion_documents', [], [
            'registration_id' => $regId,
            'task_type' => 'documents',
            '_files' => [
                'documents' => [
                    'name' => 'certificate.pdf',
                    'type' => 'application/pdf',
                    'tmp_name' => $tmp,
                    'error' => 0,
                    'size' => (int) filesize($tmp),
                ],
            ],
        ]);

        $this->assertFalse(
            is_wp_error($result),
            'Upload must succeed — got: ' . (is_wp_error($result) ? $result->get_error_message() : ''),
        );
        $attachmentId = (int) ($result['attachment_ids'][0] ?? 0);
        $this->assertGreaterThan(0, $attachmentId);
        $this->createdAttachmentIds[] = $attachmentId;

        // Positive control FIRST: a normal public uploads file must serve 200,
        // proving HTTP from this test reaches the real web server (and that
        // the deny rule did not break regular uploads).
        $control = wp_upload_bits('nginx-control-' . wp_generate_password(6, false) . '.txt', null, 'public control');
        $this->assertEmpty($control['error'] ?? '', 'Control upload must succeed');
        $this->createdFiles[] = $control['file'];

        $controlResponse = wp_remote_get($control['url'], ['sslverify' => false, 'redirection' => 0]);
        $this->assertFalse(is_wp_error($controlResponse), 'HTTP to own site must work for this assertion to mean anything');
        $this->assertSame(
            200,
            wp_remote_retrieve_response_code($controlResponse),
            'Public uploads must still serve 200 — the deny rule must not over-match',
        );

        // The actual M1 acceptance: unauthenticated (cookie-less) fetch of the
        // proof's direct URL must be denied by the web server itself.
        $proofUrl = (string) wp_get_attachment_url($attachmentId);
        $this->assertStringContainsString('/stride-proofs/', $proofUrl, 'Sanity: the URL under test is the protected path');

        $proofResponse = wp_remote_get($proofUrl, ['sslverify' => false, 'redirection' => 0]);
        $this->assertFalse(is_wp_error($proofResponse), 'HTTP request itself must not error');
        $this->assertContains(
            wp_remote_retrieve_response_code($proofResponse),
            [403, 404],
            "Unauthenticated direct URL of a proof must be denied by nginx (M1) — got HTTP "
                . wp_remote_retrieve_response_code($proofResponse) . " for {$proofUrl}",
        );
        $this->assertStringNotContainsString(
            '%PDF',
            (string) wp_remote_retrieve_body($proofResponse),
            'Proof bytes must never leak on the direct URL',
        );
    }

    // -------------------------------------------------------------------
    // M1 / attack 1 ("or attachment page") — existence, filename and count
    // must not be enumerable by anonymous visitors (panel SF-1 + NTH-3).
    // Bytes were already protected (nginx 403); these pin the METADATA leak:
    // proofs carried post_status 'inherit', so anonymous /wp/v2/media listed
    // them (source_url + user-chosen filename, which can carry PII) and the
    // attachment permalink served a 200 page.
    // -------------------------------------------------------------------

    /** @test */
    public function anonymousRestMediaQueryDoesNotListProofs(): void
    {
        $regId = $this->createRegistrationWithDocumentsTask();
        [$attachmentId] = $this->createProtectedProofAttachment($regId);

        wp_set_current_user(0);

        $request = new \WP_REST_Request('GET', '/wp/v2/media');
        $request->set_param('per_page', 100);
        $response = rest_do_request($request);

        $this->assertSame(200, $response->get_status(), 'Anonymous media listing itself must keep working');
        $listedIds = array_column((array) $response->get_data(), 'id');
        $this->assertNotContains(
            $attachmentId,
            $listedIds,
            'Proof attachment is enumerable via anonymous /wp/v2/media — '
                . 'source_url (protected path) + user-chosen filename leak (SF-1/NTH-3)',
        );
    }

    /** @test */
    public function attachmentPermalinkIsNotServedToAnonymousVisitors(): void
    {
        $regId = $this->createRegistrationWithDocumentsTask();
        [$attachmentId] = $this->createProtectedProofAttachment($regId);

        $permalink = get_permalink($attachmentId);
        $this->assertNotFalse($permalink, 'Sanity: attachment must have a permalink to probe');

        // Cookie-less request = logged-out visitor, real HTTP (same transport
        // as the nginx deny assertion above).
        $response = wp_remote_get((string) $permalink, ['sslverify' => false, 'redirection' => 0]);
        $this->assertFalse(is_wp_error($response), 'HTTP request itself must not error');
        $this->assertNotSame(
            200,
            wp_remote_retrieve_response_code($response),
            "Anonymous fetch of a proof's attachment permalink must 404/redirect, not render a page "
                . "(threat-model attack 1 names the attachment page) — URL: {$permalink}",
        );
    }

    /** @test */
    public function migrationRestampsExistingProofsToPrivate(): void
    {
        $regId = $this->createRegistrationWithDocumentsTask();
        [$attachmentId] = $this->createProtectedProofAttachment($regId);

        // Simulate a pre-v2 install: proof already in the protected dir
        // (storage v1 ran) but still publicly enumerable via 'inherit'.
        wp_update_post(['ID' => $attachmentId, 'post_status' => 'inherit']);
        $this->repo->updateCompletionTasks($regId, [
            'documents' => ['status' => 'completed', 'data' => ['files' => [$attachmentId]]],
        ]);
        update_option('stride_proof_storage_version', CompletionProofStorage::VERSION - 1);
        delete_transient('stride_proof_migration_backoff');

        CompletionProofStorage::migrate();

        $this->assertSame(
            'private',
            get_post_status($attachmentId),
            'Version-gated migration must re-stamp pre-existing proofs to post_status private',
        );
        $this->assertSame(
            CompletionProofStorage::VERSION,
            (int) get_option('stride_proof_storage_version'),
            'Migration must stamp the storage version after a clean run',
        );
    }

    // -------------------------------------------------------------------
    // M9 — the download surface is the framework api_data filter
    // -------------------------------------------------------------------

    /** @test */
    public function downloadFilterIsMountedOnTheFrameworkChain(): void
    {
        $this->assertNotFalse(
            has_filter('ntdst/api_data/stride_download_proof'),
            'stride_download_proof must be mounted as an ntdst/api_data filter (M9 / INV-2)',
        );
    }

    // -------------------------------------------------------------------
    // M2 — denial paths, through the REAL mounted filter chain (seam)
    // -------------------------------------------------------------------

    /** @test */
    public function authenticatedNonOwnerIsDeniedThroughTheMountedFilter(): void
    {
        $regId = $this->createRegistrationWithDocumentsTask();
        [$attachmentId] = $this->createProtectedProofAttachment($regId);

        $intruder = $this->createSecondaryUser('subscriber');
        $this->actingAs($intruder);

        $result = apply_filters('ntdst/api_data/stride_download_proof', [], [
            'attachment_id' => $attachmentId,
        ]);

        $this->assertInstanceOf(WP_Error::class, $result, 'Non-owner must get WP_Error, never bytes (M2)');
        $this->assertSame('forbidden', $result->get_error_code());
    }

    /** @test */
    public function loggedOutRequestIsDenied(): void
    {
        $regId = $this->createRegistrationWithDocumentsTask();
        [$attachmentId] = $this->createProtectedProofAttachment($regId);

        wp_set_current_user(0);

        $result = apply_filters('ntdst/api_data/stride_download_proof', [], [
            'attachment_id' => $attachmentId,
        ]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('not_logged_in', $result->get_error_code());
    }

    /** @test */
    public function arbitraryAttachmentIdWithoutProofMetaIsDenied(): void
    {
        // Adversarial id iteration: a normal media-library attachment must
        // never be servable through the proof handler.
        $upload = wp_upload_bits('not-a-proof-' . wp_generate_password(6, false) . '.pdf', null, '%PDF-1.4 public');
        $this->assertEmpty($upload['error'] ?? '');
        $this->createdFiles[] = $upload['file'];

        $attachmentId = (int) wp_insert_attachment(
            ['post_mime_type' => 'application/pdf', 'post_title' => 'Public file', 'post_status' => 'inherit'],
            $upload['file'],
        );
        $this->createdAttachmentIds[] = $attachmentId;

        $this->actingAs(self::$testUserId);

        $result = apply_filters('ntdst/api_data/stride_download_proof', [], [
            'attachment_id' => $attachmentId,
        ]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('forbidden', $result->get_error_code());
    }

    // -------------------------------------------------------------------
    // M3 — resolved path must live inside the protected dir
    // -------------------------------------------------------------------

    /** @test */
    public function proofWhoseFileResolvesOutsideProtectedDirIsDenied(): void
    {
        $regId = $this->createRegistrationWithDocumentsTask();
        [$attachmentId] = $this->createProtectedProofAttachment($regId);

        // Path-traversal class: attachment metadata repointed outside the
        // protected dir (e.g. a crafted _wp_attached_file) must be rejected
        // even for the legitimate owner.
        $outside = trailingslashit((string) wp_upload_dir()['basedir']) . 'outside-' . wp_generate_password(6, false) . '.pdf';
        file_put_contents($outside, '%PDF-1.4 outside');
        $this->createdFiles[] = $outside;
        update_attached_file($attachmentId, $outside);

        $this->actingAs(self::$testUserId);

        $result = apply_filters('ntdst/api_data/stride_download_proof', [], [
            'attachment_id' => $attachmentId,
        ]);

        $this->assertInstanceOf(WP_Error::class, $result, 'File outside stride-proofs/ must never be served (M3)');
        $this->assertSame('forbidden', $result->get_error_code());
    }

    // -------------------------------------------------------------------
    // M2/M4 — owner + stride_manage happy paths (resolve step; the byte/
    // header emission exits the process and is unit-covered in ResponseTest)
    // -------------------------------------------------------------------

    /** @test */
    public function ownerResolvesTheirOwnProofToPathFilenameAndStoredMime(): void
    {
        $regId = $this->createRegistrationWithDocumentsTask();
        [$attachmentId, $path] = $this->createProtectedProofAttachment($regId);

        $this->actingAs(self::$testUserId);

        $result = $this->detachedHandler()->resolveProofDownload($attachmentId);

        $this->assertFalse(
            is_wp_error($result),
            'Owner must resolve their own proof — got: ' . (is_wp_error($result) ? $result->get_error_code() : ''),
        );
        $this->assertSame($path, $result['path']);
        $this->assertSame(basename($path), $result['filename']);
        $this->assertSame('application/pdf', $result['mime'], 'MIME must come from the stored validated type (M4)');
        $this->assertSame('%PDF-1.4 stride proof fixture', file_get_contents($result['path']));
    }

    /** @test */
    public function adminWithStrideManageResolvesAnotherUsersProof(): void
    {
        $regId = $this->createRegistrationWithDocumentsTask();
        [$attachmentId] = $this->createProtectedProofAttachment($regId);

        $admin = $this->createSecondaryUser('administrator');
        $this->actingAs($admin);
        $this->assertTrue(current_user_can('stride_manage'), 'Fixture admin must carry stride_manage');

        $result = $this->detachedHandler()->resolveProofDownload($attachmentId);

        $this->assertFalse(is_wp_error($result), 'stride_manage must be able to resolve any proof (M2)');
    }

    /** @test */
    public function fileMissingOnDiskYieldsCleanErrorNotBytes(): void
    {
        $regId = $this->createRegistrationWithDocumentsTask();
        [$attachmentId, $path] = $this->createProtectedProofAttachment($regId);
        unlink($path);

        $this->actingAs(self::$testUserId);

        $result = $this->detachedHandler()->resolveProofDownload($attachmentId);

        $this->assertInstanceOf(WP_Error::class, $result, 'Missing file must be a clean WP_Error (AF-1 mid-flow edge)');
        $this->assertSame('proof_unavailable', $result->get_error_code());
    }

    // === Helpers ===

    private function createRegistrationWithDocumentsTask(): int
    {
        $edition = $this->createTestEdition();

        $regId = $this->repo->create([
            'user_id' => self::$testUserId,
            'edition_id' => $edition,
            'status' => 'pending',
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
        ]);

        $this->assertIsInt($regId, 'Registration fixture must be created');
        $this->createdRegistrationIds[] = $regId;

        $this->repo->updateCompletionTasks($regId, [
            'documents' => ['status' => 'pending', 'phase' => 'enrollment'],
        ]);

        return $regId;
    }

    /**
     * Seed a proof attachment directly into the protected dir + meta,
     * bypassing the upload path (which has its own test).
     *
     * @return array{0: int, 1: string} [attachment id, absolute path]
     */
    private function createProtectedProofAttachment(int $registrationId): array
    {
        CompletionProofStorage::ensureProtectedDir();
        $dir = CompletionProofStorage::getProtectedDir();

        $filename = wp_unique_filename($dir, 'proof-' . wp_generate_password(8, false) . '.pdf');
        $path = trailingslashit($dir) . $filename;
        file_put_contents($path, '%PDF-1.4 stride proof fixture');
        $this->createdFiles[] = $path;

        $attachmentId = (int) wp_insert_attachment(
            ['post_mime_type' => 'application/pdf', 'post_title' => 'Test proof', 'post_status' => 'inherit'],
            $path,
        );
        $this->assertGreaterThan(0, $attachmentId);
        $this->createdAttachmentIds[] = $attachmentId;

        CompletionProofStorage::markProtected($attachmentId, $registrationId);

        return [$attachmentId, $path];
    }

    private function createSecondaryUser(string $role): int
    {
        $suffix = wp_generate_password(8, false);
        $userId = wp_create_user("proof_test_{$suffix}", 'testpass123', "proof_{$suffix}@test.local");
        $this->assertIsInt($userId);
        $this->createdUserIds[] = $userId;

        $user = get_user_by('id', $userId);
        $user->set_role($role);

        return $userId;
    }

    /**
     * A handler instance for direct method calls, with its hooks removed so
     * the real mounted instance (constructed by EnrollmentService) stays the
     * only one on the filter chain.
     */
    private function detachedHandler(): CompletionTaskHandler
    {
        $handler = new CompletionTaskHandler();
        remove_filter('ntdst/api_data/stride_complete_task', [$handler, 'handleCompleteTask'], 10);
        remove_filter('ntdst/api_data/stride_upload_completion_documents', [$handler, 'handleUploadDocuments'], 10);
        remove_filter('ntdst/api_data/stride_download_proof', [$handler, 'handleDownloadProof'], 10);
        remove_action('stride/enrollment/task_completed', [$handler, 'onTaskCompleted'], 10);

        return $handler;
    }
}
