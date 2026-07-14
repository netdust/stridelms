<?php

declare(strict_types=1);

namespace Stride\Admin;

use Stride\Domain\OfferingStatus;
use Stride\Domain\QuoteStatus;
use Stride\Domain\RegistrationStatus;
use Stride\Infrastructure\BatchQueryHelper;
use Stride\Integrations\LearnDash\LearnDashHelper;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Enrollment\RegistrationTable;

/**
 * THE single definition of the Vandaag worklist queues.
 *
 * Both consumers read the SAME resolved id-sets, so the queue card's count and
 * the grid's click-through can never drift (the RC-2 class of bug — "Afgerond
 * zonder certificaat: 3" opening a grid of 40 unrelated rows):
 *
 *   - AdminStatsService::getWorklistQueueCounts → count() of each set;
 *   - GET /admin/registrations?queue=<key>      → WHERE r.id IN (set).
 *
 * Queue keys are the CLIENT vocabulary (the ?queue= deep-link values the
 * Vandaag cards emit and grid.js consumes). The stats payload's legacy count
 * keys (waitlist_open / offerte_opvolging) are mapped in AdminStatsService.
 *
 * Predicates involve per-row PHP decisions (capacity, LD certificate lookup,
 * offerte label), so the queues resolve to explicit id-sets rather than SQL —
 * the id-set IS the contract. Resolution is memoized per request per
 * edition-set; the corpus is bounded by the active-edition scope.
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

    /** @var array<string, array<string, list<int>>> per-request memo, keyed by edition-set hash. */
    private array $memo = [];

    public function __construct(
        private readonly RegistrationRepository $registrations,
        private readonly EditionService $editions,
        private readonly EditionRepository $editionRepository,
    ) {}

    /**
     * The active-edition scope every queue reasons over — owned by the edition
     * domain (one predicate, no second copy here).
     *
     * @return array<int>
     */
    public function activeEditionIds(): array
    {
        return $this->editionRepository->findActiveDateScopedIds();
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

        return $this->idsByQueue($activeEditionIds ?? $this->activeEditionIds())[$queue];
    }

    /**
     * Resolve ALL queues in one pass over the active corpus.
     *
     * @param  array<int> $activeEditionIds
     * @return array<string, list<int>>  queue key => registration ids.
     */
    public function idsByQueue(array $activeEditionIds): array
    {
        $activeEditionIds = array_values(array_unique(array_filter(array_map('intval', $activeEditionIds))));

        $memoKey = md5(implode(',', $activeEditionIds));
        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }

        $sets = array_fill_keys(self::QUEUES, []);

        if (empty($activeEditionIds) || !RegistrationTable::exists()) {
            return $this->memo[$memoKey] = $sets;
        }

        // One row fetch covers every queue's status (structured columns, M5).
        $rows = $this->registrations->findByEditionsAndStatuses(
            $activeEditionIds,
            [
                RegistrationStatus::Pending->value,
                RegistrationStatus::Waitlist->value,
                RegistrationStatus::Confirmed->value,
                RegistrationStatus::Completed->value,
                RegistrationStatus::Interest->value,
            ],
        );

        if (empty($rows)) {
            return $this->memo[$memoKey] = $sets;
        }

        // Effective status per edition (INV-7) — one batched decision pass.
        $effectiveStatuses = $this->editions->getEffectiveStatuses($activeEditionIds);

        // Offerte resolver (the single paid-proxy definition) over confirmed regs.
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
        $exportedLabel = QuoteStatus::Exported->label();

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
        foreach (array_keys($waitlistEditionIds) as $editionId) {
            $hasSpotsByEdition[$editionId] = $this->editions->hasAvailableSpots($editionId);
        }
        $courseIdByEdition = [];
        foreach (array_keys($completedEditionIds) as $editionId) {
            $courseIdByEdition[$editionId] = $this->editions->getCourseId($editionId) ?? 0;
        }

        // interest_to_invite: per-distinct-edition start_date presence (one
        // batched meta read). A non-empty start_date means the formerly
        // dateless interest anchor now has a PLANNED date → invite.
        $datedByEdition = [];
        if (!empty($interestEditionIds)) {
            $startMeta = BatchQueryHelper::batchGetPostMeta(
                array_keys($interestEditionIds),
                ['_ntdst_start_date'],
            );
            foreach (array_keys($interestEditionIds) as $editionId) {
                $startDate = $startMeta[$editionId]['_ntdst_start_date'] ?? null;
                $datedByEdition[$editionId] = is_string($startDate) && trim($startDate) !== '';
            }
        }

        $oldInterestCutoff = strtotime('-' . self::OLD_INTEREST_DAYS . ' days');

        foreach ($rows as $row) {
            $regId     = (int) $row->id;
            $userId    = (int) $row->user_id;
            $editionId = (int) $row->edition_id;
            $effective = $effectiveStatuses[$editionId] ?? null;

            switch ($row->status) {
                case RegistrationStatus::Pending->value:
                    $sets['pending'][] = $regId;
                    break;

                case RegistrationStatus::Waitlist->value:
                    // Open capacity = edition not terminal/past (effective
                    // status) AND free spots (prefetched per distinct edition).
                    if (
                        $effective !== null
                        && !$effective->isTerminal()
                        && $effective !== OfferingStatus::Completed
                        && ($hasSpotsByEdition[$editionId] ?? false)
                    ) {
                        $sets['waitlist'][] = $regId;
                    }
                    break;

                case RegistrationStatus::Confirmed->value:
                    // Absent quote OR any label that is not Exported → follow-up.
                    $label = $offerteByReg[$regId] ?? null;
                    if ($label !== $exportedLabel) {
                        $sets['offerte'][] = $regId;
                    }
                    break;

                case RegistrationStatus::Completed->value:
                    if (empty($row->completed_at)) {
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
                    $registeredTs = $row->registered_at ? strtotime((string) $row->registered_at) : false;
                    if ($registeredTs !== false && $registeredTs < $oldInterestCutoff) {
                        $sets['oldinterest'][] = $regId;
                    }
                    // Counted INDEPENDENTLY of the age check — a row may belong
                    // to both queues (they answer different questions).
                    if ($datedByEdition[$editionId] ?? false) {
                        $sets['interest_to_invite'][] = $regId;
                    }
                    break;
            }
        }

        return $this->memo[$memoKey] = $sets;
    }
}
