<?php

declare(strict_types=1);

namespace Stride\Modules\Notification;

use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\SessionRepository;

final class NotificationMapper
{
    public function __construct(
        private readonly EditionRepository $editions,
        private readonly SessionRepository $sessions,
    ) {}

    /**
     * @return array{id: string, type: string, title: string, body: string, url: string, timestamp: int}
     */
    public function fromAuditEntry(object $entry): array
    {
        $context = json_decode($entry->context ?? '{}', true) ?: [];
        $action = $entry->action ?? '';

        [$type, $title, $body, $url] = match ($action) {
            'registration.created' => $this->mapRegistrationCreated($context),
            'registration.cancelled' => $this->mapRegistrationCancelled($context),
            'attendance.marked_present' => $this->mapAttendance($context, 'aanwezigheid'),
            'attendance.marked_absent' => $this->mapAttendance($context, 'afwezig gemeld'),
            'attendance.marked_excused' => $this->mapAttendance($context, 'verontschuldigd'),
            'completion.course_completed' => $this->mapCourseCompleted($context),
            'completion.certificate_issued' => $this->mapCertificateIssued($context),
            'session.note_updated' => $this->mapSessionNoteUpdated($context),
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

    private function mapRegistrationCreated(array $context): array
    {
        $editionTitle = $this->resolveEditionTitle((int) ($context['edition_id'] ?? 0));

        return [
            'enrollment',
            sprintf('Je inschrijving voor %s is bevestigd', $editionTitle),
            '',
            $this->editionUrl((int) ($context['edition_id'] ?? 0)),
        ];
    }

    private function mapRegistrationCancelled(array $context): array
    {
        $editionTitle = $this->resolveEditionTitle((int) ($context['edition_id'] ?? 0));

        return [
            'enrollment',
            sprintf('Je inschrijving voor %s is geannuleerd', $editionTitle),
            '',
            $this->editionUrl((int) ($context['edition_id'] ?? 0)),
        ];
    }

    private function mapAttendance(array $context, string $statusText): array
    {
        $sessionDate = $this->resolveSessionDate((int) ($context['session_id'] ?? 0));

        return [
            'attendance',
            sprintf('Je %s op %s is geregistreerd', $statusText, $sessionDate),
            '',
            $this->editionUrl((int) ($context['edition_id'] ?? 0)),
        ];
    }

    private function mapCourseCompleted(array $context): array
    {
        $courseTitle = $context['course_title'] ?? $this->resolveCourseTitle((int) ($context['course_id'] ?? 0));

        return [
            'completion',
            sprintf('Je hebt %s afgerond', $courseTitle),
            '',
            get_permalink((int) ($context['course_id'] ?? 0)) ?: '',
        ];
    }

    private function mapCertificateIssued(array $context): array
    {
        $courseTitle = $context['course_title'] ?? $this->resolveCourseTitle((int) ($context['course_id'] ?? 0));

        return [
            'certificate',
            sprintf('Je certificaat voor %s is beschikbaar', $courseTitle),
            '',
            $context['certificate_link'] ?? get_permalink((int) ($context['course_id'] ?? 0)) ?: '',
        ];
    }

    private function mapSessionNoteUpdated(array $context): array
    {
        $sessionDate = $this->resolveSessionDate((int) ($context['session_id'] ?? 0));

        return [
            'session',
            sprintf('Sessie %s is bijgewerkt', $sessionDate),
            '',
            $this->editionUrl((int) ($context['edition_id'] ?? 0)),
        ];
    }

    private function resolveEditionTitle(int $editionId): string
    {
        if ($editionId <= 0) {
            return '(onbekend)';
        }

        $post = $this->editions->find($editionId);

        return is_wp_error($post) ? '(verwijderd)' : $post->post_title;
    }

    private function resolveSessionDate(int $sessionId): string
    {
        if ($sessionId <= 0) {
            return '(onbekend)';
        }

        $date = $this->sessions->getField($sessionId, 'date');

        return $date ? stride_format_date($date) : '(onbekend)';
    }

    /**
     * Course titles come from LearnDash's `sfwd-courses`, which has no Stride
     * repository (LMSAdapterInterface exposes business ops only — no title reads).
     * `get_post()` is the framework-canonical path here.
     */
    private function resolveCourseTitle(int $courseId): string
    {
        if ($courseId <= 0) {
            return '(onbekend)';
        }

        $post = get_post($courseId);

        return $post ? $post->post_title : '(verwijderd)';
    }

    private function editionUrl(int $editionId): string
    {
        if ($editionId <= 0) {
            return '';
        }

        return get_permalink($editionId) ?: '';
    }
}
