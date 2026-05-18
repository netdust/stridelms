<?php

declare(strict_types=1);

namespace Stride\Modules\Edition\Admin;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Stride\Domain\RegistrationStatus;
use Stride\Infrastructure\BatchQueryHelper;
use Stride\Modules\Attendance\AttendanceRepository;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionService;

/**
 * Comprehensive XLSX export for edition registrations.
 *
 * Sheets:
 * 1. Overzicht — edition info, summary statistics
 * 2. Deelnemers — full registration + user data
 * 3. Facturatie — invoice/billing data per participant + quote reference
 * 4. Aanwezigheid — attendance grid (users × sessions)
 * 5. Taken — completion tasks, questionnaire answers, uploads
 */
final class EditionRegistrationExporter
{
    private Style $titleStyle;
    private Style $subtitleStyle;
    private Style $headerStyle;
    private Style $rowStyleEven;
    private Style $rowStyleOdd;
    private Style $labelStyle;
    private Style $valueStyle;
    private Style $summaryHeaderStyle;

    public function __construct(
        private readonly EditionService $editionService,
        private readonly EditionRepository $editionRepository,
        private readonly SessionService $sessionService,
        private readonly AttendanceRepository $attendanceRepository,
    ) {
        $this->initStyles();
    }

    public function export(int $editionId): void
    {
        // Gather all data upfront (before any output)
        $data = $this->gatherData($editionId);

        // Build filename — decode HTML entities in title
        $editionTitle = html_entity_decode($data['editionTitle'], ENT_QUOTES, 'UTF-8');
        $slug = sanitize_title($editionTitle ?: 'editie-' . $editionId);
        $filename = 'export-' . $slug . '-' . date('Y-m-d') . '.xlsx';

        // Clean ALL output buffers — WordPress/plugins may stack multiple levels
        while (ob_get_level()) {
            ob_end_clean();
        }

        $options = new Options();
        $options->DEFAULT_ROW_HEIGHT = 20;

        $writer = new Writer($options);
        $writer->openToBrowser($filename);
        $this->writeAllSheets($writer, $data);
        $writer->close();
        exit;
    }

    /**
     * Build the XLSX to a path on disk, without sending HTTP headers.
     *
     * Produces byte-identical content to export() but writes to a file instead
     * of streaming to the browser. Used by EditionBundleZipExporter.
     */
    public function buildToFile(int $editionId, string $path): void
    {
        $data = $this->gatherData($editionId);

        $options = new Options();
        $options->DEFAULT_ROW_HEIGHT = 20;

        $writer = new Writer($options);
        $writer->openToFile($path);
        $this->writeAllSheets($writer, $data);
        $writer->close();
    }

    /**
     * Write all sheets to the open writer — shared by export() and buildToFile().
     *
     * Sheets:
     *   1. Overzicht (always)
     *   2. Deelnemers (always)
     *   3. Facturatie (always)
     *   4. Aanwezigheid (only when sessions exist)
     *   5. Taken & Vragenlijst (only when any registration has completion tasks)
     */
    private function writeAllSheets(Writer $writer, array $data): void
    {
        // Sheet 1: Overzicht (default sheet)
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Overzicht');
        $this->writeOverviewSheet($writer, $data);

        // Sheet 2: Deelnemers
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Deelnemers');
        $this->writeRegistrationsSheet($writer, $sheet, $data);

        // Sheet 3: Facturatie
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Facturatie');
        $this->writeInvoicingSheet($writer, $sheet, $data);

        // Sheet 4: Aanwezigheid (only if sessions exist)
        if (!empty($data['sessions'])) {
            $sheet = $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName('Aanwezigheid');
            $this->writeAttendanceSheet($writer, $sheet, $data);
        }

        // Sheet 5: Taken (only if any completion tasks exist)
        if (!empty($data['hasCompletionTasks'])) {
            $sheet = $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName('Taken & Vragenlijst');
            $this->writeTasksSheet($writer, $sheet, $data);
        }
    }

