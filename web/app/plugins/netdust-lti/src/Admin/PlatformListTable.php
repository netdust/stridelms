<?php
declare(strict_types=1);

namespace NetdustLTI\Admin;

use NetdustLTI\Repositories\PlatformRepository;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class PlatformListTable extends \WP_List_Table
{
    private PlatformRepository $repository;

    public function __construct(PlatformRepository $repository)
    {
        $this->repository = $repository;

        parent::__construct([
            'singular' => 'platform',
            'plural' => 'platforms',
            'ajax' => false,
        ]);
    }

    public function get_columns(): array
    {
        return [
            'name' => 'Name',
            'platform_id' => 'Platform ID',
            'client_id' => 'Client ID',
            'enabled' => 'Status',
        ];
    }

    public function prepare_items(): void
    {
        $this->_column_headers = [$this->get_columns(), [], []];
        $this->items = $this->repository->all();
    }

    protected function column_name($item): string
    {
        $editUrl = admin_url('options-general.php?page=netdust-lti&action=edit&platform_id=' . $item->id);
        $deleteUrl = wp_nonce_url(
            admin_url('options-general.php?page=netdust-lti&action=delete&platform_id=' . $item->id),
            'delete_platform_' . $item->id
        );

        $actions = [
            'edit' => sprintf('<a href="%s">Edit</a>', esc_url($editUrl)),
            'delete' => sprintf('<a href="%s" onclick="return confirm(\'Delete this platform?\')">Delete</a>', esc_url($deleteUrl)),
        ];

        return sprintf('%s %s', esc_html($item->name), $this->row_actions($actions));
    }

    protected function column_platform_id($item): string
    {
        return esc_html($item->platformId);
    }

    protected function column_client_id($item): string
    {
        return esc_html($item->clientId);
    }

    protected function column_enabled($item): string
    {
        return $item->enabled ? '<span style="color:green">Enabled</span>' : '<span style="color:gray">Disabled</span>';
    }
}
