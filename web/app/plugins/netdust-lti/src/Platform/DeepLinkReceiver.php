<?php
declare(strict_types=1);

namespace NetdustLTI\Platform;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\JWK;
use WP_Error;

use function sanitize_text_field;
use function wp_die;
use function esc_html;
use function esc_url;
use function home_url;
use function admin_url;
use function get_option;
use function wp_redirect;

/**
 * Receives and processes Deep Link responses from LTI Tools.
 *
 * When the Tool completes course selection, it POSTs back a JWT containing
 * the selected content items. This class:
 * 1. Verifies the JWT signature
 * 2. Extracts the content items (resource links)
 * 3. Displays the results or stores the resource link
 */
final class DeepLinkReceiver
{
    public function __construct(
        private readonly ToolRepository $toolRepository
    ) {}

    /**
     * Handle the deep link response from the Tool.
     */
    public function handleReturn(): void
    {
        // The Tool POSTs the JWT - ceLTIc library uses 'JWT' for Tool responses
        // Some implementations may use 'id_token', so check both
        $idToken = sanitize_text_field($_POST['JWT'] ?? $_POST['id_token'] ?? '');

        if (empty($idToken)) {
            wp_die(
                'Missing JWT in deep link response',
                'Deep Link Error',
                ['response' => 400]
            );
        }

        // Decode JWT header to get kid
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            wp_die('Invalid JWT format', 'Deep Link Error', ['response' => 400]);
        }

        $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        if (!$header || !$payload) {
            wp_die('Could not decode JWT', 'Deep Link Error', ['response' => 400]);
        }

        // Get the issuer (Tool) from the payload
        $issuer = $payload['iss'] ?? '';

        // Find the tool by its issuer URL (which should match the Tool's home_url)
        $tool = $this->findToolByIssuer($issuer);

        if (is_wp_error($tool)) {
            wp_die(
                'Could not find Tool configuration for issuer: ' . esc_html($issuer),
                'Deep Link Error',
                ['response' => 400]
            );
        }

        // Verify JWT signature using Tool's public key
        $publicKey = $tool->fields['rsa_key'] ?? '';
        $kid = $header['kid'] ?? '';

        if (empty($publicKey)) {
            // Try to fetch from JWKS endpoint
            $jwksUrl = $tool->fields['jwks_url'] ?? '';

            // Fall back to deriving JWKS URL from launch_url
            // (not from issuer, as issuer may be client_id which is not a URL)
            if (empty($jwksUrl)) {
                $launchUrl = $tool->fields['launch_url'] ?? '';
                if (!empty($launchUrl)) {
                    // Extract base URL from launch_url and append /lti/jwks
                    $parsed = parse_url($launchUrl);
                    if ($parsed && isset($parsed['scheme']) && isset($parsed['host'])) {
                        $baseUrl = $parsed['scheme'] . '://' . $parsed['host'];
                        if (!empty($parsed['port'])) {
                            $baseUrl .= ':' . $parsed['port'];
                        }
                        $jwksUrl = $baseUrl . '/lti/jwks';
                    }
                }
            }

            if (!empty($jwksUrl)) {
                $keySet = $this->fetchJwksKeySet($jwksUrl, $kid);
                if ($keySet) {
                    // Use the full key set for verification
                    try {
                        $decoded = JWT::decode($idToken, $keySet);
                        $payload = (array) $decoded;
                    } catch (\Exception $e) {
                        ntdst_log('lti')->warning('Deep Link JWT verification failed', [
                            'jwt_kid' => $kid,
                            'available_keys' => array_keys($keySet),
                            'error' => $e->getMessage(),
                        ]);

                        wp_die(
                            'JWT verification error: ' . esc_html($e->getMessage()) .
                            ' (JWT kid: ' . esc_html($kid) . ', Available keys: ' . esc_html(implode(', ', array_keys($keySet))) . ')',
                            'Deep Link Error',
                            ['response' => 400]
                        );
                    }
                } else {
                    wp_die(
                        'Could not fetch public keys from JWKS endpoint',
                        'Deep Link Error',
                        ['response' => 500]
                    );
                }
            } else {
                wp_die(
                    'No public key available for Tool verification',
                    'Deep Link Error',
                    ['response' => 500]
                );
            }
        } else {
            // Verify with direct public key
            try {
                $decoded = JWT::decode($idToken, new Key($publicKey, 'RS256'));
                $payload = (array) $decoded;
            } catch (\Exception $e) {
                wp_die(
                    'JWT verification error: ' . esc_html($e->getMessage()),
                    'Deep Link Error',
                    ['response' => 400]
                );
            }
        }

        // Extract content items (convert stdClass objects to arrays for consistent access)
        $contentItemsRaw = $payload['https://purl.imsglobal.org/spec/lti-dl/claim/content_items'] ?? [];
        $contentItems = json_decode(json_encode($contentItemsRaw), true) ?? [];

        if (empty($contentItems)) {
            wp_die(
                'No content items in deep link response',
                'Deep Link Error',
                ['response' => 400]
            );
        }

