# Flexible Membership Detection & Pricing — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make membership detection and pricing filterable so clients can override the logic, and fix the broken `is_member` meta key bug.

**Architecture:** Add `isMember()` method and update `getPrice()` signature on EditionService with two WordPress filters. Update all 7 call sites. Fix type mismatch in EnrollmentFormHandler.

**Tech Stack:** PHP 8.3, WordPress filters, NTDST Data Manager, Money value object

**Spec:** `docs/superpowers/specs/2026-03-21-flexible-membership-design.md`

---

### Task 1: Unit Tests for EditionService Membership & Pricing

**Files:**
- Create: `tests/Unit/EditionServicePricingTest.php`

- [ ] **Step 1: Write the test file**

```php
<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Domain\Money;
use Stride\Tests\TestCase;

class EditionServicePricingTest extends TestCase
{
    private EditionService $service;
    private EditionRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->createMock(EditionRepository::class);

        // EditionService extends AbstractService which calls init() in constructor.
        // We need to bypass that for unit testing.
        $this->service = $this->getMockBuilder(EditionService::class)
            ->setConstructorArgs([$this->repository])
            ->onlyMethods(['init'])
            ->getMock();
    }

    // === isMember() ===

    public function testIsMemberReturnsTrueWhenMetaIsSet(): void
    {
        $userId = 1;
        update_user_meta($userId, 'is_vad_member', true);

        $this->assertTrue($this->service->isMember($userId));
    }

    public function testIsMemberReturnsFalseWhenMetaNotSet(): void
    {
        $userId = 2;
        // No meta set

        $this->assertFalse($this->service->isMember($userId));
    }

    public function testIsMemberReturnsFalseWhenMetaIsFalse(): void
    {
        $userId = 3;
        update_user_meta($userId, 'is_vad_member', false);

        $this->assertFalse($this->service->isMember($userId));
    }

    public function testIsMemberFilterCanOverride(): void
    {
        $userId = 4;
        update_user_meta($userId, 'is_vad_member', false);

        // Register filter that always returns true
        add_filter('stride/membership/is_member', function (bool $isMember, int $uid): bool {
            return true; // Override: everyone is a member
        }, 10, 2);

        $this->assertTrue($this->service->isMember($userId));
    }

    public function testIsMemberFilterReceivesCorrectArgs(): void
    {
        $userId = 5;
        update_user_meta($userId, 'is_vad_member', true);

        $receivedArgs = [];
        add_filter('stride/membership/is_member', function (bool $isMember, int $uid) use (&$receivedArgs): bool {
            $receivedArgs = ['isMember' => $isMember, 'userId' => $uid];
            return $isMember;
        }, 10, 2);

        $this->service->isMember($userId);

        $this->assertTrue($receivedArgs['isMember']);
        $this->assertSame(5, $receivedArgs['userId']);
    }

    // === getPrice() ===

    public function testGetPriceWithoutUserIdReturnsNonMemberPrice(): void
    {
        $editionId = 100;

        $this->repository->method('getField')
            ->willReturnMap([
                [$editionId, 'price_non_member', 0, 350.0],
            ]);

        $price = $this->service->getPrice($editionId);

        $this->assertInstanceOf(Money::class, $price);
        $this->assertSame(35000, $price->inCents());
    }

    public function testGetPriceWithMemberUserIdReturnsMemberPrice(): void
    {
        $editionId = 100;
        $userId = 10;
        update_user_meta($userId, 'is_vad_member', true);

        $this->repository->method('getField')
            ->willReturnMap([
                [$editionId, 'price', 0, 250.0],
            ]);

        $price = $this->service->getPrice($editionId, $userId);

        $this->assertSame(25000, $price->inCents());
    }

    public function testGetPriceWithNonMemberUserIdReturnsNonMemberPrice(): void
    {
        $editionId = 100;
        $userId = 11;
        // No is_vad_member meta

        $this->repository->method('getField')
            ->willReturnMap([
                [$editionId, 'price_non_member', 0, 350.0],
            ]);

        $price = $this->service->getPrice($editionId, $userId);

        $this->assertSame(35000, $price->inCents());
    }

    public function testGetPriceFilterCanOverridePrice(): void
    {
        $editionId = 100;
        $userId = 12;
        update_user_meta($userId, 'is_vad_member', true);

        $this->repository->method('getField')
            ->willReturnMap([
                [$editionId, 'price', 0, 250.0],
            ]);

        // Apply 10% discount via filter
        add_filter('stride/membership/price', function (Money $price, int $eid, ?int $uid, bool $isMember): Money {
            if ($isMember) {
                return Money::cents((int) round($price->inCents() * 0.9));
            }
            return $price;
        }, 10, 4);

        $price = $this->service->getPrice($editionId, $userId);

        $this->assertSame(22500, $price->inCents());
    }

    public function testGetPriceFilterReceivesCorrectArgs(): void
    {
        $editionId = 100;
        $userId = 13;
        update_user_meta($userId, 'is_vad_member', true);

        $this->repository->method('getField')
            ->willReturnMap([
                [$editionId, 'price', 0, 200.0],
            ]);

        $receivedArgs = [];
        add_filter('stride/membership/price', function (Money $price, int $eid, ?int $uid, bool $isMember) use (&$receivedArgs): Money {
            $receivedArgs = compact('price', 'eid', 'uid', 'isMember');
            return $price;
        }, 10, 4);

        $this->service->getPrice($editionId, $userId);

        $this->assertSame(20000, $receivedArgs['price']->inCents());
        $this->assertSame(100, $receivedArgs['eid']);
        $this->assertSame(13, $receivedArgs['uid']);
        $this->assertTrue($receivedArgs['isMember']);
    }

    public function testGetPriceWithNullUserIdPassesNullToFilter(): void
    {
        $editionId = 100;

        $this->repository->method('getField')
            ->willReturnMap([
                [$editionId, 'price_non_member', 0, 350.0],
            ]);

        $receivedUid = 'not-called';
        add_filter('stride/membership/price', function (Money $price, int $eid, ?int $uid, bool $isMember) use (&$receivedUid): Money {
            $receivedUid = $uid;
            return $price;
        }, 10, 4);

        $this->service->getPrice($editionId);

        $this->assertNull($receivedUid);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit tests/Unit/EditionServicePricingTest.php`
