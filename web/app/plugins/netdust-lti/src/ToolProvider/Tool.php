<?php
declare(strict_types=1);

namespace NetdustLTI\ToolProvider;

use ceLTIc\LTI\Tool as BaseTool;
use ceLTIc\LTI\DataConnector\DataConnector;
use NetdustLTI\Shared\Domain\LtiClaims;
use NetdustLTI\ToolProvider\Services\UserProvisioner;
use NetdustLTI\ToolProvider\Services\CourseEnroller;

final class Tool extends BaseTool
{
    private ?LtiClaims $claims = null;

    public function __construct(?DataConnector $dataConnector = null)
    {
        parent::__construct($dataConnector);

        // LTI 1.3 Configuration
        $this->signatureMethod = 'RS256';
        $this->jku = home_url('/lti/jwks');
        $this->kid = get_option('netdust_lti_kid', 'netdust-lti-key-1');
        $this->rsaKey = get_option('netdust_lti_private_key');
    }

    protected function onLaunch(): void
    {
        ntdst_log('lti')->info('Launch received', [
            'platform' => $this->platform->platformId ?? 'unknown',
            'user' => $this->userResult->ltiUserId ?? 'unknown',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);

        // Extract custom params from messageParameters (ceLTIc flattens with custom_ prefix)
        $customParams = [];
        foreach ($this->messageParameters as $key => $value) {
            if (str_starts_with($key, 'custom_')) {
                $paramName = substr($key, 7); // Remove 'custom_' prefix
                $customParams[$paramName] = $value;
            }
        }

        // Extract AGS endpoint from messageParameters
        $agsEndpoint = [];
        if (isset($this->messageParameters['custom_lineitem_url'])) {
            $agsEndpoint['lineitem'] = $this->messageParameters['custom_lineitem_url'];
        }

        // Parse claims with extracted data
        $this->claims = LtiClaims::fromLtiToolWithParams($this, $customParams, $agsEndpoint);
        $this->claims = apply_filters('netdust_lti_claims', $this->claims);

        // Provision user
        $provisioner = ntdst_get(UserProvisioner::class);
        $user = $provisioner->provision($this->claims, $this->platform->getRecordId());

        if (is_wp_error($user)) {
            ntdst_log('lti')->error('User provisioning failed', [
                'error' => $user->get_error_message(),
            ]);
            $this->reason = $user->get_error_message();
            $this->ok = false;
            return;
        }

        // Enroll in course if specified
        $courseId = $this->claims->getCourseId();
        if ($courseId) {
            $enroller = ntdst_get(CourseEnroller::class);
            $enroller->enroll($user, $courseId, $this->claims, $this->platform->getRecordId());
        }

        // Clear any existing login first, then log in as LTI user
        wp_logout();
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, false);

        ntdst_log('lti')->info('Launch successful', [
            'user_id' => $user->ID,
            'course_id' => $courseId,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);

        // Redirect to course or account page
        if ($courseId) {
            $this->redirectUrl = get_permalink($courseId);
        } else {
            // Fall back to account page (mijn-account) which exists on stride
            $this->redirectUrl = home_url('/mijn-account/');
        }
    }

    protected function onContentItem(): void
    {
        ntdst_log('lti')->info('Deep linking request', [
            'platform' => $this->platform->platformId ?? 'unknown',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);

        // Store return info in session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION['lti_deep_link'] = [
            'platform_id' => $this->platform->getRecordId(),
            'return_url' => $this->returnUrl,
            'data' => $this->messageParameters['https://purl.imsglobal.org/spec/lti-dl/claim/data'] ?? null,
        ];

        // Redirect to frontend course picker (not admin - LTI users may not have admin access)
        $this->redirectUrl = home_url('/lti/deep-link-picker');
    }

    protected function onError(): void
    {
        ntdst_log('lti')->error('Launch error', [
            'reason' => $this->reason,
            'platform' => $this->platform?->platformId ?? 'unknown',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);

        $this->errorOutput = sprintf(
            '<div class="lti-error"><h1>LTI Launch Error</h1><p>%s</p></div>',
            esc_html($this->reason)
        );
    }

    public function getClaims(): ?LtiClaims
    {
        return $this->claims;
    }
}
