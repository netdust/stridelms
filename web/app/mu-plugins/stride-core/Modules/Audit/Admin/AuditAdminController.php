<?php
declare(strict_types=1);

namespace Stride\Modules\Audit\Admin;

use DateTime;
use Stride\Infrastructure\AbstractService;
use Stride\Modules\Audit\AuditService;

final class AuditAdminController extends AbstractService
{
    private const MENU_SLUG = 'stride-audit-log';
    private const CAPABILITY = 'manage_options';
    private const PARENT_SLUG = 'stride-dashboard';

    public static function metadata(): array
    {
        return [
            'name' => 'Audit Admin Controller',
            'description' => 'Admin interface for viewing audit logs',
            'admin_only' => true,
            'priority' => 100,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'audit_admin';
    }

    protected function init(): void
    {
        add_action('admin_menu', [$this, 'registerAdminPage']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_head', [$this, 'injectStyles']);
        add_action('admin_footer', [$this, 'injectScripts']);
        add_action('wp_ajax_stride_audit_export_csv', [$this, 'exportCsv']);
    }

    public function registerAdminPage(): void
    {
        add_submenu_page(
            self::PARENT_SLUG,
            'Audit Log',
            'Audit Log',
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'renderPage']
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if (!str_contains($hook, self::MENU_SLUG)) {
            return;
        }

        wp_enqueue_style('flatpickr', 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css', [], '4.6.13');
        wp_enqueue_script('flatpickr', 'https://cdn.jsdelivr.net/npm/flatpickr', [], '4.6.13', true);
        wp_enqueue_script('flatpickr-nl', 'https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/nl.js', ['flatpickr'], '4.6.13', true);

        wp_enqueue_script(
            'alpinejs',
            'https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js',
            ['flatpickr'],
            '3.14.0',
            ['strategy' => 'defer']
        );

        wp_localize_script('alpinejs', 'StrideAuditConfig', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('stride_audit'),
            'restUrl' => rest_url('stride/v1/audit'),
            'restNonce' => wp_create_nonce('wp_rest'),
        ]);
    }

    public function injectStyles(): void
    {
        if (!$this->isAuditPage()) {
            return;
        }

        $cssPath = dirname(__DIR__, 2) . '/assets/css/admin-audit.css';
        if (file_exists($cssPath)) {
            echo '<style id="stride-audit-styles">';
            include $cssPath;
            echo '</style>';
        }
    }

    public function injectScripts(): void
    {
        if (!$this->isAuditPage()) {
            return;
        }

        $jsPath = dirname(__DIR__, 2) . '/assets/js/admin-audit.js';
        if (file_exists($jsPath)) {
            echo '<script>';
            include $jsPath;
            echo '</script>';
        }
    }

    public function renderPage(): void
    {
        $templatePath = dirname(__DIR__, 2) . '/templates/admin/audit-log.php';

        if (file_exists($templatePath)) {
            include $templatePath;
        } else {
            echo '<div class="wrap"><h1>Audit Log</h1><p>Template not found.</p></div>';
        }
    }

    public function exportCsv(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('Unauthorized');
        }

        check_ajax_referer('stride_audit', 'nonce');

        $auditService = ntdst_get(AuditService::class);
        $repository = $auditService->getRepository();

        try {
            $from = new DateTime($_POST['from'] ?? '-30 days');
            $to = new DateTime($_POST['to'] ?? 'now');
        } catch (\Exception $e) {
            wp_send_json_error(['message' => 'Invalid date format'], 400);
        }

        $filters = [
            'entity_type' => sanitize_text_field($_POST['entity_type'] ?? ''),
            'actor_id' => absint($_POST['actor_id'] ?? 0) ?: null,
        ];

        $entries = $repository->findByDateRange($from, $to, array_filter($filters), 10000, 0);

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="audit-log-' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');

        fputcsv($output, ['ID', 'Date', 'Entity Type', 'Entity ID', 'Action', 'Actor ID', 'Actor Type', 'Context']);

        foreach ($entries as $entry) {
            fputcsv($output, [
                $entry->id,
                $entry->created_at,
                $entry->entity_type,
                $entry->entity_id,
                $entry->action,
                $entry->actor_id ?? 'system',
                $entry->actor_type,
                $entry->context,
            ]);
        }

        fclose($output);
        exit;
    }

    private function isAuditPage(): bool
    {
        $screen = get_current_screen();
        if (!$screen) {
            $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
            return $page === self::MENU_SLUG;
        }
        return str_contains($screen->id, self::MENU_SLUG);
    }
}
