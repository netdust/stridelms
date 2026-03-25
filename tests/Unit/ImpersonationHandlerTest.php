<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stride\Admin\ImpersonationHandler;

class ImpersonationHandlerTest extends TestCase
{
    public function test_validates_target_is_not_admin(): void
    {
        $handler = new ImpersonationHandler();
        $result = $handler->validateTarget(
            targetUserId: 42,
            targetIsAdmin: true,
            callerHasManageOptions: true
        );
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('cannot_impersonate_admin', $result->get_error_code());
    }

    public function test_validates_caller_has_manage_options(): void
    {
        $handler = new ImpersonationHandler();
        $result = $handler->validateTarget(
            targetUserId: 42,
            targetIsAdmin: false,
            callerHasManageOptions: false
        );
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('forbidden', $result->get_error_code());
    }

    public function test_validates_target_exists(): void
    {
        $handler = new ImpersonationHandler();
        $result = $handler->validateTarget(
            targetUserId: 0,
            targetIsAdmin: false,
            callerHasManageOptions: true
        );
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_user', $result->get_error_code());
    }

    public function test_passes_validation_for_valid_target(): void
    {
        $handler = new ImpersonationHandler();
        $result = $handler->validateTarget(
            targetUserId: 42,
            targetIsAdmin: false,
            callerHasManageOptions: true
        );
        $this->assertTrue($result);
    }

    public function test_generates_token_of_sufficient_length(): void
    {
        $handler = new ImpersonationHandler();
        $token = $handler->generateToken();
        $this->assertGreaterThanOrEqual(32, strlen($token));
    }

    public function test_generates_unique_tokens(): void
    {
        $handler = new ImpersonationHandler();
        $token1 = $handler->generateToken();
        $token2 = $handler->generateToken();
        $this->assertNotSame($token1, $token2);
    }

    public function test_cookie_name_constant(): void
    {
        $this->assertSame('stride_impersonate_token', ImpersonationHandler::COOKIE_NAME);
    }

    public function test_ttl_is_one_hour(): void
    {
        $this->assertSame(3600, ImpersonationHandler::TTL);
    }
}
