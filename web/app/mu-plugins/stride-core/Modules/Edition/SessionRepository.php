<?php

declare(strict_types=1);

namespace Stride\Modules\Edition;

use Stride\Domain\SessionType;
use Stride\Infrastructure\AbstractRepository;
use WP_Error;
use WP_Post;

/**
 * Session repository for CRUD operations.
 */
final class SessionRepository extends AbstractRepository
{
    protected string $postType = SessionCPT::POST_TYPE;

    /**
     * Find sessions for an edition.
     *
     * @return array<array<string, mixed>>
     */
    public function findByEdition(int $editionId): array
    {
        $sessions = $this->model()
            ->where('edition_id', $editionId)
            ->withMeta()
            ->get();

        return $this->sortByDateThenStartTime($sessions);
    }

    /**
     * Find session IDs for an edition, ordered by post ID ASC.
     *
     * Single session-ids-by-edition reader on the builder path. Goes through
     * `where('edition_id', ...)` so the relationship matches `findByEdition()`
     * / `countByEdition()` (meta-based, not post_parent-based) — keeps cascade
     * and the registrations view consistent with the find/count paths. The
     * builder resolves the edition_id meta key via the model prefix, so no
     * `_ntdst_*` literal lives here (INV-3).
     *
     * @param string $postStatus Post status scope. Cascade-delete passes 'any'
     *                           (needs trashed/draft sessions too); the
     *                           registrations view passes 'publish'.
     * @return list<int>
     */
    public function findIdsByEdition(int $editionId, string $postStatus = 'any'): array
    {
        $rows = $this->model()
            ->where('edition_id', $editionId)
            ->where('post_status', $postStatus)
            ->orderBy('ID', 'ASC')
            ->get();

        return array_map(static fn(array $row): int => (int) ($row['id'] ?? $row['ID'] ?? 0), $rows);
    }

    /**
     * Find sessions by slot within an edition.
     *
     * @return array<array<string, mixed>>
     */
    public function findBySlot(int $editionId, string $slot): array
    {
        $sessions = $this->model()
            ->where('edition_id', $editionId)
            ->where('slot', $slot)
            ->withMeta()
            ->get();

        return $this->sortByDateThenStartTime($sessions);
    }

    /**
     * Sort getPostsFast() rows by date ASC, then start_time ASC.
     *
     * The query builder's orderBy() doesn't work for meta fields, so this sort
     * happens in PHP. Reads prefixed meta keys (e.g. `_ntdst_date`) from the
     * batch query's `meta` envelope; the prefix comes from the model so a
     * config change can't silently break ordering.
     *
     * @param array<array<string, mixed>> $sessions
     * @return array<array<string, mixed>>
     */
    private function sortByDateThenStartTime(array $sessions): array
    {
        $prefix    = $this->getMetaPrefix();
        $dateKey   = $prefix . 'date';
        $startKey  = $prefix . 'start_time';

        usort($sessions, function ($a, $b) use ($dateKey, $startKey) {
            $dateCmp = strcmp($a['meta'][$dateKey] ?? '', $b['meta'][$dateKey] ?? '');
            if ($dateCmp !== 0) {
                return $dateCmp;
            }
            return strcmp($a['meta'][$startKey] ?? '', $b['meta'][$startKey] ?? '');
        });

        return $sessions;
    }

    /**
     * Count sessions for an edition.
     */
    public function countByEdition(int $editionId): int
    {
        return $this->model()
            ->where('edition_id', $editionId)
            ->count();
    }

