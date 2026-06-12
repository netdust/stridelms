<?php
/**
 * Flow shake-out #5 — Data exportability sweep
 *
 * For every writable column, populate it, run the export, verify the value
 * appears somewhere in at least one sheet.
 */
require __DIR__ . '/shake-helpers.php';

use Stride\Domain\OfferingStatus;
use Stride\Domain\RegistrationStatus;

$svc = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);
$regRepo = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class);
$completion = ntdst_get(\Stride\Modules\Enrollment\EnrollmentCompletion::class);
$editionRepo = ntdst_get(\Stride\Modules\Edition\EditionRepository::class);
$editionModel = ntdst_data()->get('vad_edition');
$exporter = ntdst_get(\Stride\Modules\Edition\Admin\EditionRegistrationExporter::class);

global $wpdb;

shake_reset_editions();
shake_clean_test_users();

/**
 * Build the export and return all text cells across all sheets.
 */
function shake_export_text(int $editionId): string
{
    $exporter = ntdst_get(\Stride\Modules\Edition\Admin\EditionRegistrationExporter::class);
    $path = "/tmp/shake-export-{$editionId}.xlsx";
    $exporter->buildToFile($editionId, $path);

    // Read shared strings + every sheet's content
    $output = '';
    $cmd = "cd /tmp && unzip -p shake-export-{$editionId}.xlsx xl/sharedStrings.xml 2>/dev/null";
    $output .= shell_exec($cmd) ?: '';
    for ($i = 1; $i <= 10; $i++) {
        $cmd = "cd /tmp && unzip -p shake-export-{$editionId}.xlsx xl/worksheets/sheet{$i}.xml 2>/dev/null";
        $output .= shell_exec($cmd) ?: '';
    }
    return $output;
}

function shake_contains(string $haystack, string $needle): bool
{
    return stripos($haystack, $needle) !== false;
}

// ===========================
// Setup: a comprehensive test registration that touches every column
// ===========================
$editionId = 13265; // has questionnaire+documents+approval; we'll add session_selection
$editionModel->updateMetaBatch($editionId, [
    'requires_session_selection' => true,
    'selection_open' => true,
    'requires_questionnaire' => true,
    'requires_documents' => true,
    'requires_approval' => true,
]);

// Configure user with various meta
$userId = 7781;
$colleagueId = 7782;
update_user_meta($userId, 'first_name', 'Export');
update_user_meta($userId, 'last_name', 'Tester');
update_user_meta($userId, 'phone', '+32 477 123 456');
update_user_meta($userId, 'billing_company', 'ExportCorp BV');
update_user_meta($userId, 'billing_vat', 'BE0123456789');
update_user_meta($userId, 'billing_address_1', 'Teststraat 42');
update_user_meta($userId, 'billing_postcode', '2000');
update_user_meta($userId, 'billing_city', 'Antwerpen');
update_user_meta($userId, 'invoice_email', 'invoice@exportcorp.test');
update_user_meta($userId, 'gln_number', '5400123456789');
update_user_meta($userId, '_stride_company_id', 42);

// Enroll
wp_set_current_user($userId);
$regId = $svc->enroll($userId, $editionId, [
    'enrollment_path' => 'colleague',
    'enrolled_by' => $colleagueId,
    'notes' => 'Test notitie EXPORTTEST',
    'enrollment_data' => [
        'enrollment_personal' => [
            'shoe_size' => 42,
            'dietary' => 'gluten-free',
        ],
    ],
]);
if (is_wp_error($regId)) {
    echo "setup FAIL: " . $regId->get_error_message() . "\n";
    exit;
}
echo "test reg=$regId\n";

// Complete questionnaire
$completion->completeTask($regId, 'questionnaire', ['answers' => ['ervaring' => 'gevorderd', 'motivatie' => 'TEST_ANSWER_X']]);
// Complete documents
$completion->completeTask($regId, 'documents', ['files' => [99]]);
// Complete approval (auto-confirms)
$completion->completeTask($regId, 'approval');

// === EX-1: All Deelnemers sheet columns populated ===
shake_section('EX-1: Deelnemers sheet contains every basic column');
$text = shake_export_text($editionId);

$checks = [
    'first_name' => 'Export',
    'last_name' => 'Tester',
    'email' => 'shake1@smoke.test',
    'phone' => '+32 477 123 456',
    'billing_company' => 'ExportCorp BV',
    'billing_vat' => 'BE0123456789',
    'billing_address_1' => 'Teststraat 42',
    'billing_postcode' => '2000',
    'billing_city' => 'Antwerpen',
    'invoice_email' => 'invoice@exportcorp.test',
    'notes' => 'EXPORTTEST',
    'status_label' => 'Bevestigd',  // RegistrationStatus::Confirmed label
    'enrollment_path' => 'Collega',  // path label
];

foreach ($checks as $col => $needle) {
    $ok = shake_contains($text, $needle);
    echo "  $col contains '$needle': " . ($ok ? "OK" : "FAIL") . "\n";
}

