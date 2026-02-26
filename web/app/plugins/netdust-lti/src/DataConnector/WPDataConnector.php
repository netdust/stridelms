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
use NetdustLTI\Repositories\PlatformRepository;

/**
 * WordPress DataConnector for celtic/lti library.
 *
 * Implements the DataConnector interface to store LTI data in WordPress.
 * Uses NTDST Data Manager via PlatformRepository for platforms.
 * Uses WordPress transients for nonces and access tokens.
 * Uses post meta on platforms for contexts.
 */
final class WPDataConnector extends DataConnector
{
    private PlatformRepository $platformRepo;

    public function __construct(?PlatformRepository $platformRepo = null)
    {
        $this->platformRepo = $platformRepo ?? new PlatformRepository();
        parent::__construct(null, 'lti_');
    }

    // ========================================================================
    // Platform methods (using PlatformRepository via Data Manager)
    // ========================================================================

    /**
     * Load platform object.
     *
     * @param Platform $platform Platform object
     * @return bool True if the platform object was successfully loaded
     */
    public function loadPlatform(Platform $platform): bool
    {
        $post = null;

        if ($platform->getRecordId()) {
            $result = $this->platformRepo->find($platform->getRecordId());
            if (!is_wp_error($result)) {
                $post = $result;
            }
        } elseif ($platform->platformId && $platform->clientId) {
            $result = $this->platformRepo->findByIssuerAndClient(
                $platform->platformId,
                $platform->clientId
            );
            if (!is_wp_error($result)) {
                $post = $result;
                // If deployment_id is specified, verify it matches
                if ($platform->deploymentId && isset($post->fields['deployment_id'])) {
                    if ($post->fields['deployment_id'] !== $platform->deploymentId) {
                        return false;
                    }
                }
            }
        }

        if (!$post) {
            return false;
        }

        // Map WP_Post fields to Platform object
        $platform->setRecordId($post->ID);
        $platform->name = $post->post_title;
        $platform->platformId = $post->fields['platform_id'] ?? '';
        $platform->clientId = $post->fields['client_id'] ?? '';
        $platform->deploymentId = $post->fields['deployment_id'] ?? '';
        $platform->authenticationUrl = $post->fields['auth_endpoint'] ?? '';
        $platform->accessTokenUrl = $post->fields['token_endpoint'] ?? '';
        $platform->jku = $post->fields['jwks_endpoint'] ?? '';
        $platform->enabled = (bool) ($post->fields['enabled'] ?? true);
        $platform->created = strtotime($post->post_date_gmt);
        $platform->updated = strtotime($post->post_modified_gmt);

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
            'enabled' => $platform->enabled,
        ];

        if ($platform->getRecordId()) {
            $result = $this->platformRepo->update($platform->getRecordId(), $data);
            $this->fixPlatformSettings($platform, false);
            return !is_wp_error($result);
        }

        $result = $this->platformRepo->create($data);

        if (is_wp_error($result)) {
            $this->fixPlatformSettings($platform, false);
            return false;
        }

        $platform->setRecordId($result);
        $platform->created = time();
        $platform->updated = time();

        $this->fixPlatformSettings($platform, false);
        return true;
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

        // Clean up transients for nonces and access tokens
        $this->deleteAllNoncesForPlatform($platformId);
        $this->deleteAccessTokenForPlatform($platformId);

        $result = $this->platformRepo->delete($platformId);

        if (!is_wp_error($result)) {
            $platform->initialize();
            return true;
        }

