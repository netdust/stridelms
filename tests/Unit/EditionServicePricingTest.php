<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionRepository;
use Stride\Modules\Membership\MembershipService;
use Stride\Domain\Money;
use Stride\Tests\TestCase;

class EditionServicePricingTest extends TestCase
{
    private EditionService $service;
    private EditionRepository $repository;
    private SessionRepository $sessions;
    private MembershipService $membership;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->createMock(EditionRepository::class);
        $this->sessions = $this->createMock(SessionRepository::class);
        $this->membership = $this->createMock(MembershipService::class);

        // EditionService extends AbstractService which calls init() in constructor.
        // We need to bypass that for unit testing.
        $this->service = $this->getMockBuilder(EditionService::class)
            ->setConstructorArgs([$this->repository, $this->sessions, $this->membership])
            ->onlyMethods(['init'])
            ->getMock();
    }

    // === isMember() — thin delegate to MembershipService ===

    public function testIsMemberDelegatesToMembershipService(): void
    {
        $userId = 5;
        $this->membership->expects(self::once())
            ->method('isMember')
            ->with($userId)
            ->willReturn(true);

        self::assertTrue($this->service->isMember($userId));
    }

    public function testIsMemberReturnsFalseWhenMembershipServiceSaysNo(): void
    {
        $this->membership->method('isMember')->willReturn(false);

        self::assertFalse($this->service->isMember(7));
    }

    // === getPrice() ===
    //
    // CANONICAL UNIT: the stored `price` / `price_non_member` field is in CENTS
    // (admin save converts the entered euros ×100; admin display divides ÷100).
    // getPrice() must therefore read the stored value AS cents — a stored 35000
    // is €350,00, and a stored 495 is €4,95. Reading it as euros (Money::eur)
    // multiplied by another 100 and rendered prices 100× too large.

    public function testGetPriceWithoutUserIdReturnsNonMemberPrice(): void
    {
        $editionId = 100;
        $this->repository->method('getField')
            ->willReturnMap([
                [$editionId, 'price_non_member', 0, 35000.0],
            ]);

        $price = $this->service->getPrice($editionId);

        self::assertInstanceOf(Money::class, $price);
        self::assertSame(35000, $price->inCents());
    }

    public function testGetPriceReadsStoredFieldAsCentsNotEuros(): void
    {
        // THE BUG: stored 495 cents = €4,95. The euros-interpretation
        // (Money::eur(495)) would yield 49500 cents = "€ 495,00".
        $editionId = 100;
        $this->repository->method('getField')
            ->willReturnMap([
                [$editionId, 'price_non_member', 0, 495.0],
            ]);

        $price = $this->service->getPrice($editionId);

        self::assertSame(495, $price->inCents());
        self::assertSame('€ 4,95', $price->format());
    }

    public function testGetPriceWithNonMemberUserIdReturnsNonMemberPrice(): void
    {
        $editionId = 100;
        $this->membership->method('isMember')->willReturn(false);
        $this->repository->method('getField')
            ->willReturnMap([
                [$editionId, 'price_non_member', 0, 35000.0],
            ]);

        $price = $this->service->getPrice($editionId, 11);

        self::assertSame(35000, $price->inCents());
    }

    public function testGetPriceWithMemberUserIdReturnsMemberPriceWhenFilterEnablesMembership(): void
    {
        // For v1 MembershipService::isMember always returns false. But the
        // pricing pipeline must still route via the `price` meta when the
        // service is told a user IS a member (e.g. a future filter override).
        $editionId = 100;
        $this->membership->method('isMember')->willReturn(true);
        $this->repository->method('getField')
            ->willReturnMap([
                [$editionId, 'price', 0, 25000.0],
            ]);

        $price = $this->service->getPrice($editionId, 10);

        self::assertSame(25000, $price->inCents());
    }

    public function testGetPriceFilterReceivesCorrectArgs(): void
    {
        $editionId = 100;
        $userId = 13;
        $this->membership->method('isMember')->willReturn(true);
        $this->repository->method('getField')
            ->willReturnMap([
                [$editionId, 'price', 0, 20000.0],
            ]);

        $receivedArgs = [];
        add_filter('stride/membership/price', function (Money $price, int $eid, ?int $uid, bool $isMember) use (&$receivedArgs): Money {
            $receivedArgs = compact('price', 'eid', 'uid', 'isMember');
            return $price;
        }, 10, 4);

        $this->service->getPrice($editionId, $userId);

        self::assertSame(20000, $receivedArgs['price']->inCents());
        self::assertSame(100, $receivedArgs['eid']);
        self::assertSame(13, $receivedArgs['uid']);
        self::assertTrue($receivedArgs['isMember']);
    }

    public function testGetPriceWithNullUserIdPassesNullToFilter(): void
    {
        $editionId = 100;
        $this->repository->method('getField')
            ->willReturnMap([
                [$editionId, 'price_non_member', 0, 35000.0],
            ]);

        $receivedUid = 'not-called';
        add_filter('stride/membership/price', function (Money $price, int $eid, ?int $uid, bool $isMember) use (&$receivedUid): Money {
            $receivedUid = $uid;
            return $price;
        }, 10, 4);

        $this->service->getPrice($editionId);

        self::assertNull($receivedUid);
    }
}
