<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Edition;

use Stride\Modules\Edition\EditionRepository;
use Stride\Tests\TestCase;

class EditionRepositorySpeakersTest extends TestCase
{
    /**
     * @param mixed $stored
     */
    private function repositoryWithStoredSpeakers($stored): EditionRepository
    {
        $repository = $this->getMockBuilder(EditionRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['rawSpeakersMeta'])
            ->getMock();

        $repository->method('rawSpeakersMeta')
            ->with(123)
            ->willReturn($stored);

        return $repository;
    }

    public function testEmptyMetaReturnsNoSpeakers(): void
    {
        $repository = $this->repositoryWithStoredSpeakers('');

        self::assertSame([], $repository->getSpeakers(123));
        self::assertSame('', $repository->getSpeakersLabel(123));
    }

    public function testLegacyPlainStringBecomesSingleSpeakerWithoutRole(): void
    {
        $repository = $this->repositoryWithStoredSpeakers('Lien De Smedt, sportpedagoge');

        self::assertSame(
            [['name' => 'Lien De Smedt, sportpedagoge', 'role' => '']],
            $repository->getSpeakers(123),
        );
    }

    public function testJsonStringDecodesToSpeakerList(): void
    {
        $repository = $this->repositoryWithStoredSpeakers(
            '[{"name":"Lien De Smedt","role":"sportpedagoge"},{"name":"Jan Janssen","role":""}]',
        );

        self::assertSame(
            [
                ['name' => 'Lien De Smedt', 'role' => 'sportpedagoge'],
                ['name' => 'Jan Janssen', 'role' => ''],
            ],
            $repository->getSpeakers(123),
        );
        self::assertSame('Lien De Smedt, Jan Janssen', $repository->getSpeakersLabel(123));
    }

    public function testAlreadyDecodedArrayIsNormalized(): void
    {
        $repository = $this->repositoryWithStoredSpeakers([
            ['name' => '  Lien De Smedt  ', 'role' => ' sportpedagoge '],
            ['name' => '', 'role' => 'dropped: no name'],
            'Plain string entry',
            42,
        ]);

        self::assertSame(
            [
                ['name' => 'Lien De Smedt', 'role' => 'sportpedagoge'],
                ['name' => 'Plain string entry', 'role' => ''],
            ],
            $repository->getSpeakers(123),
        );
    }

    public function testMalformedJsonFallsBackToLegacyStringEntry(): void
    {
        $repository = $this->repositoryWithStoredSpeakers('[{"name": broken');

        self::assertSame(
            [['name' => '[{"name": broken', 'role' => '']],
            $repository->getSpeakers(123),
        );
    }
}