// === EX-2: Quote info in Facturatie sheet ===
shake_section('EX-2: Facturatie sheet — quote data present');
$row = $regRepo->find($regId);
echo "  reg has quote_id: " . ($row->quote_id ? "Y ($row->quote_id)" : "N") . "\n";
if ($row->quote_id) {
    // Check the quote was created with our test billing
    $quote = ntdst_get(\Stride\Modules\Invoicing\QuoteService::class)->getQuote($row->quote_id);
    $quoteNum = $quote['number'] ?? $quote['meta']['quote_number'] ?? '';
    echo "  quote number: $quoteNum\n";
    if ($quoteNum) {
        echo "  quote number in export: " . (shake_contains($text, (string) $quoteNum) ? "OK" : "FAIL") . "\n";
    }
    // Total
    $total = (int) ($quote['total'] ?? $quote['meta']['total'] ?? 0);
    if ($total > 0) {
        $totalFmt = number_format($total / 100, 2, ',', '.');
        echo "  quote total ($totalFmt) in export: " . (shake_contains($text, $totalFmt) ? "OK" : "FAIL") . "\n";
    }
}

// === EX-3: completion_tasks Tasks sheet ===
shake_section('EX-3: Taken & Vragenlijst sheet — task labels + answer payload');
$taskLabels = [
    'questionnaire' => 'Vragenlijst',  // expected human label
    'documents' => 'Documenten',
    'approval' => 'Goedkeuring',
];
foreach ($taskLabels as $key => $label) {
    $hasKey = shake_contains($text, $key);
    $hasLabel = shake_contains($text, $label);
    echo "  task '$key': key_in_export=" . ($hasKey ? "Y" : "N") . " human_label_in_export=" . ($hasLabel ? "Y" : "N") . "\n";
}
echo "  questionnaire answer 'TEST_ANSWER_X' in export: " . (shake_contains($text, 'TEST_ANSWER_X') ? "OK" : "FAIL — answers lost on export") . "\n";

// === EX-4: enrollment_data.enrollment_personal extra fields ===
shake_section('EX-4: enrollment_data extra fields (shoe_size, dietary)');
$has1 = shake_contains($text, '42');           // shoe_size value
$has2 = shake_contains($text, 'gluten-free'); // dietary value
echo "  shoe_size '42' anywhere: " . ($has1 ? "OK" : "FAIL") . "\n";
echo "  dietary 'gluten-free' anywhere: " . ($has2 ? "OK" : "FAIL — enrollment_data extra fields not exported") . "\n";

// === EX-5: Metadata columns ===
shake_section('EX-5: completed_at / cancelled_at / enrolled_by / company_id');
// Cancel this reg now to populate cancelled_at
$svc->cancel($regId);
$row = $regRepo->find($regId);
echo "  cancelled_at on row: " . ($row->cancelled_at ?? 'NULL') . "\n";

// Rebuild export (after cancel)
$text2 = shake_export_text($editionId);

// cancelled_at — formatted as d/m/Y H:i
$cancelledFmt = $row->cancelled_at ? date_i18n('d/m/Y', strtotime($row->cancelled_at)) : '';
echo "  cancelled_at ($cancelledFmt) in export: " . (shake_contains($text2, $cancelledFmt) ? "OK" : "FAIL — cancelled_at not exported") . "\n";

// enrolled_by — who enrolled the participant (export writes the name, not the ID)
$enrolledByUser = get_userdata($colleagueId);
$enrolledByName = trim(($enrolledByUser->first_name ?? '') . ' ' . ($enrolledByUser->last_name ?? '')) ?: $enrolledByUser->display_name;
echo "  enrolled_by name ($enrolledByName) anywhere: " . (shake_contains($text2, $enrolledByName) ? "OK" : "FAIL — enrolled_by not exported (matters for colleague enroll)") . "\n";

// company_id (Partner API)
echo "  company_id (42) anywhere: " . (shake_contains($text2, '42') ? "OK" : "FAIL — company_id not exported") . "\n";

// === EX-6: Aanwezigheid sheet uses raw user_id when name missing ===
shake_section('EX-6: Anonymous interest/waitlist rows in Interesse/Wachtlijst sheets');
$qh = ntdst_get(\Stride\Modules\Questionnaire\QuestionnaireHandler::class);
$editionRepo->updateStatus(13224, OfferingStatus::Announcement);
$qh->handleSubmitInterest(null, [
    'edition_id' => 13224,
    'name' => 'Anon Export Tester',
    'email' => 'anon.export@flow.test',
]);
$text3 = shake_export_text(13224);
echo "  anon name in Interesse sheet: " . (shake_contains($text3, 'Anon Export Tester') ? "OK" : "FAIL") . "\n";
echo "  anon email in Interesse sheet: " . (shake_contains($text3, 'anon.export@flow.test') ? "OK" : "FAIL") . "\n";

// === EX-7: Status counts in Overzicht ===
shake_section('EX-7: Overzicht sheet shows status counts');
echo "  'Geannuleerd' (cancelled label) in overview: " . (shake_contains($text2, 'Geannuleerd') ? "OK" : "FAIL") . "\n";

echo "\n=== Flow #5 (Data exportability) complete ===\n";
