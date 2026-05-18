<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Edition;

use IntegrationTestCase;
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
use ZipArchive;

class EditionZipExportsIntegrationTest extends IntegrationTestCase
{
    private int $userId = 0;
    private int $editionId = 0;
    private int $registrationId = 0;
    private int $attachmentId = 0;
    private string $attachmentPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = wp_create_user('zip_test_user_' . wp_generate_password(6, false), 'pw', 'zip_test_' . wp_generate_password(6, false) . '@example.test');
        if (is_wp_error($this->userId)) {
            throw new \RuntimeException('Failed to create test user: ' . $this->userId->get_error_message());
        }
        wp_update_user(['ID' => $this->userId, 'first_name' => 'Marie', 'last_name' => 'Janssens']);

        $this->editionId = $this->createTestEdition(['post_title' => 'Test ZIP Editie']);

        $uploads = wp_get_upload_dir();
        $this->attachmentPath = trailingslashit($uploads['path']) . 'stride-test-attest.pdf';
        file_put_contents($this->attachmentPath, 'pdf-bytes');
        $this->attachmentId = wp_insert_attachment([
            'post_mime_type' => 'application/pdf',
            'post_title' => 'stride-test-attest',
            'post_status' => 'inherit',
        ], $this->attachmentPath);

        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'vad_registrations', [
            'user_id' => $this->userId,
            'edition_id' => $this->editionId,
            'status' => 'confirmed',
            'enrollment_data' => '{}',
            'completion_tasks' => wp_json_encode([
                'questionnaire' => ['status' => 'completed', 'data' => ['files' => [$this->attachmentId]]],
            ]),
            'registered_at' => current_time('mysql'),
        ]);
        $this->registrationId = (int) $wpdb->insert_id;
    }

    protected function tearDown(): void
    {
        global $wpdb;
        if ($this->registrationId) {
            $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $this->registrationId]);
        }
        if ($this->attachmentId) {
            wp_delete_attachment($this->attachmentId, true);
        }
        if ($this->userId) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($this->userId);
        }
        if ($this->attachmentPath && file_exists($this->attachmentPath)) {
            @unlink($this->attachmentPath);
        }
        parent::tearDown();
    }

    public function testFilesEnumerateReturnsSeededAttachment(): void
    {
        $exporter = new EditionFilesZipExporter(
            ntdst_get(EditionService::class),
            ntdst_get(EditionRepository::class),
            ntdst_get(RegistrationRepository::class),
        );

        $rows = iterator_to_array($exporter->enumerate($this->editionId), false);

        self::assertCount(1, $rows);
        self::assertStringContainsString('janssens-marie-vragenlijst-', $rows[0]['name']);
        self::assertSame($this->attachmentPath, $rows[0]['path']);
    }

    public function testBundleProducesZipWithAllArtefacts(): void
    {
        $filesExporter = new EditionFilesZipExporter(
            ntdst_get(EditionService::class),
            ntdst_get(EditionRepository::class),
            ntdst_get(RegistrationRepository::class),
        );

        $bundle = new EditionBundleZipExporter(
            new EditionRegistrationExporter(
                ntdst_get(EditionService::class),
                ntdst_get(EditionRepository::class),
                ntdst_get(SessionService::class),
                ntdst_get(AttendanceRepository::class),
            ),
            new EditionNamecardExporter(
                ntdst_get(EditionService::class),
                ntdst_get(EditionRepository::class),
            ),
            new EditionAttendanceExporter(
                ntdst_get(EditionService::class),
                ntdst_get(EditionRepository::class),
                ntdst_get(SessionService::class),
            ),
            $filesExporter,
        );

        $zipPath = $bundle->buildToFile($this->editionId);
        self::assertFileExists($zipPath);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($zipPath) === true);

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        self::assertContains('Volledig.xlsx', $names);
        self::assertContains('Naamkaartjes.docx', $names);
        self::assertContains('Presentielijst.docx', $names);
        self::assertNotEmpty(array_filter($names, fn($n) => str_starts_with($n, 'uploads/')));

        @unlink($zipPath);
        $tmpDir = dirname($zipPath);
        if (is_dir($tmpDir)) {
            foreach (glob($tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        }
    }
}
