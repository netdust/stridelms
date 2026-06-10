<?php

/**
 * Stride Infrastructure Stubs for Testing
 *
 * Provides minimal implementations of core infrastructure classes
 * so that service tests can run without loading the full framework.
 */

// Service contracts stubs
namespace stride\services\contracts {
    if (!interface_exists(FluentCRMAdapterInterface::class)) {
        interface FluentCRMAdapterInterface
        {
            public function isAvailable(): bool;
            public function getSubscriberByUserId(int $userId): ?array;
            public function getSubscriberByEmail(string $email): ?array;
            public function createSubscriber(array $data): ?int;
            public function updateSubscriber(int $subscriberId, array $data): bool;
            public function getCustomField(int $subscriberId, string $fieldKey): mixed;
            public function updateCustomField(int $subscriberId, string $fieldKey, mixed $value): bool;
            public function getCustomFields(int $subscriberId): array;
            public function addTag(int $subscriberId, int|string $tagIdOrName): bool;
            public function removeTag(int $subscriberId, int|string $tagIdOrName): bool;
            public function getTags(int $subscriberId): array;
            public function hasTag(int $subscriberId, int|string $tagIdOrName): bool;
            public function createNote(int $subscriberId, string $content, ?string $type = null): ?int;
            public function getNotes(int $subscriberId, int $limit = 10): array;
            public function getCompanies(int $subscriberId): array;
            public function getCompany(int $companyId): ?array;
            public function getCompanyCustomFields(int $companyId): array;
            public function linkToCompany(int $subscriberId, int $companyId): bool;
            public function unlinkFromCompany(int $subscriberId, int $companyId): bool;
            public function findCompanyByExportId(string $exportId): ?array;
            public function getTagIdByName(string $tagName): ?int;
            public function createCompany(array $data): ?int;
            public function updateCompany(int $companyId, array $data): bool;
            public function updateCompanyCustomFields(int $companyId, array $fields): bool;
            public function getCompanySubscribers(int $companyId): array;
            public function findCompanyByName(string $name): ?array;
            public function searchCompanies(string $query, int $limit = 10): array;
            public function getSubscribersByUserIds(array $userIds): array;
            public function getSubscribersWithCompanies(array $userIds): array;
        }
    }

    if (!interface_exists(LearnDashAdapterInterface::class)) {
        interface LearnDashAdapterInterface
        {
            public function isAvailable(): bool;
            public function getCourse(int $courseId): ?\WP_Post;
            public function getCourseSetting(int $courseId, string $key): mixed;
            public function getCourseSettings(int $courseId): array;
            public function hasAccess(int $courseId, int $userId): bool;
            public function getAccessFrom(int $userId, int $courseId): ?int;
            public function getEnrolledUsers(int $courseId): array;
            public function enrollUser(int $userId, int $courseId): bool;
            public function unenrollUser(int $userId, int $courseId): bool;
            public function hasCategory(int $courseId, string $categoryName): bool;
            public function isCompleted(int $userId, int $courseId): bool;
            public function getCertificateLink(int $courseId, int $userId): ?string;
        }
    }

    if (!interface_exists(StorageBackendInterface::class)) {
        interface StorageBackendInterface
        {
            public function getId(): string;
            public function getPriority(): int;
            public function getSupportedFields(): array;
            public function hasField(string $field): bool;
            public function getField(int $userId, string $field): mixed;
            public function getFields(int $userId, array $fields = []): array;
            public function setField(int $userId, string $field, mixed $value): bool;
            public function setFields(int $userId, array $data): bool;
            public function isAvailable(): bool;
            public function clearCache(?int $userId = null): void;
        }
    }
}

namespace Stride\Infrastructure {
    if (!class_exists(AbstractService::class)) {
        /**
         * Abstract Service Stub
         *
         * Minimal implementation for testing services that extend AbstractService.
         */
        abstract class AbstractService implements \NTDST_Service_Meta
        {
            protected array $config = [];

            // Don't call init() automatically in tests
            // Subclasses can call manually if needed

            protected function getDefaultConfig(): array
            {
                return [];
            }

            abstract protected function getConfigSlug(): string;

            abstract protected function init(): void;

            protected function dispatch(string $event, array $data = []): void
            {
                do_action("stride/{$event}", $data);
            }
        }
    }
}

