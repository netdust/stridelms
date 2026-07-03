<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * NTDST_Response as the single OWNER of the API envelope (INV-10 companion).
 *
 * Covers the static envelope builders (apiSuccess/apiError — the {success,…}
 * wire shapes Endpoints delegates to in Task 1.2) and toRestResponse() (the
 * non-exiting json() sibling used by the REST dispatch path in Task 3.4).
 */
final class NtdstResponseEnvelopeTest extends TestCase
{
    public function testApiSuccessBuildsEndpointsSuccessShape(): void
    {
        self::assertSame(
            ['success' => true, 'data' => ['id' => 7]],
            \NTDST_Response::apiSuccess(['id' => 7]),
        );
    }

    public function testApiErrorBuildsEndpointsErrorShape(): void
    {
        self::assertSame(
            ['success' => false, 'data' => ['message' => 'Nope', 'code' => 'forbidden']],
            \NTDST_Response::apiError('Nope', 'forbidden'),
        );
    }

    public function testApiErrorDefaultsCodeToError(): void
    {
        self::assertSame('error', \NTDST_Response::apiError('x')['data']['code']);
    }

    public function testToRestResponseCarriesJsonPayloadAndStatusWithoutExit(): void
    {
        $rest = \ntdst_response()->withData(['a' => 1])->toRestResponse();
        self::assertInstanceOf(\WP_REST_Response::class, $rest);
        self::assertSame(200, $rest->get_status());
        self::assertSame(['success' => true, 'data' => ['a' => 1]], $rest->get_data());
    }

    public function testToRestResponseErrorPathMirrorsJsonShape(): void
    {
        $rest = \ntdst_response()->error('Bad input', 422)->toRestResponse();
        self::assertSame(422, $rest->get_status());
        self::assertSame(['success' => false, 'error' => 'Bad input'], $rest->get_data());
    }
}
