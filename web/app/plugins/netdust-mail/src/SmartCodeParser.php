<?php

declare(strict_types=1);

namespace Netdust\Mail;

defined('ABSPATH') || exit;

/**
 * Parses SmartCodes in content strings.
 *
 * SmartCodes use the syntax {{category.field}} or {{category.field|default}}.
 * Example: {{user.first_name}}, {{site.name|My Site}}
 */
class SmartCodeParser
{
    /**
     * Regex pattern for matching SmartCodes.
     *
     * Matches: {{category.field}} and {{category.field|default}}
     * Groups:
     *   1 - category (e.g., 'user', 'site')
     *   2 - field (e.g., 'email', 'name')
     *   3 - default value (optional)
     */
    private const PATTERN = '/\{\{([a-z_]+)\.([a-z_]+)(?:\|([^}]*))?\}\}/i';

    public function __construct(
        private readonly SmartCodeRegistry $registry,
    ) {}

    /**
     * Parse SmartCodes in content.
     *
     * @param string $content The content containing SmartCodes.
     * @param array  $context Context data for resolution (e.g., user_id, post_id).
     * @return string The parsed content with SmartCodes replaced.
     */
    public function parse(string $content, array $context): string
    {
        return preg_replace_callback(
            self::PATTERN,
            function ($matches) use ($context) {
                $category = $matches[1];
                $field = $matches[2];
                $default = $matches[3] ?? '';

                $callback = $this->registry->getCallback($category, $field);

                if ($callback === null) {
                    // Unknown code - leave intact for validation
                    return $matches[0];
                }

                $value = $callback($context);

                if ($value === null || $value === '') {
                    return $default;
                }

                return (string) $value;
            },
            $content
        );
    }

    /**
     * Find unparsed SmartCodes remaining in content.
     *
     * @param string $content The content to check.
     * @return array<string> List of unparsed SmartCode identifiers (e.g., ['user.email', 'site.name']).
     */
    public function findUnparsed(string $content): array
    {
        preg_match_all(self::PATTERN, $content, $matches);

        $codes = [];
        foreach ($matches[0] as $i => $match) {
            $codes[] = $matches[1][$i] . '.' . $matches[2][$i];
        }

        return array_unique($codes);
    }

    /**
     * Extract all SmartCodes with full info.
     *
     * @param string $content The content to extract from.
     * @return array<int, array{full: string, category: string, field: string, default: string|null}>
     */
    public function extractCodes(string $content): array
    {
        preg_match_all(self::PATTERN, $content, $matches, PREG_SET_ORDER);

        $codes = [];
        foreach ($matches as $match) {
            $codes[] = [
                'full' => $match[0],
                'category' => $match[1],
                'field' => $match[2],
                'default' => $match[3] ?? null,
            ];
        }

        return $codes;
    }
}
