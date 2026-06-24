<?php

declare(strict_types=1);

namespace Stride\Admin\Mappers;

/**
 * Stateless shaper that converts a batched edition context into the COMMON
 * admin-grid item shape shared by the edition LIST view
 * (AdminAPIController::getEditions) and the AGENDA view
 * (AdminAPIController::getEditionsAgendaView).
 *
 * Pure shaper (INV-3): it runs NO queries. The controller batches the course
 * titles + registration counts + status meta upfront (BatchQueryHelper) and
 * passes them in via $context, so this mapper is N+1-safe.
 *
 * It emits ONLY the fields both views shape identically — id, course{id,title},
 * capacity, registeredCount, status, editUrl. The view-specific keys (LIST:
 * title source, startDate/endDate, course.tags, venue; AGENDA: sessionId,
 * sessionTitle, date, startTime/endTime, venue fallback) stay in their
 * respective controller methods, which merge them onto this common base.
 *
 * The `status` key is the cross-cutting surface centralized here (INV-7): both
 * views now emit the EFFECTIVE status (stored + dates + session count, derived
 * by EditionService::getEffectiveStatuses) — the SAME read the typeahead
 * (getEditionOptions) already uses, so grid and typeahead agree. The controller
 * batch-resolves the effective-status map once per page and passes it in via
 * $context['effectiveStatuses'] (a map of editionId => OfferingStatus); this
 * mapper stays a pure shaper (no queries). The raw stored status is kept ONLY
 * as a defensive fallback if an id is absent from the map (it should not be).
 */
final class EditionAdminMapper
{
    /**
     * Shape the common edition-grid item from a pre-batched context.
     *
     * @param array{
     *   editionId:int,
     *   courseId:int,
     *   courseTitle:string,
     *   capacity:int,
     *   registeredCount:int,
     *   status:string,
     *   effectiveStatuses?:array<int, \Stride\Domain\OfferingStatus>
     * } $context Pre-fetched values (no queries inside). effectiveStatuses is
     *   the batched editionId => OfferingStatus map the controller resolves via
     *   EditionService::getEffectiveStatuses() (INV-7).
     * @return array{
     *   id:int,
     *   course:array{id:int, title:string},
     *   capacity:int,
     *   registeredCount:int,
     *   status:string,
     *   editUrl:string
     * }
     */
    public static function toItem(array $context): array
    {
        $editionId = (int) $context['editionId'];

        return [
            'id' => $editionId,
            'course' => [
                'id' => (int) $context['courseId'],
                'title' => (string) $context['courseTitle'],
            ],
            'capacity' => (int) $context['capacity'],
            'registeredCount' => (int) $context['registeredCount'],
            // INV-7: emit the EFFECTIVE status (the same read the typeahead uses)
            // so grid and typeahead agree. Defensive fallback to the raw stored
            // status only if the id is absent from the batched map.
            'status' => self::resolveStatus($editionId, $context),
            'editUrl' => admin_url("post.php?post={$editionId}&action=edit"),
        ];
    }

    /**
     * Resolve the display status: effective (INV-7) from the batched map, with
     * the raw stored status (`?: 'open'`) as a defensive fallback.
     *
     * @param array<string, mixed> $context
     */
    private static function resolveStatus(int $editionId, array $context): string
    {
        $effective = $context['effectiveStatuses'][$editionId] ?? null;
        if ($effective instanceof \Stride\Domain\OfferingStatus) {
            return $effective->value;
        }

        // Fallback: id missing from the effective-status map (should not happen —
        // the controller batches every visible edition id). Log so a real gap is
        // visible, then degrade to the stored raw status.
        ntdst_log('admin')->warning('EditionAdminMapper: effective status missing for edition; falling back to stored status', [
            'edition_id' => $editionId,
        ]);

        return ($context['status'] ?? '') ?: 'open';
    }
}