        // Save the content items as LTI Resources
        $savedResources = $this->saveResources($contentItems, $tool);

        // Display the results
        $this->displayResults($contentItems, $tool, $savedResources);
    }

    /**
     * Find a tool by its issuer (which may be client_id for Tool responses).
     */
    private function findToolByIssuer(string $issuer): \WP_Post|WP_Error
    {
        // ceLTIc library sends client_id as issuer for Tool responses
        // We need to find the tool that matches this client_id or issuer URL
        $model = ntdst_data()->get('lti_tool');

        // Query tools and check their configuration
        $tools = $model->get();

        foreach ($tools as $toolData) {
            $toolPost = $model->find($toolData['ID'] ?? $toolData['id'] ?? 0);
            if (!$toolPost || is_wp_error($toolPost)) {
                continue;
            }

            // Check if client_id matches the issuer (ceLTIc sends client_id as iss)
            $clientId = $toolPost->fields['client_id'] ?? '';
            if ($clientId === $issuer) {
                return $toolPost;
            }

            // Check if the launch_url domain matches the issuer
            $launchUrl = $toolPost->fields['launch_url'] ?? '';
            $launchHost = parse_url($launchUrl, PHP_URL_HOST);
            $issuerHost = parse_url($issuer, PHP_URL_HOST);

            if ($launchHost && $issuerHost && $launchHost === $issuerHost) {
                return $toolPost;
            }

            // Also check if issuer field matches directly
            $toolIssuer = $toolPost->fields['issuer'] ?? '';
            if ($toolIssuer === $issuer) {
                return $toolPost;
            }
        }

        return new WP_Error('tool_not_found', 'Tool not found for issuer: ' . $issuer);
    }

    /**
     * Fetch JWKS key set from endpoint.
     *
     * @param string $jwksUrl The JWKS endpoint URL
     * @param string $expectedKid The kid from the JWT header (for debugging)
     * @return array<string, Key>|null Key array for JWT::decode
     */
    private function fetchJwksKeySet(string $jwksUrl, string $expectedKid = ''): ?array
    {
        // Ensure trailing slash to avoid redirect issues
        $jwksUrl = rtrim($jwksUrl, '/') . '/';

        // Handle ddev cross-container networking
        $requestUrl = $jwksUrl;
        $requestArgs = [
            'timeout' => 10,
            'sslverify' => false,
        ];

        // Check if this is a ddev site that needs internal DNS resolution
        $parsed = parse_url($jwksUrl);
        $host = $parsed['host'] ?? '';
        if (str_ends_with($host, '.ddev.site')) {
            // Extract project name (e.g., "stride" from "stride.ddev.site")
            $projectName = str_replace('.ddev.site', '', $host);
            $containerHost = "ddev-{$projectName}-web";

            // Try to resolve the internal container DNS
            $containerIp = gethostbyname($containerHost);
            if ($containerIp !== $containerHost) {
                // Build URL with internal IP but keep original path
                $scheme = $parsed['scheme'] ?? 'https';
                $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
                $path = $parsed['path'] ?? '/';
                $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';

                $requestUrl = "{$scheme}://{$containerIp}{$port}{$path}{$query}";

                // Add Host header for proper virtual host routing
                $requestArgs['headers'] = ['Host' => $host];
            }
        }

        $response = wp_remote_get($requestUrl, $requestArgs);

        if (is_wp_error($response)) {
            ntdst_log('lti')->warning('JWKS fetch failed', [
                'url' => $jwksUrl,
                'error' => $response->get_error_message(),
            ]);
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $jwks = json_decode($body, true);

        if (!isset($jwks['keys']) || !is_array($jwks['keys'])) {
            ntdst_log('lti')->warning('Invalid JWKS structure', ['url' => $jwksUrl]);
            return null;
        }

        try {
            $keySet = JWK::parseKeySet($jwks, 'RS256');
            return $keySet;
        } catch (\Exception $e) {
            ntdst_log('lti')->warning('JWKS parseKeySet failed', [
                'url' => $jwksUrl,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Save content items as LTI Resource posts.
     *
     * @param array $contentItems The content items from deep link response
     * @param \WP_Post $tool The Tool that provided these resources
     * @return array Array of saved resource IDs
     */
    private function saveResources(array $contentItems, \WP_Post $tool): array
    {
        $model = ntdst_data()->get('lti_resource');
        $savedIds = [];

        foreach ($contentItems as $item) {
            // Title can be in different places depending on LTI version/library
            $title = $item['title'] ?? $item['text'] ?? 'Untitled Resource';
            $launchUrl = $item['url'] ?? '';
            $custom = $item['custom'] ?? [];
            $courseId = $custom['ld_course_id'] ?? '';
            $description = $item['text'] ?? '';

            // Check if this resource already exists (by tool + course_id)
            if (!empty($courseId)) {
                $existing = $model->where('tool_id', $tool->ID)
                    ->where('course_id', $courseId)
                    ->first();

                if ($existing) {
                    // Update existing resource - use 'title' not 'post_title' (Data Manager maps it)
                    $model->update($existing->ID, [
                        'title' => $title,
                        'launch_url' => $launchUrl,
                        'description' => $description,
                        'custom_params' => json_encode($custom),
                    ]);
                    $savedIds[] = $existing->ID;
                    continue;
                }
            }

            // Create new resource - use 'title' not 'post_title' (Data Manager maps it)
            $result = $model->create([
                'title' => $title,
                'post_status' => 'publish',
                'tool_id' => $tool->ID,
                'launch_url' => $launchUrl,
                'course_id' => $courseId,
                'description' => $description,
                'custom_params' => json_encode($custom),
            ]);

            if (!is_wp_error($result)) {
                $savedIds[] = $result->ID;
            } else {
                ntdst_log('lti')->warning('Failed to save LTI resource', [
                    'title' => $title,
                    'error' => $result->get_error_message(),
                ]);
            }
        }

        return $savedIds;
    }

    /**
     * Display the deep link results.
     *
     * @param array $contentItems The content items
     * @param \WP_Post $tool The Tool
     * @param array $savedResources IDs of saved resources
     */
    private function displayResults(array $contentItems, \WP_Post $tool, array $savedResources = []): void
    {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title><?php esc_html_e('Deep Link Complete', 'netdust-lti'); ?></title>
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    padding: 40px;
                    max-width: 800px;
                    margin: 0 auto;
                    background: #f0f0f1;
                }
                .card {
                    background: white;
                    padding: 30px;
                    border-radius: 8px;
                    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                }
                h1 {
                    color: #1d2327;
                    font-size: 24px;
                    margin-top: 0;
                }
                .success {
                    color: #00a32a;
                    margin-bottom: 20px;
                }
                .content-item {
                    background: #f6f7f7;
                    padding: 20px;
                    border-radius: 4px;
                    margin-bottom: 15px;
                    border-left: 4px solid #2271b1;
                }
                .content-item h3 {
                    margin: 0 0 10px 0;
                    color: #1d2327;
                }
                .content-item p {
                    margin: 5px 0;
                    color: #50575e;
                }
                .content-item .url {
                    font-size: 12px;
                    color: #787c82;
                    word-break: break-all;
                }
                .actions {
                    margin-top: 30px;
                }
                .button {
                    display: inline-block;
                    padding: 10px 20px;
                    background: #2271b1;
                    color: white;
                    text-decoration: none;
                    border-radius: 4px;
                    margin-right: 10px;
                }
                .button:hover {
                    background: #135e96;
                }
                .button-secondary {
                    background: #f0f0f1;
                    color: #2271b1;
                    border: 1px solid #2271b1;
                }
                .button-secondary:hover {
                    background: #e8e8e8;
                }
            </style>
        </head>
        <body>
            <div class="card">
                <h1><?php esc_html_e('Course Selection Complete', 'netdust-lti'); ?></h1>
                <p class="success">
                    &#10003;
                    <?php
                    printf(
                        esc_html(_n(
                            '%d resource has been saved:',
                            '%d resources have been saved:',
                            count($savedResources),
                            'netdust-lti'
                        )),
                        count($savedResources)
                    );
                    ?>
                </p>

                <?php foreach ($contentItems as $index => $item): ?>
                    <div class="content-item">
                        <h3><?php echo esc_html($item['title'] ?? 'Untitled'); ?></h3>
                        <?php if (!empty($item['text'])): ?>
                            <p><?php echo esc_html($item['text']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($item['url'])): ?>
                            <p class="url">
                                <strong><?php esc_html_e('Launch URL:', 'netdust-lti'); ?></strong>
                                <?php echo esc_html($item['url']); ?>
                            </p>
                        <?php endif; ?>
                        <?php if (!empty($item['custom'])): ?>
                            <p>
                                <strong><?php esc_html_e('Course ID:', 'netdust-lti'); ?></strong>
                                <?php echo esc_html($item['custom']['ld_course_id'] ?? 'N/A'); ?>
                            </p>
                        <?php endif; ?>
                        <?php if (isset($savedResources[$index])): ?>
                            <p>
                                <a href="<?php echo esc_url(home_url('/lti/launch/' . $savedResources[$index])); ?>" class="button" target="_blank">
                                    <?php esc_html_e('Launch Course', 'netdust-lti'); ?> →
                                </a>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <div class="actions">
                    <a href="<?php echo esc_url(admin_url('edit.php?post_type=lti_resource')); ?>" class="button">
                        <?php esc_html_e('View All Resources', 'netdust-lti'); ?>
                    </a>
                    <a href="<?php echo esc_url(admin_url('edit.php?post_type=lti_tool')); ?>" class="button button-secondary">
                        <?php esc_html_e('Back to Tools', 'netdust-lti'); ?>
                    </a>
                </div>

                <p style="margin-top: 30px; color: #787c82; font-size: 13px;">
                    <?php
                    printf(
                        esc_html__('Tool: %s', 'netdust-lti'),
                        esc_html($tool->post_title)
                    );
                    ?>
                </p>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}
