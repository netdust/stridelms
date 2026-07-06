<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Modules\User;

use Stride\Modules\User\ProfileTypeService;
use Stride\Modules\User\ProfiletypeRulesSanitizer;
use Stride\Tests\TestCase;

/**
 * Cluster-3 hardening for the profile-type rules sanitizer (findings [3] + [4]).
 *
 * The array branch of sanitize() (the metabox posts a nested array of raw,
 * slashed $_POST values) must:
 *   [3] wp_unslash the voucher BEFORE sanitize_text_field — matching the
 *       JSON-string branch — so an escaped char (apostrophe/backslash) is not
 *       persisted with a stray backslash and then fails to resolve at enroll.
 *   [4] guard a non-string voucher value: (string) on an ARRAY emits an
 *       "Array to string conversion" warning and would store literal "Array";
 *       a non-string voucher must be dropped to empty (null), no warning.
 *
 * ProfileTypeService::getTypes() is mocked to the allowlist the sanitizer keys on.
 */
final class ProfiletypeRulesSanitizerTest extends TestCase
{
    private ProfileTypeService $profileTypes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->profileTypes = $this->createMock(ProfileTypeService::class);
        $this->profileTypes
            ->method('getTypes')
            ->willReturn([
                ['slug' => 'vrijwilliger', 'label' => 'Vrijwilliger'],
            ]);
    }

    private function sanitizer(): ProfiletypeRulesSanitizer
    {
        return new ProfiletypeRulesSanitizer($this->profileTypes);
    }

    /**
     * [3] A slashed voucher in the ARRAY branch must be unslashed before
     * sanitizing — so the stored code has no stray backslash.
     */
    public function testArrayBranchUnslashesVoucherBeforeSanitizing(): void
    {
        // Simulate WP's slashed request: O'Brien-25 arrives as O\'Brien-25.
        $raw = [
            'vrijwilliger' => ['block' => false, 'minimal' => false, 'voucher' => "O\\'Brien-25"],
        ];

        $clean = $this->sanitizer()->sanitize($raw);

        $this->assertSame(
            "O'Brien-25",
            $clean['vrijwilliger']['voucher'],
            'the voucher must be wp_unslash-ed before sanitize_text_field — no stray backslash may survive',
        );
    }

    /**
     * [4] A non-string (array) voucher value must be dropped to null without
     * emitting an "Array to string conversion" warning.
     */
    public function testArrayBranchDropsNonStringVoucherWithoutWarning(): void
    {
        $raw = [
            'vrijwilliger' => ['block' => false, 'minimal' => false, 'voucher' => ['unexpected' => 'array']],
        ];

        // Fail the test if PHP raises the Array-to-string conversion warning.
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \RuntimeException("unexpected PHP warning: {$errstr}");
        });

        try {
            $clean = $this->sanitizer()->sanitize($raw);
        } finally {
            restore_error_handler();
        }

        $this->assertNull(
            $clean['vrijwilliger']['voucher'],
            'a non-string voucher must be dropped to null — never coerced to the literal string "Array"',
        );
    }
}
