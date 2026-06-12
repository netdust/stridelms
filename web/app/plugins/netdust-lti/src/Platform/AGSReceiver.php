<?php
declare(strict_types=1);

namespace NetdustLTI\Platform;

use NetdustLTI\Platform\WPPlatform;
use NetdustLTI\Shared\JsonResponseTrait;
use WP_Error;

use function absint;
use function sanitize_text_field;
use function get_user_meta;
use function update_user_meta;
use function get_option;
use function get_user_by;
use function get_users;
use function wp_json_encode;
use function do_action;
use function is_wp_error;

/**
 * Handles grade submissions from LTI tools via Assignment and Grade Services (AGS).
 *
 * External LTI tools POST grade data to /lti/platform/grades endpoint.
 * This class validates the submission, stores grades in user meta,
 * and fires action hooks for integration with other systems.
 */
final class AGSReceiver
{
    use JsonResponseTrait;

    /**
     * Handle an incoming grade submission request.
     *
     * Expected flow:
     * 1. Validates POST method
     * 2. Extracts and validates bearer token from Authorization header
     * 3. Parses JSON body with score data
     * 4. Finds user by LTI sub claim
     * 5. Normalizes score (scoreGiven/scoreMaximum)
     * 6. Stores grade in user meta
     * 7. Fires lti_grade_received action hook
     * 8. Returns JSON response
     */
    public function handleGradeSubmission(): void
    {
        try {
            $this->processGradeSubmission();
        } catch (\Throwable $e) {
            ntdst_log('lti')->error('AGSReceiver fatal error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->sendJsonError('Internal error: ' . $e->getMessage(), 500);
        }
    }

    private function processGradeSubmission(): void
    {
        // Only accept POST requests
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonError('Method not allowed', 405);
        }

        // Validate bearer token
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            $this->sendJsonError('Missing bearer token', 401);
        }

        $token = $matches[1];

        // Validate OAuth2 access token
        $tokenData = $this->validateToken($token);

        if (is_wp_error($tokenData)) {
            $this->sendJsonError($tokenData->get_error_message(), 401);
        }

