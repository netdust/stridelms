<?php

declare(strict_types=1);

namespace Stride\Modules\Questionnaire;

use WP_Error;

/**
 * QuestionnaireValidator
 *
 * Validates submitted questionnaire data against the field definitions
 * for a given post and stage.
 */
class QuestionnaireValidator
{
    public function __construct(
        private readonly QuestionnaireRepository $repository,
    ) {
    }

    /**
     * Validates submitted data against the field definitions for a stage.
     *
     * @param array<string, mixed> $data      Submitted form data keyed by field name.
     * @param int                  $postId    Edition or trajectory post ID.
     * @param string               $stage     One of QuestionnaireRepository::STAGES.
     * @param string               $postType  Post type — defaults to 'vad_edition'.
     *
     * @return true|WP_Error Returns true on success, WP_Error containing all
     *                       validation messages on failure.
     */
    public function validate(
        array $data,
        int $postId,
        string $stage,
        string $postType = 'vad_edition',
    ): true|WP_Error {
        $fields = $this->repository->getFlatFieldsForStage($postId, $stage, $postType);

        $errors = [];

        foreach ($fields as $field) {
            // Description fields have no name and accept no input — skip.
            if (($field['type'] ?? '') === 'description') {
                continue;
            }

            $name = $field['name'] ?? '';

            if ($name === '') {
                continue;
            }

            $value    = $data[$name] ?? null;
            $required = (bool) ($field['required'] ?? false);
            $isEmpty  = $value === null || $value === '' || $value === false;

            if ($required && $isEmpty) {
                $errors[] = sprintf(
                    __('%s is verplicht.', 'stride'),
                    $field['label'] ?? $name,
                );
                continue;
            }

            // No further validation needed for empty optional fields.
            if ($isEmpty) {
                continue;
            }

            if (($field['type'] ?? '') === 'scale') {
                $min = (int) ($field['min'] ?? 1);
                $max = (int) ($field['max'] ?? 5);

                $intValue = (int) $value;

                if ($intValue < $min || $intValue > $max) {
                    $errors[] = sprintf(
                        __('%s moet tussen %d en %d liggen.', 'stride'),
                        $field['label'] ?? $name,
                        $min,
                        $max,
                    );
                }
            }
        }

        if (empty($errors)) {
            return true;
        }

        $wpError = new WP_Error('validation_error', array_shift($errors));

        foreach ($errors as $message) {
            $wpError->add('validation_error', $message);
        }

        return $wpError;
    }
}
