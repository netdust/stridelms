<?php
declare(strict_types=1);

namespace NetdustLTI\LTI;

use ceLTIc\LTI\Tool;
use ceLTIc\LTI\DataConnector\DataConnector;
use NetdustLTI\Domain\LtiClaims;
use NetdustLTI\Services\UserProvisioner;
use NetdustLTI\Services\CourseEnroller;

final class NetdustLTITool extends Tool
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
        ]);

        // Parse claims
        $this->claims = LtiClaims::fromLtiTool($this);

        // Provision user
        $provisioner = ntdst_get(UserProvisioner::class);
        $user = $provisioner->provision($this->claims);

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

        // Log user in
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, false);

        ntdst_log('lti')->info('Launch successful', [
            'user_id' => $user->ID,
            'course_id' => $courseId,
        ]);

        // Redirect to course or dashboard
        if ($courseId) {
            $this->redirectUrl = get_permalink($courseId);
        } else {
            $this->redirectUrl = home_url('/dashboard/');
        }
    }

    protected function onContentItem(): void
    {
        ntdst_log('lti')->info('Deep linking request', [
            'platform' => $this->platform->platformId ?? 'unknown',
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

        // Redirect to course picker
        $this->redirectUrl = admin_url('admin.php?page=netdust-lti-deep-link');
    }

    protected function onError(): void
    {
        ntdst_log('lti')->error('Launch error', [
            'reason' => $this->reason,
            'platform' => $this->platform?->platformId ?? 'unknown',
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
