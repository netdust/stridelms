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
 * The `status` key is the cross-cutting surface centralized here: both views
 * previously emitted the STORED-RAW `$editionStatus ?: 'open'`. That raw
 * behavior is reproduced verbatim (this dedup is behavior-preserving). Cluster
 * D's C1 fix resolves effective status in THIS single place.
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
     *   status:string
     * } $context Pre-fetched values (no queries inside).
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
            // Stored-raw status, verbatim. C1 (Cluster D) swaps this single
            // source to the effective status; do NOT change the semantics here.
            'status' => $context['status'] ?: 'open',
            'editUrl' => admin_url("post.php?post={$editionId}&action=edit"),
        ];
    }
}