    // =========================================================================
    // Data gathering
    // =========================================================================

    private function gatherData(int $editionId): array
    {
        $registrations = $this->getRegistrations($editionId);

        $userIds = array_unique(array_filter(
            array_map(fn($r) => (int) $r['user_id'], $registrations)
        ));
        $users = BatchQueryHelper::batchGetUsers($userIds);
        $userMeta = $this->batchGetUserMeta($userIds);

        $editionTitle = html_entity_decode(get_the_title($editionId), ENT_QUOTES, 'UTF-8');
        $courseId = $this->editionService->getCourseId($editionId);
        $courseTitle = $courseId ? html_entity_decode(get_the_title($courseId), ENT_QUOTES, 'UTF-8') : '';
        $startDate = $this->editionRepository->getField($editionId, 'start_date', '');
        $endDate = $this->editionRepository->getField($editionId, 'end_date', '');
        $venue = $this->editionRepository->getField($editionId, 'venue', '');
        $speakers = $this->editionRepository->getField($editionId, 'speakers', '');
        $capacity = (int) $this->editionRepository->getField($editionId, 'capacity', 0);
        $price = (int) $this->editionRepository->getField($editionId, 'price', 0);

        $sessions = $this->sessionService->getSessionsForEdition($editionId);

        // Batch attendance
        $attendanceByUser = BatchQueryHelper::batchGetAttendance($editionId);

        // Check for completion tasks
        $hasCompletionTasks = false;
        foreach ($registrations as $r) {
            if (!empty($r['completion_tasks'])) {
                $hasCompletionTasks = true;
                break;
            }
        }

        // Note: Extra fields from the old enrollment field group system are no longer supported.
        // Use the Questionnaire module for additional fields instead.
        $extraFields = [];

        // Parse enrollment_data JSON for each registration
        foreach ($registrations as &$r) {
            $raw = $r['enrollment_data'] ?? '';
            if (is_string($raw) && $raw !== '') {
                $r['enrollment_data_parsed'] = json_decode($raw, true) ?: [];
            } else {
                $r['enrollment_data_parsed'] = [];
            }
        }
        unset($r);

        // Batch-fetch quote data for registrations that have a quote_id
        $quotes = [];
        $quoteIds = array_unique(array_filter(
            array_map(fn($r) => (int) ($r['quote_id'] ?? 0), $registrations)
        ));
        if (!empty($quoteIds)) {
            $quoteModel = ntdst_data()->get('vad_quote');
            $quoteResults = $quoteModel
                ->whereIn('ID', $quoteIds)
                ->where('post_status', 'publish')
                ->withMeta()
                ->limit(count($quoteIds))
                ->get();
            foreach ($quoteResults as $q) {
                $quotes[(int) ($q['id'] ?? 0)] = $q;
            }
        }

        // Status counts
        $statusCounts = [];
        foreach ($registrations as $r) {
            $s = $r['status'] ?? 'unknown';
            $statusCounts[$s] = ($statusCounts[$s] ?? 0) + 1;
        }

        return [
            'editionId' => $editionId,
            'editionTitle' => $editionTitle,
            'courseId' => $courseId,
            'courseTitle' => $courseTitle,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'venue' => $venue,
            'speakers' => $speakers,
            'capacity' => $capacity,
            'price' => $price,
            'registrations' => $registrations,
            'users' => $users,
            'userMeta' => $userMeta,
            'sessions' => $sessions,
            'attendanceByUser' => $attendanceByUser,
            'hasCompletionTasks' => $hasCompletionTasks,
            'statusCounts' => $statusCounts,
            'extraFields' => $extraFields,
            'quotes' => $quotes,
        ];
    }

    // =========================================================================
    // Sheet 1: Overzicht (Dashboard)
    // =========================================================================

    private function writeOverviewSheet(Writer $writer, array $data): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setColumnWidth(24, 1);
        $sheet->setColumnWidth(40, 2);

