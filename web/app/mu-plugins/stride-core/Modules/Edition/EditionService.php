<?php

declare(strict_types=1);

namespace Stride\Modules\Edition;

use Stride\Contracts\EditionQueryInterface;
use Stride\Domain\OfferingStatus;
use Stride\Domain\Money;
use Stride\Infrastructure\AbstractService;

/**
 * Edition business logic.
 *
 * Implements EditionQueryInterface for cross-module queries.
 */
class EditionService extends AbstractService implements EditionQueryInterface
{
    public function __construct(
        private readonly EditionRepository $repository,
        private readonly SessionRepository $sessions,
        private readonly \Stride\Modules\Membership\MembershipService $membership,
    ) {
        parent::__construct();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Edition Service',
            'description' => 'Manages scheduled course offerings',
            'priority' => 10,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'edition';
    }

    protected function init(): void
    {
        EditionCPT::register();
        SessionCPT::register();

        // Register sub-components as singletons
        $sessionRepo = ntdst_get(SessionRepository::class);
        $sessionService = new SessionService($sessionRepo);
        ntdst_set(SessionService::class, fn() => $sessionService);

        $completion = new EditionCompletion($this, $this->repository, $sessionService, $sessionRepo);
        ntdst_set(EditionCompletion::class, fn() => $completion);
        add_action('stride/attendance/marked', [$completion, 'onAttendanceMarked']);
        add_action('learndash_course_completed', [$completion, 'onLearnDashCourseCompleted']);

        // Admin UI + settings (registers own hooks in constructor)
        new \Stride\Admin\StrideSettingsService();
        new Admin\EditionAdminController(
            $this,
            $this->repository,
            $sessionService,
            $sessionRepo,
            ntdst_get(\Stride\Modules\Attendance\AttendanceRepository::class),
        );

        new Admin\RegistrationModalController(
            $this,
            $this->repository,
            $sessionService,
            ntdst_get(\Stride\Modules\Edition\SessionSelection::class),
            ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class),
        );

        // Register hooks for capacity updates
        add_action('stride/registration/created', [$this, 'onRegistrationCreated']);
        add_action('stride/registration/cancelled', [$this, 'onRegistrationCancelled']);

        // Cascade delete: clean up sessions and registrations when an edition is deleted
        add_action('before_delete_post', [$this, 'onEditionDeleted']);
        add_action('wp_trash_post', [$this, 'onEditionTrashed']);

