<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Edition;

use Stride\Modules\Edition\EditionCPT;
use Stride\Tests\TestCase;

/**
 * Contract test for the Edition schema's documents-instruction fields.
 *
 * The schema key + type is the contract the read accessor and the admin UI
 * depend on: a missing/misnamed key or a non-textarea type silently breaks
 * both the input sanitizer (textarea -> sanitize_textarea_field) and the
 * later repository read. RED-first locks the contract.
 */
class EditionCPTSchemaTest extends TestCase
{
    public function test_edition_schema_declares_documents_instruction_textareas(): void
    {
        $fields = EditionCPT::getFields();

        $this->assertArrayHasKey('documents_instruction', $fields);
        $this->assertSame('textarea', $fields['documents_instruction']['type']);

        $this->assertArrayHasKey('post_documents_instruction', $fields);
        $this->assertSame('textarea', $fields['post_documents_instruction']['type']);
    }
}
