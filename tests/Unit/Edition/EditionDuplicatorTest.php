<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Edition;

use Mockery;
use Stride\Modules\Edition\EditionDuplicator;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\SessionRepository;
use Stride\Tests\TestCase;
use WP_Error;

class EditionDuplicatorTest extends TestCase
{
    private EditionDuplicator $duplicator;

    protected function setUp(): void
    {
        parent::setUp();

        $editions = Mockery::mock(EditionRepository::class);
        $editions->shouldReceive('find')
            ->with(999999)
            ->andReturn(new WP_Error('not_found', 'Post not found'));

        $sessions = Mockery::mock(SessionRepository::class);

        // Bypass init() so hook registration doesn't fire in unit context.
        $this->duplicator = $this->getMockBuilder(EditionDuplicator::class)
            ->setConstructorArgs([$editions, $sessions])
            ->onlyMethods([])
            ->getMock();
    }

    public function testDuplicateReturnsErrorWhenSourceDoesNotExist(): void
    {
        $result = $this->duplicator->duplicate(999999);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('not_found', $result->get_error_code());
    }
}
