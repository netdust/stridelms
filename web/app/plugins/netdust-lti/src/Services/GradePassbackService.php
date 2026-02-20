<?php
declare(strict_types=1);

namespace NetdustLTI\Services;

use ceLTIc\LTI\Outcome;
use ceLTIc\LTI\Platform;
use ceLTIc\LTI\User;
use ceLTIc\LTI\Service\Score;
use NetdustLTI\DataConnector\WPDataConnector;
use WP_Error;

/**
 * Service for posting grades back to LTI platforms via AGS (Assignment and Grade Services).
 *
 * This service handles score submission for:
 * - Course completions (binary pass/fail)
 * - Quiz scores (scored assessments)
 * - TinCanny/xAPI results (percentage-based)
 */
final class GradePassbackService
{
    /**
     * Post a course completion to the LTI platform.
     *
     * @param int $userId   WordPress user ID
     * @param int $courseId LearnDash course ID
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function postCompletion(int $userId, int $courseId): bool|WP_Error
    {
        return $this->postScore(
            userId: $userId,
            courseId: $courseId,
            score: 1,
            maxScore: 1,
            activityProgress: 'Completed',
            gradingProgress: 'FullyGraded'
        );
    }

    /**
     * Post a quiz score to the LTI platform.
     *
     * @param int   $userId   WordPress user ID
     * @param int   $courseId LearnDash course ID
     * @param float $score    Points earned
     * @param float $maxScore Maximum possible points
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function postQuizScore(int $userId, int $courseId, float $score, float $maxScore): bool|WP_Error
    {
        return $this->postScore(
            userId: $userId,
            courseId: $courseId,
            score: $score,
            maxScore: $maxScore,
            activityProgress: 'Completed',
            gradingProgress: 'FullyGraded'
        );
    }

    /**
     * Post a TinCanny/xAPI result to the LTI platform.
     *
     * @param int   $userId   WordPress user ID
     * @param int   $courseId LearnDash course ID
     * @param float $result   Result percentage (0-100)
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function postTinCannyScore(int $userId, int $courseId, float $result): bool|WP_Error
    {
        return $this->postScore(
            userId: $userId,
            courseId: $courseId,
            score: $result,
            maxScore: 100,
            activityProgress: 'Completed',
            gradingProgress: 'FullyGraded'
        );
    }

    /**
     * Post a score to the LTI platform via AGS.
     *
     * @param int    $userId           WordPress user ID
     * @param int    $courseId         LearnDash course ID
     * @param float  $score            Score value
     * @param float  $maxScore         Maximum possible score
     * @param string $activityProgress Activity progress status
     * @param string $gradingProgress  Grading progress status
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    private function postScore(
        int $userId,
        int $courseId,
        float $score,
        float $maxScore,
        string $activityProgress,
        string $gradingProgress
    ): bool|WP_Error {
        // Get LTI context from user meta
        $context = get_user_meta($userId, '_netdust_lti_context_' . $courseId, true);

        if (!$context) {
            return new WP_Error('no_context', 'No LTI context found for this user/course');
        }

        if (empty($context['line_item_url']) && empty($context['scores_url'])) {
            return new WP_Error('no_ags', 'No AGS endpoint available');
        }

        ntdst_log('lti-grade')->info('Posting score', [
            'user_id' => $userId,
            'course_id' => $courseId,
            'score' => "{$score}/{$maxScore}",
            'platform_id' => $context['platform_id'],
        ]);

        try {
            $dataConnector = new WPDataConnector();
            $platform = Platform::fromRecordId($context['platform_id'], $dataConnector);

            if (!$platform) {
                return new WP_Error('platform_not_found', 'Platform not found');
            }

            // Determine the score endpoint
            $scoreEndpoint = $context['scores_url'] ?? $context['line_item_url'];

            // Use the library's Score service
            $scoreService = new Score($platform, $scoreEndpoint);

            // Build outcome
            $outcome = new Outcome($score, $maxScore, $activityProgress, $gradingProgress);

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
