<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Edition;

use IntegrationTestCase;
use ReflectionMethod;
use Stride\Modules\Attendance\AttendanceRepository;
use Stride\Modules\Edition\Admin\EditionAttendanceExporter;
use Stride\Modules\Edition\Admin\EditionBundleZipExporter;
use Stride\Modules\Edition\Admin\EditionFilesZipExporter;
use Stride\Modules\Edition\Admin\EditionNamecardExporter;
use Stride\Modules\Edition\Admin\EditionRegistrationExporter;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\User\UserLifecycleService;
use ZipArchive;

/**
 * B1 (security review 2026-06-23) — the UNIVERSAL anonymise-skip across all 5
 * Edition exporters.
 *
 * THE HOLE: before Task 2a.10, only EditionFilesZipExporter honoured the
 * `_stride_anonymised_at` GDPR-erasure skip (its :76 inline check). The other
 * four (Registration, Attendance, Namecard, Bundle) had ZERO skip
 * (`grep -c anonymis` = 0). Task 2a.10 newly surfaces all five as one-click
 * roster downloads behind `canManageAdmin`, so 2a OWNS the regression: an admin
 * would egress the PII of GDPR-erased users through four exporters that ignore
 * the flag.
 *
 * THE CONTRACT (load-bearing, RED-first for 4 of 5): for each exporter, a
 * participant with `_stride_anonymised_at` set is EXCLUDED from the participant
 * set the exporter iterates. We assert the EXCLUSION (the participant the
 * generated structure does/does not include), NOT the full XLSX/DOCX byte
 * output (brittle, per the plan's "assert the dispatch + guard + the anonymise
 * exclusion, not the full output").
 *
 * The skip is lifted into ONE shared point (FiltersAnonymisedParticipants) all
 * five exporters honour — never a sixth literal copy — and uses the
 * `UserLifecycleService::isAnonymised()` predicate established in cluster 2a-A
 * (CR-6) rather than re-inlining `_stride_anonymised_at`.
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter EditionExporterAnonymise
 */
final class EditionExporterAnonymiseTest extends IntegrationTestCase
{
    private int $editionId = 0;
    private int $keptUserId = 0;
    private int $anonUserId = 0;
    private int $keptRegId = 0;
    private int $anonRegId = 0;
    private int $attachmentId = 0;
    private string $attachmentPath = '';
    private string $anonEmail = '';

    protected function setUp(): void
    {
        parent::setUp();

        // A normal confirmed participant (must STAY in every export).
        $kept = 'exp_kept_' . wp_generate_password(6, false);
        $this->keptUserId = (int) wp_create_user($kept, 'pw', $kept . '@example.test');
        wp_update_user(['ID' => $this->keptUserId, 'first_name' => 'Kept', 'last_name' => 'Person']);

        // A GDPR-erased participant (must be EXCLUDED from every export).
        $anon = 'exp_anon_' . wp_generate_password(6, false);
        $this->anonEmail = $anon . '@example.test';
        $this->anonUserId = (int) wp_create_user($anon, 'pw', $this->anonEmail);
        wp_update_user(['ID' => $this->anonUserId, 'first_name' => 'Erased', 'last_name' => 'Ghost']);
        update_user_meta($this->anonUserId, UserLifecycleService::META_ANONYMISED_AT, current_time('mysql'));

        $this->editionId = $this->createTestEdition(['post_title' => 'Anonymise Export Editie']);

        // A real uploaded file on the anonymised user so the files/bundle
        // exporters would egress it if the skip did not fire.
        $uploads = wp_get_upload_dir();
        $this->attachmentPath = trailingslashit($uploads['path']) . 'stride-anon-attest.pdf';
        file_put_contents($this->attachmentPath, 'pdf-bytes');
        $this->attachmentId = (int) wp_insert_attachment([
            'post_mime_type' => 'application/pdf',
            'post_title' => 'stride-anon-attest',
            'post_status' => 'inherit',
        ], $this->attachmentPath);

        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'vad_registrations', [
            'user_id' => $this->keptUserId,
            'edition_id' => $this->editionId,
            'status' => 'confirmed',
            'enrollment_data' => '{}',
            'completion_tasks' => '{}',
            'registered_at' => current_time('mysql'),
        ]);
        $this->keptRegId = (int) $wpdb->insert_id;