namespace Stride\Modules\Audit {
    // Logger stub for audit module
}

// NTDST Audit plugin stub for testing
namespace NTDST\Audit {
    if (!class_exists(AuditService::class)) {
        /**
         * AuditService stub for testing
         *
         * This is a minimal stub that captures record() calls for assertions.
         */
        class AuditService
        {
            public array $recordedCalls = [];

            public function record(
                string $entityType,
                int $entityId,
                string $action,
                ?int $actorId = null,
                array $context = []
            ): int|\WP_Error {
                $this->recordedCalls[] = [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'action' => $action,
                    'actor_id' => $actorId,
                    'context' => $context,
                ];

                // Mirror the real AuditService (mode-2 stub sync, audit
                // 2026-06-10): record() returns int|WP_Error — callers
                // branch on is_wp_error() (AdminAPIController PII reveal,
                // CR-B2) — and fires ntdst/audit/recorded, which consumers
                // (NotificationService badge cache, CR-F2) listen to. A
                // void/silent stub forks test-world from real-world.
                if (function_exists('do_action')) {
                    do_action('ntdst/audit/recorded', $action, $entityType, $entityId, $context, $actorId);
                }

                return count($this->recordedCalls);
            }

            /**
             * Reset recorded calls (for test isolation)
             */
            public function reset(): void
            {
                $this->recordedCalls = [];
            }

            /**
             * Get all recorded calls
             */
            public function getRecordedCalls(): array
            {
                return $this->recordedCalls;
            }

            /**
             * Get the last recorded call
             */
            public function getLastCall(): ?array
            {
                return end($this->recordedCalls) ?: null;
            }

            /**
             * Get audit entries where user is the subject (not actor).
             *
             * @param string[] $excludeActions
             * @return array<object>
             */
            public function getForSubjectUser(int $userId, int $limit = 50, int $daysBack = 30, array $excludeActions = []): array
            {
                return [];
            }

            /**
             * Get session note update entries for given edition IDs.
             *
             * @param int[] $editionIds
             * @return array<object>
             */
            public function getSessionNoteUpdates(array $editionIds, int $daysBack = 30): array
            {
                return [];
            }
        }
    }

    if (!class_exists(AuditTable::class)) {
        /**
         * AuditTable stub for testing
         *
         * Returns a predictable table name without touching the real database.
         */
        class AuditTable
        {
            public const TABLE_NAME = 'audit_log';

            public static function getTableName(): string
            {
                global $wpdb;
                return $wpdb->prefix . self::TABLE_NAME;
            }
        }
    }
}

// Global namespace for ntdst_log function
namespace {
    // Captured log calls so unit tests can assert logging behavior.
    // Shape: ['channel' => string, 'level' => string, 'message' => string, 'context' => array]
    global $_test_log_entries;
    $_test_log_entries = [];

    if (!function_exists('ntdst_log')) {
        function ntdst_log(string $channel = 'default'): object
        {
            return new class ($channel) {
                public function __construct(private string $channel) {}

                public function info(string $message, array $context = []): void
                {
                    $this->record('info', $message, $context);
                }

                public function debug(string $message, array $context = []): void
                {
                    $this->record('debug', $message, $context);
                }

                public function warning(string $message, array $context = []): void
                {
                    $this->record('warning', $message, $context);
                }

                public function error(string $message, array $context = []): void
                {
                    $this->record('error', $message, $context);
                }

                private function record(string $level, string $message, array $context): void
                {
                    global $_test_log_entries;
                    $_test_log_entries[] = [
                        'channel' => $this->channel,
                        'level' => $level,
                        'message' => $message,
                        'context' => $context,
                    ];
                }
            };
        }
    }
}
