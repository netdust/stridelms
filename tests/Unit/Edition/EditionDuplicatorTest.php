<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Edition;

use Stride\Modules\Edition\EditionDuplicator;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\SessionRepository;
use Stride\Tests\TestCase;
use WP_Error;

class EditionDuplicatorTest extends TestCase
{
    private EditionRepository $editions;
    private SessionRepository $sessions;
    private EditionDuplicator $duplicator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->editions = $this->createMock(EditionRepository::class);
        $this->sessions = $this->createMock(SessionRepository::class);

        // Bypass init() so hook registration doesn't fire in unit context.
        $this->duplicator = $this->getMockBuilder(EditionDuplicator::class)
            ->setConstructorArgs([$this->editions, $this->sessions])
            ->onlyMethods([])
            ->getMock();
    }

    public function testDuplicateReturnsErrorWhenSourceDoesNotExist(): void
    {
        // get_post() returns null for missing IDs in the test stubs.
        $result = $this->duplicator->duplicate(999999);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('not_found', $result->get_error_code());
    }
}
