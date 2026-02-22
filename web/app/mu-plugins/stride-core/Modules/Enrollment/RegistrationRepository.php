<?php

declare(strict_types=1);

namespace Stride\Modules\Enrollment;

use Stride\Domain\RegistrationStatus;
use WP_Error;

/**
 * Repository for registration data access.
 *
 * Uses custom table instead of CPT for performance.
 */
final class RegistrationRepository
{
    public const PATH_INDIVIDUAL = 'individual';
    public const PATH_COLLEAGUE = 'colleague';
    public const PATH_TRAJECTORY = 'trajectory';
    public const PATH_INTEREST = 'interest';

    private function table(): string
    {
        return RegistrationTable::getTableName();
    }

    /**
     * Create a new registration.
     *
     * @param array<string, mixed> $data
     * @return int|WP_Error Registration ID or error
     */
    public function create(array $data): int|WP_Error
    {
        global $wpdb;

        $required = ['user_id', 'edition_id'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return new WP_Error('missing_field', "Required field: {$field}");
            }
        }

        // Check for duplicate
        if ($this->exists((int) $data['user_id'], (int) $data['edition_id'])) {
            return new WP_Error('duplicate', 'User already registered for this edition');
        }

        $insert = [
            'user_id' => absint($data['user_id']),
            'edition_id' => absint($data['edition_id']),
            'status' => $data['status'] ?? RegistrationStatus::Confirmed->value,
            'enrollment_path' => $data['enrollment_path'] ?? self::PATH_INDIVIDUAL,
            'enrolled_by' => isset($data['enrolled_by']) ? absint($data['enrolled_by']) : null,
            'voucher_code' => isset($data['voucher_code']) ? sanitize_text_field($data['voucher_code']) : null,
            'quote_id' => isset($data['quote_id']) ? absint($data['quote_id']) : null,
            'notes' => isset($data['notes']) ? sanitize_textarea_field($data['notes']) : null,
        ];

        $result = $wpdb->insert($this->table(), $insert);

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to create registration');
        }

        $registrationId = (int) $wpdb->insert_id;

        // Fire audit hook
        do_action('stride/registration/created', [
            'registration_id' => $registrationId,
            'user_id' => $insert['user_id'],
            'edition_id' => $insert['edition_id'],
            'enrollment_path' => $insert['enrollment_path'],
            'enrolled_by' => $insert['enrolled_by'],
        ]);

        return $registrationId;
    }

    /**
     * Find registration by ID.
     *
     * @return \stdClass|WP_Error
     */
    public function find(int $id): \stdClass|WP_Error
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE id = %d",
            $id
        ));

        if (!$row) {
            return new WP_Error('not_found', 'Registration not found');
        }

        return $row;
    }

    /**
     * Find registration by user and edition.
     */
    public function findByUserAndEdition(int $userId, int $editionId): ?object
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE user_id = %d AND edition_id = %d",
            $userId,
            $editionId
        ));
    }

    /**
     * Check if registration exists.
     */
    public function exists(int $userId, int $editionId): bool
    {
        return $this->findByUserAndEdition($userId, $editionId) !== null;
    }

    /**
     * Get all registrations for a user.
     *
     * @return array<object>
     */
    public function findByUser(int $userId, ?string $status = null): array
    {
        global $wpdb;

        $sql = "SELECT * FROM {$this->table()} WHERE user_id = %d";
        $params = [$userId];

        if ($status !== null) {
            $sql .= " AND status = %s";
            $params[] = $status;
        }

        $sql .= " ORDER BY registered_at DESC";

        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    /**
     * Get all registrations for an edition.
     *
     * @return array<object>
     */
    public function findByEdition(int $editionId, ?string $status = null): array
    {
        global $wpdb;

        $sql = "SELECT * FROM {$this->table()} WHERE edition_id = %d";
        $params = [$editionId];

        if ($status !== null) {
            $sql .= " AND status = %s";
            $params[] = $status;
        }

        $sql .= " ORDER BY registered_at ASC";

        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    /**
     * Count confirmed registrations for edition.
     */
    public function countConfirmed(int $editionId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table()} WHERE edition_id = %d AND status = 'confirmed'",
            $editionId
        ));
    }

    /**
     * Update registration status.
     */
    public function updateStatus(int $id, RegistrationStatus $status): bool
    {
        global $wpdb;

        $data = ['status' => $status->value];

        if ($status === RegistrationStatus::Cancelled) {
            $data['cancelled_at'] = current_time('mysql');
        }

        return $wpdb->update($this->table(), $data, ['id' => $id]) !== false;
    }

    /**
     * Cancel a registration.
     */
    public function cancel(int $id): bool
    {
        // Get registration data before cancelling for audit
        $registration = $this->find($id);
        if (is_wp_error($registration)) {
            return false;
        }

        $result = $this->updateStatus($id, RegistrationStatus::Cancelled);

        if ($result) {
            // Fire audit hook
            do_action('stride/registration/cancelled', [
                'registration_id' => $id,
                'user_id' => $registration->user_id,
                'edition_id' => $registration->edition_id,
            ]);
        }

        return $result;
    }
}
