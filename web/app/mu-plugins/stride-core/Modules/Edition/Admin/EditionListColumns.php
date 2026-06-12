<?php

declare(strict_types=1);

namespace Stride\Modules\Edition\Admin;

use Stride\Modules\Edition\EditionCPT;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;
use WP_Query;

/**
 * Edition List Columns.
 *
 * Handles admin list table customization:
 * - Custom columns (course, dates, venue, capacity, status)
 * - Sortable columns
 * - Column sorting query modification
 */
final class EditionListColumns
{
    public function __construct(
        private readonly EditionService $editionService,
        private readonly EditionRepository $editionRepository,
    ) {}

    /**
     * Register hooks for list columns.
     */
    public function register(): void
    {
        add_filter('manage_' . EditionCPT::POST_TYPE . '_posts_columns', [$this, 'defineColumns']);
        add_action('manage_' . EditionCPT::POST_TYPE . '_posts_custom_column', [$this, 'renderColumn'], 10, 2);
        add_filter('manage_edit-' . EditionCPT::POST_TYPE . '_sortable_columns', [$this, 'defineSortableColumns']);
        add_action('pre_get_posts', [$this, 'handleColumnSorting']);
    }

    /**
     * Define admin list columns.
     *
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public function defineColumns(array $columns): array
    {
        return [
            'cb' => $columns['cb'] ?? '<input type="checkbox" />',
            'title' => __('Editie', 'stride'),
            'course' => __('Cursus', 'stride'),
            'start_date' => __('Startdatum', 'stride'),
            'venue' => __('Locatie', 'stride'),
            'capacity' => __('Capaciteit', 'stride'),
            'status' => __('Status', 'stride'),
        ];
    }

    /**
     * Render admin list column content.
     */
    public function renderColumn(string $column, int $postId): void
    {
        match ($column) {
            'course' => $this->renderCourseColumn($postId),
            'start_date' => $this->renderStartDateColumn($postId),
            'venue' => $this->renderVenueColumn($postId),
            'capacity' => $this->renderCapacityColumn($postId),
            'status' => $this->renderStatusColumn($postId),
            default => null,
        };
    }

    private function renderCourseColumn(int $postId): void
    {
        $courseId = (int) $this->editionRepository->getField($postId, 'course_id', 0);
        if (!$courseId) {
            echo '<span style="color:#999;">—</span>';
            return;
        }

        $courseTitle = get_the_title($courseId);
        $editUrl = get_edit_post_link($courseId);

        if ($editUrl) {
            echo '<a href="' . esc_url($editUrl) . '">' . esc_html($courseTitle) . '</a>';
        } else {
            echo esc_html($courseTitle);
        }
    }

    private function renderStartDateColumn(int $postId): void
    {
        $startDate = $this->editionRepository->getField($postId, 'start_date', '');
        $endDate = $this->editionRepository->getField($postId, 'end_date', '');

        if (!$startDate) {
            echo '<span style="color:#999;">—</span>';
            return;
        }

        echo esc_html(date_i18n('j M Y', strtotime($startDate)));
        if ($endDate && $endDate !== $startDate) {
            echo ' – ' . esc_html(date_i18n('j M Y', strtotime($endDate)));
        }
    }

    private function renderVenueColumn(int $postId): void
    {
        $venue = $this->editionRepository->getField($postId, 'venue', '');
        echo $venue ? esc_html($venue) : '<span style="color:#999;">—</span>';
    }

    private function renderCapacityColumn(int $postId): void
    {
        $capacity = (int) $this->editionRepository->getField($postId, 'capacity', 0);
        $registrations = $this->editionService->getRegisteredCount($postId);

        if ($capacity > 0) {
            $percentage = min(100, round(($registrations / $capacity) * 100));
            $color = match (true) {
                $percentage >= 100 => '#d63638',
                $percentage >= 80 => '#dba617',
                default => '#00a32a',
            };
            echo '<span style="color:' . $color . ';font-weight:500;">' . $registrations . '/' . $capacity . '</span>';
        } else {
            echo '<span>' . $registrations . '</span>';
        }
    }

    private function renderStatusColumn(int $postId): void
    {
        $status = $this->editionRepository->getField($postId, 'status', 'draft');

        $statusConfig = [
            'draft' => ['label' => __('Concept', 'stride'), 'color' => '#787c82'],
            'open' => ['label' => __('Open', 'stride'), 'color' => '#00a32a'],
            'full' => ['label' => __('Vol', 'stride'), 'color' => '#dba617'],
            'closed' => ['label' => __('Gesloten', 'stride'), 'color' => '#d63638'],
            'cancelled' => ['label' => __('Geannuleerd', 'stride'), 'color' => '#d63638'],
            'completed' => ['label' => __('Afgerond', 'stride'), 'color' => '#2271b1'],
        ];

        $config = $statusConfig[$status] ?? ['label' => ucfirst($status), 'color' => '#787c82'];

        printf(
            '<span style="display:inline-block;padding:2px 8px;border-radius:3px;background:%s20;color:%s;font-size:12px;">%s</span>',
            $config['color'],
            $config['color'],
            esc_html($config['label']),
        );
    }

    /**
     * Define sortable columns.
     *
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public function defineSortableColumns(array $columns): array
    {
        $columns['start_date'] = 'start_date';
        $columns['status'] = 'status';
        return $columns;
    }

    /**
     * Handle sorting by custom meta columns.
     */
    public function handleColumnSorting(WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        if ($query->get('post_type') !== EditionCPT::POST_TYPE) {
            return;
        }

        $orderby = $query->get('orderby');

        // Meta keys use _ntdst_ prefix as defined in EditionCPT
        if ($orderby === 'start_date') {
            $query->set('meta_key', '_ntdst_start_date');
            $query->set('orderby', 'meta_value');
        } elseif ($orderby === 'status') {
            $query->set('meta_key', '_ntdst_status');
            $query->set('orderby', 'meta_value');
        }
    }
}
