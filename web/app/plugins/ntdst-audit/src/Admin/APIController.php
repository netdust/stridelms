<?php

declare(strict_types=1);

namespace NTDST\Audit\Admin;

use DateTime;
use NTDST\Audit\AuditService;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class APIController implements \NTDST_Service_Meta
{
    private const NAMESPACE = 'ntdst/v1';

    public static function metadata(): array
    {
        return [
            'name' => 'Audit API Controller',
            'description' => 'REST API endpoints for audit log',
            'admin_only' => true,
            'priority' => 101,
        ];
    }

    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    private function getCapability(): string
    {
        return apply_filters('ntdst/audit/capability', 'manage_options');
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

        register_rest_route(self::NAMESPACE, '/audit/entity-types', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'getEntityTypes'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);
    }

    public function checkPermission(): bool
    {
        return current_user_can($this->getCapability());
    }

    public function getEntries(WP_REST_Request $request): WP_REST_Response
    {
        $auditService = ntdst_get(AuditService::class);
        $repository = $auditService->getRepository();

        try {
            $from = new DateTime($request->get_param('from') ?: '-30 days');
            $to = new DateTime($request->get_param('to') ?: 'now');
        } catch (\Exception $e) {
            return new WP_REST_Response(['message' => 'Invalid date format'], 400);
        }

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

    public function getEntityTypes(WP_REST_Request $request): WP_REST_Response
    {
        $auditService = ntdst_get(AuditService::class);
        $types = $auditService->getRepository()->getDistinctEntityTypes();

        return new WP_REST_Response($types);
    }
}