        // Title
        $titleText = $data['editionTitle'];
        if ($data['courseTitle']) {
            $titleText .= ' — ' . $data['courseTitle'];
        }
        $writer->addRow(new Row([Cell::fromValue($titleText)], $this->titleStyle));
        $writer->addRow(new Row([Cell::fromValue('Geëxporteerd ' . date_i18n('j F Y H:i'))],$this->subtitleStyle));
        $writer->addRow(new Row([Cell::fromValue('')]));

        // Edition details section
        $writer->addRow(new Row([Cell::fromValue('EDITIE DETAILS')], $this->summaryHeaderStyle));
        $writer->addRow(new Row([Cell::fromValue('')]));

        $this->writeDetailRow($writer, 'Cursus', $data['courseTitle'] ?: '—');
        $this->writeDetailRow($writer, 'Startdatum', $data['startDate'] ? date_i18n('j F Y', strtotime($data['startDate'])) : '—');
        $this->writeDetailRow($writer, 'Einddatum', $data['endDate'] ? date_i18n('j F Y', strtotime($data['endDate'])) : '—');
        $this->writeDetailRow($writer, 'Locatie', $data['venue'] ?: '—');
        $this->writeDetailRow($writer, 'Spreker(s)', $data['speakers'] ?: '—');
        $this->writeDetailRow($writer, 'Capaciteit', $data['capacity'] > 0 ? (string) $data['capacity'] : 'Onbeperkt');
        $this->writeDetailRow($writer, 'Prijs', $data['price'] > 0 ? '€ ' . number_format($data['price'] / 100, 2, ',', '.') : '—');
        $this->writeDetailRow($writer, 'Sessies', (string) count($data['sessions']));

        $writer->addRow(new Row([Cell::fromValue('')]));

        // Registration summary
        $writer->addRow(new Row([Cell::fromValue('INSCHRIJVINGEN')], $this->summaryHeaderStyle));
        $writer->addRow(new Row([Cell::fromValue('')]));

        $this->writeDetailRow($writer, 'Totaal', (string) count($data['registrations']));
        foreach ($data['statusCounts'] as $status => $count) {
            $statusEnum = RegistrationStatus::tryFrom($status);
            $label = $statusEnum ? $statusEnum->label() : ucfirst($status);
            $this->writeDetailRow($writer, $label, (string) $count);
        }

