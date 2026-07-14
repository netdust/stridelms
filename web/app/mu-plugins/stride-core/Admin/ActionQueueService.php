<?php

declare(strict_types=1);

namespace Stride\Admin;

/**
 * Pure logic class that evaluates configured alert rules against pre-fetched data
 * and returns prioritized action items for the admin dashboard.
 *
 * No WordPress functions, no database access — just rule evaluation.
 */
final class ActionQueueService
{
    /** Default rule configurations with thresholds. */
    public const DEFAULTS = [
        'capacity_threshold'  => ['enabled' => true, 'value' => 80],
        'session_approaching' => ['enabled' => true, 'value' => 1],
        'stale_quote'         => ['enabled' => true, 'value' => 7],
        'edition_starting'    => ['enabled' => true, 'value' => 3],
        'incomplete_tasks'    => ['enabled' => true, 'value' => 7],
        'gate_reminder_days'  => ['enabled' => true, 'value' => 7],
    ];

    /** Priority sort order: lower = higher priority. */
    private const PRIORITY_ORDER = [
        'red'   => 0,
        'amber' => 1,
        'blue'  => 2,
    ];

    /**
     * Evaluate enabled rules against the provided data.
     *
     * Each item carries at most ONE navigation affordance:
     *  - `target` — a workspace deep-link `{view, params}` routed through the
     *    shell's switchView (edition-scoped meldingen open the grid filtered
     *    to that edition; the stale-tasks aggregate opens the Acties panel's
     *    "Wacht op gebruiker" tab). Preferred: the admin stays in the
     *    workspace instead of being bounced to legacy wp-admin edit screens.
     *  - `url` — a raw wp-admin URL, ONLY where that screen genuinely is the
     *    workflow (the quote edit screen).
     *  - neither — the melding is informational (e.g. a session whose edition
     *    meta is missing: the old code linked post.php?post=0).
     *
     * @param array<string, array{enabled: bool, value?: int}> $rules  Rule configurations.
     * @param array<string, array>                              $data   Pre-fetched data keyed by data type.
     * @return array<int, array{rule: string, priority: string, text: string, subject_id: int|null, url: string, target: array{view: string, params: array<string, int|string>}|null}>
     */
    public function evaluate(array $rules, array $data): array
    {
        $items = [];

        if ($this->isEnabled($rules, 'capacity_threshold')) {
            $items = array_merge($items, $this->evaluateCapacity($rules['capacity_threshold'], $data['editions'] ?? []));
        }

        if ($this->isEnabled($rules, 'session_approaching')) {
            $items = array_merge($items, $this->evaluateSessionApproaching($rules['session_approaching'], $data['approaching_sessions'] ?? []));
        }

        if ($this->isEnabled($rules, 'stale_quote')) {
            $items = array_merge($items, $this->evaluateStaleQuotes($data['stale_quotes'] ?? []));
        }

        if ($this->isEnabled($rules, 'edition_starting')) {
            $items = array_merge($items, $this->evaluateEditionStarting($data['starting_soon'] ?? []));
        }

        if ($this->isEnabled($rules, 'incomplete_tasks')) {
            $items = array_merge($items, $this->evaluateIncompleteTasks($data['incomplete_tasks'] ?? []));
        }

        usort($items, static function (array $a, array $b): int {
            return (self::PRIORITY_ORDER[$a['priority']] ?? 99) <=> (self::PRIORITY_ORDER[$b['priority']] ?? 99);
        });

        return $items;
    }

    private function isEnabled(array $rules, string $key): bool
    {
        return !empty($rules[$key]['enabled']);
    }

    /**
     * Editions approaching capacity threshold.
     * @return list<array>
     */
    private function evaluateCapacity(array $rule, array $editions): array
    {
        $threshold = $rule['value'] ?? 80;
        $items = [];

        foreach ($editions as $edition) {
            $capacity = (int) ($edition['capacity'] ?? 0);
            if ($capacity <= 0) {
                continue; // Skip unlimited editions
            }

            $registered = (int) ($edition['registered'] ?? 0);
            $percentage = ($registered / $capacity) * 100;

            if ($percentage >= $threshold) {
                $title = $edition['title'] ?? '';
                $editionId = (int) ($edition['id'] ?? 0);
                $items[] = [
                    'rule'       => 'capacity_threshold',
                    'priority'   => $percentage >= 100 ? 'red' : 'amber',
                    'text'       => sprintf('%s: %d/%d plaatsen bezet (%d%%)', $title, $registered, $capacity, (int) $percentage),
                    'subject_id' => $editionId ?: null,
                    'url'        => '',
                    'target'     => $editionId > 0
                        ? ['view' => 'inschrijvingen', 'params' => ['edition_id' => $editionId]]
                        : null,
                ];
            }
        }

        return $items;
    }

