<?php
declare(strict_types=1);

namespace Stride\Modules\Audit\Admin;

use DateTime;
use Stride\Infrastructure\AbstractService;
use Stride\Modules\Audit\AuditService;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class AuditAPIController extends AbstractService
{
    private const NAMESPACE = 'stride/v1';

    public static function metadata(): array
    {
        return [
            'name' => 'Audit API Controller',
            'description' => 'REST API endpoints for audit log',
            'admin_only' => true,
            'priority' => 101,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'audit_api';
    }

    protected function init(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/audit', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'getEntries'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => [
                'from' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'to' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'entity_type' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_key',
                ],
                'actor_id' => [
                    'required' => false,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'page' => [
                    'required' => false,
                    'type' => 'integer',
                    'default' => 1,
                    'sanitize_callback' => 'absint',
                ],
                'per_page' => [
                    'required' => false,
                    'type' => 'integer',
                    'default' => 50,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/audit/users', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'searchUsers'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => [
                'search' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    public function checkPermission(): bool
    {
        return current_user_can('manage_options');
    }

    public function getEntries(WP_REST_Request $request): WP_REST_Response
    {
        $auditService = ntdst_get(AuditService::class);
        $repository = $auditService->getRepository();

        $from = new DateTime($request->get_param('from') ?: '-30 days');
        $to = new DateTime($request->get_param('to') ?: 'now');
        $page = max(1, $request->get_param('page'));
        $perPage = min(100, max(1, $request->get_param('per_page')));
        $offset = ($page - 1) * $perPage;

        $filters = array_filter([
            'entity_type' => $request->get_param('entity_type'),
            'actor_id' => $request->get_param('actor_id'),
        ]);

        $entries = $repository->findByDateRange($from, $to, $filters, $perPage, $offset);
        $total = $repository->countByDateRange($from, $to, $filters);

        // Enrich with actor names
        $actorIds = array_filter(array_unique(array_column($entries, 'actor_id')));
        $actorNames = [];

        if (!empty($actorIds)) {
            $users = get_users(['include' => $actorIds, 'fields' => ['ID', 'display_name']]);
            foreach ($users as $user) {
                $actorNames[$user->ID] = $user->display_name;
            }
        }

        $enrichedEntries = array_map(function ($entry) use ($actorNames) {
            $entry->actor_name = $actorNames[$entry->actor_id] ?? null;
            return $entry;
        }, $entries);

        return new WP_REST_Response([
            'entries' => $enrichedEntries,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    public function searchUsers(WP_REST_Request $request): WP_REST_Response
    {
        $search = $request->get_param('search');

        $users = get_users([
            'search' => '*' . $search . '*',
            'search_columns' => ['user_login', 'user_email', 'display_name'],
            'number' => 10,
            'fields' => ['ID', 'display_name', 'user_email'],
        ]);

        $results = array_map(function ($user) {
            return [
                'id' => $user->ID,
                'name' => $user->display_name,
                'email' => $user->user_email,
            ];
        }, $users);

        return new WP_REST_Response($results);
    }
}
