<?php
declare(strict_types=1);

namespace NetdustLTI\Platform;

use ceLTIc\LTI\Platform;
use ceLTIc\LTI\DataConnector\DataConnector;
use NetdustLTI\ToolProvider\WPDataConnector;

/**
 * WordPress-specific Platform wrapper for LTI 1.3.
 *
 * Extends celtic/lti Platform class to:
 * - Load RSA key from WordPress options
 * - Configure token endpoint URL
 * - Use WPDataConnector for persistence
 */
final class WPPlatform extends Platform
{
    public const OPTION_KEY_PRIVATE_KEY = 'netdust_lti_private_key';
    public const OPTION_KEY_KID = 'netdust_lti_kid';
    public const DEFAULT_KID = 'netdust-lti-key-1';

    public function __construct(?DataConnector $dataConnector = null)
    {
        parent::__construct($dataConnector ?? ntdst_get(WPDataConnector::class));

        // Load platform's RSA private key for signing access tokens
        $this->rsaKey = get_option(self::OPTION_KEY_PRIVATE_KEY);

        if (empty($this->rsaKey)) {
            ntdst_log('lti')->warning('Private RSA key not configured in WordPress options');
        }

        $this->kid = get_option(self::OPTION_KEY_KID, self::DEFAULT_KID);
        $this->signatureMethod = 'RS256';

        // Configure token endpoint URL
        $this->accessTokenUrl = home_url('/lti/platform/token');
    }

    /**
     * Handle errors from the library.
     */
    protected function onError(): void
    {
        $reason = $this->reason ?? 'Unknown error';
        ntdst_log('lti')->error('WPPlatform error', ['reason' => $reason]);
    }
}
