<?php

declare(strict_types=1);

namespace Stride\Modules\Reporting\Admin;

use Stride\Modules\Reporting\AnnualReport;
use Stride\Modules\Reporting\AnnualReportService;

/**
 * Annual Report Admin Page
 *
 * Registers the "Jaarrapport" submenu under the Stride dashboard, enqueues
 * the page assets (Chart.js + Alpine.js + page CSS/JS), localizes the report
 * payload, and renders the on-screen template.
 */
class AnnualReportPage implements \NTDST_Service_Meta
{
    private const PARENT_SLUG = 'stride-dashboard';
    private const PAGE_SLUG   = 'stride-annual-report';
    private const CAPABILITY  = 'stride_view';

    /**
     * Stores the hook suffix returned by add_submenu_page() so enqueueAssets()
     * can compare against the live value instead of a brittle hardcoded string.
     */
    private ?string $hookSuffix = null;

    public static function metadata(): array
    {
        return [
            'name'        => 'Annual Report Page',
            'description' => 'Admin submenu for the yearly government report',
            'priority'    => 60,
        ];
    }

    public function __construct(private readonly AnnualReportService $service)
    {
        add_action('admin_menu', [$this, 'registerSubmenu'], 20);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_head', [$this, 'loadChrome']);
    }

    public function loadChrome(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !str_contains((string) $screen->id, self::PAGE_SLUG)) {
            return;
        }
        if (function_exists('stride_load_tool_chrome')) {
            stride_load_tool_chrome();
        }
    }

    public function registerSubmenu(): void
    {
        $this->hookSuffix = add_submenu_page(
            self::PARENT_SLUG,
            __('Jaarrapport', 'stride'),
            __('Jaarrapport', 'stride'),
            self::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render']
        ) ?: null;
    }

    public function enqueueAssets(string $hook): void
    {
        if ($this->hookSuffix === null || $hook !== $this->hookSuffix) {
            return;
        }

        // stride-core root: …/Modules/Reporting/Admin -> up 3 -> …/stride-core
        $basePath = dirname(__DIR__, 3);
        $cssFile  = $basePath . '/assets/css/admin/annual-report.css';
        $jsFile   = $basePath . '/assets/js/admin/annual-report.js';

        if (file_exists($cssFile)) {
            wp_enqueue_style(
                'stride-annual-report',
                plugins_url('assets/css/admin/annual-report.css', $basePath . '/stride-core.php'),
                [],
                (string) filemtime($cssFile)
            );
        }

        wp_enqueue_script(
            'chart-js',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
            [],
            '4.4.1',
            true
        );

        // Page component MUST register on window before Alpine boots.
        // Loaded in footer with chart-js as dep; Alpine listed as its dep so it
        // is enqueued, but our script appears BEFORE Alpine in the DOM via the
        // dependency below (Alpine depends on stride-annual-report).
        if (file_exists($jsFile)) {
            wp_enqueue_script(
                'stride-annual-report',
                plugins_url('assets/js/admin/annual-report.js', $basePath . '/stride-core.php'),
                ['chart-js'],
                (string) filemtime($jsFile),
                true
            );
        }

        wp_enqueue_script(
            'alpinejs',
            'https://cdn.jsdelivr.net/npm/alpinejs@3.14.9/dist/cdn.min.js',
            ['stride-annual-report'],
            '3.14.9',
            ['strategy' => 'defer', 'in_footer' => true]
        );

        // Add crossorigin for CDN scripts (consistent with AdminDashboardService).
        add_filter('script_loader_tag', function (string $tag, string $handle): string {
            if (in_array($handle, ['alpinejs', 'chart-js'], true)) {
                return str_replace(' src=', ' crossorigin="anonymous" src=', $tag);
            }
            return $tag;
        }, 10, 2);

        $requestedYear  = $this->resolveRequestedYear();
        $availableYears = $this->service->availableYears();
        if (empty($availableYears)) {
            $availableYears = [(int) current_time('Y')];
        }
        if (!in_array($requestedYear, $availableYears, true)) {
            $requestedYear = $availableYears[0];
        }

        wp_localize_script('stride-annual-report', 'StrideAnnualReport', [
            'year'           => $requestedYear,
            'availableYears' => $availableYears,
            'report'         => $this->reportToJs($this->service->buildReport($requestedYear)),
            'pdfUrl'         => admin_url(
                'admin-ajax.php?action=stride_annual_report_pdf&year=' . $requestedYear
                . '&_wpnonce=' . wp_create_nonce('stride_annual_report')
            ),
            'csvBaseUrl'     => admin_url('admin-ajax.php?action=stride_annual_report_csv'),
            'csvNonce'       => wp_create_nonce('stride_annual_report'),
        ]);
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Geen toegang.', 'stride'));
        }

        // …/Modules/Reporting/Admin -> up 3 -> …/stride-core, then /templates/admin/annual-report.php
        $templatePath = dirname(__DIR__, 3) . '/templates/admin/annual-report.php';

        if (!file_exists($templatePath)) {
            echo '<div class="wrap"><h1>' . esc_html__('Jaarrapport', 'stride') . '</h1>'
               . '<p>' . esc_html__('Template ontbreekt.', 'stride') . '</p></div>';
            return;
        }

        include $templatePath;
    }

    /**
     * Resolve and clamp the requested year from the query string.
     */
    private function resolveRequestedYear(): int
    {
        $current = (int) current_time('Y');
        $year    = isset($_GET['year']) ? (int) $_GET['year'] : $current;

        // Clamp to a sensible historical/future range to avoid arbitrary input
        // flowing into the service queries.
        if ($year < 2000 || $year > 2100) {
            return $current;
        }

        return $year;
    }

    /**
     * Convert the AnnualReport DTO into a JS-friendly array shape.
     *
     * @return array{
     *     year:int,
     *     previousYear:int,
     *     generatedAt:string,
     *     kpis:array<string,array{current:int|float|null,previous:int|float|null}>,
     *     sections:list<array{id:string,title:string,headers:list<string>,rows:list<list<string|int|float|null>>}>
     * }
     */
    private function reportToJs(AnnualReport $report): array
    {
        return [
            'year'         => $report->year,
            'previousYear' => $report->previousYear,
            'generatedAt'  => $report->generatedAt,
            'kpis'         => $report->kpis,
            'sections'     => array_map(
                static fn($section) => $section->toArray(),
                $report->sections
            ),
        ];
    }
}
