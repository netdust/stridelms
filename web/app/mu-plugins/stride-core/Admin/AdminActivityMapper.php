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
     * Context may be enriched by the controller (edition_title, course_title,
     * target_user_id, edition_id, etc.) so the mapper doesn't have to query.
     *
     * @param object $entry  Row from audit log (id, action, actor_id, entity_type, entity_id, context, created_at)
     * @param string $actorName  Display name of the actor
     * @param string $targetName  Display name resolved from entity_id (e.g. for user.* events). Empty if not applicable.
     * @return array{id: int, type: string, text: string, target_url: string, actor_name: string, timestamp: int}
     */
    public static function fromAuditEntry(object $entry, string $actorName, string $targetName = ''): array
    {
        $context = json_decode($entry->context ?? '{}', true) ?: [];

        // Course completions / certificates store `course_title`; everything
        // else stores `edition_title` (when the controller has enriched it).
        $subject = $context['edition_title'] ?? $context['course_title'] ?? '';

        // Allow controller-resolved target to override anything in context
        if ($targetName !== '') {
            $context['target_name'] = $targetName;
        }

        [$type, $text] = self::resolve($entry->action, $actorName, $subject, $context);

        return [
            'id'         => (int) $entry->id,
            'type'       => $type,
            'text'       => $text,
            'target_url' => self::targetUrl($entry->action, $context),
            'actor_name' => $actorName,
            'timestamp'  => strtotime($entry->created_at),
        ];
    }

    /**
     * Resolve a target URL so the frontend can render the activity line as
     * a link. Returns '' when there's nothing meaningful to link to.
     */
    private static function targetUrl(string $action, array $context): string
    {
        $editionId = (int) ($context['edition_id'] ?? 0);
        $courseId = (int) ($context['course_id'] ?? 0);

        if (str_starts_with($action, 'registration.') || str_starts_with($action, 'attendance.') || str_starts_with($action, 'edition.')) {
            if ($editionId > 0) {
                return admin_url("post.php?post={$editionId}&action=edit");
            }
        }

        if (str_starts_with($action, 'completion.')) {
            if ($courseId > 0) {
                return admin_url("post.php?post={$courseId}&action=edit");
            }
        }

        if (str_starts_with($action, 'quote.')) {
            $quoteId = (int) ($context['quote_id'] ?? 0);
            if ($quoteId > 0) {
                return admin_url("post.php?post={$quoteId}&action=edit");
            }
        }

        return '';
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

        $editionLabel = $edition !== '' ? $edition : __('een editie', 'stride');

        return match ($action) {
            'registration.created'
                => ['enrollment', "{$name} heeft zich ingeschreven voor {$editionLabel}"],

            'registration.cancelled'
                => ['enrollment', "Inschrijving van {$name} voor {$editionLabel} is geannuleerd"],

            'attendance.marked_present'
                => ['attendance', self::attendanceText($context, $editionLabel, 'aanwezig')],

            'attendance.marked_absent'
                => ['attendance', self::attendanceText($context, $editionLabel, 'afwezig')],

            'attendance.marked_excused'
                => ['attendance', self::attendanceText($context, $editionLabel, 'verontschuldigd')],

            'completion.course_completed'
                => ['completion', $edition !== ''
                    ? "{$name} heeft {$edition} afgerond"
                    : "{$name} heeft een opleiding afgerond"],

            'completion.certificate_issued'
                => ['completion', $edition !== ''
                    ? "Certificaat uitgereikt aan {$name} voor {$edition}"
                    : "Certificaat uitgereikt aan {$name}"],

            'quote.created'
                => ['quote', $edition !== ''
                    ? "Offerte aangemaakt voor {$name} — {$edition}"
                    : "Offerte aangemaakt voor {$name}"],

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