        // Route /edities/<slug>/ → pure-LD course fallback when slug isn't an edition
        ntdst_get(EditionRouter::class)->register();
    }

    // === EditionQueryInterface Implementation ===

    public function hasAvailableSpots(int $editionId): bool
    {
        $capacity = $this->getCapacity($editionId);

        // Capacity 0 means unlimited (e.g., e-learning courses)
        if ($capacity === 0) {
            return true;
        }

        $registered = $this->getRegisteredCount($editionId);

        return $registered < $capacity;
    }

    public function getRegisteredCount(int $editionId): int
    {
        $cacheKey = 'stride_edition_reg_count_' . $editionId;
        $cached = get_transient($cacheKey);
        if ($cached !== false) {
            return (int) $cached;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'vad_registrations';

        // The seat-holding statuses come from the enum (F-V6): one capacity
        // definition for this counter AND the capacity melding.
        $statuses = \Stride\Domain\RegistrationStatus::capacityValues();
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE edition_id = %d AND status IN ({$placeholders})",
            $editionId,
            ...$statuses,
        ));

        set_transient($cacheKey, $count, 60);

        return $count;
    }

    private function invalidateRegisteredCountCache(int $editionId): void
    {
        if ($editionId > 0) {
            delete_transient('stride_edition_reg_count_' . $editionId);
        }
    }

    public function getCapacity(int $editionId): int
    {
        return (int) $this->repository->getField($editionId, 'capacity', 0);
    }

    public function getStatus(int $editionId): OfferingStatus
    {
        $status = $this->repository->getField($editionId, 'status', 'open');

        return OfferingStatus::tryFrom($status) ?? OfferingStatus::Open;
    }

    public function getCourseId(int $editionId): ?int
    {
        $courseId = $this->repository->getField($editionId, 'course_id');

        return $courseId ? (int) $courseId : null;
    }

    /**
     * Check if this edition is for an online course.
     * Derives format from the linked LearnDash course's stride_format taxonomy.
     */
    public function isOnline(int $editionId): bool
    {
        $courseId = $this->getCourseId($editionId);
        if (!$courseId) {
            return false;
        }

        $formats = get_the_terms($courseId, 'stride_format');
        if (!$formats || is_wp_error($formats)) {
            return false;
        }

        foreach ($formats as $fmt) {
            if (in_array($fmt->slug, ['online', 'webinar', 'e-learning'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the enrollment form key for this edition.
     * Returns empty string if no form is configured.
     */
    public function getEnrollmentForm(int $editionId): string
    {
        return (string) $this->repository->getField($editionId, 'enrollment_form', 'default');
    }

    /**
     * Check if this edition has an enrollment form configured.
     */
    public function hasEnrollmentForm(int $editionId): bool
    {
        return $this->getEnrollmentForm($editionId) !== '';
    }

    public function exists(int $editionId): bool
    {
        $result = $this->repository->find($editionId);

        return !is_wp_error($result);
    }

    public function requiresApproval(int $editionId): bool
    {
        return (bool) $this->repository->getField($editionId, 'requires_approval', false);
    }

    // === Public API ===

    /**
     * Check if a user is a member.
     *
     * Delegates to MembershipService. Kept as a thin pass-through so
     * existing callers (`$editionService->isMember($id)`) keep working.
     */
    public function isMember(int $userId): bool
    {
        return $this->membership->isMember($userId);
    }

    /**
     * Get price for edition.
     *
     * Single-price contract: every offering has ONE price (`price_non_member`,
     * the canonical field). There is no member/non-member branch — discounts are
     * vouchers, not membership. The `stride/membership/price` filter is kept as an
     * inert escape hatch so a future client can still branch on membership; it
     * receives the computed $isMember flag for that purpose, but core no longer
     * selects a different meta field on it.
     */
    public function getPrice(int $editionId, ?int $userId = null): Money
    {
        // Still computed so the escape-hatch filter keeps a stable 4-arg
        // signature ($price, $editionId, $userId, $isMember) — core does not
        // branch on it.
        $isMember = $userId !== null ? $this->isMember($userId) : false;
        // Stored value is canonical CENTS (admin save ×100, admin display ÷100).
        // Read it as cents — never Money::eur(), which would treat it as euros
        // and render 100× too large.
        $amount = (int) $this->repository->getField($editionId, 'price_non_member', 0);
        $price = Money::cents($amount);

        return apply_filters('stride/membership/price', $price, $editionId, $userId, $isMember);
    }

    /**
     * Check if enrollment is allowed.
     *
     * Combines admin-managed status, capacity, AND a calendar-aware past-check.
     * The past-check guards against an admin who forgot to flip an Open edition
     * to Completed/Archived after its end_date passed — we won't let users
     * enroll in something that already happened.
     */
    public function canEnroll(int $editionId): bool
    {
        // Effective status folds in past-date and missing-sessions overrides:
        // a klassikaal edition with no scheduled sessions reads as Announcement
        // (interest-only), a past edition reads as Completed, and so on. We
        // delegate to that primitive so the answer matches what the visitor
        // actually sees in the CTA chain.
        $status = $this->getEffectiveStatus($editionId);

        if (!$status->allowsEnrollment()) {
            return false;
        }

        return $this->hasAvailableSpots($editionId);
    }

    /**
     * True when the edition's end_date (or start_date as fallback) is in the past.
     *
     * Pure calendar check — independent of OfferingStatus. Use this anywhere
     * UI logic depends on "can a user still take action on this edition?".
     */
    public function isPast(int $editionId): bool
    {
        $endDate   = (string) $this->repository->getField($editionId, 'end_date', '');
        $startDate = (string) $this->repository->getField($editionId, 'start_date', '');

        return self::isPastDates($endDate ?: null, $startDate ?: null);
    }

    /**
     * The calendar predicate behind isPast(), on prefetched date values.
     *
     * end_date wins; start_date is the fallback; no dates at all → not past.
     */
    private static function isPastDates(?string $endDate, ?string $startDate): bool
    {
        $reference = $endDate ?: $startDate;
        if (!$reference) {
            return false;
        }

        return strtotime($reference) < strtotime(date('Y-m-d'));
    }

    /**
     * Alias for canEnroll for handler compatibility.
     */
    public function isEnrollmentOpen(int $editionId): bool
    {
        return $this->canEnroll($editionId);
    }

    /**
     * Display status for the public frontend.
     *
     * Stored `_ntdst_status` reflects admin intent — but several conditions
     * can override what we actually show:
     *
     *  - Terminal stored statuses (Cancelled/Completed/Archived) always win
     *    over derived overrides.
     *  - Past end_date → Completed, regardless of stored status.
     *  - Klassikaal edition with zero scheduled sessions → Announcement.
     *    You can't enroll in a date that doesn't exist yet; the visitor
     *    can express interest until the admin schedules sessions.
     *
     * Use this anywhere a status is shown to a visitor. Use `getStatus()`
     * when admin intent matters (queries by stored status, transitions, etc.).
     */
    public function getEffectiveStatus(int $editionId): OfferingStatus
    {
        return $this->getEffectiveStatusFromPrefetched(
            $this->getStatus($editionId),
            ((string) $this->repository->getField($editionId, 'end_date', '')) ?: null,
            ((string) $this->repository->getField($editionId, 'start_date', '')) ?: null,
            $this->isClassroom($editionId),
            $this->sessions->countByEdition($editionId),
        );
    }

    /**
     * The effective-status DECISION ENGINE, operating on prefetched inputs.
     *
     * INV-7: this is the single place where the display-status rules live.
     * `getEffectiveStatus()` (single edition) and `getEffectiveStatuses()`
     * (batch, catalog pre-pass) both delegate here — never re-implement
     * these rules anywhere else, and never call this with partial data
     * (a wrong session count or classroom flag changes the decision).
     *
     * Rules, in priority order (see getEffectiveStatus() docblock):
     *  1. Terminal stored statuses (Cancelled/Completed/Archived) win.
     *  2. Past end_date (start_date fallback) → Completed.
     *  3. Classroom edition with zero published sessions → Announcement.
     *  4. Otherwise: the stored admin intent.
     */
    public function getEffectiveStatusFromPrefetched(
        OfferingStatus $stored,
        ?string $endDate,
        ?string $startDate,
        bool $isClassroom,
        int $publishedSessionCount,
    ): OfferingStatus {
        if ($stored->isTerminal()) {
            return $stored;
        }
        if (self::isPastDates($endDate, $startDate)) {
            return OfferingStatus::Completed;
        }
        if ($isClassroom && $publishedSessionCount === 0) {
            return OfferingStatus::Announcement;
        }

        return $stored;
    }

    /**
     * Batch variant of getEffectiveStatus() for catalog/list surfaces.
     *
     * Primes the WP post/meta/term caches and batches the session count
     * (SessionRepository::countByEditions, one GROUP BY) so the cost is
     * independent of the number of editions. The DECISION for every id
     * still goes through getEffectiveStatusFromPrefetched() — one INV-7
     * decision point, never forked.
     *
     * Per-request only: nothing here is cached across requests, so a
     * status change is reflected on the next page load.
     *
     * @param array<int> $editionIds
     * @return array<int, OfferingStatus> Map of edition_id => effective status
     */
    public function getEffectiveStatuses(array $editionIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $editionIds))));
        if (empty($ids)) {
            return [];
        }

        // Prime posts + meta for the editions, then posts + meta + terms for
        // their courses (isClassroom reads the course's stride_format terms).
        _prime_post_caches($ids, false, true);

        $courseIds = [];
        foreach ($ids as $id) {
            $courseId = $this->getCourseId($id);
            if ($courseId) {
                $courseIds[$courseId] = $courseId;
            }
        }
        if (!empty($courseIds)) {
            _prime_post_caches(array_values($courseIds), true, true);
        }

        $sessionCounts = $this->sessions->countByEditions($ids);

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = $this->getEffectiveStatusFromPrefetched(
                $this->getStatus($id),
                ((string) $this->repository->getField($id, 'end_date', '')) ?: null,
                ((string) $this->repository->getField($id, 'start_date', '')) ?: null,
                $this->isClassroom($id),
                $sessionCounts[$id] ?? 0,
            );
        }

        return $out;
    }

    /**
     * Pick the primary edition for a course's CTA from a set of ALREADY-ACTIVE
     * edition ids (Task 3.4 / B4) — the single home for the "which cohort drives
     * the enroll button" policy that used to live in single-sfwd-courses.php and
     * the catalog course-card prepass.
     *
     * THE RULE: among the given ids, return the FIRST whose effective status
     * (INV-7, via getEffectiveStatuses) allowsEnrollment(); if none is enrollable,
     * return the first id; if the array is empty, return null.
     *
     * B4: a course can have a RUNNING cohort (active, not enrollable) AND an OPEN
     * cohort (enrollable). The enrollable cohort must win even when it is not
     * first — otherwise the CTA reads "Niet beschikbaar" while an open cohort
     * exists. This is policy (a ranking), not a pass-through.
     *
     * Caller contract: pass the course's active edition ids (e.g. from
     * EditionRepository::findActiveIdsByCourse()). Effective status may still flip
     * a stale member of that set to a non-enrollable state — which is exactly why
     * the pick reads effective status rather than trusting the stored value.
     *
     * @param array<int> $activeEditionIds Active edition ids, in caller order.
     * @return int|null The primary edition id, or null when the set is empty.
     */
    public function getPrimaryEdition(array $activeEditionIds): ?int
    {
        $ids = array_values(array_filter(array_map('intval', $activeEditionIds)));
        if (empty($ids)) {
            return null;
        }

        $statuses = $this->getEffectiveStatuses($ids);

        foreach ($ids as $id) {
            if (($statuses[$id] ?? null)?->allowsEnrollment()) {
                return $id;
            }
        }

        // No enrollable cohort: the first active edition is still the primary.
        return $ids[0];
    }

    /**
     * Default upper bound on the catalog enumeration (ids only — memory guard).
     * The theme passes STRIDENCE_CATALOG_MAX_ITEMS in; stride-core must NOT
     * reference the theme constant (INV-5), so it carries its own default.
     */
    public const CATALOG_MAX_ITEMS = 500;

    /** The catalog keys getCatalogItems() understands. */
    private const ONLINE_FORMAT_SLUGS    = ['online', 'webinar', 'e-learning'];
    private const CLASSROOM_FORMAT_SLUGS = ['klassikaal', 'classroom'];

    /**
     * THE catalog item list for a catalog key — the POLICY that used to live in
     * helpers/catalog.php (Cluster 3 / Task 3.2). Composes the repo's eligible
     * enumeration (Task 3.1) into the fully-shaped, light item list the theme's
     * INV-7 batch prepass consumes:
     *
     *   - 'klassikaal': eligible editions, format-excluded (an online-only-format
     *     course's edition is dropped), NO pure-LD courses, returned UNORDERED —
     *     the theme applies its KLASSIKAAL band-ordering presentation on top.
     *   - 'online': eligible editions of online-format courses PLUS pure-LD
     *     online courses (kind 'course') that never had an edition. Flat.
     *
     * Returns the exact struct the prepass + pure-renderer partials expect, so
     * neither is touched by this extraction:
     *   list<
     *     array{kind:'edition', edition:array{id,title,course_id,start_date,
     *       end_date,venue,price,capacity,status,spots_remaining},
     *       themes:list<string>}
     *     | array{kind:'course', course_id:int, themes:list<string>}
     *   >
     *
     * INV-3: every query is a repo call (findCatalogEligibleIds /
     * findOnlineFormatCourseIds / courseIdsWithAnyEdition / findFields). INV-5:
     * no theme symbol is referenced — theme slugs are read here via the
     * taxonomy, not via stridence_catalog_theme_slugs(). INV-7: the item's
     * `status` is the RAW stored status passed through to the prepass, which
     * applies effective-status — this method does NOT pre-apply it (preserve
     * the existing wiring; do not double-apply).
     *
     * @param string $catalog 'klassikaal' or 'online' (anything not 'online'
     *                         is treated as klassikaal, matching the theme).
     * @return list<array<string, mixed>>
     */
    public function getCatalogItems(string $catalog, int $limit = self::CATALOG_MAX_ITEMS): array
    {
        return $catalog === 'online'
            ? $this->getOnlineCatalogItems($limit)
            : $this->getKlassikaalCatalogItems($limit);
    }

    /**
     * /klassikaal policy: all eligible editions, then drop editions of
     * online-ONLY-format courses (online format present AND no classroom
     * format). Returned UNORDERED — band-ordering is theme presentation.
     *
     * @return list<array<string, mixed>>
     */
    private function getKlassikaalCatalogItems(int $limit): array
    {
        $ids = $this->repository->findCatalogEligibleIds(null, $limit);
        $this->warnIfCapped(count($ids), $limit, 'klassikaal editions');

        $items = $this->hydrateEditionItems($ids);

        return array_values(array_filter($items, function (array $item): bool {
            $courseId = (int) ($item['edition']['course_id'] ?? 0);
            if (!$courseId) {
                return true;
            }

            return !$this->isOnlineOnlyFormat($courseId);
        }));
    }

    /**
     * /online policy: (a) eligible editions of online-format courses, plus
     * (b) pure-LD online courses (online format, never had an edition) tagged
     * kind 'course'. Flat — no band-ordering.
     *
     * @return list<array<string, mixed>>
     */
    private function getOnlineCatalogItems(int $limit): array
    {
        $onlineCourseIds = $this->repository->findOnlineFormatCourseIds($limit);
        $this->warnIfCapped(count($onlineCourseIds), $limit, 'online courses');

        if (empty($onlineCourseIds)) {
            return [];
        }

        // (a) Active editions of online courses.
        $editionIds = $this->repository->findCatalogEligibleIds($onlineCourseIds, $limit);
        $this->warnIfCapped(count($editionIds), $limit, 'online editions');
        $items = $this->hydrateEditionItems($editionIds);

        // (b) Pure-LD online courses (never had an edition at all).
        $withEditions = $this->repository->courseIdsWithAnyEdition($onlineCourseIds);
        $pureLdIds    = array_values(array_diff($onlineCourseIds, $withEditions));

        if (!empty($pureLdIds)) {
            _prime_post_caches($pureLdIds, true, true);
            foreach ($pureLdIds as $courseId) {
                if (get_post_status($courseId) !== 'publish') {
                    continue;
                }
                $items[] = [
                    'kind'      => 'course',
                    'course_id' => $courseId,
                    'themes'    => $this->courseThemeSlugs($courseId),
                ];
            }
        }

        return $items;
    }

    /** Default item cap per homepage-teaser strip (archive-sfwd-courses.php). */
    public const ARCHIVE_TEASER_LIMIT = 6;

    /**
     * Homepage SEO teaser strip items (archive-sfwd-courses.php) — Cluster 3 /
     * Task 3.3. DISTINCT from getCatalogItems(): the teaser is a 6-item homepage
     * strip, NOT the full catalog. Its behaviour is PRESERVED, not converged to
     * the canonical date-window rule (Stefan, 2026-06-30 — product ruling):
     *
     *   - 'classroom': ACTIVE-status-ONLY editions (NO date window — a past-end
     *     active classroom edition still shows), editions of online-format
     *     courses EXCLUDED, dateless EXCLUDED (start_date EXISTS-join), capped.
     *   - 'online': the CANONICAL date-window applies (past-grace dropped),
     *     online-format-course-scoped, dateless EXCLUDED for the teaser, capped,
     *     then pure-LD online courses top up the remainder (kind 'course').
     *
     * Returns the exact prepass struct getCatalogItems() returns, so the INV-7
     * batch prepass + pure-renderer partials are untouched. INV-3: every query
     * is a repo call. INV-5: no theme symbol referenced (the cap is passed in,
     * theme slugs are read via the taxonomy here).
     *
     * @param string $strip 'classroom' or 'online'.
     * @return list<array<string, mixed>>
     */
    public function getArchiveTeaserItems(string $strip, int $limit = self::ARCHIVE_TEASER_LIMIT): array
    {
        // The online-format course set drives both strips: the classroom strip
        // EXCLUDES editions of these courses; the online strip is SCOPED to them.
        $onlineCourseIds = $this->repository->findOnlineFormatCourseIds(self::CATALOG_MAX_ITEMS);

        if ($strip === 'online') {
            return $this->getArchiveOnlineTeaserItems($onlineCourseIds, $limit);
        }

        $ids = $this->repository->findArchiveClassroomTeaserIds($onlineCourseIds, $limit);

        return $this->hydrateEditionItems($ids);
    }

    /**
     * 'online' teaser strip: date-windowed editions of online-format courses,
     * dateless-excluded, capped, then pure-LD online courses topping up the
     * remainder — the exact behaviour of the inline archive query (Task 3.3).
     *
     * @param list<int> $onlineCourseIds
     * @return list<array<string, mixed>>
     */
    private function getArchiveOnlineTeaserItems(array $onlineCourseIds, int $limit): array
    {
        if (empty($onlineCourseIds)) {
            return [];
        }

        $editionIds = $this->repository->findArchiveOnlineTeaserIds($onlineCourseIds, $limit);
        $items = $this->hydrateEditionItems($editionIds);

        // Top up with pure-LD online courses (never had an edition at all). A
        // course with only past editions is off-catalog until a new one is
        // scheduled, so courseIdsWithAnyEdition() is the right exclusion.
        $remaining = $limit - count($items);
        if ($remaining <= 0) {
            return $items;
        }

        $withEditions = $this->repository->courseIdsWithAnyEdition($onlineCourseIds);
        $pureLdIds    = array_values(array_diff($onlineCourseIds, $withEditions));
        if (empty($pureLdIds)) {
            return $items;
        }

        $pureLdIds = array_slice($pureLdIds, 0, $remaining);
        _prime_post_caches($pureLdIds, true, true);
        foreach ($pureLdIds as $courseId) {
            if (get_post_status($courseId) !== 'publish') {
                continue;
            }
            $items[] = [
                'kind'      => 'course',
                'course_id' => $courseId,
                'themes'    => $this->courseThemeSlugs($courseId),
            ];
        }

        return $items;
    }

    /**
     * Hydrate eligible edition IDs into the light item structs the prepass
     * expects. Batches post/meta/term caches first. Preserves the INF-1
     * published-course guard verbatim: an edition whose course is no longer
     * published (trashed/draft/private/deleted) produces no public card —
     * get_post_status() returns a status for TRASHED posts too, so the plain
     * get_post() null-check only catches hard deletes. Course-less editions
     * (course_id 0) stay eligible.
     *
     * @param list<int> $editionIds
     * @return list<array{kind: string, edition: array<string, mixed>, themes: list<string>}>
     */
    private function hydrateEditionItems(array $editionIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $editionIds))));
        if (empty($ids)) {
            return [];
        }

        _prime_post_caches($ids, false, true);

        $courseIds = [];
        foreach ($ids as $id) {
            $courseId = (int) $this->repository->getField($id, 'course_id', 0);
            if ($courseId) {
                $courseIds[$courseId] = $courseId;
            }
        }
        if (!empty($courseIds)) {
            _prime_post_caches(array_values($courseIds), true, true);
        }

        $items = [];
        foreach ($ids as $id) {
            $post = get_post($id);
            if (!$post) {
                continue;
            }
            $fields   = $this->repository->findFields($id);
            $courseId = (int) ($fields['course_id'] ?? 0);

            // INF-1: edition of a non-published course is suppressed.
            if ($courseId && get_post_status($courseId) !== 'publish') {
                continue;
            }

            $items[] = [
                'kind'    => 'edition',
                'edition' => [
                    'id'              => $id,
                    'title'           => $post->post_title,
                    'course_id'       => $courseId ?: null,
                    'start_date'      => $fields['start_date'] ?? null,
                    'end_date'        => $fields['end_date'] ?? null,
                    'venue'           => $fields['venue'] ?? null,
                    'price'           => $fields['price'] ?? null,
                    'capacity'        => $fields['capacity'] ?? null,
                    'status'          => $fields['status'] ?? 'open',
                    'spots_remaining' => $fields['spots_remaining'] ?? null,
                ],
                'themes'  => $courseId ? $this->courseThemeSlugs($courseId) : [],
            ];
        }

        return $items;
    }

    /**
     * True when the course has an online format AND no classroom format —
     * the "online-only" exclusion rule for /klassikaal.
     */
    private function isOnlineOnlyFormat(int $courseId): bool
    {
        $formats = get_the_terms($courseId, 'stride_format');
        if (!$formats || is_wp_error($formats)) {
            return false;
        }
        $slugs = wp_list_pluck($formats, 'slug');

        $isOnline    = (bool) array_intersect($slugs, self::ONLINE_FORMAT_SLUGS);
        $isClassroom = (bool) array_intersect($slugs, self::CLASSROOM_FORMAT_SLUGS);

        return $isOnline && !$isClassroom;
    }

    /**
     * stride_theme slugs for a course (term cache expected primed). Read here
     * via the taxonomy directly — INV-5 forbids stride-core calling the theme's
     * stridence_catalog_theme_slugs().
     *
     * @return list<string>
     */
    private function courseThemeSlugs(int $courseId): array
    {
        $terms = get_the_terms($courseId, 'stride_theme');
        if (!$terms || is_wp_error($terms)) {
            return [];
        }

        return array_values(wp_list_pluck($terms, 'slug'));
    }

    /**
     * Observability for the enumeration cap (moved from the theme's
     * stridence_catalog_warn_if_capped). A result filling the cap has silently
     * truncated the catalog — surface it rather than presenting it as whole.
     */
    private function warnIfCapped(int $count, int $limit, string $context): void
    {
        if ($count >= $limit) {
            ntdst_log('edition')->warning('catalog enumeration filled the cap — items beyond it are silently hidden', [
                'context' => $context,
                'cap'     => $limit,
            ]);
        }
    }

    /**
     * True when the edition's course is classified as classroom format.
     *
     * Differs from `!isOnline()`: requires a positive classroom signal so
     * we don't accidentally apply klassikaal-specific rules to editions
     * without any course taxonomy (data-invalid editions, test fixtures,
     * legacy imports).
     */
    private function isClassroom(int $editionId): bool
    {
        $courseId = $this->getCourseId($editionId);
        if (!$courseId) {
            return false;
        }
        $formats = get_the_terms($courseId, 'stride_format');
        if (!$formats || is_wp_error($formats)) {
            return false;
        }
        foreach ($formats as $fmt) {
            if (in_array($fmt->slug, ['klassikaal', 'classroom'], true)) {
                return true;
            }
        }
        return false;
    }

    // === Event Handlers ===

    /**
     * Handle registration created event.
     *
     * @param array<string, mixed> $data
     */
    public function onRegistrationCreated(array $data): void
    {
        $editionId = (int) ($data['edition_id'] ?? 0);
        $this->invalidateRegisteredCountCache($editionId);

        if ($editionId && !$this->hasAvailableSpots($editionId)) {
            $this->repository->updateStatus($editionId, OfferingStatus::Full);
        }
    }

    /**
     * Handle registration cancelled event.
     *
     * @param array<string, mixed> $data
     */
    public function onRegistrationCancelled(array $data): void
    {
        $editionId = (int) ($data['edition_id'] ?? 0);
        $this->invalidateRegisteredCountCache($editionId);

        $currentStatus = $this->getStatus($editionId);

        if ($editionId && $currentStatus === OfferingStatus::Full) {
            if ($this->hasAvailableSpots($editionId)) {
                $this->repository->updateStatus($editionId, OfferingStatus::Open);
            }
        }
    }

    /**
     * Cascade delete: remove sessions and registrations when edition is permanently deleted.
     */
    public function onEditionDeleted(int $postId): void
    {
        if (get_post_type($postId) !== EditionCPT::POST_TYPE) {
            return;
        }

        $this->deleteChildSessions($postId);
        $this->deleteEditionRegistrations($postId);
    }

    /**
     * Cascade trash: also trash child sessions when edition is trashed.
     */
    public function onEditionTrashed(int $postId): void
    {
        if (get_post_type($postId) !== EditionCPT::POST_TYPE) {
            return;
        }

        $this->deleteChildSessions($postId);
        $this->deleteEditionRegistrations($postId);
    }

    private function deleteChildSessions(int $editionId): void
    {
        $sessions = $this->sessions->findIdsByEdition($editionId);

        if (empty($sessions)) {
            return;
        }

        // Bulk delete attendance records once, then drop the session posts.
        // Done per-post via wp_delete_post so meta + relationships clean up
        // through WordPress, but the attendance hit is now a single DELETE.
        $attendanceRepo = ntdst_get(\Stride\Modules\Attendance\AttendanceRepository::class);
        $attendanceRepo->deleteBySessions($sessions);

        foreach ($sessions as $sessionId) {
            wp_delete_post($sessionId, true);
        }
    }

    private function deleteEditionRegistrations(int $editionId): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'vad_registrations';
        $wpdb->delete($table, ['edition_id' => $editionId], ['%d']);

        // Justified INV-3 bypass (bulk delete), but the invalidation
        // convergence point allows no bulk-path exception: drop the
        // repository's per-request cache so stale rows can't be served.
        ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class)->clearCache();
    }

    /**
     * Header-meta aggregation for a course page: the next upcoming edition date,
     * how many editions are upcoming, and the price range across those upcoming
     * editions. Extracted from templates/course/header.php so the template stays
     * pure presentation (Cluster 3 / B5).
     *
     * "Upcoming" = a published edition of this course whose start_date is today
     * or later. The date boundary uses wp_date('Y-m-d') (the Belgian site tz),
     * which is the correct calendar boundary for this audience.
     *
     * Optional-price semantics (INTENTIONAL flow, not error-swallowing): price is
     * decorative in the header, so a getPrice() failure on a single edition must
     * not blank the whole line — that edition is simply excluded from the range
     * (per-edition try/catch) and the rest still aggregate. A 0/absent price is
     * likewise excluded from min/max via the `> 0` guard.
     *
     * @return array{
     *     next_edition_date: ?string,
     *     upcoming_count: int,
     *     price_min_cents: ?int,
     *     price_max_cents: ?int,
     * }
     */
    public function getCourseHeaderSummary(int $courseId): array
    {
        $nextEditionDate = null;
        $upcomingCount   = 0;
        $priceMinCents   = null;
        $priceMaxCents   = null;

        $today = wp_date('Y-m-d');

        foreach ($this->repository->findByCourse($courseId) as $row) {
            $eid = (int) ($row['id'] ?? $row['ID'] ?? 0);
            if (!$eid) {
                continue;
            }

            $start = (string) $this->repository->getField($eid, 'start_date', '');
            if ($start === '' || $start < $today) {
                continue;
            }

            $upcomingCount++;
            if ($nextEditionDate === null || $start < $nextEditionDate) {
                $nextEditionDate = $start;
            }

            try {
                $cents = $this->getPrice($eid)->inCents();
                if ($cents > 0) {
                    $priceMinCents = $priceMinCents === null ? $cents : min($priceMinCents, $cents);
                    $priceMaxCents = $priceMaxCents === null ? $cents : max($priceMaxCents, $cents);
                }
            } catch (\Throwable $e) {
                // Intentional: price is optional decoration in the header. A
                // failed lookup drops THIS edition from the range, never the line.
            }
        }

        return [
            'next_edition_date' => $nextEditionDate,
            'upcoming_count'    => $upcomingCount,
            'price_min_cents'   => $priceMinCents,
            'price_max_cents'   => $priceMaxCents,
        ];
    }

    /**
     * Publicly-visible editions for a course's discovery surface, partitioned into
     * upcoming/past — the visibility POLICY + the partition + the sort extracted out
     * of templates/course/editions-list.php (Cluster 3 / Task 3.6 / B6). The template
     * becomes a pure renderer over this struct.
     *
     * THE POLICY (this is the value the method adds — not a pass-through):
     *   - Active (Announcement/Open/Full/InProgress) → shown.
     *   - Completed → shown (it lives in the collapsed "past" block).
     *   - Anything else (Draft/Postponed/Cancelled/Archived) → SUPPRESSED from the
     *     public set UNLESS $userId is enrolled in that edition — an enrolled user
     *     must still see their own registration even after the edition is cancelled.
     *   - A guest ($userId null) gets ZERO enrolled-exception: only the public set.
     *
     * THE PARTITION is EFFECTIVE-STATUS-driven (INV-7): a Completed edition is past
     * regardless of its stored start_date (an admin can leave a future date on a
     * cohort marked Completed); every other visible edition partitions by start_date
     * vs today — start before today → past[], else upcoming[]. Upcoming is sorted ASC
     * by start_date, past DESC.
     *
     * INV-7: visibility AND partition read the EFFECTIVE status (getEffectiveStatuses,
     * batched), never the raw stored status — an edition whose stored start is future
     * but whose effective status is Completed is treated as past. INV-3: editions and
     * fields come through the repository; enrollment through EnrollmentService (resolved
     * lazily to avoid the EditionService↔EnrollmentService DI cycle — EnrollmentService
     * depends on EditionQueryInterface, which this class implements).
     *
     * session_count and price_cents are presentation-enrichment carried in the row so
     * the template renders without a second per-edition lookup; the optional-price
     * try/catch is preserved (a price-lookup failure yields 0, never a fatal — price is
     * decorative on this discovery surface).
     *
     * @return array{
     *     upcoming: list<array{id:int,start_date:string,end_date:string,venue:string,
     *         status:OfferingStatus,is_enrolled:bool,permalink:string,
     *         session_count:int,price_cents:int}>,
     *     past: list<array{id:int,start_date:string,end_date:string,venue:string,
     *         status:OfferingStatus,is_enrolled:bool,permalink:string,
     *         session_count:int,price_cents:int}>,
     * }
     */
    public function getPubliclyVisibleEditions(int $courseId, ?int $userId = null): array
    {
        $editionIds = [];
        foreach ($this->repository->findByCourse($courseId) as $row) {
            $eid = (int) ($row['id'] ?? $row['ID'] ?? 0);
            if ($eid) {
                $editionIds[] = $eid;
            }
        }

        if (empty($editionIds)) {
            return ['upcoming' => [], 'past' => []];
        }

        // INV-7: effective status through the single decision point (batched).
        $statuses      = $this->getEffectiveStatuses($editionIds);
        $sessionCounts = $this->sessions->countByEditions($editionIds);
        $enrolledIds   = $userId
            ? ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class)->getEnrolledEditionIds($userId)
            : [];

        // Site-timezone "today" boundary, consistent with getCourseHeaderSummary
        // (both decide "is this edition upcoming" — they must agree at midnight).
        $today    = (int) strtotime(wp_date('Y-m-d'));
        $upcoming = [];
        $past     = [];

        foreach ($editionIds as $editionId) {
            $status     = $statuses[$editionId] ?? OfferingStatus::Draft;
            $isEnrolled = in_array($editionId, $enrolledIds, true);

            // Visibility policy: suppress non-active, non-Completed editions from the
            // public set unless the visitor is enrolled in them.
            if (!$isEnrolled && !$status->isActive() && $status !== OfferingStatus::Completed) {
                continue;
            }

            $startDate = (string) $this->repository->getField($editionId, 'start_date', '');
            $endDate   = (string) $this->repository->getField($editionId, 'end_date', '');
            $venue     = (string) $this->repository->getField($editionId, 'venue', '');

            try {
                $price      = $this->getPrice($editionId, $userId ?: null);
                $priceCents = $price->inCents();
            } catch (\Throwable $e) {
                // Intentional: price is decorative on this discovery surface. A failed
                // lookup falls back to 0 (hidden), never a fatal.
                $priceCents = 0;
            }

            $row = [
                'id'            => $editionId,
                'start_date'    => $startDate,
                'end_date'      => $endDate,
                'venue'         => $venue,
                'status'        => $status,
                'is_enrolled'   => $isEnrolled,
                'permalink'     => (string) get_permalink($editionId),
                'session_count' => (int) ($sessionCounts[$editionId] ?? 0),
                'price_cents'   => $priceCents,
            ];

            // Effective-status partition: a Completed edition is past no matter its
            // stored start; otherwise partition by start_date vs today.
            $startTs = $startDate ? strtotime($startDate) : 0;
            if ($status === OfferingStatus::Completed || ($startTs && $startTs < $today)) {
                $past[] = $row;
            } else {
                $upcoming[] = $row;
            }
        }

        usort($upcoming, static fn(array $a, array $b): int => strcmp((string) $a['start_date'], (string) $b['start_date']));
        usort($past, static fn(array $a, array $b): int => strcmp((string) $b['start_date'], (string) $a['start_date']));

        return ['upcoming' => $upcoming, 'past' => $past];
    }
}