        return false;
    }

    /**
     * Load platform objects.
     *
     * @return Platform[] Array of all defined Platform objects
     */
    public function getPlatforms(): array
    {
        $platforms = [];
        $allPlatforms = $this->platformRepo->all();

        foreach ($allPlatforms as $platformData) {
            $platform = Platform::fromRecordId((int) $platformData['ID'], $this);
            $platforms[] = $platform;
        }

        return $platforms;
    }

    /**
     * Delete all nonces for a platform (cleanup helper).
     */
    private function deleteAllNoncesForPlatform(int $platformId): void
    {
        // Transients are automatically cleaned up by WordPress
        // We can't easily enumerate all nonces for a platform with transients
        // This is acceptable as they expire naturally
    }

    /**
     * Delete access token for a platform.
     */
    private function deleteAccessTokenForPlatform(int $platformId): void
    {
        delete_transient("lti_token_{$platformId}");
    }

    // ========================================================================
    // Nonce methods (using WordPress transients for auto-expiring storage)
    // ========================================================================

    /**
     * Generate transient key for a nonce.
     */
    private function getNonceTransientKey(int $platformId, string $nonce): string
    {
        return 'lti_nonce_' . $platformId . '_' . md5($nonce);
    }

    /**
     * Load nonce object.
     *
     * @param PlatformNonce $nonce Nonce object
     * @return bool True if the nonce object was successfully loaded (already exists/used)
     */
    public function loadPlatformNonce(PlatformNonce $nonce): bool
    {
        $platformId = $nonce->getPlatform()->getRecordId();

        if (!$platformId) {
            return false;
        }

        $key = $this->getNonceTransientKey($platformId, $nonce->getValue());
        $exists = get_transient($key);

        // Return true if nonce exists (already used), false if not found (safe to use)
        return $exists !== false;
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

        $key = $this->getNonceTransientKey($platformId, $nonce->getValue());
        $ttl = max(0, $nonce->expires - time());

        // Store nonce with expiration - transients auto-expire
        return set_transient($key, '1', $ttl);
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

        $key = $this->getNonceTransientKey($platformId, $nonce->getValue());
        return delete_transient($key);
    }

    // ========================================================================
    // Access Token methods (using WordPress transients for auto-expiring storage)
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

        $data = get_transient("lti_token_{$platformId}");

        if (!$data || !is_array($data)) {
            return false;
        }

        $accessToken->token = $data['token'];
        $accessToken->expires = (int) $data['expires'];
        $accessToken->scopes = $data['scopes'] ?? [];
        $accessToken->created = (int) ($data['created'] ?? time());
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

        $now = time();
        $ttl = max(0, $accessToken->expires - $now);

        $data = [
            'token' => $accessToken->token,
            'expires' => $accessToken->expires,
            'scopes' => $accessToken->scopes,
            'created' => $now,
        ];

        $result = set_transient("lti_token_{$platformId}", $data, $ttl);

        if ($result) {
            $accessToken->created = $now;
            $accessToken->updated = $now;
        }

        return $result;
    }

    // ========================================================================
    // Context methods (using post meta on platform CPT)
    // ========================================================================

    /**
     * Get all contexts for a platform from post meta.
     *
     * @param int $platformId Platform post ID
     * @return array Array of context data arrays
     */
    private function getPlatformContexts(int $platformId): array
    {
        $contexts = get_post_meta($platformId, '_lti_contexts', true);
        return is_array($contexts) ? $contexts : [];
    }

    /**
     * Save all contexts for a platform to post meta.
     *
     * @param int $platformId Platform post ID
     * @param array $contexts Array of context data arrays
     */
    private function savePlatformContexts(int $platformId, array $contexts): void
    {
        update_post_meta($platformId, '_lti_contexts', $contexts);
    }

    /**
     * Generate a unique context record ID from platform and context ID.
     */
    private function generateContextRecordId(int $platformId, string $ltiContextId): int
    {
        // Create a deterministic ID from platform + context
        return abs(crc32("{$platformId}_{$ltiContextId}"));
    }

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

        $contexts = $this->getPlatformContexts($platformId);
        $contextKey = $context->ltiContextId;

        if (!isset($contexts[$contextKey])) {
            return false;
        }

        $data = $contexts[$contextKey];
        $context->setRecordId($this->generateContextRecordId($platformId, $contextKey));
        $context->title = (string) ($data['ld_course_id'] ?? '');
        $context->created = (int) ($data['created'] ?? time());
        $context->updated = (int) ($data['updated'] ?? time());

        $settings = $data['settings'] ?? [];
        if (is_array($settings)) {
            $context->setSettings($settings);
        }

        return true;
    }

    /**
     * Save context object.
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

        $contexts = $this->getPlatformContexts($platformId);
        $contextKey = $context->ltiContextId;
        $now = time();

        if (isset($contexts[$contextKey])) {
            // Update existing context
            $contexts[$contextKey]['settings'] = $context->getSettings();
            $contexts[$contextKey]['updated'] = $now;
        } else {
            // Create new context
            $contexts[$contextKey] = [
                'lti_context_id' => $context->ltiContextId,
                'ld_course_id' => (int) $context->title,
                'settings' => $context->getSettings(),
                'created' => $now,
                'updated' => $now,
            ];
        }

        $this->savePlatformContexts($platformId, $contexts);
        $context->setRecordId($this->generateContextRecordId($platformId, $contextKey));
        $context->updated = $now;

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
        $platformId = $context->getPlatform()->getRecordId();

        if (!$platformId || !$context->ltiContextId) {
            return false;
        }

        $contexts = $this->getPlatformContexts($platformId);
        $contextKey = $context->ltiContextId;

        if (!isset($contexts[$contextKey])) {
            return false;
        }

        unset($contexts[$contextKey]);
        $this->savePlatformContexts($platformId, $contexts);
        $context->initialize();

        return true;
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
     * With transients, WordPress handles cleanup automatically.
     * This method is kept for interface compatibility.
     *
     * @return int Number of nonces deleted (always 0 with transients)
     */
    public function cleanupExpiredNonces(): int
    {
        // Transients auto-expire, no manual cleanup needed
        return 0;
    }

    /**
     * Clean up expired access tokens.
     *
     * With transients, WordPress handles cleanup automatically.
     * This method is kept for interface compatibility.
     *
     * @return int Number of tokens deleted (always 0 with transients)
     */
    public function cleanupExpiredTokens(): int
    {
        // Transients auto-expire, no manual cleanup needed
        return 0;
    }
}
