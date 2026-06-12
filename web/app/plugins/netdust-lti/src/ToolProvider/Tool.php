<?php
declare(strict_types=1);

namespace NetdustLTI\ToolProvider;

use ceLTIc\LTI\Tool as BaseTool;
use ceLTIc\LTI\DataConnector\DataConnector;
use ceLTIc\LTI\Profile\Item;
use ceLTIc\LTI\Profile\Message;
use ceLTIc\LTI\Profile\ResourceHandler;
use NetdustLTI\Shared\Domain\LtiClaims;
use NetdustLTI\ToolProvider\Services\UserProvisioner;
use NetdustLTI\ToolProvider\Services\CourseEnroller;

final class Tool extends BaseTool
{
    private ?LtiClaims $claims = null;
    private ?int $ltiTargetPostId = null;
    private ?string $ltiNavToken = null;

    public function __construct(?DataConnector $dataConnector = null)
    {
        parent::__construct($dataConnector);

        // Allow 2 minutes for the OIDC round-trip (default 10s is too tight)
        static::$stateLife = 120;

        $homeUrl = home_url();

        // LTI 1.3 Configuration
        $this->signatureMethod = 'RS256';
        $this->jku = $homeUrl . '/lti/jwks';
        $this->kid = get_option('netdust_lti_kid', 'netdust-lti-key-1');
        $this->rsaKey = get_option('netdust_lti_private_key');

        // Tool identity for Dynamic Registration
        $this->baseUrl = $homeUrl;
        $this->product->name = get_bloginfo('name') ?: 'VAD Vormingen';
        $this->product->description = 'LearnDash course delivery via LTI 1.3';

        // Required AGS scopes
        $this->requiredScopes = [
            'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem',
            'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem.readonly',
            'https://purl.imsglobal.org/spec/lti-ags/scope/score',
            'https://purl.imsglobal.org/spec/lti-ags/scope/result.readonly',
        ];

        // Resource handler with supported message types
        $launchMessage = new Message('LtiResourceLinkRequest', '/lti/launch', [
            'User.id', 'Person.name.full', 'Person.name.given', 'Person.name.family', 'Person.email.primary',
        ]);
        $deepLinkMessage = new Message('LtiDeepLinkingRequest', '/lti/deep-link', [
            'User.id', 'Person.name.full', 'Person.email.primary',
        ]);

        $this->resourceHandlers[] = new ResourceHandler(
            new Item('lti', 'LearnDash LTI', null),
            '',
            [$launchMessage],
            [$deepLinkMessage]
        );
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

        // Flag user for LTI iframe support (allows X-Frame-Options removal + SameSite=None cookies)
        update_user_meta($user->ID, '_lti_iframe_until', time() + 8 * HOUR_IN_SECONDS);

        // Generate navigation token for cookie-free iframe browsing.
        // Links within the iframe carry this token so the user stays authenticated
        // without relying on third-party cookies.
        $this->ltiNavToken = wp_generate_password(32, false);
        set_transient('lti_nav_' . $this->ltiNavToken, [
            'user_id'     => $user->ID,
            'course_id'   => $courseId,
            'platform_id' => $this->platform->getRecordId(),
            'created_at'  => time(),
            'lti_version' => $this->messageParameters['lti_version'] ?? 'unknown',
        ], 8 * HOUR_IN_SECONDS);

        ntdst_log('lti')->info('Nav token generated', [
            'token' => substr($this->ltiNavToken, 0, 8) . '...',
            'user_id' => $user->ID,
        ]);

        // Set current user for this request (token-based auth; no cookie needed)
        wp_set_current_user($user->ID);

        ntdst_log('lti')->info('Launch successful', [
            'user_id' => $user->ID,
            'course_id' => $courseId,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);

        // Resolve target post for inline rendering (avoid redirect which exits)
        if ($courseId) {
            $firstLessonId = $this->getFirstLessonId($courseId);
            $this->ltiTargetPostId = $firstLessonId ?: $courseId;

            ntdst_log('lti')->info('Launch target', [
                'course_id' => $courseId,
                'first_lesson_id' => $firstLessonId,
                'target_post_id' => $this->ltiTargetPostId,
            ]);
        } else {
            // No course — fall back to redirect
            $this->redirectUrl = home_url('/mijn-account/');
        }
    }

    protected function onContentItem(): void
    {
        ntdst_log('lti')->info('Deep linking request', [
            'platform' => $this->platform->platformId ?? 'unknown',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);

        // Store return info in a transient with a random token.
        // Sessions don't work reliably in cross-origin iframes (third-party cookie blocking),
        // so we pass the token via URL parameter instead.
        $token = wp_generate_password(32, false);
        $deepLinkData = [
            'platform_id' => $this->platform->getRecordId(),
            'return_url' => $this->returnUrl,
            'data' => $this->messageParameters['https://purl.imsglobal.org/spec/lti-dl/claim/data'] ?? null,
        ];
        set_transient('lti_dl_' . $token, $deepLinkData, 15 * MINUTE_IN_SECONDS);

        // Redirect to frontend course picker with token
        $this->redirectUrl = home_url('/lti/deep-link-picker?dl_token=' . $token);
    }

    protected function onRegistration(): void
    {
        // Let the ceLTIc library handle the full Dynamic Registration flow:
        // 1. Fetch platform's OpenID configuration
        // 2. Build tool configuration
        // 3. Register with the platform
        // 4. Save platform locally
        // 5. Show response page (closes registration in the platform)
        parent::onRegistration();
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

    /**
     * Get the post ID of the first lesson in a course.
     */
    private function getFirstLessonId(int $courseId): ?int
    {
        if (!function_exists('learndash_course_get_steps_by_type')) {
            return null;
        }

        $lessonIds = learndash_course_get_steps_by_type($courseId, 'sfwd-lessons');

        if (!empty($lessonIds)) {
            return (int) reset($lessonIds);
        }

        return null;
    }

    public function getLtiTargetPostId(): ?int
    {
        return $this->ltiTargetPostId;
    }

    public function getLtiNavToken(): ?string
    {
        return $this->ltiNavToken;
    }

    public function getClaims(): ?LtiClaims
    {
        return $this->claims;
    }

    public function getErrorOutput(): ?string
    {
        return $this->errorOutput;
    }
}