        // Parse score submission
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->sendJsonError('Invalid JSON body', 400);
        }

        if (!is_array($data)) {
            $this->sendJsonError('Expected JSON object', 400);
        }

        // Extract score data
        $scoreGiven = floatval($data['scoreGiven'] ?? 0);
        $scoreMaximum = floatval($data['scoreMaximum'] ?? 1);
        $comment = sanitize_text_field($data['comment'] ?? '');
        $activityProgress = sanitize_text_field($data['activityProgress'] ?? 'Completed');
        $gradingProgress = sanitize_text_field($data['gradingProgress'] ?? 'FullyGraded');

        // Extract user from score data (LTI AGS includes userId in score submission)
        $ltiUserId = $data['userId'] ?? '';
        if (empty($ltiUserId)) {
            $this->sendJsonError('userId required in score data', 400);
        }

        $userId = $this->findUserByLtiSub($ltiUserId);
        if (!$userId) {
            $this->sendJsonError('User not found for userId: ' . $ltiUserId, 404);
        }

        // Normalize score to 0-1 range
        $normalizedScore = $scoreMaximum > 0 ? $scoreGiven / $scoreMaximum : 0;

        // With library auth, we don't get tool_id from token
        // Use 0 as generic tool ID (grades are keyed by resource_link_id)
        $toolId = 0;
        $resourceLinkId = $this->extractResourceLinkFromUrl();

        // Store grade
        $this->storeGrade($userId, $toolId, $resourceLinkId, $normalizedScore, $activityProgress, $comment);

        // Fire action hook for integrations
        do_action('lti_grade_received', $userId, $toolId, $normalizedScore, $activityProgress);

        $this->sendJsonSuccess(['success' => true], 200);
    }

    /**
     * Store a grade in user meta.
     *
     * Grades are stored in a structured array under _lti_grades meta key:
     * [
     *     'tool_42' => [
     *         'course_123' => [
     *             'score' => 0.85,
     *             'max_score' => 1.0,
     *             'comment' => 'Great work!',
     *             'timestamp' => '2024-01-15T10:30:00+00:00',
     *             'activity' => 'Completed',
     *         ],
     *     ],
     * ]
     *
     * @param int $userId WordPress user ID
     * @param int $toolId LTI tool ID
     * @param string $resourceLinkId Unique identifier for the resource/course
     * @param float $score Normalized score (0-1)
     * @param string $activityProgress LTI activity progress status
     * @param string $comment Optional comment from the tool
     */
    public function storeGrade(
        int $userId,
        int $toolId,
        string $resourceLinkId,
        float $score,
        string $activityProgress,
        string $comment = ''
    ): void {
        $grades = get_user_meta($userId, '_lti_grades', true) ?: [];

        $toolKey = "tool_{$toolId}";

        if (!isset($grades[$toolKey])) {
            $grades[$toolKey] = [];
        }

        $grades[$toolKey][$resourceLinkId] = [
            'score' => $score,
            'max_score' => 1.0,
            'comment' => $comment,
            'timestamp' => gmdate('c'),
            'activity' => $activityProgress,
        ];

        update_user_meta($userId, '_lti_grades', $grades);
    }

    /**
     * Retrieve stored grades for a user.
     *
     * @param int $userId WordPress user ID
     * @param int|null $toolId Optional tool ID to filter by
     * @return array Grades array, or empty array if none found
     */
    public function getGrades(int $userId, ?int $toolId = null): array
    {
        $grades = get_user_meta($userId, '_lti_grades', true) ?: [];

        if ($toolId !== null) {
            return $grades["tool_{$toolId}"] ?? [];
        }

        return $grades;
    }

    /**
     * Validate JWT access token using library.
     *
     * @param string $token JWT Bearer token from Authorization header
     * @return array|WP_Error Token claims on success, WP_Error on failure
     */
    private function validateToken(string $token): array|WP_Error
    {
        // Create platform instance for verification
        $platform = new WPPlatform();

        // Required scopes for grade submission
        $requiredScopes = [
            'https://purl.imsglobal.org/spec/lti-ags/scope/score',
        ];

        // Library verifies JWT signature and checks scopes
        // Returns true if valid, modifies $requiredScopes to matched scopes
        if (!$platform->verifyAuthorization($requiredScopes)) {
            $reason = $platform->reason ?? 'Token verification failed';
            ntdst_log('lti')->warning('AGSReceiver: Token validation failed', ['reason' => $reason]);
            return new WP_Error('invalid_token', $reason);
        }

        // Token is valid - return minimal data for grade storage
        return [
            'valid' => true,
            'scopes' => $requiredScopes,
        ];
    }

    /**
     * Extract resource link ID from the grades URL.
     *
     * The URL is typically: /lti/platform/grades or /lti/platform/grades/{resource_id}
     * For now we use a generic identifier since we're using a single grades endpoint.
     */
    private function extractResourceLinkFromUrl(): string
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';

        // Check if there's a resource ID in the URL
        if (preg_match('#/lti/platform/grades/([^/]+)#', $requestUri, $matches)) {
            return sanitize_text_field($matches[1]);
        }

        // Default resource link for the single grades endpoint
        return 'default';
    }

    /**
     * Find WordPress user by LTI sub claim.
     *
     * First attempts to find by WordPress user ID (if sub is numeric).
     * Falls back to searching by _lti_user_id meta field.
     *
     * @param string $sub LTI subject claim
     * @return int|null WordPress user ID, or null if not found
     */
    private function findUserByLtiSub(string $sub): ?int
    {
        // Sub is typically the WP user ID from our JWT
        $userId = absint($sub);

        if ($userId && get_user_by('ID', $userId)) {
            return $userId;
        }

        // Try to find by LTI ID mapping
        $users = get_users([
            'meta_key' => '_lti_user_id',
            'meta_value' => $sub,
            'number' => 1,
        ]);

        if (!empty($users)) {
            return $users[0]->ID;
        }

        return null;
    }

}
