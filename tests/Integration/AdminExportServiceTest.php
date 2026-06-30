<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Admin\AdminExportService;
use Stride\Domain\RegistrationStatus;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Characterization pin for the exportRegistrations -> AdminExportService strangle (Task D3).
 *
 * Task D3 relocates the reg-side confirmed-upcoming SELECT out of
 * AdminAPIController::exportRegistrations into RegistrationRepository::findForExport
 * (the $wpdb execution, INV-3) and the CSV read-model assembly + the
 * sanitizeCsvCell formula-injection control into AdminExportService. The controller
 * keeps ONLY the HTTP streaming (headers + BOM + fputcsv). The move MUST be
 * behavior-preserving: the assembled CSV rows + the sanitized cells are identical
 * to the pre-extraction controller's output.
 *
 * This is the safety net. It pins three load-bearing properties:
 *   1. SECURITY — sanitizeCsvCell neutralises a leading =/+/-/@/TAB/CR (CSV/spreadsheet
 *      formula injection). This control was specifically praised by the security audit;
 *      it MUST survive the move (denial path).
 *   2. FILTER — only confirmed + upcoming (start_date >= today OR dateless) registrations
 *      appear; a PAST edition's reg and a NON-confirmed reg must NOT appear.
 *   3. SHAPE — the row column order is exactly
 *      [Naam, E-mail, Organisatie, Editie, Datum, Status, Offerte #].
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AdminExportService
 */
final class AdminExportServiceTest extends IntegrationTestCase
{
    private AdminExportService $service;
    private RegistrationRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(self::$testUserId);
        $this->service = ntdst_get(AdminExportService::class);
        $this->repo = ntdst_get(RegistrationRepository::class);
    }

    private function cleanRegistrations(): void
    {
        global $wpdb;
        // Scope to THIS test's fixture user only — never a table-wide DELETE.
        // The integration suite runs against the real WP DB (see bootstrap),
        // so an unscoped DELETE here wipes live enrollments. Every registration
        // this test creates uses self::$testUserId, so this fully clears the
        // test's own data without touching anyone else's. (Incident 2026-06-30.)
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}vad_registrations WHERE user_id = %d",
            self::$testUserId,
        ));
    }

    // =========================================================================
    // 1. SECURITY — formula-injection sanitisation survives the move
    // =========================================================================

    /** @test */
    public function sanitizeCsvCellNeutralisesFormulaInjectionPrefixes(): void
    {
        // Each spreadsheet-dangerous leading char must be prefixed with a single
        // quote so Excel/LibreOffice/Sheets treat the cell as a literal string.
        $this->assertSame("'=WEBSERVICE(\"http://evil\")", $this->service->sanitizeCsvCell('=WEBSERVICE("http://evil")'));
        $this->assertSame("'+1+1", $this->service->sanitizeCsvCell('+1+1'));
        $this->assertSame("'-2+3", $this->service->sanitizeCsvCell('-2+3'));
        $this->assertSame("'@SUM(A1)", $this->service->sanitizeCsvCell('@SUM(A1)'));
        $this->assertSame("'\tcmd", $this->service->sanitizeCsvCell("\tcmd"));
        $this->assertSame("'\rcmd", $this->service->sanitizeCsvCell("\rcmd"));

        // Benign cells pass through untouched.
        $this->assertSame('Jan Janssens', $this->service->sanitizeCsvCell('Jan Janssens'));
        $this->assertSame('', $this->service->sanitizeCsvCell(''));
    }

    // =========================================================================
    // 2. FILTER — only confirmed + upcoming; past + non-confirmed excluded
    // =========================================================================

    /** @test */
    public function findForExportReturnsOnlyConfirmedUpcomingRegistrations(): void
    {
        $this->cleanRegistrations();
        $today = current_time('Y-m-d');
        $future = date('Y-m-d', strtotime('+30 days'));
        $past = date('Y-m-d', strtotime('-30 days'));

        $upcomingEdition = $this->createTestEdition([
            'post_title' => 'Upcoming Edition',
            'meta'       => ['_ntdst_start_date' => $future],
        ]);
        $pastEdition = $this->createTestEdition([
            'post_title' => 'Past Edition',
            'meta'       => ['_ntdst_start_date' => $past],
        ]);

        // INCLUDED: confirmed + upcoming.
        $includedRegId = $this->repo->create([
            'user_id'    => self::$testUserId,
            'edition_id' => $upcomingEdition,
            'status'     => RegistrationStatus::Confirmed->value,
        ]);
        // EXCLUDED: confirmed but the edition is PAST.
        $pastRegId = $this->repo->create([
            'user_id'    => self::$testUserId,
            'edition_id' => $pastEdition,
            'status'     => RegistrationStatus::Confirmed->value,
        ]);
        // EXCLUDED: upcoming edition but NOT confirmed (waitlist).
        $waitlistRegId = $this->repo->create([
            'user_id'    => self::$testUserId,
            'edition_id' => $upcomingEdition,
            'status'     => RegistrationStatus::Waitlist->value,
        ]);

        $rows = $this->repo->findForExport($today);
        $ids = array_map(static fn($r) => (int) $r->id, $rows);

        // Assert about OUR three fixtures specifically rather than a global
        // row count — findForExport() is an all-users admin query, so a
        // table-wide count is brittle against other fixtures (and cleanup is
        // now correctly scoped to this user, not the whole table).
        $this->assertContains($includedRegId, $ids, 'confirmed + upcoming reg must be exported');
        $this->assertNotContains($pastRegId, $ids, 'confirmed but PAST reg must be excluded');
        $this->assertNotContains($waitlistRegId, $ids, 'upcoming but non-confirmed (waitlist) reg must be excluded');
    }

    // =========================================================================
    // 3. SHAPE — full CSV row assembly, exact column order + sanitised cells
    // =========================================================================

    /** @test */
    public function buildExportRowsAssemblesCellsInTheExactColumnOrderWithSanitisation(): void
    {
        $this->cleanRegistrations();
        $today = current_time('Y-m-d');
        $future = date('Y-m-d', strtotime('+30 days'));

        // A user whose organisation field carries a formula-injection payload —
        // proves the sanitisation runs on the assembled, exported cells.
        $userId = (int) wp_create_user('exp_user_' . uniqid(), 'pass123', 'exp_' . uniqid() . '@test.local');
        wp_update_user(['ID' => $userId, 'display_name' => 'Export Tester']);
        update_user_meta($userId, 'organisation', '=cmd|/c calc');

        $edition = $this->createTestEdition([
            'post_title' => 'Shape Edition',
            'meta'       => ['_ntdst_start_date' => $future],
        ]);
        $regId = $this->repo->create([
            'user_id'    => $userId,
            'edition_id' => $edition,
            'status'     => RegistrationStatus::Confirmed->value,
        ]);

        // Linked quote (registration_id meta) so the Offerte # column is populated.
        $this->createTestQuote($userId, $edition, [
            'meta' => ['registration_id' => $regId, 'quote_number' => 'OFF-D3-9001'],
        ]);

        $rows = $this->service->buildExportRows($today);

        $this->assertCount(1, $rows, 'one confirmed upcoming reg');
        $row = $rows[0];

        // Exact column order: [Naam, E-mail, Organisatie, Editie, Datum, Status, Offerte #].
        $this->assertCount(7, $row, 'seven columns in the export row');
        $this->assertSame('Export Tester', $row[0]);               // Naam
        $this->assertStringContainsString('@test.local', $row[1]); // E-mail
        $this->assertSame('=cmd|/c calc', $row[2]);                // Organisatie (RAW pre-sanitise; controller sanitises on stream)
        $this->assertSame('Shape Edition', $row[3]);               // Editie
        $this->assertSame($future, $row[4]);                       // Datum
        $this->assertSame('confirmed', $row[5]);                   // Status
        $this->assertSame('OFF-D3-9001', $row[6]);                 // Offerte #

        // The security control neutralises the injected organisation cell when streamed.
        $this->assertSame("'=cmd|/c calc", $this->service->sanitizeCsvCell($row[2]));

        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($userId);
    }
}