Expected: FAIL — `isMember()` method does not exist yet on EditionService

- [ ] **Step 3: Commit test file**

```bash
git add tests/Unit/EditionServicePricingTest.php
git commit -m "test(edition): add unit tests for membership detection and flexible pricing"
```

---

### Task 2: Implement EditionService Changes

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/EditionService.php:204-213`

- [ ] **Step 1: Add `isMember()` method and update `getPrice()` signature**

Replace lines 204-213 in `EditionService.php`:

```php
    /**
     * Check if a user is a member.
     *
     * Uses `is_vad_member` user meta as default.
     * Override via `stride/membership/is_member` filter.
     */
    public function isMember(int $userId): bool
    {
        $isMember = (bool) get_user_meta($userId, 'is_vad_member', true);

        return (bool) apply_filters('stride/membership/is_member', $isMember, $userId);
    }

    /**
     * Get price for edition.
     *
     * When $userId is provided, checks membership for member pricing.
     * When null (anonymous/display), returns non-member price.
     *
     * Override via `stride/membership/price` filter.
     */
    public function getPrice(int $editionId, ?int $userId = null): Money
    {
        $isMember = $userId !== null ? $this->isMember($userId) : false;
        $field = $isMember ? 'price' : 'price_non_member';
        $amount = (float) $this->repository->getField($editionId, $field, 0);
        $price = Money::eur($amount);

        return apply_filters('stride/membership/price', $price, $editionId, $userId, $isMember);
    }
```

- [ ] **Step 2: Run unit tests to verify they pass**

Run: `ddev exec vendor/bin/phpunit tests/Unit/EditionServicePricingTest.php`
Expected: ALL tests pass

- [ ] **Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/EditionService.php
git commit -m "feat(edition): add filterable membership detection and pricing"
```

---

### Task 3: Update EnrollmentQuoteHandler Call Site

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Handlers/EnrollmentQuoteHandler.php:195-207`

- [ ] **Step 1: Simplify `getEditionPrice()` to delegate**

Replace lines 195-207:

```php
    /**
     * Get edition price for user.
     */
    private function getEditionPrice(int $editionId, int $userId): Money
    {
        $editionService = ntdst_get(\Stride\Modules\Edition\EditionService::class);

        return $editionService->getPrice($editionId, $userId);
    }
```

- [ ] **Step 2: Verify unit tests still pass**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit`
Expected: ALL pass

- [ ] **Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Handlers/EnrollmentQuoteHandler.php
git commit -m "refactor(enrollment): delegate pricing to EditionService filters"
```

---

### Task 4: Update EnrollmentFormHandler — Fix Type Mismatch

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Handlers/EnrollmentFormHandler.php:453-491`

- [ ] **Step 1: Fix `getItemPrice()` return type and caller**

Replace `getItemPrice` method (lines 480-491):

```php
    /**
     * Get price for an item (edition or trajectory).
     */
    private function getItemPrice(string $itemType, int $itemId): ?Money
    {
        if ($itemType === 'trajectory') {
            $trajectoryService = ntdst_get(TrajectoryService::class);
            $trajectory = $trajectoryService->getTrajectory($itemId);
            return $trajectory ? Money::eur((float) $trajectory['price']) : null;
        }

        // Default: edition — pass current user for member pricing
        $editions = ntdst_get(EditionService::class);
        $userId = get_current_user_id() ?: null;

        return $editions->getPrice($itemId, $userId);
    }
```

Fix the caller at lines 454-459. Replace:

```php
        // Get price based on item type
        $price = $this->getItemPrice($itemType, $itemId);
        if ($price === null) {
            return new WP_Error('invalid_item', __('Item niet gevonden.', 'stride'));
        }

        $subtotal = Money::cents((int) round($price * 100));
        $discount = $vouchers->calculateDiscount($validation, $subtotal);
```

