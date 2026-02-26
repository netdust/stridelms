<?php
declare(strict_types=1);

namespace NetdustLTI\Platform;

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
        // Only accept POST requests
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(['error' => 'Method not allowed'], 405);
        }

        // Validate bearer token
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            $this->sendJsonResponse(['error' => 'Missing bearer token'], 401);
        }

        $token = $matches[1];

        // Validate JWT and extract claims
        $claims = $this->validateToken($token);

        if (is_wp_error($claims)) {
            $this->sendJsonResponse(['error' => $claims->get_error_message()], 401);
        }

        // Parse score submission
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (!$data) {
            $this->sendJsonResponse(['error' => 'Invalid JSON body'], 400);
        }

        // Extract user from claims
        $userId = $this->findUserByLtiSub($claims['sub'] ?? '');

        if (!$userId) {
            $this->sendJsonResponse(['error' => 'User not found'], 404);
        }

        // Extract score data
        $scoreGiven = floatval($data['scoreGiven'] ?? 0);
        $scoreMaximum = floatval($data['scoreMaximum'] ?? 1);
        $comment = sanitize_text_field($data['comment'] ?? '');
        $activityProgress = sanitize_text_field($data['activityProgress'] ?? 'Completed');
        $gradingProgress = sanitize_text_field($data['gradingProgress'] ?? 'FullyGraded');

        // Normalize score to 0-1 range
        $normalizedScore = $scoreMaximum > 0 ? $scoreGiven / $scoreMaximum : 0;

        // Extract tool and resource from claims
        $toolId = absint($claims['tool_id'] ?? 0);
        $resourceLinkId = $claims['https://purl.imsglobal.org/spec/lti/claim/resource_link']['id'] ?? 'unknown';

        // Store grade
        $this->storeGrade($userId, $toolId, $resourceLinkId, $normalizedScore, $activityProgress, $comment);

        // Fire action hook for integrations
        do_action('lti_grade_received', $userId, $toolId, $normalizedScore, $activityProgress);

        $this->sendJsonResponse(['success' => true], 200);
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
     * Validate JWT token using the platform's public key.
     *
     * @param string $token JWT token from Authorization header
     * @return array|WP_Error Claims array on success, WP_Error on failure
     */
    private function validateToken(string $token): array|WP_Error
    {
        try {
            $publicKey = get_option('netdust_lti_public_key');

            if (!$publicKey) {
                return new WP_Error('config_error', 'Public key not configured');
            }

            // Decode and validate JWT
            $claims = \ceLTIc\LTI\Jwt\FirebaseClient::verify($token, $publicKey);

            if (!$claims) {
                return new WP_Error('invalid_token', 'Token verification failed');
            }

            return (array) $claims;
        } catch (\Exception $e) {
            return new WP_Error('token_error', $e->getMessage());
        }
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

    /**
     * Send JSON response and exit.
     *
     * @param array $data Response data
     * @param int $status HTTP status code
     */
    private function sendJsonResponse(array $data, int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo wp_json_encode($data);
        exit;
    }
}
