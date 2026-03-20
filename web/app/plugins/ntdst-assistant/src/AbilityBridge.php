<?php
declare(strict_types=1);

namespace NtdstAssistant;

class AbilityBridge implements \NTDST_Service_Meta
{
    public static function metadata(): array
    {
        return [
            'name'        => 'Assistant Ability Bridge',
            'description' => 'Translates WP Abilities API to Claude tool format with confirmation flow',
            'priority'    => 15,
        ];
    }

    public function __construct(
        private readonly ConversationStore $store,
    ) {}

    // ---------------------------------------------------------------
    // Name Mapping: WP uses `/`, Claude requires `__`
    // ---------------------------------------------------------------

    public static function toClaudeName(string $wpName): string
    {
        return str_replace('/', '__', $wpName);
    }

    public static function toWpName(string $claudeName): string
    {
        return str_replace('__', '/', $claudeName);
    }

    // ---------------------------------------------------------------
    // Tool Definitions — convert WP abilities to Claude format
    // ---------------------------------------------------------------

    /**
     * Build Claude-compatible tool definitions from registered WP abilities.
     *
     * @return array<int, array{name: string, description: string, input_schema: array}>
     */
    public function getToolDefinitions(): array
    {
        $tools = [];

        foreach (wp_get_abilities() as $ability) {
            /** @var \WP_Ability $ability */
            if (!$ability->get_meta_item('show_in_rest', false)) {
                continue;
            }

            $schema = $ability->get_input_schema();
            if (empty($schema)) {
                $schema = ['type' => 'object', 'properties' => new \stdClass()];
            }

            $tools[] = [
                'name'         => self::toClaudeName($ability->get_name()),
                'description'  => $ability->get_description(),
                'input_schema' => $schema,
            ];
        }

        /** @var array $tools */
        return apply_filters('ntdst_assistant/tools', $tools);
    }

    // ---------------------------------------------------------------
    // Execute — dispatch or request confirmation
    // ---------------------------------------------------------------

    /**
     * Execute an ability or return a pending confirmation for write operations.
     *
     * @param string $wpName  Ability name in WP format (e.g. "stride/get-editions")
     * @param array  $input   Input parameters
     * @return array{status: string, result?: mixed, confirm_token?: string, summary?: string}|\WP_Error
     */
    public function execute(string $wpName, array $input): array|\WP_Error
    {
        $ability = wp_get_ability($wpName);
        if ($ability === null) {
            return new \WP_Error('unknown_ability', "Ability '{$wpName}' is not registered.");
        }

        $permCheck = $ability->check_permissions($input);
        if ($permCheck instanceof \WP_Error) {
            return $permCheck;
        }
        if ($permCheck === false) {
            return new \WP_Error('forbidden', "Permission denied for '{$wpName}'.");
        }

        $readonly = (bool) $ability->get_meta_item('readonly', false);

        if ($readonly) {
            return $this->doExecute($ability, $input);
        }

        return $this->createPendingConfirmation($ability, $input);
    }

    /**
     * Execute a previously confirmed write action by verifying its HMAC token.
     *
     * @param int    $userId       The user requesting confirmation
     * @param string $confirmToken The HMAC token from the pending confirmation
     * @return array{status: string, result: mixed}|\WP_Error
     */
    public function executeConfirmed(int $userId, string $confirmToken): array|\WP_Error
    {
        $pending = $this->store->getPending($userId);
        if ($pending === null) {
            return new \WP_Error('no_pending', 'No pending action found.');
        }

        // Verify HMAC
        $expectedToken = $this->computeToken(
            $pending['ability'],
            $pending['input'],
            $pending['user_id'],
            $pending['time'],
        );

        if (!hash_equals($expectedToken, $confirmToken)) {
            return new \WP_Error('invalid_token', 'Confirmation token is invalid.');
        }

        // Re-check permissions before executing
        $ability = wp_get_ability($pending['ability']);
        if ($ability === null) {
            return new \WP_Error('unknown_ability', "Ability '{$pending['ability']}' is no longer registered.");
        }

        $permCheck = $ability->check_permissions($pending['input']);
        if ($permCheck instanceof \WP_Error) {
            return $permCheck;
        }
        if ($permCheck === false) {
            return new \WP_Error('forbidden', "Permission denied for '{$pending['ability']}'.");
        }

        // Execute and clear pending
        $result = $this->doExecute($ability, $pending['input']);
        $this->store->clearPending($userId);

        return $result;
    }

    // ---------------------------------------------------------------
    // Internal helpers
    // ---------------------------------------------------------------

    /**
     * Fire hooks and execute the ability.
     */
    private function doExecute(\WP_Ability $ability, array $input): array
    {
        do_action('ntdst_assistant/before_execute', $ability->get_name(), $input);

        $result = $ability->execute($input);

        do_action('ntdst_assistant/after_execute', $ability->get_name(), $input, $result);

        return [
            'status' => 'executed',
            'result' => $result,
        ];
    }

    /**
     * Store a pending confirmation and return the token + summary.
     */
    private function createPendingConfirmation(\WP_Ability $ability, array $input): array
    {
        $userId = get_current_user_id();
        $time   = time();
        $token  = $this->computeToken($ability->get_name(), $input, $userId, $time);

        $pending = [
            'ability' => $ability->get_name(),
            'input'   => $input,
            'user_id' => $userId,
            'time'    => $time,
            'token'   => $token,
        ];

        $this->store->setPending($userId, $pending);

        // Build human-readable summary
        $describeCallback = $ability->get_meta_item('describe_input');
        $summary = is_callable($describeCallback)
            ? call_user_func($describeCallback, $input)
            : sprintf('%s with input: %s', $ability->get_label(), json_encode($input));

        return [
            'status'        => 'pending_confirmation',
            'confirm_token' => $token,
            'summary'       => $summary,
        ];
    }

    /**
     * Compute HMAC token for a pending action.
     */
    private function computeToken(string $abilityName, array $input, int $userId, int $time): string
    {
        $payload = json_encode([
            $abilityName,
            $input,
            $userId,
            $time,
        ]);

        return hash_hmac('sha256', $payload, wp_salt('auth'));
    }
}
