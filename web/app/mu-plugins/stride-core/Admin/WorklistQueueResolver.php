<?php

declare(strict_types=1);

namespace Stride\Admin;

use Stride\Domain\OfferingStatus;
use Stride\Domain\QuoteStatus;
use Stride\Domain\RegistrationStatus;
use Stride\Integrations\LearnDash\LearnDashHelper;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Enrollment\RegistrationTable;

/**
 * THE single definition of the Vandaag worklist queues.
 *
 * Both consumers read the SAME resolved id-sets, so the queue card's count and
 * the grid's click-through cannot drift on the definition (the RC-2 class of
 * bug — "Afgerond zonder certificaat: 3" opening a grid of 40 unrelated rows):
 *
 *   - AdminStatsService::getWorklistQueueCounts → count() of each set
 *     (cached in the stats transient, ≤120s staleness);
 *   - GET /admin/registrations?queue=<key>      → WHERE r.id IN (set),
 *     resolved live per request.
 *
 * The definition is shared; the counts read a TTL-bounded snapshot of it, so
 * a card can lag a live click-through by up to the stats TTL when an input
 * outside the bust set changes (e.g. a LearnDash certificate being issued).
 *
 * Queue keys are the CLIENT vocabulary (the ?queue= deep-link values the
 * Vandaag cards emit and grid.js consumes). The stats payload's legacy count
 * keys (waitlist_open / offerte_opvolging) are mapped in AdminStatsService.
 *
 * Predicates involve per-row PHP decisions (capacity, LD certificate lookup,
 * offerte label), so the queues resolve to explicit id-sets rather than SQL —
 * the id-set IS the contract. Resolution is memoized per request per
 * (edition-set, queue-subset); a single-queue request (the grid's ?queue=
 * path, re-hit on every pagination/sort/search interaction) only fetches the
 * statuses and runs the per-row work THAT queue needs — never e.g. a LD
 * certificate lookup per completed row to serve ?queue=pending.
 */
final class WorklistQueueResolver
{
    /** The ?queue= keys, in card order. */
    public const QUEUES = [
        'pending',
        'waitlist',
        'offerte',
        'nocert',
        'oldinterest',
        'interest_to_invite',
    ];

    /**
     * Interest rows older than this many days surface in "Oude interesse".
     */
    public const OLD_INTEREST_DAYS = 90;

    /** @var array<string, array<string, list<int>>> per-request memo, keyed by edition-set + queue-subset hash. */
    private array $memo = [];

    public function __construct(
        private readonly RegistrationRepository $registrations,
        private readonly EditionService $editions,
        private readonly EditionRepository $editionRepository,
    ) {}

    /**
     * The registration status(es) each queue's predicate starts from. Also
     * the client contract for a status-homogeneous armed select-all (grid.js
     * QUEUE_STATUS mirrors the single status per queue — pinned by the
     * cross-language contract test).
     *
     * @return array<string, list<string>> queue key => registration statuses.
     */
    private static function queueStatuses(): array
    {
        return [
            'pending'            => [RegistrationStatus::Pending->value],
            'waitlist'           => [RegistrationStatus::Waitlist->value],
            'offerte'            => [RegistrationStatus::Confirmed->value],
            'nocert'             => [RegistrationStatus::Completed->value],
            'oldinterest'        => [RegistrationStatus::Interest->value],
            'interest_to_invite' => [RegistrationStatus::Interest->value],
        ];
    }

    /**
     * The active-edition scope every queue reasons over — owned by the edition
     * domain (one predicate, no second copy here).
     *
     * @return array<int>
     */
    public function activeEditionIds(): array
    {
        return $this->editionRepository->findAdminActiveIds();
    }

    /**
     * The registration ids matching ONE queue, or null for an unknown key.
     *
     * @return list<int>|null
     */
    public function idsForQueue(string $queue, ?array $activeEditionIds = null): ?array
    {
        if (!in_array($queue, self::QUEUES, true)) {
            return null;
        }

        return $this->resolve($activeEditionIds ?? $this->activeEditionIds(), [$queue])[$queue];
    }

    /**
     * Resolve ALL queues in one pass over the active corpus.
     *
     * @param  array<int> $activeEditionIds
     * @return array<string, list<int>>  queue key => registration ids.
     */
    public function idsByQueue(array $activeEditionIds): array
    {
        return $this->resolve($activeEditionIds, self::QUEUES);
    }

    /**
     * Resolve the requested queue subset over the active corpus.
     *
     * One row fetch covers exactly the statuses the requested queues need;
     * the per-queue support work (capacity probes, offerte labels, LD
     * certificate lookups, planned-date meta) only runs for queues actually
     * requested — the expensive nocert/offerte passes never tax an unrelated
     * single-queue grid request.
     *
     * @param  array<int>   $activeEditionIds
     * @param  list<string> $queues  Subset of self::QUEUES (caller-validated).
     * @return array<string, list<int>>  queue key => registration ids (keys = $queues).
     */
    private function resolve(array $activeEditionIds, array $queues): array
    {
        $activeEditionIds = array_values(array_unique(array_filter(array_map('intval', $activeEditionIds))));

        $memoKey = md5(implode(',', $activeEditionIds)) . '|' . implode(',', $queues);
        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }

        $sets = array_fill_keys($queues, []);

        if (empty($activeEditionIds) || !RegistrationTable::exists()) {
            return $this->memo[$memoKey] = $sets;
        }

        $wants = array_fill_keys($queues, true);

        $statuses = [];
        foreach ($queues as $queue) {
            foreach (self::queueStatuses()[$queue] as $status) {
                $statuses[$status] = true;
            }
        }

