<?php

declare(strict_types=1);

namespace Netdust\Mail;

defined('ABSPATH') || exit;

/**
 * Fluent builder for sending emails.
 *
 * Usage:
 *   ndmail('welcome')
 *     ->context(['user_id' => $userId])
 *     ->to($email)
 *     ->send();
 */
class MailBuilder
{
    /**
     * Context data for SmartCode resolution.
     */
    private array $context = [];

    /**
     * Send options (to, cc, bcc).
     */
    private array $options = [];

    /**
     * Extra attachments beyond template config.
     */
    private array $extraAttachments = [];

    public function __construct(
        private readonly MailService $service,
        private readonly string $templateSlug,
    ) {}

    /**
     * Add context data for SmartCode resolution.
     *
     * @param array $context Key-value pairs.
     * @return $this
     */
    public function context(array $context): self
    {
        $this->context = array_merge($this->context, $context);
        return $this;
    }

    /**
     * Set recipient email.
     *
     * @param string $email Email address.
     * @return $this
     */
    public function to(string $email): self
    {
        $this->options['to'] = $email;
        return $this;
    }

    /**
     * Set CC recipient.
     *
     * @param string $email Email address.
     * @return $this
     */
    public function cc(string $email): self
    {
        $this->options['cc'] = $email;
        return $this;
    }

    /**
     * Set BCC recipient.
     *
     * @param string $email Email address.
     * @return $this
     */
    public function bcc(string $email): self
    {
        $this->options['bcc'] = $email;
        return $this;
    }

    /**
     * Add an extra attachment file path.
     *
     * @param string $filePath Absolute path to file.
     * @return $this
     */
    public function attach(string $filePath): self
    {
        $this->extraAttachments[] = $filePath;
        return $this;
    }

    /**
     * Send the email.
     *
     * @return bool|\WP_Error True on success, WP_Error on failure.
     */
    public function send(): bool|\WP_Error
    {
        if (!empty($this->extraAttachments)) {
            $this->options['extra_attachments'] = $this->extraAttachments;
        }

        return $this->service->send($this->templateSlug, $this->context, $this->options);
    }

    /**
     * Get the template slug.
     *
     * @return string
     */
    public function getTemplateSlug(): string
    {
        return $this->templateSlug;
    }

    /**
     * Get current context.
     *
     * @return array
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Get current options.
     *
     * @return array
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Get extra attachments.
     *
     * @return array
     */
    public function getExtraAttachments(): array
    {
        return $this->extraAttachments;
    }
}
