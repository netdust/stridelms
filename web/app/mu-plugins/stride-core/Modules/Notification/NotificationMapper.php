<?php

declare(strict_types=1);

namespace Stride\Modules\Notification;

/**
 * Maps audit log entries to notification display format.
 *
 * Stateless mapper — resolves titles from post IDs at render time.
 */
final class NotificationMapper
{
    /**
     * Convert an audit log entry to a notification array.
     *
     * @return array{id: string, type: string, title: string, body: string, url: string, timestamp: int}
     */
    public static function fromAuditEntry(object $entry): array
    {
        $context = json_decode($entry->context ?? '{}', true) ?: [];
        $action = $entry->action ?? '';

        [$type, $title, $body, $url] = match ($action) {
            'registration.created' => self::mapRegistrationCreated($context),
            'registration.cancelled' => self::mapRegistrationCancelled($context),
            'attendance.marked_present' => self::mapAttendance($context, 'aanwezigheid'),
            'attendance.marked_absent' => self::mapAttendance($context, 'afwezig gemeld'),
            'attendance.marked_excused' => self::mapAttendance($context, 'verontschuldigd'),
            'completion.course_completed' => self::mapCourseCompleted($context),
            'completion.certificate_issued' => self::mapCertificateIssued($context),
            'session.note_updated' => self::mapSessionNoteUpdated($context),
            default => ['action', $action, '', ''],
        };

        return [
            'id' => 'audit_' . ($entry->id ?? md5($action . ($entry->created_at ?? ''))),
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'timestamp' => strtotime($entry->created_at ?? 'now') ?: time(),
        ];
    }

    private static function mapRegistrationCreated(array $context): array
    {
        $editionTitle = self::resolveEditionTitle((int) ($context['edition_id'] ?? 0));

        return [
            'enrollment',
            sprintf('Je inschrijving voor %s is bevestigd', $editionTitle),
            '',
            self::editionUrl((int) ($context['edition_id'] ?? 0)),
        ];
    }

    private static function mapRegistrationCancelled(array $context): array
    {
        $editionTitle = self::resolveEditionTitle((int) ($context['edition_id'] ?? 0));

        return [
            'enrollment',
            sprintf('Je inschrijving voor %s is geannuleerd', $editionTitle),
            '',
            self::editionUrl((int) ($context['edition_id'] ?? 0)),
        ];
    }

    private static function mapAttendance(array $context, string $statusText): array
    {
        $sessionDate = self::resolveSessionDate((int) ($context['session_id'] ?? 0));

        return [
            'attendance',
            sprintf('Je %s op %s is geregistreerd', $statusText, $sessionDate),
            '',
            self::editionUrl((int) ($context['edition_id'] ?? 0)),
        ];
    }

    private static function mapCourseCompleted(array $context): array
    {
        $courseTitle = $context['course_title'] ?? self::resolveCourseTitle((int) ($context['course_id'] ?? 0));

        return [
            'completion',
            sprintf('Je hebt %s afgerond', $courseTitle),
            '',
            get_permalink((int) ($context['course_id'] ?? 0)) ?: '',
        ];
    }

    private static function mapCertificateIssued(array $context): array
    {
        $courseTitle = $context['course_title'] ?? self::resolveCourseTitle((int) ($context['course_id'] ?? 0));

        return [
            'certificate',
            sprintf('Je certificaat voor %s is beschikbaar', $courseTitle),
            '',
            $context['certificate_link'] ?? get_permalink((int) ($context['course_id'] ?? 0)) ?: '',
        ];
    }

    private static function mapSessionNoteUpdated(array $context): array
    {
        $sessionDate = self::resolveSessionDate((int) ($context['session_id'] ?? 0));

        return [
            'session',
            sprintf('Sessie %s is bijgewerkt', $sessionDate),
            '',
            self::editionUrl((int) ($context['edition_id'] ?? 0)),
        ];
    }

    // === Resolvers (fetch titles/dates from posts) ===

    private static function resolveEditionTitle(int $editionId): string
    {
        if ($editionId <= 0) {
            return '(onbekend)';
        }

        $post = get_post($editionId);

        return $post ? $post->post_title : '(verwijderd)';
    }

    private static function resolveSessionDate(int $sessionId): string
    {
        if ($sessionId <= 0) {
            return '(onbekend)';
        }

        $date = ntdst_data()->get('vad_session')->getMeta($sessionId, 'date');

        return $date ? stride_format_date($date) : '(onbekend)';
    }

    private static function resolveCourseTitle(int $courseId): string
    {
        if ($courseId <= 0) {
            return '(onbekend)';
        }

        $post = get_post($courseId);

        return $post ? $post->post_title : '(verwijderd)';
    }

    private static function editionUrl(int $editionId): string
    {
        if ($editionId <= 0) {
            return '';
        }

        return get_permalink($editionId) ?: '';
    }
}