        $rows = $this->registrations->findByEditionsAndStatuses(
            $activeEditionIds,
            array_keys($statuses),
        );

        if (empty($rows)) {
            return $this->memo[$memoKey] = $sets;
        }

        // Effective status per edition (INV-7) — only the waitlist queue's
        // open-capacity rule reads it.
        $effectiveStatuses = isset($wants['waitlist'])
            ? $this->editions->getEffectiveStatuses($activeEditionIds)
            : [];

        // Offerte resolver (the single paid-proxy definition) over confirmed regs.
        $offerteByReg = [];
        $exportedLabel = QuoteStatus::Exported->label();
        if (isset($wants['offerte'])) {
            $confirmedRegIds = [];
            foreach ($rows as $row) {
                if ($row->status === RegistrationStatus::Confirmed->value) {
                    $confirmedRegIds[] = (int) $row->id;
                }
            }
            // Lazy container read — AdminRegistrationQueryService consumes THIS
            // class for the queue param, so a constructor dependency would cycle.
            $offerteByReg = !empty($confirmedRegIds)
                ? ntdst_get(AdminRegistrationQueryService::class)->offerteStatusesForRegistrations($confirmedRegIds)
                : [];
        }

        // Pre-resolve per-edition lookups ONCE per distinct edition (CR-2/CR-3).
        $waitlistEditionIds = [];
        $completedEditionIds = [];
        $interestEditionIds = [];
        foreach ($rows as $row) {
            $editionId = (int) $row->edition_id;
            if ($row->status === RegistrationStatus::Waitlist->value) {
                $waitlistEditionIds[$editionId] = true;
            } elseif ($row->status === RegistrationStatus::Completed->value && !empty($row->completed_at)) {
                $completedEditionIds[$editionId] = true;
            } elseif ($row->status === RegistrationStatus::Interest->value) {
                $interestEditionIds[$editionId] = true;
            }
        }
        $hasSpotsByEdition = [];
        if (isset($wants['waitlist'])) {
            foreach (array_keys($waitlistEditionIds) as $editionId) {
                $hasSpotsByEdition[$editionId] = $this->editions->hasAvailableSpots($editionId);
            }
        }
        $courseIdByEdition = [];
        if (isset($wants['nocert'])) {
            foreach (array_keys($completedEditionIds) as $editionId) {
                $courseIdByEdition[$editionId] = $this->editions->getCourseId($editionId) ?? 0;
            }
        }

        // interest_to_invite: per-distinct-edition planned-date presence (one
        // batched meta read, owned by the edition repo — INV-3). A dated
        // edition means the formerly dateless interest anchor is now PLANNED.
        $datedByEdition = [];
        if (isset($wants['interest_to_invite']) && !empty($interestEditionIds)) {
            $datedByEdition = array_fill_keys(
                $this->editionRepository->filterIdsWithStartDate(array_keys($interestEditionIds)),
                true,
            );
        }

        $oldInterestCutoff = strtotime('-' . self::OLD_INTEREST_DAYS . ' days');

        foreach ($rows as $row) {
            $regId     = (int) $row->id;
            $userId    = (int) $row->user_id;
            $editionId = (int) $row->edition_id;
            $effective = $effectiveStatuses[$editionId] ?? null;

            switch ($row->status) {
                case RegistrationStatus::Pending->value:
                    if (isset($wants['pending'])) {
                        $sets['pending'][] = $regId;
                    }
                    break;

                case RegistrationStatus::Waitlist->value:
                    // Open capacity = edition not terminal/past (effective
                    // status) AND free spots (prefetched per distinct edition).
                    if (
                        isset($wants['waitlist'])
                        && $effective !== null
                        && !$effective->isTerminal()
                        && $effective !== OfferingStatus::Completed
                        && ($hasSpotsByEdition[$editionId] ?? false)
                    ) {
                        $sets['waitlist'][] = $regId;
                    }
                    break;

                case RegistrationStatus::Confirmed->value:
                    // Absent quote OR any label that is not Exported → follow-up.
                    if (isset($wants['offerte'])) {
                        $label = $offerteByReg[$regId] ?? null;
                        if ($label !== $exportedLabel) {
                            $sets['offerte'][] = $regId;
                        }
                    }
                    break;

                case RegistrationStatus::Completed->value:
                    if (!isset($wants['nocert']) || empty($row->completed_at)) {
                        break;
                    }
                    $courseId = $courseIdByEdition[$editionId] ?? 0;
                    if ($courseId <= 0) {
                        // No course → no certificate path → needs attention.
                        $sets['nocert'][] = $regId;
                        break;
                    }
                    // Cert link is a per-(course,user) fact — stays per-row.
                    if (LearnDashHelper::getCertificateLink($courseId, $userId) === '') {
                        $sets['nocert'][] = $regId;
                    }
                    break;

                case RegistrationStatus::Interest->value:
                    if (isset($wants['oldinterest'])) {
                        $registeredTs = $row->registered_at ? strtotime((string) $row->registered_at) : false;
                        if ($registeredTs !== false && $registeredTs < $oldInterestCutoff) {
                            $sets['oldinterest'][] = $regId;
                        }
                    }
                    // Counted INDEPENDENTLY of the age check — a row may belong
                    // to both queues (they answer different questions).
                    if (isset($wants['interest_to_invite']) && ($datedByEdition[$editionId] ?? false)) {
                        $sets['interest_to_invite'][] = $regId;
                    }
                    break;
            }
        }

        return $this->memo[$memoKey] = $sets;
    }
}
