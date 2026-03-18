<?php
declare(strict_types=1);

namespace NetdustLTI\Platform;

use NetdustLTI\Platform\ToolRepository;
use NTDST_Service_Meta;

/**
 * Provides [lti_launch] shortcode for embedding LTI launches.
 *
 * Renders a POST form that submits to /lti/platform/launch with tool
 * and resource information. Supports both numeric tool IDs and slugs.
 */
final class LtiLaunchShortcode implements NTDST_Service_Meta
{
    public static function metadata(): array
    {
        return [
            'name' => 'LTI Launch Shortcode',
            'description' => 'Provides [lti_launch] shortcode for embedding LTI launches',
            'priority' => 15,
        ];
    }

    public function __construct(
        private readonly ToolRepository $toolRepository
    ) {
        add_shortcode('lti_launch', [$this, 'render']);
    }

    /**
     * Render the shortcode.
     *
     * Usage:
     * [lti_launch tool="stride" course_id="123"]Launch Course[/lti_launch]
     * [lti_launch tool="42" class="button primary"]Start Learning[/lti_launch]
     * [lti_launch tool="stride" mode="discover"]Browse Courses[/lti_launch]
     *
     * @param array|string $atts Shortcode attributes
     * @param string|null $content Button text content
     * @return string Rendered HTML form
     */
    public function render(array|string $atts, ?string $content = null): string
    {
        $atts = shortcode_atts([
            'tool' => '',
            'course_id' => '',
            'target_uri' => '',
            'class' => 'button',
            'mode' => 'launch', // 'launch' or 'discover'
        ], $atts, 'lti_launch');

        // Resolve tool ID from slug or numeric ID
        $toolId = $this->resolveToolId($atts['tool']);

        if (!$toolId) {
            return '<!-- LTI Launch: Tool not found -->';
        }

        $buttonText = $content ?: 'Launch';
        $formId = 'lti-launch-' . wp_unique_id();
        $launchUrl = home_url('/lti/platform/launch');

        $messageType = $atts['mode'] === 'discover'
            ? 'LtiDeepLinkingRequest'
            : 'LtiResourceLinkRequest';

        ob_start();
        ?>
        <form id="<?php echo esc_attr($formId); ?>"
              action="<?php echo esc_url($launchUrl); ?>"
              method="post"
              target="_blank"
              class="lti-launch-form">
            <input type="hidden" name="tool_id" value="<?php echo esc_attr((string) $toolId); ?>">
            <input type="hidden" name="resource_link_id" value="<?php echo esc_attr($atts['course_id']); ?>">
            <input type="hidden" name="target_link_uri" value="<?php echo esc_attr($atts['target_uri']); ?>">
            <input type="hidden" name="message_type" value="<?php echo esc_attr($messageType); ?>">
            <?php wp_nonce_field('lti_shortcode_launch', 'lti_nonce'); ?>
            <button type="submit" class="<?php echo esc_attr($atts['class']); ?>">
                <?php echo esc_html($buttonText); ?>
            </button>
        </form>
        <?php
        return ob_get_clean();
    }

    /**
     * Resolve a tool reference to a tool ID.
     *
     * @param string $toolRef Numeric ID or slug
     * @return int|null Tool ID or null if not found
     */
    private function resolveToolId(string $toolRef): ?int
    {
        if (empty($toolRef)) {
            return null;
        }

        // If numeric, use directly
        if (is_numeric($toolRef)) {
            return absint($toolRef);
        }

        // Otherwise, look up by slug
        $tool = $this->toolRepository->findBySlug($toolRef);

        if (is_wp_error($tool)) {
            return null;
        }

        return $tool->ID;
    }
}
