<?php
declare(strict_types=1);

namespace NtdstAssistant;

class SystemPrompt implements \NTDST_Service_Meta
{
    private string $basePath;
    private ?string $cachedPrompt = null;

    public static function metadata(): array
    {
        return [
            'name' => 'Assistant System Prompt',
            'description' => 'Builds system prompt with base rules and filtered domain context',
            'priority' => 15,
        ];
    }

    public function __construct()
    {
        $this->basePath = dirname(__DIR__) . '/prompts/base.md';
    }

    public function build(): string
    {
        if ($this->cachedPrompt !== null) {
            return $this->cachedPrompt;
        }

        $base = file_exists($this->basePath)
            ? file_get_contents($this->basePath)
            : '';

        $context = [
            'user_id' => get_current_user_id(),
            'locale' => get_locale(),
            'abilities' => array_map(
                fn(\WP_Ability $a) => $a->get_name(),
                wp_get_abilities()
            ),
        ];

        $this->cachedPrompt = apply_filters('ntdst_assistant/system_prompt', $base, $context);
        return $this->cachedPrompt;
    }
}
