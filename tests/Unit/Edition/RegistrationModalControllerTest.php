<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Edition;

use Stride\Modules\Edition\Admin\RegistrationModalController;
use Stride\Modules\Edition\EditionRepository;
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
        $editionRepository = $this->createMock(EditionRepository::class);
        $sessionService = $this->createMock(SessionService::class);
        $sessionSelection = $this->createMock(SessionSelection::class);
        $registrations = $this->createMock(RegistrationRepository::class);

        $this->controller = $this->getMockBuilder(RegistrationModalController::class)
            ->setConstructorArgs([
                $editionService,
                $editionRepository,
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
            $this->createMock(EditionRepository::class),
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
            $this->createMock(EditionRepository::class),
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
        $editionRepository = $this->createMock(EditionRepository::class);
        $editionRepository->method('find')->willReturn(new \WP_Post(['post_title' => 'My Edition']));

        // Seed user 42 so get_userdata() resolves (the stub reads $_test_users[$id]).
        global $_test_users;
        $user = new \WP_User();
        $user->ID = 42;
        $user->display_name = 'Test User';
        $_test_users[42] = $user;

        $controller = new RegistrationModalController(
            $editionService,
            $editionRepository,
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

    public function testEnrollmentModalRendersSessionSelections(): void
    {
        $reg = (object) [
            'id' => 1, 'user_id' => 42, 'edition_id' => 99,
            'enrollment_data' => '{}',
            'completion_tasks' => '{}',
        ];

        $registrations = $this->createMock(RegistrationRepository::class);
        $registrations->method('find')->willReturn($reg);

        $editionService = $this->createMock(EditionService::class);
        $editionRepository = $this->createMock(EditionRepository::class);
        $editionRepository->method('find')->willReturn(new \WP_Post(['post_title' => 'E']));

        $sessionSelection = $this->createMock(SessionSelection::class);
        $sessionSelection->method('getSelections')->with(1)->willReturn([501]);
        $sessionSelection->method('getSlotConfig')->with(99)->willReturn([
            ['slot' => 'a', 'label' => 'Module 1 — Kies 1 uit 2'],
        ]);

        $sessionService = $this->createMock(SessionService::class);
        $sessionService->method('getSession')->with(501)->willReturn([
            'id' => 501, 'date' => '2026-06-01', 'start_time' => '09:00',
            'slot' => 'a', 'location' => 'Brussel',
        ]);

        // Seed user 42 in stubs (same pattern as Task 6's test).
        global $_test_users;
        $_test_users[42] = new \WP_User((object) ['ID' => 42, 'display_name' => 'Test User']);

        $controller = new RegistrationModalController($editionService, $editionRepository, $sessionService, $sessionSelection, $registrations);
        $result = $controller->buildPayload(1, 'enrollment');

        unset($_test_users[42]);

        self::assertStringContainsString('Module 1 — Kies 1 uit 2', $result['html']);
        self::assertStringContainsString('Brussel', $result['html']);
    }

    public function testEnrollmentModalRendersQuestionnaireAnswers(): void
    {
        $reg = (object) [
            'id' => 1, 'user_id' => 42, 'edition_id' => 99,
            'enrollment_data' => '{}',
            'completion_tasks' => wp_json_encode([
                'questionnaire' => [
                    'status' => 'completed',
                    'data' => ['answers' => ['Wat is uw ervaring?' => 'Veel']],
                ],
            ]),
        ];

        $registrations = $this->createMock(RegistrationRepository::class);
        $registrations->method('find')->willReturn($reg);

        $editionService = $this->createMock(EditionService::class);
        $editionRepository = $this->createMock(EditionRepository::class);
        $editionRepository->method('find')->willReturn(new \WP_Post(['post_title' => 'E']));

        global $_test_users;
        $_test_users[42] = new \WP_User((object) ['ID' => 42, 'display_name' => 'Test User']);

        $controller = new RegistrationModalController(
            $editionService,
            $editionRepository,
            $this->createMock(SessionService::class),
            $this->createMock(SessionSelection::class),
            $registrations,
        );
        $result = $controller->buildPayload(1, 'enrollment');

        unset($_test_users[42]);

        self::assertStringContainsString('Wat is uw ervaring?', $result['html']);
        self::assertStringContainsString('Veel', $result['html']);
    }

    public function testEnrollmentModalRendersDocuments(): void
    {
        $reg = (object) [
            'id' => 1, 'user_id' => 42, 'edition_id' => 99,
            'enrollment_data' => '{}',
            'completion_tasks' => wp_json_encode([
                'documents' => ['status' => 'completed', 'data' => ['files' => [123]]],
            ]),
        ];

        $registrations = $this->createMock(RegistrationRepository::class);
        $registrations->method('find')->willReturn($reg);

        $editionService = $this->createMock(EditionService::class);
        $editionRepository = $this->createMock(EditionRepository::class);
        $editionRepository->method('find')->willReturn(new \WP_Post(['post_title' => 'E']));

        global $_test_users;
        $_test_users[42] = new \WP_User((object) ['ID' => 42, 'display_name' => 'Test User']);

        $controller = new RegistrationModalController(
            $editionService,
            $editionRepository,
            $this->createMock(SessionService::class),
            $this->createMock(SessionSelection::class),
            $registrations,
        );
        $result = $controller->buildPayload(1, 'enrollment');

        unset($_test_users[42]);

        // The unit test does not stub wp_get_attachment_url; just verify the section
        // renders something other than the empty-state when files exist.
        self::assertStringNotContainsString(
            'Geen documenten geüpload',
            $result['html'],
        );
    }

    public function testCompletionModalRendersTasksAndProgress(): void
    {
        $reg = (object) [
            'id' => 1, 'user_id' => 42, 'edition_id' => 99,
            'enrollment_data' => '{}',
            'completion_tasks' => wp_json_encode([
                'questionnaire' => ['status' => 'completed', 'completed_at' => '2026-05-01 10:00:00'],
                'documents'     => ['status' => 'pending'],
            ]),
        ];

        $registrations = $this->createMock(RegistrationRepository::class);
        $registrations->method('find')->willReturn($reg);

        $editionService = $this->createMock(EditionService::class);
        $editionRepository = $this->createMock(EditionRepository::class);
        $editionRepository->method('find')->willReturn(new \WP_Post(['post_title' => 'E']));
        $editionService->method('getCourseId')->willReturn(777);

        global $_test_users;
        $_test_users[42] = new \WP_User((object) ['ID' => 42, 'display_name' => 'Test User']);

        $controller = new RegistrationModalController(
            $editionService,
            $editionRepository,
            $this->createMock(SessionService::class),
            $this->createMock(SessionSelection::class),
            $registrations,
        );
        $result = $controller->buildPayload(1, 'completion');

        unset($_test_users[42]);

        self::assertStringContainsString('Voltooiing', $result['title']);
        self::assertStringContainsString('Vragenlijst', $result['html']);
        self::assertStringContainsString('Documenten', $result['html']);
    }
}
