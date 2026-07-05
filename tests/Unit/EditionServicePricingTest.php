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

    public function testGetPriceIgnoresMembershipAndAlwaysReturnsNonMemberPrice(): void
    {
        // SINGLE-PRICE CONTRACT: there is no member/non-member branch. Even when
        // the membership service reports a user IS a member, getPrice reads the
        // canonical single price (`price_non_member`) and ignores the legacy
        // `price` meta entirely. A distinct `price` value is seeded precisely to
        // prove the old member branch is gone — if it still fired, this would
        // return 25000 instead of the canonical 35000.
        $editionId = 100;
        $this->membership->method('isMember')->willReturn(true);
        $this->repository->method('getField')
            ->willReturnMap([
                [$editionId, 'price', 0, 25000.0],
                [$editionId, 'price_non_member', 0, 35000.0],
            ]);

        $price = $this->service->getPrice($editionId, 10);

        self::assertSame(35000, $price->inCents());
    }

    public function testGetPriceIsIdenticalForMemberAndNonMember(): void
    {
        // The single-price contract, stated as parity: whatever a non-member
        // pays, a member pays the same. Discounts are vouchers, not membership.
        $editionId = 100;
        // Member on the first call, non-member on the second — same edition,
        // same canonical price expected either way.
        $this->membership->method('isMember')
            ->willReturnOnConsecutiveCalls(true, false);
        $this->repository->method('getField')
            ->willReturnMap([
                [$editionId, 'price', 0, 25000.0],
                [$editionId, 'price_non_member', 0, 35000.0],
            ]);

        $memberPrice = $this->service->getPrice($editionId, 10);
        $nonMemberPrice = $this->service->getPrice($editionId, 11);

        self::assertSame(35000, $memberPrice->inCents());
        self::assertSame($memberPrice->inCents(), $nonMemberPrice->inCents());
    }

    public function testGetPriceFilterReceivesCorrectArgs(): void
    {
        // ESCAPE HATCH: the `stride/membership/price` filter must survive the
        // single-price cleanup. It still fires with a stable 4-arg signature
        // ($price, $editionId, $userId, $isMember) — the $isMember flag is still
        // computed and passed through so a future client can branch on it even
        // though core no longer does. The price handed to the filter is now the
        // canonical `price_non_member`.
        $editionId = 100;
        $userId = 13;
        $this->membership->method('isMember')->willReturn(true);
        $this->repository->method('getField')
            ->willReturnMap([
                [$editionId, 'price_non_member', 0, 20000.0],
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

    public function testGetPriceFilterCanOverrideThePrice(): void
    {
        // The escape hatch is not just observed — it can WRITE. A hooked filter
        // that returns a different Money value wins over the canonical price.
        $editionId = 100;
        $this->membership->method('isMember')->willReturn(false);
        $this->repository->method('getField')
            ->willReturnMap([
                [$editionId, 'price_non_member', 0, 35000.0],
            ]);

        add_filter('stride/membership/price', function (Money $price, int $eid, ?int $uid, bool $isMember): Money {
            return Money::cents(9900);
        }, 10, 4);

        $price = $this->service->getPrice($editionId, 42);

        self::assertSame(9900, $price->inCents());
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
