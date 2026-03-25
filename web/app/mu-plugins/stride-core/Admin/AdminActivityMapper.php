<?php

declare(strict_types=1);

namespace Stride\Admin;

/**
 * Stateless mapper that converts audit log entries to admin-perspective display strings.
 *
 * Unlike NotificationMapper (student-facing: "Je inschrijving..."), this produces
 * admin-facing strings: "Jan Peeters heeft zich ingeschreven voor..."
 */
final class AdminActivityMapper
{
    /** Actions that should be shown in the admin activity feed. */
    private const KNOWN_ACTIONS = [
        'registration.created',
        'registration.cancelled',
        'attendance.marked_present',
        'attendance.marked_absent',
        'attendance.marked_excused',
        'completion.course_completed',
        'completion.certificate_issued',
        'quote.created',
        'quote.sent',
        'impersonation.started',
        'user.created',
        'user.updated',
        'edition.created',
        'edition.updated',
    ];

    /**
     * Check if an audit entry should appear in the activity feed.
     */
    public static function isKnownAction(object $entry): bool
    {
        return in_array($entry->action ?? '', self::KNOWN_ACTIONS, true);
    }

    /**
     * Convert an audit log entry to an admin-friendly activity array.
     *
     * @param object $entry  Row from audit log (id, action, actor_id, context, created_at)
     * @param string $actorName  Display name of the actor
     * @return array{id: int, type: string, text: string, actor_name: string, timestamp: int}
     */
    public static function fromAuditEntry(object $entry, string $actorName): array
    {
        $context = json_decode($entry->context ?? '{}', true) ?: [];
        $edition = $context['edition_title'] ?? '';

        [$type, $text] = self::resolve($entry->action, $actorName, $edition, $context);

        return [
            'id'         => (int) $entry->id,
            'type'       => $type,
            'text'       => $text,
            'actor_name' => $actorName,
            'timestamp'  => strtotime($entry->created_at),
        ];
    }

    /**
     * @return array{0: string, 1: string}  [type, text]
     */
    private static function resolve(string $action, string $name, string $edition, array $context): array
    {
        return match ($action) {
            'registration.created'
                => ['enrollment', "{$name} heeft zich ingeschreven voor {$edition}"],

            'registration.cancelled'
                => ['enrollment', "Inschrijving van {$name} voor {$edition} is geannuleerd"],

            'attendance.marked_present'
                => ['attendance', self::attendanceText($context, $edition, 'aanwezig')],

            'attendance.marked_absent'
                => ['attendance', self::attendanceText($context, $edition, 'afwezig')],

            'attendance.marked_excused'
                => ['attendance', self::attendanceText($context, $edition, 'verontschuldigd')],

            'completion.course_completed'
                => ['completion', "{$name} heeft {$edition} afgerond"],

            'completion.certificate_issued'
                => ['completion', "Certificaat uitgereikt aan {$name} voor {$edition}"],

            'quote.created'
                => ['quote', "Offerte aangemaakt voor {$name} — {$edition}"],

            'quote.sent'
                => ['quote', "Offerte verzonden naar {$name}"],

            default
                => ['action', "{$name}: {$action}"],
        };
    }

    private static function attendanceText(array $context, string $edition, string $status): string
    {
        $userName = $context['user_name'] ?? 'Onbekend';

        return "{$userName} {$status} gemarkeerd bij {$edition}";
    }
}