        if (!empty($data['sessions'])) {
            $writer->addRow(new Row([Cell::fromValue('')]));
            $writer->addRow(new Row([Cell::fromValue('SESSIES')], $this->summaryHeaderStyle));
            $writer->addRow(new Row([Cell::fromValue('')]));

            foreach ($data['sessions'] as $session) {
                $sessionDate = !empty($session['date']) ? date_i18n('D j M', strtotime($session['date'])) : '';
                $time = !empty($session['start_time']) ? $session['start_time'] : '';
                if (!empty($session['end_time'])) {
                    $time .= ' – ' . $session['end_time'];
                }
                $this->writeDetailRow($writer, $sessionDate . ' ' . $time, $session['title'] ?? ($session['location'] ?? ''));
            }
        }
    }

    // =========================================================================
    // Sheet 2: Deelnemers
    // =========================================================================

    private function writeRegistrationsSheet(Writer $writer, $sheet, array $data): void
    {
        // Build dynamic extra field columns from field group definitions
        $extraFields = $data['extraFields'] ?? [];

        // Fixed columns
        $headers = [
            'Voornaam', 'Achternaam', 'E-mail', 'Telefoon',
        ];
        $colWidths = [14, 14, 28, 16];

        // Extra field columns (from field groups)
        foreach ($extraFields as $field) {
            $headers[] = $field['label'] ?? ($field['name'] ?? '');
            $colWidths[] = 20;
        }

        // Invoice columns — clearly prefixed with "Factuur:"
        $headers = array_merge($headers, [
            'Factuur: Organisatie', 'Factuur: BTW-nummer', 'Factuur: Adres',
            'Factuur: Postcode', 'Factuur: Stad', 'Factuur: E-mail',
        ]);
        $colWidths = array_merge($colWidths, [22, 18, 24, 12, 14, 24]);

        // Metadata columns
        $headers = array_merge($headers, [
            'Status', 'Inschrijfwijze', 'Inschrijfdatum', 'Sessieselectie', 'Opmerking',
        ]);
        $colWidths = array_merge($colWidths, [14, 14, 18, 14, 30]);

        // Set column widths (OpenSpout uses 1-indexed columns)
        foreach ($colWidths as $idx => $width) {
            $sheet->setColumnWidth($width, $idx + 1);
        }

        $writer->addRow(new Row(
            array_map(fn($h) => Cell::fromValue($h), $headers),
            $this->headerStyle
        ));

        // Build session lookup for selections display
        $sessionMap = [];
        foreach ($data['sessions'] as $s) {
            $sessionMap[(int) $s['id']] = (!empty($s['date']) ? date_i18n('j M', strtotime($s['date'])) . ' ' : '') . ($s['title'] ?? '');
        }

        foreach ($data['registrations'] as $index => $registration) {
            $userId = (int) $registration['user_id'];
            $user = $data['users'][$userId] ?? null;
            $meta = $data['userMeta'][$userId] ?? [];
            $status = RegistrationStatus::tryFrom($registration['status'] ?? '');
            $registeredAt = $registration['registered_at'] ?? '';
            $enrollmentData = $registration['enrollment_data_parsed'] ?? [];

            // Parse selections
            $selectionsText = '';
            if (!empty($registration['selections'])) {
                $selections = json_decode($registration['selections'], true);
                if (is_array($selections)) {
                    $labels = [];
                    foreach ($selections as $sid) {
                        if (is_int($sid) || is_numeric($sid)) {
                            $labels[] = $sessionMap[(int) $sid] ?? 'Sessie #' . $sid;
                        }
                    }
                    $selectionsText = implode(', ', $labels);
                }
            }

            // Personal columns
            $cells = [
                Cell::fromValue($user?->first_name ?? ''),
                Cell::fromValue($user?->last_name ?? ''),
                Cell::fromValue($user?->user_email ?? ''),
                Cell::fromValue($meta['phone'] ?? ''),
            ];

            // Extra field columns (values from enrollment_data)
            foreach ($extraFields as $field) {
                $fieldName = $field['name'] ?? '';
                $cells[] = Cell::fromValue($enrollmentData[$fieldName] ?? '');
            }

            // Invoice columns (canonical keys first, legacy fallbacks for historical data)
            $cells[] = Cell::fromValue($meta['billing_company'] ?? '');
            $cells[] = Cell::fromValue($meta['billing_vat'] ?? '');
            $cells[] = Cell::fromValue($meta['billing_address_1'] ?? '');
            $cells[] = Cell::fromValue($meta['billing_postcode'] ?? '');
            $cells[] = Cell::fromValue($meta['billing_city'] ?? '');
            $cells[] = Cell::fromValue($meta['invoice_email'] ?? '');

            // Metadata columns
            $cells[] = Cell::fromValue($status?->label() ?? ($registration['status'] ?? ''));
            $cells[] = Cell::fromValue($this->enrollmentPathLabel($registration['enrollment_path'] ?? ''));
            $cells[] = Cell::fromValue($registeredAt ? date_i18n('d/m/Y H:i', strtotime($registeredAt)) : '');
            $cells[] = Cell::fromValue($selectionsText);
            $cells[] = Cell::fromValue($registration['notes'] ?? '');

            $style = ($index % 2 === 0) ? $this->rowStyleEven : $this->rowStyleOdd;
            $writer->addRow(new Row($cells, $style));
        }
    }

    // =========================================================================
    // Sheet 3: Facturatie
    // =========================================================================

    private function writeInvoicingSheet(Writer $writer, $sheet, array $data): void
    {
        $headers = [
            'Voornaam', 'Achternaam', 'E-mail',
            'Organisatie', 'BTW-nummer', 'GLN/Peppol',
            'Adres', 'Postcode', 'Stad', 'Factuur e-mail',
            'Offertenummer', 'Offertebedrag', 'Korting', 'Kortingscode', 'Status offerte',
        ];
        $colWidths = [14, 14, 26, 22, 18, 16, 24, 10, 14, 24, 16, 14, 12, 14, 14];

        foreach ($colWidths as $idx => $width) {
            $sheet->setColumnWidth($width, $idx + 1);
        }

        $writer->addRow(new Row(
            array_map(fn($h) => Cell::fromValue($h), $headers),
            $this->headerStyle
        ));

        foreach ($data['registrations'] as $index => $registration) {
            $userId = (int) $registration['user_id'];
            $user = $data['users'][$userId] ?? null;
            $meta = $data['userMeta'][$userId] ?? [];
            $quoteId = (int) ($registration['quote_id'] ?? 0);
            $quote = $data['quotes'][$quoteId] ?? null;

            // Quote billing data (stored on quote as JSON)
            $billing = [];
            if ($quote) {
                $raw = $quote['meta']['billing'] ?? '';
                if (is_string($raw) && $raw !== '') {
                    $billing = json_decode($raw, true) ?: [];
                } elseif (is_array($raw)) {
                    $billing = $raw;
                }
            }

            // Prefer billing from quote, fallback to user meta (canonical then legacy)
            $org = $billing['company'] ?? $meta['billing_company'] ?? '';
            $vat = $billing['vat_number'] ?? $meta['billing_vat'] ?? '';
            $gln = $billing['gln_number'] ?? $meta['gln_number'] ?? '';
            $address = $billing['address'] ?? $meta['billing_address_1'] ?? '';
            $postal = $billing['postal_code'] ?? $meta['billing_postcode'] ?? '';
            $city = $billing['city'] ?? $meta['billing_city'] ?? '';
            $invoiceEmail = $billing['email'] ?? $meta['invoice_email'] ?? '';

            // Quote fields
            $quoteNumber = '';
            $quoteTotal = '';
            $quoteDiscount = '';
            $voucherCode = '';
            $quoteStatus = '';
            if ($quote) {
                $quoteNumber = $quote['meta']['quote_number'] ?? '';
                $totalCents = (int) ($quote['meta']['total'] ?? 0);
                $quoteTotal = $totalCents > 0 ? '€ ' . number_format($totalCents / 100, 2, ',', '.') : '';
                $discountCents = (int) ($quote['meta']['discount'] ?? 0);
                $quoteDiscount = $discountCents > 0 ? '€ ' . number_format($discountCents / 100, 2, ',', '.') : '';
                $voucherCode = $quote['meta']['voucher_code'] ?? '';
                $quoteStatus = $quote['meta']['status'] ?? '';
            }

            $cells = [
                Cell::fromValue($user?->first_name ?? ''),
                Cell::fromValue($user?->last_name ?? ''),
                Cell::fromValue($user?->user_email ?? ''),
                Cell::fromValue($org),
                Cell::fromValue($vat),
                Cell::fromValue($gln),
                Cell::fromValue($address),
                Cell::fromValue($postal),
                Cell::fromValue($city),
                Cell::fromValue($invoiceEmail),
                Cell::fromValue($quoteNumber),
                Cell::fromValue($quoteTotal),
                Cell::fromValue($quoteDiscount),
                Cell::fromValue($voucherCode),
                Cell::fromValue($this->quoteStatusLabel($quoteStatus)),
            ];

            $style = ($index % 2 === 0) ? $this->rowStyleEven : $this->rowStyleOdd;
            $writer->addRow(new Row($cells, $style));
        }
    }

    // =========================================================================
    // Sheet 4: Aanwezigheid
    // =========================================================================

    private function writeAttendanceSheet(Writer $writer, $sheet, array $data): void
    {
        $sessions = $data['sessions'];

        // Column widths: name, email, then one per session, then totals (1-indexed)
        $sheet->setColumnWidth(22, 1);  // Naam
        $sheet->setColumnWidth(26, 2);  // E-mail
        $colIdx = 3;
        foreach ($sessions as $_) {
            $sheet->setColumnWidth(14, $colIdx++);
        }
        $sheet->setColumnWidth(10, $colIdx);   // Aanwezig
        $sheet->setColumnWidth(10, $colIdx + 1); // %

        // Headers
        $headerCells = [
            Cell::fromValue('Naam'),
            Cell::fromValue('E-mail'),
        ];
        foreach ($sessions as $session) {
            $label = !empty($session['date']) ? date_i18n('j M', strtotime($session['date'])) : '';
            if (!empty($session['start_time'])) {
                $label .= "\n" . $session['start_time'];
            }
            $headerCells[] = Cell::fromValue($label);
        }
        $headerCells[] = Cell::fromValue('Aanwezig');
        $headerCells[] = Cell::fromValue('%');

        $writer->addRow(new Row($headerCells, $this->headerStyle));

        // Filter to confirmed/completed only
        $confirmedRegs = array_filter($data['registrations'], function ($r) {
            $s = RegistrationStatus::tryFrom($r['status'] ?? '');
            return $s === RegistrationStatus::Confirmed || $s === RegistrationStatus::Completed;
        });

        $statusLabels = [
            'present' => 'A',   // Aanwezig
            'absent' => 'X',    // Afwezig
            'excused' => 'V',   // Verontschuldigd
        ];

        $presentStyle = (new Style())->setFontSize(10)->setFontColor(Color::rgb(0, 128, 0))->setCellAlignment(CellAlignment::CENTER);
        $absentStyle = (new Style())->setFontSize(10)->setFontColor(Color::rgb(200, 0, 0))->setCellAlignment(CellAlignment::CENTER);
        $excusedStyle = (new Style())->setFontSize(10)->setFontColor(Color::rgb(150, 120, 0))->setCellAlignment(CellAlignment::CENTER);
        $emptyStyle = (new Style())->setFontSize(10)->setCellAlignment(CellAlignment::CENTER)->setFontColor(Color::rgb(200, 200, 200));
        $totalStyle = (new Style())->setFontSize(10)->setFontBold()->setCellAlignment(CellAlignment::CENTER);

        foreach (array_values($confirmedRegs) as $index => $registration) {
            $userId = (int) $registration['user_id'];
            $user = $data['users'][$userId] ?? null;
            $userAttendance = $data['attendanceByUser'][$userId] ?? [];

            $rowStyle = ($index % 2 === 0) ? $this->rowStyleEven : $this->rowStyleOdd;

            $cells = [
                Cell::fromValue($user ? ($user->first_name . ' ' . $user->last_name) : 'Gebruiker #' . $userId, $rowStyle),
                Cell::fromValue($user?->user_email ?? '', $rowStyle),
            ];

            $presentCount = 0;
            foreach ($sessions as $session) {
                $sessionId = (int) $session['id'];
                $status = $userAttendance[$sessionId] ?? null;

                if ($status === 'present') {
                    $cells[] = Cell::fromValue('A', $presentStyle);
                    $presentCount++;
                } elseif ($status === 'absent') {
                    $cells[] = Cell::fromValue('X', $absentStyle);
                } elseif ($status === 'excused') {
                    $cells[] = Cell::fromValue('V', $excusedStyle);
                } else {
                    $cells[] = Cell::fromValue('–', $emptyStyle);
                }
            }

            $totalSessions = count($sessions);
            $pct = $totalSessions > 0 ? round(($presentCount / $totalSessions) * 100) : 0;
            $cells[] = Cell::fromValue($presentCount . '/' . $totalSessions, $totalStyle);
            $cells[] = Cell::fromValue($pct . '%', $totalStyle);

            $writer->addRow(new Row($cells));
        }

        // Totals row
        $writer->addRow(new Row([Cell::fromValue('')]));
        $totalsCells = [
            Cell::fromValue('TOTAAL', $this->summaryHeaderStyle),
            Cell::fromValue(''),
        ];
        foreach ($sessions as $session) {
            $sessionId = (int) $session['id'];
            $count = 0;
            foreach ($confirmedRegs as $r) {
                $uid = (int) $r['user_id'];
                if (($data['attendanceByUser'][$uid][$sessionId] ?? '') === 'present') {
                    $count++;
                }
            }
            $totalsCells[] = Cell::fromValue($count . '/' . count($confirmedRegs), $totalStyle);
        }
        $writer->addRow(new Row($totalsCells));

        // Legend
        $writer->addRow(new Row([Cell::fromValue('')]));
        $writer->addRow(new Row([
            Cell::fromValue('Legenda:', $this->labelStyle),
            Cell::fromValue('A = Aanwezig, X = Afwezig, V = Verontschuldigd'),
        ]));
    }

    // =========================================================================
    // Sheet 5: Taken & Vragenlijst
    // =========================================================================

    private function writeTasksSheet(Writer $writer, $sheet, array $data): void
    {
        $sheet->setColumnWidth(22, 1);  // Naam
        $sheet->setColumnWidth(16, 2);  // Taak
        $sheet->setColumnWidth(12, 3);  // Status
        $sheet->setColumnWidth(18, 4);  // Voltooid op
        $sheet->setColumnWidth(50, 5);  // Details

        $headers = ['Naam', 'Taak', 'Status', 'Voltooid op', 'Details'];
        $writer->addRow(new Row(
            array_map(fn($h) => Cell::fromValue($h), $headers),
            $this->headerStyle
        ));

        $rowIndex = 0;
        foreach ($data['registrations'] as $registration) {
            $userId = (int) $registration['user_id'];
            $user = $data['users'][$userId] ?? null;
            $userName = $user ? ($user->first_name . ' ' . $user->last_name) : 'Gebruiker #' . $userId;

            $tasksJson = $registration['completion_tasks'] ?? '';
            if (empty($tasksJson)) {
                continue;
            }

            $tasks = is_string($tasksJson) ? (json_decode($tasksJson, true) ?: []) : (is_array($tasksJson) ? $tasksJson : []);
            if (empty($tasks)) {
                continue;
            }

            foreach ($tasks as $task) {
                $taskType = $task['type'] ?? $task['label'] ?? '';
                $taskLabel = $task['label'] ?? ucfirst($taskType);
                $taskStatus = ($task['status'] ?? '') === 'completed' ? 'Voltooid' : 'In afwachting';
                $completedAt = $task['completed_at'] ?? '';
                if ($completedAt) {
                    $completedAt = date_i18n('d/m/Y H:i', strtotime($completedAt));
                }

                // Build detail string
                $details = [];
                $taskData = $task['data'] ?? [];

                if (!empty($taskData['answers']) && is_array($taskData['answers'])) {
                    foreach ($taskData['answers'] as $question => $answer) {
                        if (is_string($question)) {
                            $details[] = $question . ': ' . (is_string($answer) ? $answer : json_encode($answer));
                        } else {
                            $details[] = is_string($answer) ? $answer : json_encode($answer);
                        }
                    }
                }

                if (!empty($taskData['files']) && is_array($taskData['files'])) {
                    $fileNames = [];
                    foreach ($taskData['files'] as $fileId) {
                        $filePath = get_attached_file((int) $fileId);
                        $fileNames[] = $filePath ? basename($filePath) : 'Bestand #' . $fileId;
                    }
                    $details[] = 'Bestanden: ' . implode(', ', $fileNames);
                }

                if (!empty($taskData['selected_sessions']) && is_array($taskData['selected_sessions'])) {
                    $sessionLabels = [];
                    foreach ($data['sessions'] as $s) {
                        if (in_array((int) $s['id'], array_map('intval', $taskData['selected_sessions']), true)) {
                            $sessionLabels[] = (!empty($s['date']) ? date_i18n('j M', strtotime($s['date'])) . ' ' : '') . ($s['title'] ?? '');
                        }
                    }
                    $details[] = 'Sessies: ' . implode(', ', $sessionLabels);
                }

                $style = ($rowIndex % 2 === 0) ? $this->rowStyleEven : $this->rowStyleOdd;

                $writer->addRow(new Row([
                    Cell::fromValue($userName),
                    Cell::fromValue($taskLabel),
                    Cell::fromValue($taskStatus),
                    Cell::fromValue($completedAt),
                    Cell::fromValue(implode("\n", $details)),
                ], $style));

                $rowIndex++;
            }
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function initStyles(): void
    {
        $this->titleStyle = (new Style())
            ->setFontBold()
            ->setFontSize(14)
            ->setFontColor(Color::rgb(30, 30, 30));

        $this->subtitleStyle = (new Style())
            ->setFontSize(10)
            ->setFontColor(Color::rgb(100, 105, 112));

        $this->headerStyle = (new Style())
            ->setFontBold()
            ->setFontSize(10)
            ->setFontColor(Color::WHITE)
            ->setBackgroundColor(Color::toARGB(Color::rgb(34, 113, 177)))
            ->setCellAlignment(CellAlignment::LEFT);

        $this->rowStyleEven = (new Style())->setFontSize(10);
        $this->rowStyleOdd = (new Style())->setFontSize(10)->setBackgroundColor(Color::toARGB(Color::rgb(245, 247, 250)));

        $this->labelStyle = (new Style())
            ->setFontSize(10)
            ->setFontBold()
            ->setFontColor(Color::rgb(100, 105, 112));

        $this->valueStyle = (new Style())->setFontSize(10);

        $this->summaryHeaderStyle = (new Style())
            ->setFontBold()
            ->setFontSize(11)
            ->setFontColor(Color::rgb(34, 113, 177));
    }

    private function writeDetailRow(Writer $writer, string $label, string $value): void
    {
        $writer->addRow(new Row([
            Cell::fromValue($label, $this->labelStyle),
            Cell::fromValue($value, $this->valueStyle),
        ]));
    }

    private function quoteStatusLabel(string $status): string
    {
        return match ($status) {
            'draft' => 'Concept',
            'sent' => 'Verzonden',
            'accepted' => 'Aanvaard',
            'rejected' => 'Afgewezen',
            'cancelled' => 'Geannuleerd',
            'paid' => 'Betaald',
            default => $status ?: '—',
        };
    }

    private function enrollmentPathLabel(string $path): string
    {
        return match ($path) {
            'individual' => 'Individueel',
            'colleague' => 'Collega-inschrijving',
            'trajectory' => 'Traject',
            default => $path ?: '—',
        };
    }

    private function getRegistrations(int $editionId): array
    {
        global $wpdb;
        $table = $wpdb->prefix . EditionAdminController::REGISTRATIONS_TABLE;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE edition_id = %d ORDER BY registered_at DESC",
            $editionId
        ), ARRAY_A) ?: [];
    }

    private function batchGetUserMeta(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        global $wpdb;
        $userIds = array_map('intval', array_unique($userIds));
        $metaKeys = [
            'organisation', 'department', 'phone',
            'billing_company', 'billing_vat', 'billing_address_1', 'billing_postcode', 'billing_city',
            'invoice_email', 'gln_number',
        ];

        $userPlaceholders = implode(',', array_fill(0, count($userIds), '%d'));
        $keyPlaceholders = implode(',', array_fill(0, count($metaKeys), '%s'));

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta}
             WHERE user_id IN ({$userPlaceholders}) AND meta_key IN ({$keyPlaceholders})",
            ...array_merge($userIds, $metaKeys)
        ));

        $meta = [];
        foreach ($results as $row) {
            $meta[(int) $row->user_id][$row->meta_key] = $row->meta_value;
        }
        return $meta;
    }
}
