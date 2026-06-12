<?php

declare(strict_types=1);

namespace Stride\Modules\Edition;

use Stride\Domain\OfferingStatus;
use Stride\Infrastructure\AbstractRepository;

/**
 * Repository for edition data access.
 */
final class EditionRepository extends AbstractRepository
{
    protected string $postType = EditionCPT::POST_TYPE;

    /**
     * Get editions for a specific course (all post-status=publish, any status).
     *
     * @return array<array<string, mixed>>
     */
    public function findByCourse(int $courseId): array
    {
        return $this->model()
            ->where('course_id', $courseId)
            ->where('post_status', 'publish')
            ->orderBy('start_date', 'ASC')
            ->withMeta()
            ->get();
    }

    /**
     * Get IDs of editions for a course that are "active" — i.e. publicly
     * visible, listed in catalogs, reachable via slug routing. Excludes
     * terminal statuses (cancelled, completed, archived) and drafts.
     *
     * Status set lives on OfferingStatus::activeCases().
     *
     * @return list<int>
     */
    public function findActiveIdsByCourse(int $courseId): array
    {
        $rows = $this->model()
            ->where('course_id', $courseId)
            ->where('post_status', 'publish')
            ->whereIn('status', OfferingStatus::activeValues())
            ->get();

        return array_map(static fn(array $row): int => (int) ($row['id'] ?? $row['ID'] ?? 0), $rows);
    }

    /**
     * Get upcoming editions (start date >= today).
     *
     * @return array<array<string, mixed>>
     */
    public function findUpcoming(int $limit = 10): array
    {
        $today = date('Y-m-d');

        return $this->model()
            ->where('start_date', ['>=', $today])
            ->where('post_status', 'publish')
            ->whereNot('status', OfferingStatus::Cancelled->value)
            ->orderBy('start_date', 'ASC')
            ->limit($limit)
            ->withMeta()
            ->get();
    }

    /**
     * Get editions with available spots.
     *
     * @return array<array<string, mixed>>
     */
    public function findWithAvailability(): array
    {
        return $this->model()
            ->where('status', OfferingStatus::Open->value)
            ->where('post_status', 'publish')
            ->orderBy('start_date', 'ASC')
            ->withMeta()
            ->get();
    }

    /**
     * Query editions with flexible filters.
     *
     * @param array{
     *     course_id?: int,
     *     status?: string,
     *     start_date_from?: string,
     *     start_date_to?: string,
     *     limit?: int,
     * } $filters
     * @return array<array<string, mixed>>
     */
    public function findByFilters(array $filters = [], int $limit = 100): array
    {
        $query = $this->model()
            ->where('post_status', 'publish')
            ->orderBy('start_date', 'ASC')
            ->withMeta();

        if (!empty($filters['course_id'])) {
            $query->where('course_id', (int) $filters['course_id']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['start_date_from'])) {
            $query->where('start_date', ['>=', $filters['start_date_from']]);
        }

        if (!empty($filters['start_date_to'])) {
            $query->where('start_date', ['<=', $filters['start_date_to']]);
        }

        return $query->limit($limit)->get();
    }

    /**
     * Batch-fetch editions by ID, keyed by edition_id.
     *
     * Returns only editions that exist; missing IDs are silently dropped.
     * Includes all post statuses (publish, draft, trash) so callers that
     * need to render historical references don't lose data.
     *
     * @param int[] $editionIds
     * @return array<int, \WP_Post>
     */
    public function findManyById(array $editionIds): array
    {
        if (empty($editionIds)) {
            return [];
        }

        $posts = get_posts([
            'post_type' => $this->postType,
            'post__in' => array_map('intval', $editionIds),
            'posts_per_page' => count($editionIds),
            'post_status' => 'any',
        ]);

        $map = [];
        foreach ($posts as $post) {
            $map[(int) $post->ID] = $post;
        }

        return $map;
    }

