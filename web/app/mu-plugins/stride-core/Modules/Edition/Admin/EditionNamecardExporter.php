<?php

declare(strict_types=1);

namespace Stride\Modules\Edition\Admin;

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Writer\WriterInterface;
use Stride\Infrastructure\BatchQueryHelper;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;

/**
 * Exports name cards as a Word document for printing.
 *
 * Layout: 2 columns × N rows of cards, each with name + organisation.
 * Designed to be printed and cut into individual name badges.
 */
final class EditionNamecardExporter
{
    public function __construct(
        private readonly EditionService $editionService,
        private readonly EditionRepository $editionRepository,
    ) {}

    public function export(int $editionId): void
    {
        $writer = $this->buildWriter($editionId);

        // Stream to browser
        $editionTitle = html_entity_decode(get_the_title($editionId), ENT_QUOTES, 'UTF-8');
        $slug = sanitize_title($editionTitle ?: 'editie-' . $editionId);
        $filename = 'naamkaartjes-' . $slug . '-' . date('Y-m-d') . '.docx';

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
     * Build the namecards Word doc to a path on disk, without HTTP headers.
     *
     * Produces byte-identical content to export() but writes to a file instead
     * of streaming to the browser. Used by EditionBundleZipExporter.
     */
    public function buildToFile(int $editionId, string $path): void
    {
        $this->buildWriter($editionId)->save($path);
    }

    /**
     * Build a PhpWord Word2007 writer with the full namecard document loaded.
     * Shared by export() (streams to browser) and buildToFile() (writes to disk).
     */
    private function buildWriter(int $editionId): WriterInterface
    {
        $registrations = $this->getConfirmedRegistrations($editionId);

        $userIds = array_unique(array_filter(
            array_map(fn($r) => (int) $r['user_id'], $registrations),
        ));
        $users = BatchQueryHelper::batchGetUsers($userIds);
        $userMeta = $this->batchGetOrgMeta($userIds);

        // Build card data (sorted alphabetically)
        $cards = [];
        foreach ($registrations as $registration) {
            $userId = (int) $registration['user_id'];
            $user = $users[$userId] ?? null;
            if (!$user) {
                continue;
            }
            $meta = $userMeta[$userId] ?? [];
            $cards[] = [
                'name' => trim($user->first_name . ' ' . $user->last_name) ?: $user->display_name,
                'org' => $meta['organisation'] ?? '',
            ];
        }
        usort($cards, fn($a, $b) => strcasecmp($a['name'], $b['name']));

        $phpWord = new PhpWord();

        // Page setup
        $sectionStyle = [
            'marginTop' => 600,
            'marginBottom' => 600,
            'marginLeft' => 800,
            'marginRight' => 800,
        ];

        $section = $phpWord->addSection($sectionStyle);

        // Title
        $editionTitle = get_the_title($editionId);
        $startDate = $this->editionRepository->getField($editionId, 'start_date', '');

        $section->addText(
            htmlspecialchars($editionTitle),
            ['size' => 14, 'bold' => true, 'color' => '2271B1'],
            ['alignment' => Jc::CENTER, 'spaceAfter' => 60],
        );

        if ($startDate) {
            $section->addText(
                date_i18n('j F Y', strtotime($startDate)),
                ['size' => 10, 'color' => '646970'],
                ['alignment' => Jc::CENTER, 'spaceAfter' => 200],
            );
        }

        // Create a table for 2-column card layout
        $table = $section->addTable([
            'borderSize' => 6,
            'borderColor' => 'C3C4C7',
            'cellMargin' => 120,
            'alignment' => Jc::CENTER,
        ]);

        // Card dimensions (in twips: 1 inch = 1440 twips)
        $cardWidth = 4500; // ~3.1 inches per card
        $cardHeight = 1800; // ~1.25 inches

        // Two cards per row
        $chunked = array_chunk($cards, 2);
        foreach ($chunked as $pair) {
            $table->addRow($cardHeight);

            foreach ($pair as $card) {
                $cell = $table->addCell($cardWidth, [
                    'valign' => 'center',
                ]);
                $cell->addText(
                    htmlspecialchars($card['name']),
                    ['size' => 16, 'bold' => true, 'color' => '1D2327'],
                    ['alignment' => Jc::CENTER, 'spaceAfter' => 40],
                );
                if ($card['org']) {
                    $cell->addText(
                        htmlspecialchars($card['org']),
                        ['size' => 10, 'color' => '646970'],
                        ['alignment' => Jc::CENTER],
                    );
                }
            }

            // Fill empty cell if odd number
            if (count($pair) === 1) {
                $table->addCell($cardWidth);
            }
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
            $editionId,
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
            ...array_merge($userIds, $metaKeys),
        ));

        $meta = [];
        foreach ($results as $row) {
            $meta[(int) $row->user_id][$row->meta_key] = $row->meta_value;
        }
        return $meta;
    }
}
