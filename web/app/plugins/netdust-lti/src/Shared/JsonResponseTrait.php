<?php
declare(strict_types=1);

namespace NetdustLTI\Shared;

/**
 * Provides standardized JSON response methods for LTI handlers.
 *
 * This trait provides consistent JSON output while maintaining
 * LTI/OAuth2 protocol compatibility for error responses.
 */
trait JsonResponseTrait
{
    /**
     * Send a JSON success response and exit.
     *
     * @param array $data Response data
     * @param int $status HTTP status code (default 200)
     */
    protected function sendJsonSuccess(array $data, int $status = 200): never
    {
        $this->outputJson($data, $status);
    }

    /**
     * Send an OAuth2-compatible JSON error response and exit.
     *
     * Used for LTI/OAuth2 protocol compliance where errors must
     * have 'error' and 'error_description' fields.
     *
     * @param string $error OAuth2 error code (e.g., 'invalid_client')
     * @param string $description Human-readable error description
     * @param int $status HTTP status code
     */
    protected function sendOAuthError(string $error, string $description, int $status): never
    {
        $this->outputJson([
            'error' => $error,
            'error_description' => $description,
        ], $status, ['Cache-Control: no-store']);
    }

    /**
     * Send a simple JSON error response and exit.
     *
     * @param string $message Error message
     * @param int $status HTTP status code
     */
    protected function sendJsonError(string $message, int $status): never
    {
        $this->outputJson(['error' => $message], $status);
    }

    /**
     * Output JSON with headers and exit.
     *
     * @param array $data Data to encode as JSON
     * @param int $status HTTP status code
     * @param array $extraHeaders Additional headers to send
     */
    private function outputJson(array $data, int $status, array $extraHeaders = []): never
    {
        http_response_code($status);
        header('Content-Type: application/json');

        foreach ($extraHeaders as $header) {
            header($header);
        }

        echo wp_json_encode($data);
        exit;
    }
}
