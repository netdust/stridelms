<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Domain\Money;
use Stride\Tests\TestCase;

class EditionServicePricingTest extends TestCase
{
    private EditionService $service;
    private EditionRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->createMock(EditionRepository::class);

        // EditionService extends AbstractService which calls init() in constructor.
        // We need to bypass that for unit testing.
        $this->service = $this->getMockBuilder(EditionService::class)
            ->setConstructorArgs([$this->repository])
            ->onlyMethods(['init'])
            ->getMock();
    }

    // === isMember() ===

    public function testIsMemberReturnsTrueWhenMetaIsSet(): void
    {
        $userId = 1;
        update_user_meta($userId, 'is_vad_member', true);

        $this->assertTrue($this->service->isMember($userId));
    }

    public function testIsMemberReturnsFalseWhenMetaNotSet(): void
    {
        $userId = 2;

        $this->assertFalse($this->service->isMember($userId));
    }

    public function testIsMemberReturnsFalseWhenMetaIsFalse(): void
    {
        $userId = 3;
        update_user_meta($userId, 'is_vad_member', false);

        $this->assertFalse($this->service->isMember($userId));
    }

    public function testIsMemberFilterCanOverride(): void
    {
        $userId = 4;
        update_user_meta($userId, 'is_vad_member', false);

        add_filter('stride/membership/is_member', function (bool $isMember, int $uid): bool {
            return true;
        }, 10, 2);

        $this->assertTrue($this->service->isMember($userId));
    }

    public function testIsMemberFilterReceivesCorrectArgs(): void
    {
        $userId = 5;
        update_user_meta($userId, 'is_vad_member', true);

        $receivedArgs = [];
        add_filter('stride/membership/is_member', function (bool $isMember, int $uid) use (&$receivedArgs): bool {
            $receivedArgs = ['isMember' => $isMember, 'userId' => $uid];
            return $isMember;
        }, 10, 2);

        $this->service->isMember($userId);

        $this->assertTrue($receivedArgs['isMember']);
        $this->assertSame(5, $receivedArgs['userId']);
    }

    // === getPrice() ===

    public function testGetPriceWithoutUserIdReturnsNonMemberPrice(): void
    {
        $editionId = 100;

        $this->repository->method('getField')
            ->willReturnMap([
                [$editionId, 'price_non_member', 0, 350.0],
            ]);

        $price = $this->service->getPrice($editionId);

        $this->assertInstanceOf(Money::class, $price);
        $this->assertSame(35000, $price->inCents());
    }

    public function testGetPriceWithMemberUserIdReturnsMemberPrice(): void
    {
        $editionId = 100;
        $userId = 10;
        update_user_meta($userId, 'is_vad_member', true);

        $this->repository->method('getField')
            ->willReturnMap([
                [$editionId, 'price', 0, 250.0],
            ]);

        $price = $this->service->getPrice($editionId, $userId);

        $this->assertSame(25000, $price->inCents());
    }

    public function testGetPriceWithNonMemberUserIdReturnsNonMemberPrice(): void
    {
        $editionId = 100;
        $userId = 11;

        $this->repository->method('getField')
            ->willReturnMap([
                [$editionId, 'price_non_member', 0, 350.0],
            ]);

        $price = $this->service->getPrice($editionId, $userId);

        $this->assertSame(35000, $price->inCents());
    }

    public function testGetPriceFilterCanOverridePrice(): void
    {
        $editionId = 100;
        $userId = 12;
        update_user_meta($userId, 'is_vad_member', true);

        $this->repository->method('getField')
            ->willReturnMap([
                [$editionId, 'price', 0, 250.0],
            ]);

        add_filter('stride/membership/price', function (Money $price, int $eid, ?int $uid, bool $isMember): Money {
            if ($isMember) {
                return Money::cents((int) round($price->inCents() * 0.9));
            }
            return $price;
        }, 10, 4);

        $price = $this->service->getPrice($editionId, $userId);

        $this->assertSame(22500, $price->inCents());
    }

    public function testGetPriceFilterReceivesCorrectArgs(): void
    {
        $editionId = 100;
        $userId = 13;
        update_user_meta($userId, 'is_vad_member', true);

        $this->repository->method('getField')
            ->willReturnMap([
                [$editionId, 'price', 0, 200.0],
            ]);

        $receivedArgs = [];
        add_filter('stride/membership/price', function (Money $price, int $eid, ?int $uid, bool $isMember) use (&$receivedArgs): Money {
            $receivedArgs = compact('price', 'eid', 'uid', 'isMember');
            return $price;
        }, 10, 4);

        $this->service->getPrice($editionId, $userId);

        $this->assertSame(20000, $receivedArgs['price']->inCents());
        $this->assertSame(100, $receivedArgs['eid']);
        $this->assertSame(13, $receivedArgs['uid']);
        $this->assertTrue($receivedArgs['isMember']);
    }

    public function testGetPriceWithNullUserIdPassesNullToFilter(): void
    {
        $editionId = 100;

        $this->repository->method('getField')
            ->willReturnMap([
                [$editionId, 'price_non_member', 0, 350.0],
            ]);

        $receivedUid = 'not-called';
        add_filter('stride/membership/price', function (Money $price, int $eid, ?int $uid, bool $isMember) use (&$receivedUid): Money {
            $receivedUid = $uid;
            return $price;
        }, 10, 4);

        $this->service->getPrice($editionId);

        $this->assertNull($receivedUid);
    }
}
