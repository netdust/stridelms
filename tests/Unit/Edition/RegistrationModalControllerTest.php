<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Edition;

use Stride\Modules\Edition\Admin\RegistrationModalController;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Edition\SessionSelection;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Tests\TestCase;

class RegistrationModalControllerTest extends TestCase
{
    private RegistrationModalController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $editionService = $this->createMock(EditionService::class);
        $sessionService = $this->createMock(SessionService::class);
        $sessionSelection = $this->createMock(SessionSelection::class);
        $registrations = $this->createMock(RegistrationRepository::class);

        $this->controller = $this->getMockBuilder(RegistrationModalController::class)
            ->setConstructorArgs([
                $editionService,
                $sessionService,
                $sessionSelection,
                $registrations,
            ])
            ->onlyMethods([])
            ->getMock();
    }

    public function testNonceConstantPinsExpectedValue(): void
    {
        self::assertSame(
            'stride_edition_admin',
            RegistrationModalController::NONCE_AJAX
        );
    }
}
