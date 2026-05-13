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
        'user.deleted',
        'user.role_changed',
        'user.profile_updated',
        'edition.created',
        'edition.updated',
        'auth.login',
        'auth.logout',
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
     * @param object $entry  Row from audit log (id, action, actor_id, entity_type, entity_id, context, created_at)
     * @param string $actorName  Display name of the actor
     * @param string $targetName  Display name resolved from entity_id (e.g. for user.* events). Empty if not applicable.
     * @return array{id: int, type: string, text: string, actor_name: string, timestamp: int}
     */
    public static function fromAuditEntry(object $entry, string $actorName, string $targetName = ''): array
    {
        $context = json_decode($entry->context ?? '{}', true) ?: [];
        $edition = $context['edition_title'] ?? '';

        // Allow controller-resolved target to override anything in context
        if ($targetName !== '') {
            $context['target_name'] = $targetName;
        }

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
        // usermeta.* events fire frequently (one per key) — collapse to one line.
        // Subject is the target user (entity_id), not the actor (which may be Systeem on bulk imports).
        if (str_starts_with($action, 'usermeta.')) {
            $subject = $context['target_name'] ?? '';
            if ($subject === '') {
                $subject = $name === 'Systeem' ? 'verwijderde gebruiker' : $name;
            }
            return ['user', "Profielgegevens van {$subject} bijgewerkt"];
        }

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

            'user.created'
                => ['user', self::userText($context, $name, 'aangemaakt')],

            'user.updated', 'user.profile_updated'
                => ['user', self::userText($context, $name, 'bijgewerkt')],

            'user.deleted'
                => ['user', self::userText($context, $name, 'verwijderd')],

            'user.role_changed'
                => ['user', self::roleChangedText($context, $name)],

            'impersonation.started'
                => ['user', "{$name} bekijkt de site als " . ($context['target_name'] ?? 'andere gebruiker')],

            'edition.created'
                => ['edition', $edition !== ''
                    ? "Editie aangemaakt: {$edition}"
                    : "Editie aangemaakt door {$name}"],

            'edition.updated'
                => ['edition', $edition !== ''
                    ? "Editie bijgewerkt: {$edition}"
                    : "Editie bijgewerkt door {$name}"],

            'auth.login'
                => ['auth', "{$name} ingelogd"],

            'auth.logout'
                => ['auth', "{$name} uitgelogd"],

            default
                => ['action', "{$name}: {$action}"],
        };
    }

    private static function userText(array $context, string $name, string $verb): string
    {
        $target = $context['target_name'] ?? $context['user_name'] ?? '';
        // Actor is "Systeem" and we have no target → audit references a user that no longer exists.
        if ($target === '' && $name === 'Systeem') {
            return "Gebruiker {$verb} (account niet meer beschikbaar)";
        }
        if ($target !== '' && $target !== $name) {
            return "{$target} {$verb} door {$name}";
        }
        return "{$name} {$verb}";
    }

    private static function roleChangedText(array $context, string $name): string
    {
        $target = $context['target_name'] ?? $context['user_name'] ?? '';
        $from = $context['from_role'] ?? $context['old_role'] ?? '';
        $to = $context['to_role'] ?? $context['new_role'] ?? '';

        $subject = $target !== '' && $target !== $name ? $target : $name;
        if ($target === '' && $name === 'Systeem') {
            $subject = 'verwijderde gebruiker';
        }

        if ($from !== '' && $to !== '') {
            return "Rol van {$subject} gewijzigd van {$from} naar {$to}";
        }
        if ($to !== '') {
            return "Rol van {$subject} gewijzigd naar {$to}";
        }
        return "Rol van {$subject} gewijzigd";
    }

    private static function attendanceText(array $context, string $edition, string $status): string
    {
        $userName = $context['user_name'] ?? 'Onbekend';

        return "{$userName} {$status} gemarkeerd bij {$edition}";
    }
}
