<?php

declare(strict_types=1);

namespace Netdust\Mail;

defined('ABSPATH') || exit;

/**
 * Custom Post Type registration for email templates.
 *
 * CPT: ndmail_template
 * Meta prefix: _ndmail_
 *
 * Uses ntdst_data() Data Manager for CPT registration with automatic
 * metabox generation and field management.
 */
class MailTemplateCPT
{
    public const POST_TYPE = 'ndmail_template';

    /**
     * Register the CPT with WordPress via Data Manager.
     */
    public static function register(): void
    {
        if (post_type_exists(self::POST_TYPE)) {
            return;
        }

        self::registerRestMeta();

        ntdst_data()->register(self::POST_TYPE, [
            'meta_prefix' => '_ndmail_',
            'label' => __('Email Templates', 'netdust-mail'),
            'labels' => [
                'singular_name' => __('Email Template', 'netdust-mail'),
                'add_new' => __('New Template', 'netdust-mail'),
                'add_new_item' => __('Add New Email Template', 'netdust-mail'),
                'edit_item' => __('Edit Email Template', 'netdust-mail'),
                'view_item' => __('View Email Template', 'netdust-mail'),
                'search_items' => __('Search Templates', 'netdust-mail'),
                'not_found' => __('No templates found', 'netdust-mail'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_rest' => true,
            'rest_base' => 'mail-templates',
            'supports' => ['title', 'editor', 'custom-fields'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'fields' => self::getFields(),
        ]);
    }

    /**
     * Register meta fields for REST API access.
     */
    public static function registerRestMeta(): void
    {
        $authCallback = fn() => current_user_can('manage_options');

        $stringKeys = ['subject', 'category', 'status', 'trigger'];
        foreach ($stringKeys as $key) {
            register_post_meta(self::POST_TYPE, '_ndmail_' . $key, [
                'show_in_rest' => true,
                'single' => true,
                'type' => 'string',
                'auth_callback' => $authCallback,
            ]);
        }

        register_post_meta(self::POST_TYPE, '_ndmail_attachments', [
            'show_in_rest' => [
                'schema' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string'],
                            'media_id' => ['type' => 'string'],
                            'generator' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            'single' => true,
            'type' => 'array',
            'auth_callback' => $authCallback,
        ]);
    }

    /**
     * Get field definitions for the CPT.
     *
     * Note: Email body uses WordPress's native editor (post_content).
     *
     * @return array<string, array{type: string, label: string, ...}>
     */
    public static function getFields(): array
    {
        return [
            'subject' => [
                'type' => 'text',
                'label' => __('Subject Line', 'netdust-mail'),
                'description' => __('Supports SmartCodes like {{user.first_name}}', 'netdust-mail'),
                'required' => true,
            ],
            'category' => [
                'type' => 'select',
                'label' => __('Category', 'netdust-mail'),
                'options' => [
                    '' => __('-- Select --', 'netdust-mail'),
                    'auth' => __('Authentication', 'netdust-mail'),
                    'notification' => __('Notification', 'netdust-mail'),
                    'transactional' => __('Transactional', 'netdust-mail'),
                    'marketing' => __('Marketing', 'netdust-mail'),
                ],
            ],
            'status' => [
                'type' => 'select',
                'label' => __('Status', 'netdust-mail'),
                'options' => [
                    'draft' => __('Draft', 'netdust-mail'),
                    'active' => __('Active', 'netdust-mail'),
                ],
                'default' => 'draft',
            ],
            'trigger' => [
                'type' => 'select',
                'label' => __('Auto-send Trigger', 'netdust-mail'),
                'description' => __('Automatically send when this WordPress action fires', 'netdust-mail'),
                'options' => self::getTriggerOptions(),
            ],
            'attachments' => [
                'type' => 'repeater',
                'label' => __('Attachments', 'netdust-mail'),
                'description' => __('Add media files or PDF generators', 'netdust-mail'),
                'button_text' => __('Add Attachment', 'netdust-mail'),
                'sub_fields' => [
                    'type' => [
                        'type' => 'select',
                        'label' => __('Type', 'netdust-mail'),
                        'options' => [
                            'media' => __('Media File', 'netdust-mail'),
                            'pdf' => __('PDF Generator', 'netdust-mail'),
                        ],
                    ],
                    'media_id' => [
                        'type' => 'text',
                        'label' => __('Media', 'netdust-mail'),
                    ],
                    'generator' => [
                        'type' => 'select',
                        'label' => __('Generator', 'netdust-mail'),
                        'options' => self::getGeneratorOptions(),
                    ],
                ],
            ],
        ];
    }

    /**
     * Get available trigger options from the filter.
     *
     * @return array<string, string>
     */
    private static function getTriggerOptions(): array
    {
        $options = ['' => __('-- None (manual only) --', 'netdust-mail')];

        /** @var array<string, array{label: string, ...}> $triggers */
        $triggers = apply_filters('ndmail_triggers', []);
        foreach ($triggers as $key => $config) {
            $options[$key] = $config['label'] ?? $key;
        }

        return $options;
    }

    /**
     * Get available PDF generator options from the filter.
     *
     * @return array<string, string>
     */
    private static function getGeneratorOptions(): array
    {
        $options = ['' => __('-- Select Generator --', 'netdust-mail')];

        /** @var array<string, array{label: string, ...}> $generators */
        $generators = apply_filters('ndmail_pdf_generators', []);
        foreach ($generators as $key => $config) {
            $options[$key] = $config['label'] ?? $key;
        }

        return $options;
    }
}