    /**
     * Sessions happening within the configured days.
     * @return list<array>
     */
    private function evaluateSessionApproaching(array $rule, array $sessions): array
    {
        $items = [];

        foreach ($sessions as $session) {
            $title = $session['edition_title'] ?? '';
            $date = $session['date'] ?? '';
            // A session without edition meta gets NO link at all — the old
            // sprintf produced post.php?post=0&action=edit (a dead edit screen).
            $editionId = (int) ($session['edition_id'] ?? 0);
            $items[] = [
                'rule'       => 'session_approaching',
                'priority'   => 'blue',
                'text'       => sprintf('Sessie "%s" op %s nadert', $title, $date),
                'subject_id' => $session['id'] ?? null,
                'url'        => '',
                'target'     => $editionId > 0
                    ? ['view' => 'inschrijvingen', 'params' => ['edition_id' => $editionId]]
                    : null,
            ];
        }

        return $items;
    }

    /**
     * Quotes not actioned within configured days.
     * @return list<array>
     */
    private function evaluateStaleQuotes(array $quotes): array
    {
        $count = count($quotes);
        if ($count === 0) {
            return [];
        }

        // Quote workflow lives on the wp-admin quote screens — the one melding
        // family that keeps a raw URL instead of a workspace target.
        if ($count === 1) {
            $quote = $quotes[0];
            return [[
                'rule'       => 'stale_quote',
                'priority'   => 'amber',
                'text'       => sprintf('Offerte %s wacht op actie', $quote['number'] ?? ''),
                'subject_id' => $quote['id'] ?? null,
                'url'        => sprintf('/wp/wp-admin/post.php?post=%d&action=edit', $quote['id'] ?? 0),
                'target'     => null,
            ]];
        }

        return [[
            'rule'       => 'stale_quote',
            'priority'   => 'amber',
            'text'       => sprintf('%d offertes wachten op actie', $count),
            'subject_id' => null,
            'url'        => '/wp/wp-admin/edit.php?post_type=vad_quote',
            'target'     => null,
        ]];
    }

    /**
     * Editions starting within configured days.
     * @return list<array>
     */
    private function evaluateEditionStarting(array $editions): array
    {
        $items = [];

        foreach ($editions as $edition) {
            $title = $edition['title'] ?? '';
            $date = $edition['start_date'] ?? '';
            $editionId = (int) ($edition['id'] ?? 0);
            $items[] = [
                'rule'       => 'edition_starting',
                'priority'   => 'blue',
                'text'       => sprintf('Editie "%s" start op %s', $title, $date),
                'subject_id' => $editionId ?: null,
                'url'        => '',
                'target'     => $editionId > 0
                    ? ['view' => 'inschrijvingen', 'params' => ['edition_id' => $editionId]]
                    : null,
            ];
        }

        return $items;
    }

    /**
     * Tasks not completed within configured days.
     * @return list<array>
     */
    private function evaluateIncompleteTasks(array $tasks): array
    {
        $count = count($tasks);
        if ($count === 0) {
            return [];
        }

        return [[
            'rule'       => 'incomplete_tasks',
            'priority'   => 'amber',
            'text'       => sprintf('%d inschrijving%s met openstaande taken', $count, $count > 1 ? 'en' : ''),
            'subject_id' => null,
            'url'        => '',
            // The per-person list is the Acties panel's "Wacht op gebruiker"
            // tab, one panel over — the old link was a dead #action-required
            // anchor from the pre-workspace dashboard.
            'target'     => ['view' => 'vandaag', 'params' => ['tab' => 'gebruiker']],
        ]];
    }
}
