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

// Global namespace for ntdst_log function
namespace {
    if (!function_exists('ntdst_log')) {
        function ntdst_log(string $channel = 'default'): object
        {
            return new class {
                public function info(string $message, array $context = []): void {}
                public function debug(string $message, array $context = []): void {}
                public function warning(string $message, array $context = []): void {}
                public function error(string $message, array $context = []): void {}
            };
        }
    }
}
