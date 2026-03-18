<?php

declare(strict_types=1);

namespace Netdust\Mail;

defined('ABSPATH') || exit;

/**
 * Handles email attachment resolution.
 *
 * Resolves attachment configurations (media IDs, PDF generators)
 * to actual file paths for email sending.
 */
class AttachmentHandler
{
    /**
     * Cached PDF generators from filter.
     */
    private ?array $pdfGenerators = null;

    /**
     * Resolve attachment configuration to file paths.
     *
     * @param array $attachments Attachment configuration from template.
     * @param array $context     Context data for dynamic resolution.
     * @return array<string>|\WP_Error List of file paths or error.
     */
    public function resolve(array $attachments, array $context): array|\WP_Error
    {
        if (empty($attachments)) {
            return [];
        }

        // Handle JSON string
        if (is_string($attachments)) {
            $attachments = json_decode($attachments, true) ?: [];
        }

        $files = [];

        foreach ($attachments as $attachment) {
            $type = $attachment['type'] ?? null;

            if ($type === 'media') {
                $result = $this->resolveMedia($attachment);
            } elseif ($type === 'pdf') {
                $result = $this->resolvePdf($attachment, $context);
            } else {
                continue; // Skip unknown types
            }

            if (is_wp_error($result)) {
                return $result;
            }

            if ($result) {
                $files[] = $result;
            }
        }

        return $files;
    }

    /**
     * Resolve a media attachment to its file path.
     *
     * @param array $attachment Attachment configuration.
     * @return string|\WP_Error File path or error.
     */
    private function resolveMedia(array $attachment): string|\WP_Error
    {
        // Support both 'id' (legacy) and 'media_id' (repeater field)
        $id = $attachment['media_id'] ?? $attachment['id'] ?? null;
        if (!$id) {
            return new \WP_Error('ndmail_invalid_attachment', 'Media attachment missing ID');
        }

        $path = get_attached_file($id);
        if (!$path || !file_exists($path)) {
            return new \WP_Error(
                'ndmail_media_not_found',
                sprintf('Media file not found for attachment ID %d', $id)
            );
        }

        return $path;
    }

    /**
     * Resolve a PDF attachment via registered generator.
     *
     * @param array $attachment Attachment configuration.
     * @param array $context    Context data for generator.
     * @return string|\WP_Error File path or error.
     */
    private function resolvePdf(array $attachment, array $context): string|\WP_Error
    {
        $generatorKey = $attachment['generator'] ?? null;
        if (!$generatorKey) {
            return new \WP_Error('ndmail_invalid_attachment', 'PDF attachment missing generator');
        }

        $generators = $this->getPdfGenerators();
        if (!isset($generators[$generatorKey])) {
            return new \WP_Error(
                'ndmail_unknown_generator',
                sprintf('Unknown PDF generator: %s', $generatorKey)
            );
        }

        $generator = $generators[$generatorKey];
        $contextKey = $generator['context_key'] ?? null;

        if ($contextKey && !isset($context[$contextKey])) {
            return new \WP_Error(
                'ndmail_missing_pdf_context',
                sprintf('PDF generator "%s" requires context key "%s"', $generatorKey, $contextKey)
            );
        }

        $contextId = $contextKey ? $context[$contextKey] : null;
        $callback = $generator['callback'] ?? null;

        if (!is_callable($callback)) {
            return new \WP_Error(
                'ndmail_invalid_generator',
                sprintf('PDF generator "%s" has invalid callback', $generatorKey)
            );
        }

        try {
            $path = $callback($contextId);

            if (!$path || !file_exists($path)) {
                return new \WP_Error(
                    'ndmail_pdf_generation_failed',
                    sprintf('PDF generator "%s" did not produce a valid file', $generatorKey)
                );
            }

            return $path;
        } catch (\Throwable $e) {
            return new \WP_Error(
                'ndmail_pdf_generation_error',
                sprintf('PDF generation failed: %s', $e->getMessage())
            );
        }
    }

    /**
     * Get registered PDF generators from filter.
     *
     * @return array<string, array> Generator configurations.
     */
    private function getPdfGenerators(): array
    {
        if ($this->pdfGenerators === null) {
            $this->pdfGenerators = apply_filters('ndmail_pdf_generators', []);
        }
        return $this->pdfGenerators;
    }

    /**
     * Get available PDF generators for UI display.
     *
     * @return array<string, array{label: string, context_key: string|null}> Generator info.
     */
    public function getAvailableGenerators(): array
    {
        $generators = $this->getPdfGenerators();
        $options = [];

        foreach ($generators as $key => $config) {
            $options[$key] = [
                'label' => $config['label'] ?? $key,
                'context_key' => $config['context_key'] ?? null,
            ];
        }

        return $options;
    }
}
