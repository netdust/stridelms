<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Modules\Enrollment;

use ReflectionClass;
use ReflectionMethod;
use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Tests\TestCase;
use WP_Error;

/**
 * Tests EnrollmentService::resolveLeadAccount() — the INV-9 convergence point:
 * collision-safe find-or-create for anonymous waitlist leads.
 *
 * Tier A: find-or-create + email validation + the collision-safe credential
 * decision (an existing account must NEVER receive a credential/notification).
 *
 * This method resolves the ACCOUNT only — no mail, no billing-meta write.
 */
final class ResolveLeadAccountTest extends TestCase
{
    /**
     * Invoke the private resolveLeadAccount() without booting the heavy
     * AbstractService constructor (which registers hooks). Mirrors the
     * newInstanceWithoutConstructor idiom used by sibling Enrollment tests.
     *
     * @return array{user_id:int, was_existing:bool}|WP_Error
     */
    private function resolve(string $email, string $name): array|WP_Error
    {
        $service = (new ReflectionClass(EnrollmentService::class))
            ->newInstanceWithoutConstructor();

        $method = new ReflectionMethod(EnrollmentService::class, 'resolveLeadAccount');
        $method->setAccessible(true);

        return $method->invoke($service, $email, $name);
    }

    protected function setUp(): void
    {
        parent::setUp();
        global $_test_new_user_notification_calls;
        $_test_new_user_notification_calls = 0;
    }

    // (a) unknown email → creates an active user, was_existing=false, name set
    public function testUnknownEmailCreatesNewActiveUser(): void
    {
        global $_test_users;

        $result = $this->resolve('newlead@example.test', 'Jan Janssen');

        $this->assertIsArray($result, 'Unknown email should resolve to a created account.');
        $this->assertFalse($result['was_existing'], 'A freshly created account is not pre-existing.');
        $this->assertGreaterThan(0, $result['user_id']);

        // The user actually exists in the stubbed user store.
        $this->assertArrayHasKey($result['user_id'], $_test_users);

        // Name was set on the new user (first/last/display from the lead name).
        $created = $_test_users[$result['user_id']];
        $this->assertSame('Jan', $created->first_name);
        $this->assertSame('Janssen', $created->last_name);
    }

    // (b) existing email → returns that ID, was_existing=true, NO credential mail
    public function testExistingEmailReturnsExistingIdWithoutCredentialMail(): void
    {
        global $_test_new_user_notification_calls;

        $existing = $this->createUser([
            'ID' => 4242,
            'user_email' => 'known@example.test',
            'user_login' => 'known',
        ]);

        $usersBefore = count($GLOBALS['_test_users']);

        $result = $this->resolve('known@example.test', 'Imposter Name');

        $this->assertIsArray($result);
        $this->assertTrue($result['was_existing'], 'A matched account must be flagged as existing.');
        $this->assertSame($existing->ID, $result['user_id']);

        // M-COLLISION-SAFE / attack 6: no credential/welcome notification to an
        // existing account, and no new user created.
        $this->assertSame(
            0,
            $_test_new_user_notification_calls,
            'wp_new_user_notification must NOT fire for a matched existing account.',
        );
        $this->assertCount(
            $usersBefore,
            $GLOBALS['_test_users'],
            'No new user may be created when the email already has an account.',
        );

        // No name overwrite on the existing account (no profile mutation here).
        $this->assertNotSame('Imposter', $GLOBALS['_test_users'][$existing->ID]->first_name);
    }

    // (c) malformed / empty email → WP_Error('lead_no_email'), no user created
    public function testMalformedEmailReturnsWpErrorAndCreatesNoUser(): void
    {
        global $_test_users, $_test_new_user_notification_calls;

        $usersBefore = count($_test_users);

        $result = $this->resolve('not-an-email', 'Jan Janssen');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('lead_no_email', $result->get_error_code());
        $this->assertCount($usersBefore, $_test_users, 'A malformed email must create no user.');
        $this->assertSame(0, $_test_new_user_notification_calls);
    }

    public function testEmptyEmailReturnsWpErrorAndCreatesNoUser(): void
    {
        global $_test_users;

        $usersBefore = count($_test_users);

        $result = $this->resolve('', 'Jan Janssen');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('lead_no_email', $result->get_error_code());
        $this->assertCount($usersBefore, $_test_users, 'An empty email must create no user.');
    }
}
