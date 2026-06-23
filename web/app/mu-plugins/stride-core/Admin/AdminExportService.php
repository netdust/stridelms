<?php

declare(strict_types=1);

namespace Stride\Admin;

use Stride\Infrastructure\BatchQueryHelper;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Invoicing\QuoteRepository;

/**
 * Read-model assembly for the admin registrations CSV export.
 *
 * Thin service — owns the per-row CSV assembly (user/org/quote enrichment, batch-
 * primed) and the spreadsheet-formula-injection sanitisation control. No raw $wpdb
 * SELECTs of its own: delegates to RegistrationRepository::findForExport (the
 * confirmed-upcoming reg query, INV-3), QuoteRepository::findQuoteIdsByRegistrations
 * (the already-delegated quote side) and BatchQueryHelper (INV-3). Does NOT stream:
 * AdminAPIController::exportRegistrations keeps the headers + BOM + fputcsv (the
 * HTTP/response layer, not read-model).
 *
 * Moved VERBATIM from AdminAPIController::exportRegistrations (Task D3, behavior-
 * preserving strangle) — same SELECT (now in the repo), same column order, same
 * batch enrichment, same quote-number fallback, same sanitizeCsvCell control.
 *
 * Registered in plugin-config.php.
 */
final class AdminExportService
{
    public function __construct(
        private readonly RegistrationRepository $registrations,
        private readonly QuoteRepository $quotes,
    ) {}

    /**
     * Build the CSV export rows (assembled cells, pre-sanitisation) for confirmed
     * registrations of upcoming/dateless editions as of $today.
     *
     * The caller (the controller) writes the static header row + BOM and applies
     * sanitizeCsvCell() to each cell while streaming — the security control lives
     * here but is invoked per-cell at stream time so the raw assembled value stays
     * inspectable.
     *
     * Column order: [Naam, E-mail, Organisatie, Editie, Datum, Status, Offerte #].
     *
     * @param  string $today  Today's date (Y-m-d).
     * @return array<int,array<int,string>>  One ordered cell array per row.
     */
    public function buildExportRows(string $today): array
    {
        $registrations = $this->registrations->findForExport($today);

        // Batch-prime all per-row lookups so the export's query count stays
        // constant regardless of row count (audit 2.6): one user fetch + one
        // user-meta prime + one quote map + one quote-meta fetch replace the
        // previous ~4 queries per row. A missing user (deleted account) maps
        // to null and renders as blank cells, same as get_userdata() === false.
        $userIds = array_values(array_unique(array_filter(array_map(
            static fn($reg) => (int) ($reg->user_id ?? 0),
            $registrations,
        ))));
        $users = BatchQueryHelper::batchGetUsers($userIds);
        if ($userIds !== []) {
            update_meta_cache('user', $userIds);
        }

        $registrationIds = array_values(array_filter(array_map(
            static fn($reg) => (int) ($reg->id ?? 0),
            $registrations,
        )));
        $quoteIdsByRegistration = $this->quotes->findQuoteIdsByRegistrations($registrationIds);
        $quoteMeta = BatchQueryHelper::batchGetPostMeta(
            array_values($quoteIdsByRegistration),
            ['quote_number'],
        );

        $rows = [];
        foreach ($registrations as $reg) {
            $user = $users[(int) ($reg->user_id ?? 0)] ?? null;
            $name = $user ? $user->display_name : 'Onbekend';
            $email = $user ? $user->user_email : '';
            $org = $user ? (get_user_meta($user->ID, 'organisation', true) ?: '') : '';

            // Linked quote number from the batched map (fallback mirrors the
            // old per-row behaviour: 'Q-' . post ID when the meta is empty).
            $quoteNumber = '';
            $quoteId = $quoteIdsByRegistration[(int) ($reg->id ?? 0)] ?? 0;
            if ($quoteId) {
                $quoteNumber = (string) ($quoteMeta[$quoteId]['quote_number'] ?? '') ?: 'Q-' . $quoteId;
            }

            $rows[] = [
                (string) $name,
                (string) $email,
                (string) $org,
                (string) ($reg->edition_title ?? ''),
                (string) ($reg->edition_date ?? ''),
                (string) ($reg->status ?? ''),
                (string) $quoteNumber,
            ];
        }

        return $rows;
    }

    /**
     * Neutralise CSV / spreadsheet formula injection.
     *
     * Excel, LibreOffice and Google Sheets execute any cell whose first
     * character is `=`, `+`, `-`, `@`, TAB or CR. An attacker who can place
     * arbitrary text into a user-facing field (display_name, organisation,
     * edition title) could exfiltrate data via `=WEBSERVICE(...)` when an
     * admin opens the export. Prefix any such cell with a single quote so
     * the spreadsheet treats it as a literal string.
     *
     * Moved VERBATIM from AdminAPIController::sanitizeCsvCell (Task D3) — this
     * is export-specific; the controller invokes it per-cell while streaming.
     */
    public function sanitizeCsvCell(mixed $value): string
    {
        $str = (string) $value;
        if ($str === '') {
            return '';
        }
        $first = $str[0];
        if ($first === '=' || $first === '+' || $first === '-' || $first === '@' || $first === "\t" || $first === "\r") {
            return "'" . $str;
        }
        return $str;
    }
}
