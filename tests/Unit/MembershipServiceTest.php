<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Modules\Membership\MembershipService;
use Stride\Tests\TestCase;

/**
 * v1: MembershipService always reports "not a member" unless an override
 * filter says otherwise. Tests pin that contract.
 */
final class MembershipServiceTest extends TestCase
{
    private MembershipService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MembershipService();
    }

    public function testIsMemberReturnsFalseForRegularUser(): void
    {
        self::assertFalse($this->service->isMember(42));
    }

    public function testIsMemberReturnsFalseForZeroUserId(): void
    {
        self::assertFalse($this->service->isMember(0));
    }

    public function testIsMemberReturnsFalseForNegativeUserId(): void
    {
        self::assertFalse($this->service->isMember(-1));
    }

    public function testIgnoresArbitraryMembershipShapedUserMeta(): void
    {
        // Pre-v1 the codebase read membership from user meta directly. v1
        // deliberately does NOT — there is no UI to onboard a member, so
        // any membership-shaped meta left over from imports or past flows
        // must not silently grant discounted pricing.
        $userId = 100;
        update_user_meta($userId, 'membership_active', true);
        update_user_meta($userId, '_member', '1');

        self::assertFalse($this->service->isMember($userId));
    }

    public function testFilterCanForceMembership(): void
    {
        $userId = 101;
        add_filter('stride/membership/is_member', static fn (bool $is): bool => true);

        self::assertTrue($this->service->isMember($userId));
    }

    public function testFilterReceivesCorrectArgs(): void
    {
        $captured = [];
        add_filter('stride/membership/is_member', static function (bool $is, int $uid) use (&$captured): bool {
            $captured = ['is' => $is, 'uid' => $uid];
            return $is;
        }, 10, 2);

        $this->service->isMember(99);

        self::assertFalse($captured['is']);
        self::assertSame(99, $captured['uid']);
    }
}