        $wpdb->insert($wpdb->prefix . 'vad_registrations', [
            'user_id' => $this->anonUserId,
            'edition_id' => $this->editionId,
            'status' => 'confirmed',
            'enrollment_data' => '{}',
            'completion_tasks' => wp_json_encode([
                'questionnaire' => ['status' => 'completed', 'data' => ['files' => [$this->attachmentId]]],
            ]),
            'registered_at' => current_time('mysql'),
        ]);
        $this->anonRegId = (int) $wpdb->insert_id;
    }

    protected function tearDown(): void
    {
        global $wpdb;
        foreach ([$this->keptRegId, $this->anonRegId] as $rid) {
            if ($rid) {
                $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $rid]);
            }
        }
        if ($this->attachmentId) {
            wp_delete_attachment($this->attachmentId, true);
        }
        if ($this->attachmentPath && file_exists($this->attachmentPath)) {
            @unlink($this->attachmentPath);
        }
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ([$this->keptUserId, $this->anonUserId] as $uid) {
            if ($uid) {
                wp_delete_user($uid);
            }
        }
        parent::tearDown();
    }

    /**
     * Invoke a private participant-loader and return the user_ids it yields.
     *
     * @return int[]
     */
    private function loaderUserIds(object $exporter, string $method): array
    {
        $m = new ReflectionMethod($exporter, $method);
        $m->setAccessible(true);
        $rows = $m->invoke($exporter, $this->editionId);

        return array_map(static fn($r) => (int) ($r['user_id'] ?? 0), $rows);
    }

    private function registrationExporter(): EditionRegistrationExporter
    {
        return new EditionRegistrationExporter(
            ntdst_get(EditionService::class),
            ntdst_get(EditionRepository::class),
            ntdst_get(SessionService::class),
            ntdst_get(AttendanceRepository::class),
        );
    }

    private function attendanceExporter(): EditionAttendanceExporter
    {
        return new EditionAttendanceExporter(
            ntdst_get(EditionService::class),
            ntdst_get(EditionRepository::class),
            ntdst_get(SessionService::class),
        );
    }

    private function namecardExporter(): EditionNamecardExporter
    {
        return new EditionNamecardExporter(
            ntdst_get(EditionService::class),
            ntdst_get(EditionRepository::class),
        );
    }

    private function filesExporter(): EditionFilesZipExporter
    {
        return new EditionFilesZipExporter(
            ntdst_get(EditionRepository::class),
            ntdst_get(RegistrationRepository::class),
        );
    }

    // =========================================================================
    // 1. EditionRegistrationExporter (XLSX) — RED before 2a.10
    // =========================================================================

    /** @test */
    public function registrationExporterExcludesAnonymisedParticipant(): void
    {
        $ids = $this->loaderUserIds($this->registrationExporter(), 'getRegistrations');

        $this->assertContains($this->keptUserId, $ids, 'the normal participant must be in the registration export');
        $this->assertNotContains(
            $this->anonUserId,
            $ids,
            'a GDPR-erased participant must be EXCLUDED from the registration (XLSX) export (B1)',
        );
    }

    // =========================================================================
    // 2. EditionAttendanceExporter (DOCX) — RED before 2a.10
    // =========================================================================

    /** @test */
    public function attendanceExporterExcludesAnonymisedParticipant(): void
    {
        $ids = $this->loaderUserIds($this->attendanceExporter(), 'getConfirmedRegistrations');

        $this->assertContains($this->keptUserId, $ids, 'the normal participant must be in the attendance sheet');
        $this->assertNotContains(
            $this->anonUserId,
            $ids,
            'a GDPR-erased participant must be EXCLUDED from the attendance (presentielijst) export (B1)',
        );
    }

    // =========================================================================
    // 3. EditionNamecardExporter (DOCX) — RED before 2a.10
    // =========================================================================

    /** @test */
    public function namecardExporterExcludesAnonymisedParticipant(): void
    {
        $ids = $this->loaderUserIds($this->namecardExporter(), 'getConfirmedRegistrations');

        $this->assertContains($this->keptUserId, $ids, 'the normal participant must get a name card');
        $this->assertNotContains(
            $this->anonUserId,
            $ids,
            'a GDPR-erased participant must be EXCLUDED from the name-card (naamkaartjes) export (B1)',
        );
    }

    // =========================================================================
    // 4. EditionFilesZipExporter (ZIP) — un-mocked seam; was GREEN, must STAY green
    // =========================================================================

    /** @test */
    public function filesExporterExcludesAnonymisedParticipant(): void
    {
        // enumerate() is public and yields one row per uploadable file across
        // the edition's registrations — the REAL, un-mocked participant chain.
        // The anonymised user has an uploaded attachment; the kept user has none.
        $rows = iterator_to_array($this->filesExporter()->enumerate($this->editionId), false);

        $this->assertEmpty(
            $rows,
            'the only uploaded file belongs to the anonymised user — it MUST NOT be enumerated for export (B1, the M8 precedent that already held)',
        );
    }

    // =========================================================================
    // 5. EditionBundleZipExporter (ZIP) — RED before 2a.10 (composes the 4 above)
    // =========================================================================

    /** @test */
    public function bundleExporterExcludesAnonymisedParticipant(): void
    {
        $files = $this->filesExporter();
        $bundle = new EditionBundleZipExporter(
            $this->registrationExporter(),
            $this->namecardExporter(),
            $this->attendanceExporter(),
            $files,
        );

        $zipPath = $bundle->buildToFile($this->editionId);
        $this->assertFileExists($zipPath);

        $extractDir = $zipPath . '-extract';
        wp_mkdir_p($extractDir);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->extractTo($extractDir);
        $zip->close();

        // 1. No uploaded file from the erased user (the files-exporter leg).
        $uploads = array_values(array_filter($names, static fn($n) => str_starts_with((string) $n, 'uploads/')));
        $this->assertEmpty(
            $uploads,
            'the bundle must NOT carry the anonymised user\'s uploaded file (B1 — the only uploader is the erased user)',
        );

        // 2. The composed XLSX (registration-exporter leg) must NOT contain the
        //    erased user's email anywhere — the real bundle leak the uploads-only
        //    check is blind to.
        $xlsxText = $this->dumpXlsxStrings($extractDir . '/Volledig.xlsx');
        $keptUser = get_userdata($this->keptUserId);
        $this->assertStringContainsString(
            (string) ($keptUser->user_email ?? ''),
            $xlsxText,
            'sanity: the kept participant\'s email IS present in the bundle XLSX (so the negative assertion is meaningful)',
        );
        $this->assertStringNotContainsString(
            $this->anonEmail,
            $xlsxText,
            'the bundle\'s composed XLSX must NOT egress the anonymised participant\'s email (B1 — fixing the components fixes the bundle)',
        );

        // Cleanup
        foreach (glob($extractDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($extractDir);
        @unlink($zipPath);
        $tmpDir = dirname($zipPath);
        if (is_dir($tmpDir)) {
            foreach (glob($tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        }
    }

    /**
     * Concatenate every shared-string + inline-string cell value in an XLSX.
     *
     * We only need a substring search for an email, so reading the raw
     * sharedStrings + sheet XML out of the (already-extracted) workbook is
     * enough — no need to parse cells positionally.
     */
    private function dumpXlsxStrings(string $xlsxPath): string
    {
        if (!file_exists($xlsxPath)) {
            return '';
        }
        $zip = new ZipArchive();
        if ($zip->open($xlsxPath) !== true) {
            return '';
        }
        $text = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_ends_with($name, '.xml')) {
                $text .= (string) $zip->getFromIndex($i);
            }
        }
        $zip->close();

        return $text;
    }
}
