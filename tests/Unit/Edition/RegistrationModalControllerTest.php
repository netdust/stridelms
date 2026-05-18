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

    public function testBuildPayloadReturnsErrorWhenRegistrationNotFound(): void
    {
        $registrations = $this->createMock(RegistrationRepository::class);
        $registrations->method('find')->willReturn(null);

        $controller = new RegistrationModalController(
            $this->createMock(EditionService::class),
            $this->createMock(SessionService::class),
            $this->createMock(SessionSelection::class),
            $registrations,
        );

        $result = $controller->buildPayload(123, 'enrollment');

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('registration_not_found', $result->get_error_code());
    }

    public function testBuildPayloadReturnsErrorForAnonymisedUser(): void
    {
        $reg = (object) ['id' => 1, 'user_id' => 42, 'edition_id' => 99];

        $registrations = $this->createMock(RegistrationRepository::class);
        $registrations->method('find')->willReturn($reg);

        // Stride\Tests\Stubs::set_user_meta to simulate anonymised user
        \update_user_meta(42, '_stride_anonymised_at', time());

        $controller = new RegistrationModalController(
            $this->createMock(EditionService::class),
            $this->createMock(SessionService::class),
            $this->createMock(SessionSelection::class),
            $registrations,
        );

        $result = $controller->buildPayload(1, 'enrollment');

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('user_unavailable', $result->get_error_code());

        \delete_user_meta(42, '_stride_anonymised_at');
    }

    public function testEnrollmentModalRendersFormSection(): void
    {
        $reg = (object) [
            'id' => 1,
            'user_id' => 42,
            'edition_id' => 99,
            'enrollment_data' => wp_json_encode(['phone_secondary' => '+32 123', 'organisation' => 'X']),
            'completion_tasks' => '{}',
        ];

        $registrations = $this->createMock(RegistrationRepository::class);
        $registrations->method('find')->willReturn($reg);

        $editionService = $this->createMock(EditionService::class);
        $editionService->method('getEdition')->willReturn(new \WP_Post(['post_title' => 'My Edition']));

        // Seed user 42 so get_userdata() resolves (the stub reads $_test_users[$id]).
        global $_test_users;
        $user = new \WP_User();
        $user->ID = 42;
        $user->display_name = 'Test User';
        $_test_users[42] = $user;

        $controller = new RegistrationModalController(
            $editionService,
            $this->createMock(SessionService::class),
            $this->createMock(SessionSelection::class),
            $registrations,
        );

        $result = $controller->buildPayload(1, 'enrollment');

        unset($_test_users[42]);

        self::assertIsArray($result);
        self::assertStringContainsString('Inschrijvingsformulier', $result['html']);
        self::assertStringContainsString('+32 123', $result['html']);
        // Identity fields (organisation) MUST be skipped — already shown inline
        self::assertStringNotContainsString('class="stride-form-row" data-key="organisation"', $result['html']);
    }
}