With:

```php
        // Get price based on item type
        $price = $this->getItemPrice($itemType, $itemId);
        if ($price === null) {
            return new WP_Error('invalid_item', __('Item niet gevonden.', 'stride'));
        }

        $discount = $vouchers->calculateDiscount($validation, $price);
```

- [ ] **Step 2: Add Money import if missing**

Check if `use Stride\Domain\Money;` already exists at top of file. Add if missing.

- [ ] **Step 3: Verify unit tests still pass**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit`
Expected: ALL pass

- [ ] **Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/Handlers/EnrollmentFormHandler.php
git commit -m "fix(enrollment): fix getItemPrice type mismatch and pass userId for member pricing"
```

---

### Task 5: Update Template Call Sites

**Files:**
- Modify: `web/app/themes/stridence/templates/forms/enrollment.php:61-62`
- Modify: `web/app/themes/stridence/single-vad_edition.php:42`

- [ ] **Step 1: Update enrollment.php template**

Replace lines 61-62 in `templates/forms/enrollment.php`:

```php
        $isMember     = (bool) get_user_meta($current_user->ID, 'is_member', true);
        $price        = $editionService->getPrice($item_id, $isMember);
```

With:

```php
        $price        = $editionService->getPrice($item_id, $current_user->ID);
```

- [ ] **Step 2: Update single-vad_edition.php template**

Replace line 42 in `single-vad_edition.php`:

```php
    $price      = $editionService->getPrice($edition_id);
```

With:

```php
    $price      = $editionService->getPrice($edition_id, get_current_user_id() ?: null);
```

- [ ] **Step 3: Commit**

```bash
git add web/app/themes/stridence/templates/forms/enrollment.php web/app/themes/stridence/single-vad_edition.php
git commit -m "fix(templates): use filterable pricing with user context"
```

---

## Verification Stages (MANDATORY)

> Run AFTER all implementation tasks. NOT done until all stages pass.
> If ANY stage fails: fix → re-run that stage → continue.

### Stage V1: Static Analysis

```bash
ddev exec vendor/bin/phpcs --standard=PSR12 web/app/mu-plugins/stride-core/Modules/Edition/EditionService.php web/app/mu-plugins/stride-core/Handlers/EnrollmentQuoteHandler.php web/app/mu-plugins/stride-core/Handlers/EnrollmentFormHandler.php
```

Expected: No errors. Fix all issues before proceeding.

### Stage V2: Unit Tests

**Test file:**
- `tests/Unit/EditionServicePricingTest.php`

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
```

Expected: ALL tests pass, including new pricing tests.

### Stage V3: Acceptance Tests (Browser)

**Test file to create:**
- `tests/acceptance/MembershipPricingCest.php`

**Scenarios to cover:**

```
VISITOR FLOW:
  SCENARIO: Anonymous visitor sees non-member price on edition page
    GIVEN: An edition with member price €250 and non-member price €350
    WHEN: Anonymous user visits the edition page
    THEN: Page shows €350 (non-member price)

  SCENARIO: Member user sees member price on edition page
    GIVEN: An edition with member price €250 and non-member price €350
    AND: A user with is_vad_member = true
    WHEN: User visits the edition page
    THEN: Page shows €250 (member price)

  SCENARIO: Non-member user sees non-member price on edition page
    GIVEN: An edition with member price €250 and non-member price €350
    AND: A user without is_vad_member
    WHEN: User visits the edition page
    THEN: Page shows €350 (non-member price)

ENROLLMENT FLOW:
  SCENARIO: Member sees member price on enrollment form
    GIVEN: An edition with member price €250 and non-member price €350
    AND: A logged-in member user
    WHEN: User visits the enrollment page
    THEN: Sidebar shows €250

  SCENARIO: Non-member sees non-member price on enrollment form
    GIVEN: An edition with both prices
    AND: A logged-in non-member user
    WHEN: User visits the enrollment page
    THEN: Sidebar shows €350
```

```bash
ddev exec vendor/bin/codecept run acceptance MembershipPricingCest --steps
```

Expected: ALL acceptance tests pass.

### Stage V4: Full Regression

```bash
ddev exec vendor/bin/codecept run
```

Expected: Zero failures across all suites.

### Stage V5: Smoke Test Checklist

```markdown
## Manual Smoke Test

- [ ] Visit: https://stride.ddev.site/vormingen/{any-edition-slug}/ (not logged in)
      Expected: Non-member price displayed, no console errors
- [ ] Visit same page logged in as seed_student1 (member)
      Expected: Member price displayed
- [ ] Visit same page logged in as seed_student3 (non-member)
      Expected: Non-member price displayed
- [ ] Visit enrollment form as seed_student1
      Expected: Sidebar shows member price
- [ ] Database: `ddev exec wp eval "echo (ntdst_get(\Stride\Modules\Edition\EditionService::class))->isMember(get_user_by('email','seed_student1@seed.test')->ID) ? 'member' : 'not member';"`
      Expected: "member"
```