    /**
     * Batch-resolve edition_id → course_id for a list of edition IDs.
     *
     * Returns a map of edition_id => course_id, only for editions that
     * actually have a course_id assigned. IDs with no course are omitted
     * (use `array_key_exists()` rather than `??`-coalesce if you need to
     * distinguish "no course" from "unknown id").
     *
     * @param int[] $editionIds
     * @return array<int, int>
     */
    public function findCourseIdsForEditions(array $editionIds): array
    {
        if (empty($editionIds)) {
            return [];
        }

        $rows = $this->model()
            ->whereIn('ID', array_map('intval', $editionIds))
            ->where('post_status', 'publish')
            ->withMeta()
            ->get();

        $prefixedKey = $this->getMetaPrefix() . 'course_id';

        $map = [];
        foreach ($rows as $row) {
            $editionId = (int) ($row['id'] ?? $row['ID'] ?? 0);
            $courseId = (int) ($row['meta'][$prefixedKey] ?? 0);
            if ($editionId > 0 && $courseId > 0) {
                $map[$editionId] = $courseId;
            }
        }

        return $map;
    }

    /**
     * Of the given course ids, return those that have at least one edition.
     *
     * Used by the catalog (Task G1 / audit 2.2) to tell pure-LD courses
     * (never had an edition) apart from courses whose editions all expired —
     * the latter go off-catalog until a new edition is scheduled. Matches
     * the relationship semantics the theme templates previously inlined as
     * raw SQL: ANY edition post row counts, in ANY post_status (publish,
     * draft, trash, …) — deliberately no post_status filter (INF-3): an
     * edition row, even drafted or trashed, signals admin intent to run
     * editions for the course, so the course is not "pure LD". Kept as-is
     * for parity with the pre-refactor inline SQL (ship-mode).
     *
     * @param array<int> $courseIds
     * @return list<int>
     */
    public function courseIdsWithAnyEdition(array $courseIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $courseIds)));
        if (empty($ids)) {
            return [];
        }

        global $wpdb;

        $courseKey    = $this->getMetaPrefix() . 'course_id';
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT pm.meta_value + 0
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s
               AND p.post_type = %s
               AND pm.meta_value IN ({$placeholders})",
            array_merge([$courseKey, $this->postType], $ids),
        ));

        return array_map('intval', $rows);
    }

    /**
     * Update edition status.
     */
    public function updateStatus(int $editionId, OfferingStatus $status): void
    {
        $this->model()->updateMetaBatch($editionId, ['status' => $status->value]);
    }

    /**
     * Update edition meta fields.
     */
    public function updateMeta(int $editionId, array $data): bool
    {
        return $this->model()->updateMetaBatch($editionId, $data);
    }

    /**
     * Speakers as a normalized list of ['name' => string, 'role' => string].
     *
     * The meta is a JSON array of {name, role} since 2026-06; legacy values
     * are plain strings ("Lien De Smedt, sportpedagoge") and are returned as
     * a single entry with the whole string as name and an empty role.
     *
     * @return array<int, array{name: string, role: string}>
     */
    public function getSpeakers(int $editionId): array
    {
        // Raw meta, NOT getField(): the json-typed schema decodes legacy
        // plain-string values to [] on the formatted read path, which would
        // silently drop them before this normalization can see them.
        $raw = $this->rawSpeakersMeta($editionId);

        if (is_string($raw)) {
            $raw = trim($raw);
            if ($raw === '') {
                return [];
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                // Legacy plain-string value
                return [['name' => $raw, 'role' => '']];
            }
            $raw = $decoded;
        }

        if (!is_array($raw)) {
            return [];
        }

        $speakers = [];
        foreach ($raw as $entry) {
            if (is_string($entry)) {
                $entry = ['name' => $entry];
            }
            if (!is_array($entry)) {
                continue;
            }
            $name = trim((string) ($entry['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $speakers[] = [
                'name' => $name,
                'role' => trim((string) ($entry['role'] ?? '')),
            ];
        }

        return $speakers;
    }

    /**
     * Speakers as a flat display string (joined names) — for API payloads
     * and exports that consumed the legacy string field.
     */
    public function getSpeakersLabel(int $editionId): string
    {
        return implode(', ', array_column($this->getSpeakers($editionId), 'name'));
    }

    /**
     * Unformatted speakers meta (overridable seam for unit tests).
     */
    protected function rawSpeakersMeta(int $editionId): mixed
    {
        return get_post_meta($editionId, $this->model()->getMetaPrefix() . 'speakers', true);
    }
}
