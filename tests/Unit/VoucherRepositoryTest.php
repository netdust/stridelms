<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Domain\VoucherStatus;
use Stride\Tests\TestCase;

/**
 * Unit tests for VoucherRepository meta access
 *
 * Tests that VoucherRepository.getField() correctly uses Data Manager
 * for meta access after refactoring.
 */
class VoucherRepositoryTest extends TestCase
{
    /**
     * @test
     */
    public function testGetFieldReadsFromDataManager(): void
    {
        $voucher = $this->createVoucher(['ID' => 100]);

        $this->setDataManagerMeta('vad_voucher', 100, [
            'code' => 'TESTCODE123',
            'discount_type' => 'percentage',
            'discount_value' => 20,
        ]);

        $model = ntdst_data()->get('vad_voucher');

        $this->assertEquals('TESTCODE123', $model->getMeta(100, 'code'));
        $this->assertEquals('percentage', $model->getMeta(100, 'discount_type'));
        $this->assertEquals(20, $model->getMeta(100, 'discount_value'));
    }

    /**
     * @test
     */
    public function testGetFieldReturnsDefaultForMissingKey(): void
    {
        $voucher = $this->createVoucher(['ID' => 101]);

        $model = ntdst_data()->get('vad_voucher');

        $result = $model->getMeta(101, 'non_existent_field', 'default_value');
        $this->assertEquals('default_value', $result);

        $resultNull = $model->getMeta(101, 'non_existent_field');
        $this->assertNull($resultNull);
    }

    /**
     * @test
     */
    public function testGetFieldReadsStatus(): void
    {
        $voucher = $this->createVoucher(['ID' => 102]);

        $this->setDataManagerMeta('vad_voucher', 102, [
            'status' => VoucherStatus::Active->value,
        ]);

        $model = ntdst_data()->get('vad_voucher');
        $status = $model->getMeta(102, 'status');

        $this->assertEquals(VoucherStatus::Active->value, $status);

        // Can parse to enum
        $statusEnum = VoucherStatus::tryFrom($status);
        $this->assertEquals(VoucherStatus::Active, $statusEnum);
    }

    /**
     * @test
     */
    public function testGetFieldReadsNumericValues(): void
    {
        $voucher = $this->createVoucher(['ID' => 103]);

        $this->setDataManagerMeta('vad_voucher', 103, [
            'max_uses' => 50,
            'used_count' => 12,
            'discount_value' => 25.50,
        ]);

        $model = ntdst_data()->get('vad_voucher');

        $this->assertEquals(50, $model->getMeta(103, 'max_uses'));
        $this->assertEquals(12, $model->getMeta(103, 'used_count'));
        $this->assertEquals(25.50, $model->getMeta(103, 'discount_value'));
    }

    /**
     * @test
     */
    public function testGetFieldReadsEditionId(): void
    {
        $voucher = $this->createVoucher(['ID' => 104]);

        $this->setDataManagerMeta('vad_voucher', 104, [
            'edition_id' => 999,
        ]);

        $model = ntdst_data()->get('vad_voucher');
        $editionId = $model->getMeta(104, 'edition_id');

        $this->assertEquals(999, $editionId);
    }

    /**
     * @test
     */
    public function testGetFieldReadsRedemptionsArray(): void
    {
        $voucher = $this->createVoucher(['ID' => 105]);

        $redemptions = [
            ['user_id' => 1, 'date' => '2024-01-15', 'registration_id' => 100],
            ['user_id' => 2, 'date' => '2024-01-16', 'registration_id' => 101],
        ];

        $this->setDataManagerMeta('vad_voucher', 105, [
            'redemptions' => $redemptions,
        ]);

        $model = ntdst_data()->get('vad_voucher');
        $result = $model->getMeta(105, 'redemptions');

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals(1, $result[0]['user_id']);
        $this->assertEquals(2, $result[1]['user_id']);
    }

    /**
     * @test
     */
    public function testUpdateMetaBatchWritesMultipleFields(): void
    {
        $voucher = $this->createVoucher(['ID' => 106]);

        $model = ntdst_data()->get('vad_voucher');
        $result = $model->updateMetaBatch(106, [
            'code' => 'NEWCODE',
            'status' => VoucherStatus::Exhausted->value,
            'used_count' => 10,
        ]);

        $this->assertTrue($result);

        // Verify all fields were written
        $this->assertEquals('NEWCODE', $this->getDataManagerMeta('vad_voucher', 106, 'code'));
        $this->assertEquals(VoucherStatus::Exhausted->value, $this->getDataManagerMeta('vad_voucher', 106, 'status'));
        $this->assertEquals(10, $this->getDataManagerMeta('vad_voucher', 106, 'used_count'));
    }

    /**
     * @test
     * @dataProvider voucherStatusProvider
     */
    public function testAllVoucherStatusesCanBeStoredAndRetrieved(VoucherStatus $status): void
    {
        $voucherId = 200 + ord($status->value[0]);
        $voucher = $this->createVoucher(['ID' => $voucherId]);

        $model = ntdst_data()->get('vad_voucher');
        $model->updateMetaBatch($voucherId, [
            'status' => $status->value,
        ]);

        $retrieved = $model->getMeta($voucherId, 'status');
        $this->assertEquals($status->value, $retrieved);

        $parsed = VoucherStatus::tryFrom($retrieved);
        $this->assertEquals($status, $parsed);
    }

    public static function voucherStatusProvider(): array
    {
        return [
            'active' => [VoucherStatus::Active],
            'exhausted' => [VoucherStatus::Exhausted],
            'expired' => [VoucherStatus::Expired],
            'disabled' => [VoucherStatus::Disabled],
        ];
    }

    /**
     * @test
     */
    public function testDirectPostMetaDoesNotReturnDataManagerValues(): void
    {
        $voucher = $this->createVoucher(['ID' => 107]);

        // Set via Data Manager
        $this->setDataManagerMeta('vad_voucher', 107, [
            'code' => 'DMCODE',
        ]);

        // Direct get_post_meta should NOT find it (different storage)
        $directResult = get_post_meta(107, 'code', true);
        $this->assertEmpty($directResult);

        // Data Manager should find it
        $model = ntdst_data()->get('vad_voucher');
        $this->assertEquals('DMCODE', $model->getMeta(107, 'code'));
    }
}
