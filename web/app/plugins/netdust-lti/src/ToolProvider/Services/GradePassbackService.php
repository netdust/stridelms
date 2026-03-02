<?php
declare(strict_types=1);

namespace NetdustLTI\ToolProvider\Services;

use ceLTIc\LTI\Outcome;
use ceLTIc\LTI\Platform;
use ceLTIc\LTI\User;
use ceLTIc\LTI\Service\Score;
use NetdustLTI\ToolProvider\Domain\GradePayload;
use NetdustLTI\ToolProvider\WPDataConnector;
use WP_Error;

/**
 * Service for posting grades back to LTI platforms via AGS (Assignment and Grade Services).
 *
 * Accepts a GradePayload value object and submits the score to the originating platform.
 * Two filter hooks allow external code to modify or suppress grade submissions:
 *
 * - `netdust_lti_grade_payload`    — modify the payload before submission
 * - `netdust_lti_should_post_grade` — return false to suppress submission
 */
final class GradePassbackService
{
    /**
     * Post a grade to the LTI platform via AGS.
     *
     * @param GradePayload $payload Immutable value object describing the grade
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function postGrade(GradePayload $payload): bool|WP_Error
    {
        /** @var GradePayload $payload Allow plugins to modify the payload before submission */
        $payload = apply_filters('netdust_lti_grade_payload', $payload);

        /** @var bool $shouldPost Allow plugins to suppress grade submission */
        $shouldPost = apply_filters('netdust_lti_should_post_grade', true, $payload);

        if (!$shouldPost) {
            return true;
        }

        // Get LTI context from user meta
        $context = get_user_meta($payload->userId, '_netdust_lti_context_' . $payload->courseId, true);

        if (!$context) {
            return new WP_Error('no_context', 'No LTI context found for this user/course');
        }

        if (empty($context['line_item_url']) && empty($context['scores_url'])) {
            return new WP_Error('no_ags', 'No AGS endpoint available');
        }

        ntdst_log('lti-grade')->info('Posting score', [
            'user_id' => $payload->userId,
            'course_id' => $payload->courseId,
            'score' => "{$payload->score}/{$payload->maxScore}",
            'platform_id' => $context['platform_id'],
        ]);

        try {
            $dataConnector = ntdst_get(WPDataConnector::class);
            $platform = Platform::fromRecordId($context['platform_id'], $dataConnector);

            if (!$platform) {
                return new WP_Error('platform_not_found', 'Platform not found');
            }

            // Determine the score endpoint
            $scoreEndpoint = $context['scores_url'] ?? $context['line_item_url'];

            // Use the library's Score service
            $scoreService = new Score($platform, $scoreEndpoint);

            // Build outcome (including comment if set)
            $outcome = new Outcome(
                $payload->score,
                $payload->maxScore,
                $payload->activityProgress,
                $payload->gradingProgress,
            );

            if ($payload->comment !== null) {
                $outcome->comment = $payload->comment;
            }

            // Build user object with LTI user ID
            $ltiUser = new User();
            $ltiUser->ltiUserId = $context['lti_user_id'];

            // Submit the score
            $success = $scoreService->submit($outcome, $ltiUser);

            if (!$success) {
                $http = $scoreService->getHttpMessage();
                $errorMessage = $http?->error ?? 'Unknown error';

                ntdst_log('lti-grade')->error('AGS score submission failed', [
                    'error' => $errorMessage,
                    'http_status' => $http?->status ?? 'unknown',
                ]);

                return new WP_Error('ags_error', 'AGS score submission failed: ' . $errorMessage);
            }

            ntdst_log('lti-grade')->info('Score posted successfully');
            return true;

        } catch (\Exception $e) {
            ntdst_log('lti-grade')->error('Exception posting score', [
                'error' => $e->getMessage(),
            ]);
            return new WP_Error('exception', $e->getMessage());
        }
    }
}
