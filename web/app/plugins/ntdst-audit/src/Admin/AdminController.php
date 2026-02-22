<?php

declare(strict_types=1);

namespace NTDST\Audit\Admin;

use DateTime;
use NTDST\Audit\AuditService;

final class AdminController implements \NTDST_Service_Meta
{
    private const MENU_SLUG = 'ntdst-audit-log';

    public static function metadata(): array
    {
        return [
            'name' => 'Audit Admin Controller',
            'description' => 'Admin interface for viewing audit logs',
            'admin_only' => true,
            'priority' => 100,
        ];
    }

    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        add_action('admin_menu', [$this, 'registerAdminPage']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_head', [$this, 'injectStyles']);
        add_action('admin_footer', [$this, 'injectScripts']);
        add_action('wp_ajax_ntdst_audit_export_csv', [$this, 'exportCsv']);
    }

    private function getCapability(): string
    {
        return apply_filters('ntdst/audit/capability', 'manage_options');
    }

    public function registerAdminPage(): void
    {
        add_management_page(
            'Audit Log',
            'Audit Log',
            $this->getCapability(),
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

        wp_localize_script('alpinejs', 'NtdstAuditConfig', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ntdst_audit'),
            'restUrl' => rest_url('ntdst/v1/audit'),
            'restNonce' => wp_create_nonce('wp_rest'),
        ]);
    }

    public function injectStyles(): void
    {
        if (!$this->isAuditPage()) {
            return;
        }

        $cssPath = NTDST_AUDIT_PATH . 'assets/css/admin-audit.css';
        if (file_exists($cssPath)) {
            echo '<style id="ntdst-audit-styles">';
            include $cssPath;
            echo '</style>';
        }
    }

    public function injectScripts(): void
    {
        if (!$this->isAuditPage()) {
            return;
        }

        $jsPath = NTDST_AUDIT_PATH . 'assets/js/admin-audit.js';
        if (file_exists($jsPath)) {
            echo '<script>';
            include $jsPath;
            echo '</script>';
        }
    }

    public function renderPage(): void
    {
        $templatePath = NTDST_AUDIT_PATH . 'templates/admin/audit-log.php';

        if (file_exists($templatePath)) {
            include $templatePath;
        } else {
            echo '<div class="wrap"><h1>Audit Log</h1><p>Template not found.</p></div>';
        }
    }

    public function exportCsv(): void
    {
        if (!current_user_can($this->getCapability())) {
            wp_die('Unauthorized');
        }

        check_ajax_referer('ntdst_audit', 'nonce');

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
