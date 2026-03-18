<?php

declare(strict_types=1);

namespace Tests\Unit\NetdustMail;

use Netdust\Mail\MailBuilder;
use Netdust\Mail\MailService;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * @covers \Netdust\Mail\MailBuilder
 */
class MailBuilderTest extends TestCase
{
    private MailService $mockService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockService = $this->createMock(MailService::class);
    }

    public function testConstructorSetsTemplateSlug(): void
    {
        $builder = new MailBuilder($this->mockService, 'welcome');
        $this->assertEquals('welcome', $builder->getTemplateSlug());
    }

    public function testContextMergesData(): void
    {
        $builder = new MailBuilder($this->mockService, 'test');

        $builder->context(['user_id' => 1]);
        $this->assertEquals(['user_id' => 1], $builder->getContext());

        $builder->context(['post_id' => 42]);
        $this->assertEquals(['user_id' => 1, 'post_id' => 42], $builder->getContext());
    }

    public function testContextReturnsSelf(): void
    {
        $builder = new MailBuilder($this->mockService, 'test');
        $result = $builder->context(['user_id' => 1]);
        $this->assertSame($builder, $result);
    }

    public function testToSetsRecipient(): void
    {
        $builder = new MailBuilder($this->mockService, 'test');
        $builder->to('test@example.com');

        $options = $builder->getOptions();
        $this->assertEquals('test@example.com', $options['to']);
    }

    public function testToReturnsSelf(): void
    {
        $builder = new MailBuilder($this->mockService, 'test');
        $result = $builder->to('test@example.com');
        $this->assertSame($builder, $result);
    }

    public function testCcSetsCcRecipient(): void
    {
        $builder = new MailBuilder($this->mockService, 'test');
        $builder->cc('cc@example.com');

        $options = $builder->getOptions();
        $this->assertEquals('cc@example.com', $options['cc']);
    }

    public function testCcReturnsSelf(): void
    {
        $builder = new MailBuilder($this->mockService, 'test');
        $result = $builder->cc('cc@example.com');
        $this->assertSame($builder, $result);
    }

    public function testBccSetsBccRecipient(): void
    {
        $builder = new MailBuilder($this->mockService, 'test');
        $builder->bcc('bcc@example.com');

        $options = $builder->getOptions();
        $this->assertEquals('bcc@example.com', $options['bcc']);
    }

    public function testBccReturnsSelf(): void
    {
        $builder = new MailBuilder($this->mockService, 'test');
        $result = $builder->bcc('bcc@example.com');
        $this->assertSame($builder, $result);
    }

    public function testAttachAddsFilePath(): void
    {
        $builder = new MailBuilder($this->mockService, 'test');
        $builder->attach('/path/to/file.pdf');

        $this->assertEquals(['/path/to/file.pdf'], $builder->getExtraAttachments());
    }

    public function testAttachAddsMultipleFiles(): void
    {
        $builder = new MailBuilder($this->mockService, 'test');
        $builder->attach('/path/to/file1.pdf');
        $builder->attach('/path/to/file2.pdf');

        $this->assertEquals(
            ['/path/to/file1.pdf', '/path/to/file2.pdf'],
            $builder->getExtraAttachments()
        );
    }

    public function testAttachReturnsSelf(): void
    {
        $builder = new MailBuilder($this->mockService, 'test');
        $result = $builder->attach('/path/to/file.pdf');
        $this->assertSame($builder, $result);
    }

    public function testSendCallsServiceWithCorrectArguments(): void
    {
        $this->mockService->expects($this->once())
            ->method('send')
            ->with(
                'welcome',
                ['user_id' => 1],
                ['to' => 'test@example.com']
            )
            ->willReturn(true);

        $builder = new MailBuilder($this->mockService, 'welcome');
        $result = $builder
            ->context(['user_id' => 1])
            ->to('test@example.com')
            ->send();

        $this->assertTrue($result);
    }

    public function testSendIncludesExtraAttachments(): void
    {
        $this->mockService->expects($this->once())
            ->method('send')
            ->with(
                'test',
                [],
                [
                    'to' => 'test@example.com',
                    'extra_attachments' => ['/path/to/file.pdf'],
                ]
            )
            ->willReturn(true);

        $builder = new MailBuilder($this->mockService, 'test');
        $builder
            ->to('test@example.com')
            ->attach('/path/to/file.pdf')
            ->send();
    }

    public function testSendReturnsWpErrorOnFailure(): void
    {
        $error = new WP_Error('send_failed', 'Email sending failed');

        $this->mockService->expects($this->once())
            ->method('send')
            ->willReturn($error);

        $builder = new MailBuilder($this->mockService, 'test');
        $result = $builder->to('test@example.com')->send();

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('send_failed', $result->get_error_code());
    }

    public function testFluentChaining(): void
    {
        $this->mockService->expects($this->once())
            ->method('send')
            ->with(
                'welcome',
                ['user_id' => 1, 'post_id' => 42],
                [
                    'to' => 'user@example.com',
                    'cc' => 'cc@example.com',
                    'bcc' => 'bcc@example.com',
                    'extra_attachments' => ['/path/to/doc.pdf'],
                ]
            )
            ->willReturn(true);

        $builder = new MailBuilder($this->mockService, 'welcome');
        $result = $builder
            ->context(['user_id' => 1])
            ->context(['post_id' => 42])
            ->to('user@example.com')
            ->cc('cc@example.com')
            ->bcc('bcc@example.com')
            ->attach('/path/to/doc.pdf')
            ->send();

        $this->assertTrue($result);
    }

    public function testEmptyBuilderSendsWithDefaults(): void
    {
        $this->mockService->expects($this->once())
            ->method('send')
            ->with('test', [], [])
            ->willReturn(true);

        $builder = new MailBuilder($this->mockService, 'test');
        $builder->send();
    }

    public function testGetTemplateSlugReturnsCorrectValue(): void
    {
        $builder = new MailBuilder($this->mockService, 'my-template');
        $this->assertEquals('my-template', $builder->getTemplateSlug());
    }

    public function testGetContextReturnsEmptyArrayByDefault(): void
    {
        $builder = new MailBuilder($this->mockService, 'test');
        $this->assertEquals([], $builder->getContext());
    }

    public function testGetOptionsReturnsEmptyArrayByDefault(): void
    {
        $builder = new MailBuilder($this->mockService, 'test');
        $this->assertEquals([], $builder->getOptions());
    }

    public function testGetExtraAttachmentsReturnsEmptyArrayByDefault(): void
    {
        $builder = new MailBuilder($this->mockService, 'test');
        $this->assertEquals([], $builder->getExtraAttachments());
    }

    public function testContextOverwritesExistingKeys(): void
    {
        $builder = new MailBuilder($this->mockService, 'test');
        $builder->context(['user_id' => 1]);
        $builder->context(['user_id' => 2]);

        $this->assertEquals(['user_id' => 2], $builder->getContext());
    }

    public function testToOverwritesPreviousRecipient(): void
    {
        $builder = new MailBuilder($this->mockService, 'test');
        $builder->to('first@example.com');
        $builder->to('second@example.com');

        $options = $builder->getOptions();
        $this->assertEquals('second@example.com', $options['to']);
    }

    public function testMultipleOptionsCanBeSet(): void
    {
        $builder = new MailBuilder($this->mockService, 'test');
        $builder
            ->to('to@example.com')
            ->cc('cc@example.com')
            ->bcc('bcc@example.com');

        $options = $builder->getOptions();
        $this->assertEquals('to@example.com', $options['to']);
        $this->assertEquals('cc@example.com', $options['cc']);
        $this->assertEquals('bcc@example.com', $options['bcc']);
    }
}
