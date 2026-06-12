<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\SessionSelection;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Tests\TestCase;

/**
 * Regression for B-001: admin saves session slot pick-count as `max_selections`
 * (EditionSessionsMetabox.php), but business logic + completion UI read
 * `pick_count`. With N > 1, validation accepts under-selection.
 *
 * Tests use legacy `pick_count` key too to confirm back-compat.
 */
final class SessionSelectionSlotKeysTest extends TestCase
{
    private SessionService $sessions;
    private EditionRepository $editions;
    private RegistrationRepository $registrations;
    private SessionSelection $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sessions = $this->createMock(SessionService::class);
        $this->editions = $this->createMock(EditionRepository::class);
        $this->registrations = $this->createMock(RegistrationRepository::class);

        $this->service = new SessionSelection(
            $this->sessions,
            $this->editions,
            $this->registrations,
        );
    }

    public function testValidateSelectionsRejectsUnderSelectionWhenAdminSavedMaxSelectionsTwo(): void
    {
        // Admin configured: "Verdieping (kies 2)" with max_selections=2, required
        $this->editions->method('getField')
            ->with(99, 'session_slots')
            ->willReturn([
                ['slot' => 'verdieping', 'required' => true, 'max_selections' => 2],
            ]);

        // Slot has 3 sessions; user selected only 1
        $this->sessions->method('getSessionsBySlot')
            ->with(99, 'verdieping')
            ->willReturn([
                ['id' => 101],
                ['id' => 102],
                ['id' => 103],
            ]);
        $this->registrations->method('getSelections')
            ->with(50)
            ->willReturn([101]); // only one selection

        $result = $this->service->validateSelections(50, 99);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('incomplete_selection', $result->get_error_code());
    }

    public function testValidateSelectionsAcceptsCompleteSelectionWhenMaxSelectionsTwo(): void
    {
        $this->editions->method('getField')
            ->willReturn([
                ['slot' => 'verdieping', 'required' => true, 'max_selections' => 2],
            ]);

        $this->sessions->method('getSessionsBySlot')->willReturn([
            ['id' => 101], ['id' => 102], ['id' => 103],
        ]);
        $this->registrations->method('getSelections')
            ->willReturn([101, 103]); // two selections — meets max_selections=2

        $result = $this->service->validateSelections(50, 99);

        self::assertTrue($result);
    }

    public function testValidateSelectionsRespectsLegacyPickCountKey(): void
    {
        // Legacy DB rows: stored with `pick_count` (from old seed/JSON path).
        // Must still honour the value for back-compat.
        $this->editions->method('getField')
            ->willReturn([
                ['slot' => 'verdieping', 'required' => true, 'pick_count' => 2],
            ]);

        $this->sessions->method('getSessionsBySlot')->willReturn([
            ['id' => 101], ['id' => 102],
        ]);
        $this->registrations->method('getSelections')
            ->willReturn([101]); // only one — should fail under N=2 legacy too

        $result = $this->service->validateSelections(50, 99);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('incomplete_selection', $result->get_error_code());
    }

    public function testValidateSelectionsDefaultsToOneWhenNeitherKeyPresent(): void
    {
        $this->editions->method('getField')
            ->willReturn([
                ['slot' => 'verdieping', 'required' => true],
            ]);

        $this->sessions->method('getSessionsBySlot')->willReturn([['id' => 101]]);
        $this->registrations->method('getSelections')->willReturn([101]);

        $result = $this->service->validateSelections(50, 99);

        self::assertTrue($result);
    }
}
