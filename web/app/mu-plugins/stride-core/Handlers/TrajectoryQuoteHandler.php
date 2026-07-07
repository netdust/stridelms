<?php

declare(strict_types=1);

namespace Stride\Handlers;

/**
 * Creates quotes when users enroll in trajectories (the event-driven path).
 *
 * Thin handler — listens for the dedicated `stride/trajectory/registration/created`
 * event (dispatched from TrajectorySelection::enroll()) and builds the trajectory
 * quote + attendee-keyed auto-voucher. Mirrors EnrollmentQuoteHandler (the edition
 * path) with trajectory-specific safety fixes from the adversarial review
 * (plan §4 A1-A5): namespaced pending-billing transient, editionScoped:false on
 * applyVoucher, and a body that can NEVER throw out of do_action (A3).
 *
 * ── SIGNATURE SHELL (test-author, RED-first) ────────────────────────────────
 * This class is the minimal shell the test-author committed so the contract test
 * (TrajectoryQuoteHandlerEventTest) fails BEHAVIORALLY (event fires → handler does
 * nothing → no quote) rather than "class not found". The BODY of
 * onTrajectoryRegistrationCreated is the IMPLEMENTER's to fill per plan Task 2
 * (idempotency guard, free-skip, quote build, billing, manual voucher, auto-voucher
 * redeem, link + transient clear) — all wrapped in try/catch(\Throwable) so it never
 * escapes do_action. The test above it is IMMUTABLE: green it without weakening it.
 */
final class TrajectoryQuoteHandler
{
    public function __construct()
    {
        add_action('stride/trajectory/registration/created', [$this, 'onTrajectoryRegistrationCreated']);
    }

    /**
     * Build the trajectory quote (+ auto-voucher) for a trajectory registration.
     *
     * @param array{registration_id: int, user_id: int, trajectory_id: int, enrolled_by?: int|null} $data
     */
    public function onTrajectoryRegistrationCreated(array $data): void
    {
        // SHELL: no logic. Implementer fills the body (plan Task 2). The empty
        // body is why the RED contract test fails behaviorally: the event reaches
        // the handler, but no quote is created.
    }
}
