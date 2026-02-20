<?php
declare(strict_types=1);

namespace NetdustLTI\DataConnector;

use ceLTIc\LTI\DataConnector\DataConnector;
use ceLTIc\LTI\Platform;
use ceLTIc\LTI\PlatformNonce;
use ceLTIc\LTI\AccessToken;
use ceLTIc\LTI\Context;
use ceLTIc\LTI\ResourceLink;
use ceLTIc\LTI\UserResult;

/**
 * WordPress DataConnector for celtic/lti library.
 *
 * Implements the DataConnector interface to store LTI data in WordPress
 * custom tables using wpdb.
 */
final class WPDataConnector extends DataConnector
{
    private \wpdb $wpdb;
    private string $prefix;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->prefix = $wpdb->prefix . 'netdust_lti_';
        parent::__construct(null, $this->prefix);
    }

    // ========================================================================
    // Platform methods
    // ========================================================================

    /**
     * Load platform object.
     *
     * @param Platform $platform Platform object
     * @return bool True if the platform object was successfully loaded
     */
    public function loadPlatform(Platform $platform): bool
    {
        $sql = "SELECT * FROM {$this->prefix}platforms WHERE ";

        if ($platform->getRecordId()) {
            $sql .= $this->wpdb->prepare("id = %d", $platform->getRecordId());
        } elseif ($platform->platformId && $platform->clientId) {
            // Include deployment_id in validation when provided (security requirement)
            if ($platform->deploymentId) {
                $sql .= $this->wpdb->prepare(
                    "platform_id = %s AND client_id = %s AND deployment_id = %s",
                    $platform->platformId,
                    $platform->clientId,
                    $platform->deploymentId
                );
            } else {
                $sql .= $this->wpdb->prepare(
                    "platform_id = %s AND client_id = %s",
                    $platform->platformId,
                    $platform->clientId
                );
            }
        } elseif ($platform->getKey()) {
            // Support loading by consumer key (platform_id)
            $sql .= $this->wpdb->prepare("platform_id = %s", $platform->getKey());
        } else {
            return false;
        }

        $row = $this->wpdb->get_row($sql, ARRAY_A);

        if (!$row) {
            return false;
        }

        $platform->setRecordId((int) $row['id']);
        $platform->name = $row['name'];
        $platform->platformId = $row['platform_id'];
        $platform->clientId = $row['client_id'];
        $platform->deploymentId = $row['deployment_id'];
        $platform->authenticationUrl = $row['auth_endpoint'];
        $platform->accessTokenUrl = $row['token_endpoint'];
        $platform->jku = $row['jwks_endpoint'];
        $platform->enabled = (bool) $row['enabled'];
        $platform->created = strtotime($row['created_at']);
        $platform->updated = strtotime($row['updated_at']);

        // Fix platform settings after loading
        $this->fixPlatformSettings($platform, false);

        return true;
    }

    /**
     * Save platform object.
     *
     * @param Platform $platform Platform object
     * @return bool True if the platform object was successfully saved
     */
    public function savePlatform(Platform $platform): bool
    {
        // Fix platform settings before saving
        $this->fixPlatformSettings($platform, true);

        $data = [
            'name' => $platform->name,
            'platform_id' => $platform->platformId,
            'client_id' => $platform->clientId,
            'deployment_id' => $platform->deploymentId,
            'auth_endpoint' => $platform->authenticationUrl,
            'token_endpoint' => $platform->accessTokenUrl,
            'jwks_endpoint' => $platform->jku,
            'enabled' => $platform->enabled ? 1 : 0,
            'updated_at' => current_time('mysql'),
        ];

        if ($platform->getRecordId()) {
            $result = $this->wpdb->update(
                $this->prefix . 'platforms',
                $data,
                ['id' => $platform->getRecordId()]
            );

            // Restore platform settings
            $this->fixPlatformSettings($platform, false);

            return $result !== false;
        }

        $data['created_at'] = current_time('mysql');
        $result = $this->wpdb->insert($this->prefix . 'platforms', $data);

        if ($result) {
            $platform->setRecordId((int) $this->wpdb->insert_id);
            $platform->created = time();
        }

        $platform->updated = time();

        // Restore platform settings
        $this->fixPlatformSettings($platform, false);

        return $result !== false;
    }

    /**
     * Delete platform object.
     *
     * @param Platform $platform Platform object
     * @return bool True if the platform object was successfully deleted
     */
    public function deletePlatform(Platform $platform): bool
    {
        $platformId = $platform->getRecordId();

        if (!$platformId) {
            return false;
        }

        // Cascade delete related data
        $this->wpdb->delete($this->prefix . 'access_tokens', ['platform_id' => $platformId]);
        $this->wpdb->delete($this->prefix . 'nonces', ['platform_id' => $platformId]);
        $this->wpdb->delete($this->prefix . 'contexts', ['platform_id' => $platformId]);

        $result = $this->wpdb->delete(
            $this->prefix . 'platforms',
            ['id' => $platformId]
        );

        if ($result !== false) {
            $platform->initialize();
        }

        return $result !== false;
    }

    /**
     * Load platform objects.
     *
     * @return Platform[] Array of all defined Platform objects
     */
    public function getPlatforms(): array
    {
        $platforms = [];
        $rows = $this->wpdb->get_results(
            "SELECT id FROM {$this->prefix}platforms ORDER BY name",
            ARRAY_A
        );

        foreach ($rows as $row) {
            $platform = Platform::fromRecordId((int) $row['id'], $this);
            $platforms[] = $platform;
        }

        return $platforms;
    }

    // ========================================================================
    // Nonce methods (required for security)
    // ========================================================================

    /**
     * Load nonce object.
     *
     * @param PlatformNonce $nonce Nonce object
     * @return bool True if the nonce object was successfully loaded (already exists)
     */
    public function loadPlatformNonce(PlatformNonce $nonce): bool
    {
        $platformId = $nonce->getPlatform()->getRecordId();

        if (!$platformId) {
            return false;
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->prefix}nonces
                 WHERE platform_id = %d AND nonce = %s AND expires_at > NOW()",
                $platformId,
                $nonce->getValue()
            ),
            ARRAY_A
        );

        // Return true if nonce exists (already used), false if not found (safe to use)
        return $row !== null;
    }

    /**
     * Save nonce object.
     *
     * @param PlatformNonce $nonce Nonce object
     * @return bool True if the nonce object was successfully saved
     */
    public function savePlatformNonce(PlatformNonce $nonce): bool
    {
        $platformId = $nonce->getPlatform()->getRecordId();

        if (!$platformId) {
            return false;
        }

        $result = $this->wpdb->insert(
            $this->prefix . 'nonces',
            [
                'platform_id' => $platformId,
                'nonce' => $nonce->getValue(),
                'expires_at' => date('Y-m-d H:i:s', $nonce->expires),
            ]
        );

        return $result !== false;
    }

    /**
     * Delete nonce object.
     *
     * @param PlatformNonce $nonce Nonce object
     * @return bool True if the nonce object was successfully deleted
     */
    public function deletePlatformNonce(PlatformNonce $nonce): bool
    {
        $platformId = $nonce->getPlatform()->getRecordId();

        if (!$platformId) {
            return false;
        }

        $result = $this->wpdb->delete(
            $this->prefix . 'nonces',
            [
                'platform_id' => $platformId,
                'nonce' => $nonce->getValue(),
            ]
        );

        return $result !== false;
    }

    // ========================================================================
    // Access Token methods (required for AGS)
    // ========================================================================

    /**
     * Load access token object.
     *
     * @param AccessToken $accessToken Access token object
     * @return bool True if the access token object was successfully loaded
     */
    public function loadAccessToken(AccessToken $accessToken): bool
    {
        $platformId = $accessToken->getPlatform()->getRecordId();

        if (!$platformId) {
            return false;
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->prefix}access_tokens
                 WHERE platform_id = %d AND expires_at > NOW()",
                $platformId
            ),
            ARRAY_A
        );

        if (!$row) {
            return false;
        }

        $accessToken->token = $row['token'];
        $accessToken->expires = strtotime($row['expires_at']);
        $accessToken->scopes = json_decode($row['scopes'] ?? '[]', true);
        $accessToken->created = strtotime($row['created_at']);
        $accessToken->updated = $accessToken->created;

        return true;
    }

    /**
     * Save access token object.
     *
     * @param AccessToken $accessToken Access token object
     * @return bool True if the access token object was successfully saved
     */
    public function saveAccessToken(AccessToken $accessToken): bool
    {
        $platformId = $accessToken->getPlatform()->getRecordId();

        if (!$platformId) {
            return false;
        }

        // Delete existing token for this platform
        $this->wpdb->delete(
            $this->prefix . 'access_tokens',
            ['platform_id' => $platformId]
        );

        $result = $this->wpdb->insert(
            $this->prefix . 'access_tokens',
            [
                'platform_id' => $platformId,
                'token' => $accessToken->token,
                'expires_at' => date('Y-m-d H:i:s', $accessToken->expires),
                'scopes' => json_encode($accessToken->scopes),
                'created_at' => current_time('mysql'),
            ]
        );

        if ($result) {
            $accessToken->created = time();
            $accessToken->updated = time();
        }

        return $result !== false;
    }

    // ========================================================================
    // Context methods (minimal implementation)
    // ========================================================================

    /**
     * Load context object.
     *
     * @param Context $context Context object
     * @return bool True if the context object was successfully loaded
     */
    public function loadContext(Context $context): bool
    {
        $platformId = $context->getPlatform()->getRecordId();

        if (!$platformId || !$context->ltiContextId) {
            return false;
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->prefix}contexts
                 WHERE platform_id = %d AND lti_context_id = %s",
                $platformId,
                $context->ltiContextId
            ),
            ARRAY_A
        );

        if (!$row) {
            return false;
        }

        $context->setRecordId((int) $row['id']);
        // Store LD course ID in title for easy access
        $context->title = (string) $row['ld_course_id'];
        $context->created = strtotime($row['created_at']);
        $context->updated = strtotime($row['updated_at']);

        // Load settings if stored
        $settings = json_decode($row['settings'] ?? '{}', true);
        if (is_array($settings)) {
            $context->setSettings($settings);
        }

        return true;
    }

    /**
     * Save context object.
     *
     * Minimal implementation - contexts are primarily managed by ContextRepository.
     * This method ensures the celtic/lti library can save context settings.
     *
     * @param Context $context Context object
     * @return bool True if the context object was successfully saved
     */
    public function saveContext(Context $context): bool
    {
        $platformId = $context->getPlatform()->getRecordId();

        if (!$platformId || !$context->ltiContextId) {
            return false;
        }

        $context->updated = time();

        // Only update settings for existing contexts
        if ($context->getRecordId()) {
            $result = $this->wpdb->update(
                $this->prefix . 'contexts',
                [
                    'settings' => json_encode($context->getSettings()),
                    'updated_at' => current_time('mysql'),
                ],
                ['id' => $context->getRecordId()]
            );

            return $result !== false;
        }

        // For new contexts, let ContextRepository handle creation
        // This ensures ld_course_id is properly set
        return true;
    }

    /**
     * Delete context object.
     *
     * @param Context $context Context object
     * @return bool True if the Context object was successfully deleted
     */
    public function deleteContext(Context $context): bool
    {
        if (!$context->getRecordId()) {
            return false;
        }

        $result = $this->wpdb->delete(
            $this->prefix . 'contexts',
            ['id' => $context->getRecordId()]
        );

        if ($result !== false) {
            $context->initialize();
        }

        return $result !== false;
    }

    // ========================================================================
    // ResourceLink methods (minimal implementation)
    // ========================================================================

    /**
     * Load resource link object.
     *
     * Minimal implementation - resource links are stored in the contexts table
     * via the resource_link_id column.
     *
     * @param ResourceLink $resourceLink ResourceLink object
     * @return bool True if the resource link object was successfully loaded
     */
    public function loadResourceLink(ResourceLink $resourceLink): bool
    {
        // Resource links are handled via Context in our implementation
        $now = time();
        $resourceLink->created = $now;
        $resourceLink->updated = $now;

        return true;
    }

    /**
     * Save resource link object.
     *
     * @param ResourceLink $resourceLink ResourceLink object
     * @return bool True if the resource link object was successfully saved
     */
    public function saveResourceLink(ResourceLink $resourceLink): bool
    {
        $resourceLink->updated = time();

        return true;
    }

    /**
     * Delete resource link object.
     *
     * @param ResourceLink $resourceLink ResourceLink object
     * @return bool True if the resource link object was successfully deleted
     */
    public function deleteResourceLink(ResourceLink $resourceLink): bool
    {
        $resourceLink->initialize();

        return true;
    }

    // ========================================================================
    // UserResult methods (minimal implementation)
    // ========================================================================

    /**
     * Load user object.
     *
     * Minimal implementation - user mapping is handled by WordPress users.
     *
     * @param UserResult $userResult UserResult object
     * @return bool True if the user object was successfully loaded
     */
    public function loadUserResult(UserResult $userResult): bool
    {
        $now = time();
        $userResult->created = $now;
        $userResult->updated = $now;

        return true;
    }

    /**
     * Save user object.
     *
     * @param UserResult $userResult UserResult object
     * @return bool True if the user object was successfully saved
     */
    public function saveUserResult(UserResult $userResult): bool
    {
        $userResult->updated = time();

        return true;
    }

    /**
     * Delete user object.
     *
     * @param UserResult $userResult UserResult object
     * @return bool True if the user object was successfully deleted
     */
    public function deleteUserResult(UserResult $userResult): bool
    {
        $userResult->initialize();

        return true;
    }

    // ========================================================================
    // Utility methods
    // ========================================================================

    /**
     * Clean up expired nonces.
     *
     * @return int Number of nonces deleted
     */
    public function cleanupExpiredNonces(): int
    {
        return (int) $this->wpdb->query(
            "DELETE FROM {$this->prefix}nonces WHERE expires_at < NOW()"
        );
    }

    /**
     * Clean up expired access tokens.
     *
     * @return int Number of tokens deleted
     */
    public function cleanupExpiredTokens(): int
    {
        return (int) $this->wpdb->query(
            "DELETE FROM {$this->prefix}access_tokens WHERE expires_at < NOW()"
        );
    }
}
