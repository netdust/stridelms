<?php

declare(strict_types=1);

namespace Stride\Modules\Edition\Admin;

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\TblWidth;
use PhpOffice\PhpWord\Style\Cell as CellStyle;
use PhpOffice\PhpWord\Writer\WriterInterface;
use Stride\Infrastructure\BatchQueryHelper;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionService;

/**
 * Exports a printable attendance sign-off sheet as Word document.
 *
 * One page per session with a table: Nr, Naam, Organisatie, Handtekening.
 * Designed for printing — attendees sign on paper during the session.
 */
final class EditionAttendanceExporter
{
    public function __construct(
        private readonly EditionService $editionService,
        private readonly EditionRepository $editionRepository,
        private readonly SessionService $sessionService,
    ) {}

    public function export(int $editionId): void
    {
        $writer = $this->buildWriter($editionId);

        // Stream to browser
        $editionTitle = html_entity_decode(get_the_title($editionId), ENT_QUOTES, 'UTF-8');
        $slug = sanitize_title($editionTitle ?: 'editie-' . $editionId);
        $filename = 'presentielijst-' . $slug . '-' . date('Y-m-d') . '.docx';

        if (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer->save('php://output');
        exit;
    }

    /**
     * Build the attendance Word doc to a path on disk, without HTTP headers.
     *
     * Produces byte-identical content to export() but writes to a file instead
     * of streaming to the browser. Used by EditionBundleZipExporter.
     */
    public function buildToFile(int $editionId, string $path): void
    {
        $this->buildWriter($editionId)->save($path);
    }

    /**
     * Build a PhpWord Word2007 writer with the full attendance sheet loaded.
     * Shared by export() (streams to browser) and buildToFile() (writes to disk).
     */
    private function buildWriter(int $editionId): WriterInterface
    {
        $registrations = $this->getConfirmedRegistrations($editionId);

        $userIds = array_unique(array_filter(
            array_map(fn($r) => (int) $r['user_id'], $registrations)
        ));
        $users = BatchQueryHelper::batchGetUsers($userIds);
        $userMeta = $this->batchGetOrgMeta($userIds);

        // Build sorted attendee list
        $attendees = [];
        foreach ($registrations as $registration) {
            $userId = (int) $registration['user_id'];
            $user = $users[$userId] ?? null;
            if (!$user) {
                continue;
            }
            $meta = $userMeta[$userId] ?? [];
            $attendees[] = [
                'name' => trim($user->first_name . ' ' . $user->last_name) ?: $user->display_name,
                'org' => $meta['organisation'] ?? '',
            ];
        }
        usort($attendees, fn($a, $b) => strcasecmp($a['name'], $b['name']));

        $sessions = $this->sessionService->getSessionsForEdition($editionId);
        $editionTitle = get_the_title($editionId);
        $venue = $this->editionRepository->getField($editionId, 'venue', '');

        $phpWord = new PhpWord();

        // Global styles
        $phpWord->setDefaultFontSize(10);
        $phpWord->setDefaultFontName('Calibri');

        // If no sessions, create a single generic sheet
        if (empty($sessions)) {
            $sessions = [['id' => 0, 'date' => '', 'start_time' => '', 'end_time' => '', 'title' => '', 'location' => '']];
        }

        $headerBg = 'E8F0FE';
        $headerColor = '1D2327';
        $borderColor = 'B4B9BE';

        foreach ($sessions as $sessionIndex => $session) {
            $sectionStyle = [
                'marginTop' => 800,
                'marginBottom' => 600,
                'marginLeft' => 800,
                'marginRight' => 800,
                'orientation' => 'landscape',
            ];

            // Page break between sessions (new section = new page)
            $section = $phpWord->addSection($sectionStyle);

            // Header: edition title
            $section->addText(
                htmlspecialchars($editionTitle),
                ['size' => 14, 'bold' => true, 'color' => '2271B1'],
                ['spaceAfter' => 40]
            );

            // Session info line
            $sessionInfo = [];
            if (!empty($session['date'])) {
                $sessionInfo[] = date_i18n('l j F Y', strtotime($session['date']));
            }
            if (!empty($session['start_time'])) {
                $time = $session['start_time'];
                if (!empty($session['end_time'])) {
                    $time .= ' – ' . $session['end_time'];
                }
                $sessionInfo[] = $time;
            }
            $sessionLocation = $session['location'] ?? $venue;
            if ($sessionLocation) {
                $sessionInfo[] = $sessionLocation;
            }
            if (!empty($session['title'])) {
                $sessionInfo[] = $session['title'];
            }

            if (!empty($sessionInfo)) {
                $section->addText(
                    htmlspecialchars(implode('  ·  ', $sessionInfo)),
                    ['size' => 10, 'color' => '646970'],
                    ['spaceAfter' => 200]
                );
            }

            $section->addText(
                'PRESENTIELIJST',
                ['size' => 11, 'bold' => true, 'color' => $headerColor, 'allCaps' => true],
                ['spaceAfter' => 120]
            );

            // Attendance table
            $tableStyle = [
                'borderSize' => 4,
                'borderColor' => $borderColor,
                'cellMargin' => 80,
                'width' => 100 * 50, // 100% in fiftieths of a percent
                'unit' => TblWidth::PERCENT,
            ];

            $table = $section->addTable($tableStyle);

            // Header row
            $cellStyleHeader = [
                'bgColor' => $headerBg,
                'valign' => 'center',
            ];
            $fontHeader = ['size' => 9, 'bold' => true, 'color' => $headerColor];
            $pHeader = ['spaceAfter' => 0, 'spaceBefore' => 0];

            $table->addRow(400);
            $table->addCell(600, $cellStyleHeader)->addText('Nr', $fontHeader, $pHeader);
            $table->addCell(3600, $cellStyleHeader)->addText('Naam', $fontHeader, $pHeader);
            $table->addCell(2400, $cellStyleHeader)->addText('Organisatie', $fontHeader, $pHeader);
            $table->addCell(3600, $cellStyleHeader)->addText('Handtekening', $fontHeader, $pHeader);

            // Data rows
            $fontCell = ['size' => 10, 'color' => '1D2327'];
            $fontCellLight = ['size' => 9, 'color' => '646970'];
            $pCell = ['spaceAfter' => 0, 'spaceBefore' => 0];
            $rowHeight = 600; // ~0.42 inch — enough room to sign

            foreach ($attendees as $i => $attendee) {
                $rowBg = ($i % 2 === 0) ? 'FFFFFF' : 'F8F9FA';
                $cellStyle = ['bgColor' => $rowBg, 'valign' => 'center'];

                $table->addRow($rowHeight);
                $table->addCell(600, $cellStyle)->addText((string) ($i + 1), $fontCellLight, $pCell);
                $table->addCell(3600, $cellStyle)->addText(htmlspecialchars($attendee['name']), $fontCell, $pCell);
                $table->addCell(2400, $cellStyle)->addText(htmlspecialchars($attendee['org']), $fontCellLight, $pCell);
                $table->addCell(3600, $cellStyle); // Empty cell for signature
            }

            // Add a few empty rows for walk-ins
            for ($j = 0; $j < 3; $j++) {
                $idx = count($attendees) + $j;
                $rowBg = ($idx % 2 === 0) ? 'FFFFFF' : 'F8F9FA';
                $cellStyle = ['bgColor' => $rowBg, 'valign' => 'center'];

                $table->addRow($rowHeight);
                $table->addCell(600, $cellStyle)->addText((string) ($idx + 1), $fontCellLight, $pCell);
                $table->addCell(3600, $cellStyle);
                $table->addCell(2400, $cellStyle);
                $table->addCell(3600, $cellStyle);
            }

            // Footer
            $section->addTextBreak();
            $section->addText(
                sprintf('Totaal deelnemers: %d  ·  Geëxporteerd %s', count($attendees), date_i18n('j M Y H:i')),
                ['size' => 8, 'color' => 'A0A5AA', 'italic' => true],
                ['alignment' => Jc::END]
            );
        }

        return IOFactory::createWriter($phpWord, 'Word2007');
    }

    private function getConfirmedRegistrations(int $editionId): array
    {
        global $wpdb;
        $table = $wpdb->prefix . EditionAdminController::REGISTRATIONS_TABLE;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, status FROM {$table}
             WHERE edition_id = %d AND status IN ('confirmed', 'completed')
             ORDER BY registered_at ASC",
            $editionId
        ), ARRAY_A) ?: [];
    }

    private function batchGetOrgMeta(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        global $wpdb;
        $userIds = array_map('intval', array_unique($userIds));
        $metaKeys = ['organisation', 'department'];

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
