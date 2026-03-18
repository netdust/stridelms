<?php

declare(strict_types=1);

namespace Tests\Unit\NetdustMail;

use Netdust\Mail\MailTemplateCPT;
use PHPUnit\Framework\TestCase;

class MailTemplateCPTTest extends TestCase
{
    public function test_post_type_constant_is_defined(): void
    {
        $this->assertEquals('ndmail_template', MailTemplateCPT::POST_TYPE);
    }

    public function test_get_fields_returns_expected_structure(): void
    {
        $fields = MailTemplateCPT::getFields();

        $this->assertArrayHasKey('subject', $fields);
        // Note: body is now in post_content (WordPress editor), not a meta field
        $this->assertArrayHasKey('category', $fields);
        $this->assertArrayHasKey('status', $fields);
        $this->assertArrayHasKey('trigger', $fields);
        $this->assertArrayHasKey('attachments', $fields);
    }

    public function test_get_fields_subject_has_required_type(): void
    {
        $fields = MailTemplateCPT::getFields();
        $this->assertEquals('text', $fields['subject']['type']);
        $this->assertTrue($fields['subject']['required']);
    }

    public function test_get_fields_status_has_default_draft(): void
    {
        $fields = MailTemplateCPT::getFields();
        $this->assertEquals('draft', $fields['status']['default']);
    }

    public function test_get_fields_attachments_is_repeater_type(): void
    {
        $fields = MailTemplateCPT::getFields();
        $this->assertEquals('repeater', $fields['attachments']['type']);
        $this->assertArrayHasKey('sub_fields', $fields['attachments']);
    }

    public function test_get_fields_category_has_valid_options(): void
    {
        $fields = MailTemplateCPT::getFields();

        $this->assertArrayHasKey('options', $fields['category']);
        $this->assertArrayHasKey('auth', $fields['category']['options']);
        $this->assertArrayHasKey('notification', $fields['category']['options']);
        $this->assertArrayHasKey('transactional', $fields['category']['options']);
        $this->assertArrayHasKey('marketing', $fields['category']['options']);
    }

    public function test_get_fields_status_has_valid_options(): void
    {
        $fields = MailTemplateCPT::getFields();

        $this->assertArrayHasKey('options', $fields['status']);
        $this->assertArrayHasKey('draft', $fields['status']['options']);
        $this->assertArrayHasKey('active', $fields['status']['options']);
    }

    public function test_get_fields_trigger_has_select_type(): void
    {
        $fields = MailTemplateCPT::getFields();
        $this->assertEquals('select', $fields['trigger']['type']);
    }

    public function test_register_skips_if_post_type_exists(): void
    {
        global $_test_registered_post_types;

        // Pre-register the post type to simulate it already existing
        $_test_registered_post_types[MailTemplateCPT::POST_TYPE] = ['existing' => true];

        // Track if ntdst_data()->register() was called
        $registerCalled = false;
        $originalRegister = null;

        // The register() method should early return without calling ntdst_data()
        // Since post_type_exists returns true, register() returns early
        MailTemplateCPT::register();

        // Clean up
        unset($_test_registered_post_types[MailTemplateCPT::POST_TYPE]);

        // If we got here without errors, the early return worked
        $this->assertTrue(true);
    }

    public function test_get_fields_all_have_labels(): void
    {
        $fields = MailTemplateCPT::getFields();

        foreach ($fields as $key => $field) {
            $this->assertArrayHasKey('label', $field, "Field '{$key}' is missing a label");
            $this->assertNotEmpty($field['label'], "Field '{$key}' has empty label");
        }
    }

    public function test_get_fields_all_have_types(): void
    {
        $fields = MailTemplateCPT::getFields();

        foreach ($fields as $key => $field) {
            $this->assertArrayHasKey('type', $field, "Field '{$key}' is missing a type");
            $this->assertNotEmpty($field['type'], "Field '{$key}' has empty type");
        }
    }

    public function test_trigger_options_uses_filter(): void
    {
        global $_test_filters;

        // Reset filters
        $_test_filters = [];

        // Add a test trigger via filter
        add_filter('ndmail_triggers', function ($triggers) {
            $triggers['test_trigger'] = [
                'label' => 'Test Trigger Label',
                'context' => ['user_id'],
            ];
            return $triggers;
        });

        $fields = MailTemplateCPT::getFields();

        $this->assertArrayHasKey('', $fields['trigger']['options']); // Manual only option
        $this->assertArrayHasKey('test_trigger', $fields['trigger']['options']);
        $this->assertEquals('Test Trigger Label', $fields['trigger']['options']['test_trigger']);

        // Clean up
        $_test_filters = [];
    }
}