    /**
     * Batch-count published sessions for multiple editions in one GROUP BY query.
     *
     * The N-independent equivalent of calling countByEdition() per id —
     * added for the catalog batch pre-pass (Task G1 / audit 2.2). Same
     * relationship semantics as countByEdition() (meta-based edition_id
     * link, published sessions). The meta prefix comes from the model so
     * a config change can't silently break the join (INV-3 vocabulary).
     *
     * @param array<int> $editionIds
     * @return array<int, int> Map of edition_id => count (all input ids present, defaulting to 0)
     */
    public function countByEditions(array $editionIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $editionIds)));
        if (empty($ids)) {
            return [];
        }

        global $wpdb;

        $editionKey   = $this->getMetaPrefix() . 'edition_id';
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.meta_value AS edition_id, COUNT(*) AS c
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
             WHERE p.post_type = %s
               AND p.post_status = 'publish'
               AND pm.meta_value IN ({$placeholders})
             GROUP BY pm.meta_value",
            array_merge([$editionKey, $this->postType], $ids),
        ));

        $out = array_fill_keys($ids, 0);
        foreach ($rows as $row) {
            $out[(int) $row->edition_id] = (int) $row->c;
        }

        return $out;
    }

    /**
     * Sum total duration (hours) across a batch of sessions in a single query.
     *
     * Performance-driven: the alternative would be N find() round-trips through
     * the cache for callers that already know the IDs (e.g. selected slot sessions).
     * The model's `meta_prefix` is honored so a future prefix change here can't
     * silently break.
     *
     * @param list<int> $sessionIds
     */
    public function sumDurationHours(array $sessionIds): float
    {
        if (empty($sessionIds)) {
            return 0.0;
        }

        global $wpdb;

        $prefix       = $this->model()->getMetaPrefix();
        $startKey     = $prefix . 'start_time';
        $endKey       = $prefix . 'end_time';
        $placeholders = implode(',', array_fill(0, count($sessionIds), '%d'));

        $args = array_merge([$endKey], $sessionIds, [$startKey]);

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT pm_start.post_id, pm_start.meta_value AS start_time, pm_end.meta_value AS end_time
             FROM {$wpdb->postmeta} pm_start
             LEFT JOIN {$wpdb->postmeta} pm_end
                ON pm_start.post_id = pm_end.post_id AND pm_end.meta_key = %s
             WHERE pm_start.post_id IN ({$placeholders})
               AND pm_start.meta_key = %s",
            $args,
        ));

        $totalHours = 0.0;
        foreach ($results as $row) {
            if (!empty($row->start_time) && !empty($row->end_time)) {
                $start = strtotime((string) $row->start_time);
                $end   = strtotime((string) $row->end_time);
                if ($end > $start) {
                    $totalHours += ($end - $start) / 3600;
                }
            }
        }

        return $totalHours;
    }

    /**
     * Validate session data before create/update.
     */
    public function validate(array $data): true|WP_Error
    {
        if (empty($data['edition_id'])) {
            return new WP_Error('missing_edition', 'Edition ID is required');
        }

        if (empty($data['date'])) {
            return new WP_Error('missing_date', 'Date is required');
        }

        // Compare time-only strings - strtotime() converts to timestamps for today
        // e.g., '09:00' < '17:00' when both are converted to same-day timestamps
        if (!empty($data['start_time']) && !empty($data['end_time'])) {
            if (strtotime($data['end_time']) <= strtotime($data['start_time'])) {
                return new WP_Error('invalid_time_range', 'End time must be after start time');
            }
        }

        // Validate type if provided
        if (!empty($data['type'])) {
            $validType = SessionType::tryFrom($data['type']);
            if ($validType === null) {
                return new WP_Error('invalid_type', 'Invalid session type');
            }
        }

        return true;
    }

    /**
     * Create session with validation.
     */
    public function create(array $data): WP_Post|WP_Error
    {
        $validation = $this->validate($data);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Auto-generate title from date + time when none provided.
        if (empty($data['title'])) {
            $title = (string) ($data['date'] ?? '');
            if (!empty($data['start_time'])) {
                $title .= ' ' . $data['start_time'];
            }
            $data['title'] = $title;
        }

        return parent::create($data);
    }

    /**
     * Update session with validation.
     */
    public function update(int $id, array $data): WP_Post|WP_Error
    {
        // Merge with existing data for validation
        $existing = $this->find($id);
        if (is_wp_error($existing)) {
            return $existing;
        }

        $mergedData = array_merge([
            'edition_id' => $this->getField($id, 'edition_id'),
            'date' => $this->getField($id, 'date'),
        ], $data);

        $validation = $this->validate($mergedData);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Auto-generate title from date + time only if not set by caller.
        if (!isset($data['title'])) {
            if (isset($data['date']) || isset($data['start_time'])) {
                $date = $data['date'] ?? $this->getField($id, 'date', '');
                $startTime = $data['start_time'] ?? $this->getField($id, 'start_time', '');
                $data['title'] = $date . ($startTime ? ' ' . $startTime : '');
            }
        }

        return parent::update($id, $data);
    }
}
