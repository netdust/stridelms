<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Modules\Trajectory;

use PHPUnit\Framework\TestCase;
use Stride\Modules\Trajectory\TrajectoryCPT;

/**
 * Unit test: the vad_trajectory schema declares the two documents-instruction
 * textarea fields the "Documenten uploaden" completion task reads.
 *
 * Tier A — the schema IS the contract the admin metabox save (textarea ->
 * sanitize_textarea_field) and the EnrollmentCompletion read accessor depend
 * on. A missing key or wrong type silently breaks both sanitize-on-input and
 * the per-trajectory instruction read.
 *
 * Run: ddev exec vendor/bin/phpunit --filter TrajectoryCPTSchemaTest --testsuite Unit
 */
final class TrajectoryCPTSchemaTest extends TestCase
{
    public function test_trajectory_schema_declares_documents_instruction_textareas(): void
    {
        $fields = TrajectoryCPT::getFields();

        $this->assertArrayHasKey('documents_instruction', $fields);
        $this->assertSame('textarea', $fields['documents_instruction']['type']);

        $this->assertArrayHasKey('post_documents_instruction', $fields);
        $this->assertSame('textarea', $fields['post_documents_instruction']['type']);
    }

    public function test_documents_instruction_fields_carry_dutch_labels(): void
    {
        $fields = TrajectoryCPT::getFields();

        $this->assertArrayHasKey('label', $fields['documents_instruction']);
        $this->assertNotEmpty($fields['documents_instruction']['label']);

        $this->assertArrayHasKey('label', $fields['post_documents_instruction']);
        $this->assertNotEmpty($fields['post_documents_instruction']['label']);
    }
}
