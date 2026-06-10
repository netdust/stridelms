<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Admin;

use IntegrationTestCase;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Regression for audit H-1 (dead column, CSV export).
 *
 * AdminAPIController::exportRegistrations() ordered by `r.created_at`, but
 * the column on wp_vad_registrations is `registered_at` (RegistrationTable).
 * The DB error emptied the result set, so the export silently produced a CSV
 * with a header row and zero data rows.
 *
 * Contract: a confirmed registration for an upcoming edition MUST appear as
 * a data row in the export. The formula-injection neutralisation
 * (sanitizeCsvCell) MUST stay applied to user-controlled cells.
 *
 * exportRegistrations() writes to php://output and calls exit, so the test
 * drives it in a child PHP process and captures stdout. The route's
 * permission denial path (canManageAdmin) is owned by AF-5's acceptance
 * touch, not this test.
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationExportTest
 */
final class RegistrationExportTest extends IntegrationTestCase
{
    private RegistrationRepository $registrations;

    /** @var list<int> */
    private array $testRegistrationIds = [];

    private ?string $runnerPath = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registrations = ntdst_get(RegistrationRepository::class);
    }

    protected function tearDown(): void
    {
        global $wpdb;
        foreach ($this->testRegistrationIds as $regId) {
            $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $regId]);
        }
        $this->testRegistrationIds = [];

        if ($this->runnerPath && file_exists($this->runnerPath)) {
            unlink($this->runnerPath);
        }
        $this->runnerPath = null;

        parent::tearDown();
    }

    public function testExportContainsConfirmedRegistrationRow(): void
    {
        // Upcoming edition so it passes the start_date >= today filter.
        $editionId = $this->createTestEdition([
            'meta' => ['_ntdst_start_date' => wp_date('Y-m-d', strtotime('+30 days'))],
        ]);

        // Adversarial cell: a display_name starting with "=" must come out
        // of the export neutralised by sanitizeCsvCell (WP-security gate).
        wp_update_user(['ID' => self::$testUserId, 'display_name' => '=2+5']);

        $regId = $this->registrations->create([
            'user_id' => self::$testUserId,
            'edition_id' => $editionId,
            'status' => 'confirmed',
        ]);
        $this->assertIsInt($regId, 'Failed to create confirmed registration: ' . wp_json_encode($regId));
        $this->testRegistrationIds[] = $regId;

        $output = $this->runExportInChildProcess();

        $this->assertStringContainsString(
            'Naam;E-mail;Organisatie',
            $output,
            'CSV header row missing — export did not run. Output: ' . $this->snippet($output)
        );

        $user = get_userdata(self::$testUserId);
        $this->assertNotFalse($user);

        // ≥1 data row: the confirmed registration's user email must be in the
        // body. If the export query errored on a dead column, the child
        // process echoes the wpdb error (show_errors), so a failure here is
        // attributable in the captured output below.
        $this->assertStringContainsString(
            $user->user_email,
            $output,
            'Expected a CSV data row for confirmed registration ' . $regId
            . ' — export body is empty or the row is missing. Output: ' . $this->snippet($output)
        );

        // Negative/adversarial: the formula payload is prefixed with a quote.
        $this->assertStringContainsString(
            "'=2+5",
            $output,
            'Formula-injection payload not neutralised — sanitizeCsvCell missing from export. Output: '
            . $this->snippet($output)
        );
    }

    /**
     * exportRegistrations() echoes CSV and exits, which would kill the
     * PHPUnit process — so run it in a child PHP process against the same
     * (committed) database state and capture stdout.
     */
    private function runExportInChildProcess(): string
    {
        $projectRoot = dirname(__DIR__, 3);

        $runner = <<<'RUNNER'
<?php

declare(strict_types=1);

require $argv[1] . '/web/wp/wp-load.php';

global $wpdb;
// Echo DB errors into stdout so a failing assertion is attributable to the
// real cause (e.g. an unknown-column error) instead of a silent empty body.
$wpdb->show_errors();

$controller = new \Stride\Admin\AdminAPIController(
    ntdst_get(\Stride\Modules\Attendance\AttendanceRepository::class),
    ntdst_get(\Stride\Modules\Edition\EditionRepository::class),
    ntdst_get(\Stride\Modules\Edition\SessionRepository::class),
);

$controller->exportRegistrations(new \WP_REST_Request('GET', '/stride/v1/admin/export/registrations'));
RUNNER;

        $this->runnerPath = sys_get_temp_dir() . '/stride-export-runner-' . getmypid() . '.php';
        file_put_contents($this->runnerPath, $runner);

        $cmd = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($this->runnerPath)
            . ' ' . escapeshellarg($projectRoot)
            . ' 2>&1';

        $output = shell_exec($cmd);

        $this->assertIsString($output, 'Child export process produced no output');
        $this->assertNotSame('', trim($output), 'Child export process produced empty output');

        return $output;
    }

    private function snippet(string $output): string
    {
        return strlen($output) > 2000 ? substr($output, 0, 2000) . '… [truncated]' : $output;
    }
}
