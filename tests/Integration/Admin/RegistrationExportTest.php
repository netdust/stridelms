<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Admin;

use IntegrationTestCase;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Regression for audit H-1 (dead column, CSV export) + audit 2.6 (N+1 batch).
 *
 * H-1: AdminAPIController::exportRegistrations() ordered by `r.created_at`,
 * but the column on wp_vad_registrations is `registered_at`
 * (RegistrationTable). The DB error emptied the result set, so the export
 * silently produced a CSV with a header row and zero data rows.
 *
 * 2.6: the export row loop did a per-row get_userdata() + get_user_meta()
 * + quote get_var() — ~3 queries per registration. The batched rewrite must
 * keep the query count INDEPENDENT of row count while producing output
 * byte-identical to the per-row version for the same fixtures (the row
 * format characterization below pins that contract).
 *
 * Contract: a confirmed registration for an upcoming edition MUST appear as
 * a data row in the export. The formula-injection neutralisation
 * (sanitizeCsvCell) MUST stay applied to user-controlled cells. A
 * registration whose user was deleted MUST render with blank user cells
 * ('Onbekend' name, empty email/organisation) without aborting the stream
 * (AF-5 mid-flow edge).
 *
 * exportRegistrations() writes to php://output and calls exit, so the test
 * drives it in a child PHP process and captures stdout. A shutdown function
 * in the child (which runs after the method's exit) appends a
 * `#STRIDE_EXPORT_QUERY_DELTA=<n>#` marker with the $wpdb->num_queries
 * delta around the export call, so query-count assertions survive the exit.
 * The route's permission denial path (canManageAdmin) is owned by AF-5's
 * acceptance touch, not this test.
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationExportTest
 */
final class RegistrationExportTest extends IntegrationTestCase
{
    /**
     * Allowed growth in export query count when row count grows 5 → 50.
     * Batched lookups are one query each regardless of N, so the only
     * variance is constant per-request noise (shutdown hooks etc.).
     */
    private const QUERY_GROWTH_BUDGET = 8;

    private RegistrationRepository $registrations;

    /** @var list<int> */
    private array $testRegistrationIds = [];

    /** @var list<int> */
    private array $testUserIds = [];

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

        if ($this->testUserIds !== []) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            foreach ($this->testUserIds as $userId) {
                if (get_userdata($userId) !== false) {
                    wp_delete_user($userId);
                }
            }
            $this->testUserIds = [];
        }

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
     * Audit 2.6 contract: the export's query count must not scale with the
     * number of exported rows. Per-row get_userdata/get_user_meta/quote
     * get_var cost ~3 queries each, so +45 rows used to add ~135 queries.
     * After batching, growth must stay within a small constant budget.
     */
    public function testExportQueryCountIsIndependentOfRowCount(): void
    {
        $editionId = $this->createTestEdition([
            'meta' => ['_ntdst_start_date' => wp_date('Y-m-d', strtotime('+30 days'))],
        ]);

        $this->seedExportRows($editionId, 5);
        $deltaSmall = $this->extractQueryDelta($this->runExportInChildProcess());

        $this->seedExportRows($editionId, 45); // 50 total
        $deltaLarge = $this->extractQueryDelta($this->runExportInChildProcess());

        $growth = $deltaLarge - $deltaSmall;
        $this->assertLessThanOrEqual(
            self::QUERY_GROWTH_BUDGET,
            $growth,
            sprintf(
                'Export query count must be independent of row count: +45 rows grew the '
                . 'query count by %d (N=5 run: %d queries, N=50 run: %d queries). '
                . 'Per-row lookups (~3/row) are back inside the export loop.',
                $growth,
                $deltaSmall,
                $deltaLarge,
            )
        );
    }

    /**
     * Characterization of the exact row format (byte-identity guard for the
     * batch rewrite): name;email;organisation;edition;date;status;quote.
     * Covers both quote-number shapes: the stored quote_number meta and the
     * 'Q-<post id>' fallback when the meta is empty.
     */
    public function testExportRowFormatIsCharacterized(): void
    {
        $suffix = wp_generate_password(6, false, false);
        $startDate = wp_date('Y-m-d', strtotime('+21 days'));
        $editionId = $this->createTestEdition([
            'post_title' => 'CharEdition' . $suffix,
            'meta' => ['_ntdst_start_date' => $startDate],
        ]);

        // Row 1: full data, explicit quote number.
        $userId = $this->createExportUser('char1_' . $suffix, 'CharOrg ' . $suffix);
        wp_update_user(['ID' => $userId, 'display_name' => 'Char User ' . $suffix]);
        $regId = $this->createConfirmedRegistration($userId, $editionId);
        $this->createTestQuote($userId, $editionId, [
            'meta' => [
                'registration_id' => $regId,
                'quote_number' => 'OFF-CHAR-' . $suffix,
            ],
        ]);

        // Row 2: quote with EMPTY quote_number meta → 'Q-<post id>' fallback.
        $userId2 = $this->createExportUser('char2_' . $suffix, 'CharOrg2 ' . $suffix);
        wp_update_user(['ID' => $userId2, 'display_name' => 'Char Twee ' . $suffix]);
        $regId2 = $this->createConfirmedRegistration($userId2, $editionId);
        $quoteId2 = $this->createTestQuote($userId2, $editionId, [
            'meta' => [
                'registration_id' => $regId2,
                'quote_number' => '',
            ],
        ]);

        $output = $this->runExportInChildProcess();

        $user = get_userdata($userId);
        $this->assertNotFalse($user);
        $expectedRow = $this->csvLine([
            'Char User ' . $suffix,
            $user->user_email,
            'CharOrg ' . $suffix,
            'CharEdition' . $suffix,
            $startDate,
            'confirmed',
            'OFF-CHAR-' . $suffix,
        ]);
        $this->assertStringContainsString(
            $expectedRow,
            $output,
            'Export row format drifted from the characterized '
            . 'name;email;org;edition;date;status;quote layout. Output: ' . $this->snippet($output)
        );

        $user2 = get_userdata($userId2);
        $this->assertNotFalse($user2);
        $expectedRow2 = $this->csvLine([
            'Char Twee ' . $suffix,
            $user2->user_email,
            'CharOrg2 ' . $suffix,
            'CharEdition' . $suffix,
            $startDate,
            'confirmed',
            'Q-' . $quoteId2,
        ]);
        $this->assertStringContainsString(
            $expectedRow2,
            $output,
            'Empty quote_number meta must fall back to Q-<post id>. Output: ' . $this->snippet($output)
        );
    }

    /**
     * CR-E3 pin: when TWO published quotes link to one registration —
     * production CAN produce this (open M2 trajectory quote race) —
     * findQuoteIdsByRegistrations' MIN(p.ID) must deterministically export
     * the lower-ID quote's number. Not RED-first: the behavior is already
     * correct; this characterizes the only case where MIN(p.ID) differs
     * from an arbitrary LIMIT 1 so the determinism cannot silently regress.
     */
    public function testDuplicateQuotesOnOneRegistrationExportLowerIdQuoteNumber(): void
    {
        $suffix = wp_generate_password(6, false, false);
        $startDate = wp_date('Y-m-d', strtotime('+10 days'));
        $editionId = $this->createTestEdition([
            'post_title' => 'DupeEdition' . $suffix,
            'meta' => ['_ntdst_start_date' => $startDate],
        ]);

        $userId = $this->createExportUser('dupe_' . $suffix, 'DupeOrg ' . $suffix);
        wp_update_user(['ID' => $userId, 'display_name' => 'Dupe User ' . $suffix]);
        $regId = $this->createConfirmedRegistration($userId, $editionId);

        // Two published quotes on ONE registration; auto-increment makes the
        // first the lower post ID.
        $lowerQuoteId = $this->createTestQuote($userId, $editionId, [
            'meta' => ['registration_id' => $regId, 'quote_number' => 'OFF-DUPE-LOW-' . $suffix],
        ]);
        $higherQuoteId = $this->createTestQuote($userId, $editionId, [
            'meta' => ['registration_id' => $regId, 'quote_number' => 'OFF-DUPE-HIGH-' . $suffix],
        ]);
        $this->assertLessThan($higherQuoteId, $lowerQuoteId, 'Fixture assumption: first quote has the lower post ID');

        $output = $this->runExportInChildProcess();

        $user = get_userdata($userId);
        $this->assertNotFalse($user);
        $this->assertStringContainsString(
            $this->csvLine([
                'Dupe User ' . $suffix,
                $user->user_email,
                'DupeOrg ' . $suffix,
                'DupeEdition' . $suffix,
                $startDate,
                'confirmed',
                'OFF-DUPE-LOW-' . $suffix,
            ]),
            $output,
            'Two quotes on one registration must deterministically export the lower-ID quote. Output: '
            . $this->snippet($output)
        );
        $this->assertStringNotContainsString(
            'OFF-DUPE-HIGH-' . $suffix,
            $output,
            'The higher-ID duplicate quote leaked into the export — MIN(p.ID) determinism broken. Output: '
            . $this->snippet($output)
        );
    }

    /**
     * AF-5 mid-flow edge: a registration whose user was deleted yields blank
     * user cells ('Onbekend' name, empty email/organisation) and the export
     * stream continues — subsequent rows still render.
     */
    public function testDeletedUserYieldsBlankCellsAndStreamContinues(): void
    {
        $suffix = wp_generate_password(6, false, false);
        $startDate = wp_date('Y-m-d', strtotime('+14 days'));
        $editionId = $this->createTestEdition([
            'post_title' => 'GhostEdition' . $suffix,
            'meta' => ['_ntdst_start_date' => $startDate],
        ]);

        $ghostId = $this->createExportUser('ghost_' . $suffix, 'Ghost Org');
        $this->createConfirmedRegistration($ghostId, $editionId);

        // A surviving row that must still follow the ghost row in the stream.
        $this->createConfirmedRegistration(self::$testUserId, $editionId);

        require_once ABSPATH . 'wp-admin/includes/user.php';
        $this->assertTrue(wp_delete_user($ghostId), 'Failed to delete ghost user');

        $output = $this->runExportInChildProcess();

        // Blank cells, not an aborted stream: name falls back to 'Onbekend',
        // email + organisation are empty, edition/date/status still render.
        $expectedGhostRow = $this->csvLine([
            'Onbekend',
            '',
            '',
            'GhostEdition' . $suffix,
            $startDate,
            'confirmed',
            '',
        ]);
        $this->assertStringContainsString(
            $expectedGhostRow,
            $output,
            'Deleted-user registration must render with blank user cells. Output: ' . $this->snippet($output)
        );

        // Stream continued past the ghost row: the surviving user's row exists.
        $alive = get_userdata(self::$testUserId);
        $this->assertNotFalse($alive);
        $this->assertStringContainsString(
            $alive->user_email,
            $output,
            'Rows after the deleted-user row are missing — export stream aborted. Output: '
            . $this->snippet($output)
        );

        // The shutdown marker proves the child exited through the normal
        // export path (fclose + exit), not a fatal mid-stream.
        $this->extractQueryDelta($output);
    }

    /**
     * Seed $count confirmed registrations, each with its OWN user (so user
     * caches cannot mask per-row get_userdata queries), an organisation meta
     * value, and a linked quote (so the quote-number path is exercised).
     */
    private function seedExportRows(int $editionId, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $login = 'export_n1_' . wp_generate_password(8, false, false);
            $userId = $this->createExportUser($login, 'Org ' . $login);
            $regId = $this->createConfirmedRegistration($userId, $editionId);
            $this->createTestQuote($userId, $editionId, [
                'meta' => ['registration_id' => $regId],
            ]);
        }
    }

    private function createExportUser(string $login, string $organisation): int
    {
        $userId = wp_create_user($login, 'testpass123', $login . '@test.local');
        if (is_wp_error($userId)) {
            throw new \RuntimeException('Failed to create export user: ' . $userId->get_error_message());
        }
        $this->testUserIds[] = $userId;
        update_user_meta($userId, 'organisation', $organisation);

        return $userId;
    }

    private function createConfirmedRegistration(int $userId, int $editionId): int
    {
        $regId = $this->registrations->create([
            'user_id' => $userId,
            'edition_id' => $editionId,
            'status' => 'confirmed',
        ]);
        $this->assertIsInt($regId, 'Failed to create confirmed registration: ' . wp_json_encode($regId));
        $this->testRegistrationIds[] = $regId;

        return $regId;
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

// exportRegistrations() ends with exit, so a shutdown function is the only
// way to emit the query count AFTER the CSV body. Registered post-bootstrap,
// so WordPress' own shutdown queries run first and land identically in every
// measurement run — they cancel out of the N=5 vs N=50 comparison.
register_shutdown_function(static function (): void {
    global $wpdb;
    if (isset($GLOBALS['stride_export_num_queries_before'])) {
        echo "\n#STRIDE_EXPORT_QUERY_DELTA="
            . ($wpdb->num_queries - $GLOBALS['stride_export_num_queries_before'])
            . "#\n";
    }
});

$controller = new \Stride\Admin\AdminAPIController(
    ntdst_get(\Stride\Modules\Attendance\AttendanceRepository::class),
    ntdst_get(\Stride\Modules\Edition\EditionRepository::class),
    ntdst_get(\Stride\Modules\Edition\SessionRepository::class),
);

$GLOBALS['stride_export_num_queries_before'] = $wpdb->num_queries;
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

    /**
     * Format one expected CSV line exactly the way the export does
     * (fputcsv with ';' delimiter), so quoting rules match production.
     */
    private function csvLine(array $cells): string
    {
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $cells, ';');
        rewind($fh);
        $line = stream_get_contents($fh);
        fclose($fh);

        $this->assertIsString($line);

        return $line;
    }

    private function extractQueryDelta(string $output): int
    {
        $matched = preg_match('/#STRIDE_EXPORT_QUERY_DELTA=(\d+)#/', $output, $m);
        $this->assertSame(
            1,
            $matched,
            'Query-delta marker missing — child process died before its shutdown function ran. Output: '
            . $this->snippet($output)
        );

        return (int) $m[1];
    }

    private function snippet(string $output): string
    {
        return strlen($output) > 2000 ? substr($output, 0, 2000) . '… [truncated]' : $output;
    }
}
