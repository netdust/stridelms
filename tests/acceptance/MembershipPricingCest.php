<?php

declare(strict_types=1);

use Tests\Support\AcceptanceTester;

/**
 * Membership Pricing Acceptance Tests
 *
 * Verifies that member and non-member users see correct prices
 * on edition detail pages and enrollment forms.
 *
 * Price meta is stored in euros (float) with _ntdst_ prefix.
 * Money::format() outputs "€ 250,00" style.
 */
class MembershipPricingCest
{
    private int $editionId;
    private string $editionSlug;
    private int $courseId;
    private int $memberUserId;
    private int $nonMemberUserId;

    /** Member price in euros */
    private float $memberPrice = 250.00;
    /** Non-member price in euros */
    private float $nonMemberPrice = 350.00;

    public function _before(AcceptanceTester $I): void
    {
        $timestamp = time();

        // Create test course
        $this->courseId = $I->havePostInDatabase([
            'post_type' => 'sfwd-courses',
            'post_title' => 'Pricing Test Course',
            'post_status' => 'publish',
        ]);

        // Create test edition with both prices
        $this->editionSlug = 'pricing-test-' . $timestamp;
        $this->editionId = $I->havePostInDatabase([
            'post_type' => 'vad_edition',
            'post_title' => 'Pricing Test Edition ' . $timestamp,
            'post_name' => $this->editionSlug,
            'post_status' => 'publish',
        ]);

        $I->havePostmetaInDatabase($this->editionId, '_ntdst_course_id', $this->courseId);
        $I->havePostmetaInDatabase($this->editionId, '_ntdst_price', $this->memberPrice);
        $I->havePostmetaInDatabase($this->editionId, '_ntdst_price_non_member', $this->nonMemberPrice);
        $I->havePostmetaInDatabase($this->editionId, '_ntdst_status', 'open');
        $I->havePostmetaInDatabase($this->editionId, '_ntdst_capacity', 20);
        $I->havePostmetaInDatabase($this->editionId, '_ntdst_start_date', date('Y-m-d', strtotime('+30 days')));
        $I->havePostmetaInDatabase($this->editionId, '_ntdst_venue', 'Antwerpen');

        // Create member user (is_vad_member = true)
        $this->memberUserId = $I->haveUserInDatabase('pricing_member_' . $timestamp, 'subscriber', [
            'user_email' => 'pricing_member_' . $timestamp . '@test.local',
            'display_name' => 'Member User',
        ]);
        $I->haveUserMetaInDatabase($this->memberUserId, 'first_name', 'Member');
        $I->haveUserMetaInDatabase($this->memberUserId, 'last_name', 'User');
        $I->haveUserMetaInDatabase($this->memberUserId, 'is_vad_member', '1');

        // Create non-member user (no is_vad_member meta)
        $this->nonMemberUserId = $I->haveUserInDatabase('pricing_nonmember_' . $timestamp, 'subscriber', [
            'user_email' => 'pricing_nonmember_' . $timestamp . '@test.local',
            'display_name' => 'NonMember User',
        ]);
        $I->haveUserMetaInDatabase($this->nonMemberUserId, 'first_name', 'NonMember');
        $I->haveUserMetaInDatabase($this->nonMemberUserId, 'last_name', 'User');
    }

    private function editionUrl(): string
    {
        return '/vormingen/' . $this->editionSlug . '/';
    }

    private function enrollmentUrl(): string
    {
        return '/vormingen/' . $this->editionId . '/inschrijving/';
    }

    // =========================================================================
    // EDITION DETAIL PAGE — PRICE DISPLAY
    // =========================================================================

    /**
     * @test
     */
    public function memberSeesLowerPriceOnEditionPage(AcceptanceTester $I): void
    {
        $I->wantTo('verify member user sees member price on edition detail page');

        $I->loginAsUserId($this->memberUserId, $this->editionUrl());
        $I->waitForElement('body', 10);

        $I->see('€ 250,00');
        $I->dontSee('€ 350,00');
        $I->dontSee('Fatal error');
    }

    /**
     * @test
     */
    public function nonMemberSeesHigherPriceOnEditionPage(AcceptanceTester $I): void
    {
        $I->wantTo('verify non-member user sees non-member price on edition detail page');

        $I->loginAsUserId($this->nonMemberUserId, $this->editionUrl());
        $I->waitForElement('body', 10);

        $I->see('€ 350,00');
        $I->dontSee('€ 250,00');
        $I->dontSee('Fatal error');
    }

    /**
     * @test
     */
    public function anonymousVisitorSeesNonMemberPriceOnEditionPage(AcceptanceTester $I): void
    {
        $I->wantTo('verify anonymous visitor sees non-member price on edition detail page');

        $I->amOnPage($this->editionUrl());
        $I->waitForElement('body', 10);

        $I->see('€ 350,00');
        $I->dontSee('€ 250,00');
        $I->dontSee('Fatal error');
    }

    // =========================================================================
    // ENROLLMENT FORM — SIDEBAR PRICE
    // =========================================================================

    /**
     * @test
     */
    public function memberSeesMemberPriceOnEnrollmentForm(AcceptanceTester $I): void
    {
        $I->wantTo('verify member sees member price in enrollment form sidebar');

        $I->loginAsUserId($this->memberUserId, $this->enrollmentUrl());
        $I->waitForElement('form', 10);

        $I->see('€ 250,00');
        $I->dontSee('€ 350,00');
        $I->dontSee('Fatal error');
    }

    /**
     * @test
     */
    public function nonMemberSeesNonMemberPriceOnEnrollmentForm(AcceptanceTester $I): void
    {
        $I->wantTo('verify non-member sees non-member price in enrollment form sidebar');

        $I->loginAsUserId($this->nonMemberUserId, $this->enrollmentUrl());
        $I->waitForElement('form', 10);

        $I->see('€ 350,00');
        $I->dontSee('€ 250,00');
        $I->dontSee('Fatal error');
    }

    // =========================================================================
    // EDGE CASES
    // =========================================================================

    /**
     * @test
     */
    public function editionWithZeroPriceShowsNoPrice(AcceptanceTester $I): void
    {
        $I->wantTo('verify zero-price edition does not show price');

        // Update edition to free
        $I->havePostmetaInDatabase($this->editionId, '_ntdst_price', 0);
        $I->havePostmetaInDatabase($this->editionId, '_ntdst_price_non_member', 0);

        $I->loginAsUserId($this->memberUserId, $this->editionUrl());
        $I->waitForElement('body', 10);

        // Should not show "€ 0,00" — zero price is typically hidden
        $I->dontSee('€ 0,00');
        $I->dontSee('Fatal error');
    }

    /**
     * @test
     */
    public function memberWithExplicitFalseSeesNonMemberPrice(AcceptanceTester $I): void
    {
        $I->wantTo('verify user with is_vad_member=0 sees non-member price');

        // Create user with explicit false membership
        $timestamp = time();
        $userId = $I->haveUserInDatabase('pricing_false_' . $timestamp, 'subscriber', [
            'user_email' => 'pricing_false_' . $timestamp . '@test.local',
        ]);
        $I->haveUserMetaInDatabase($userId, 'first_name', 'False');
        $I->haveUserMetaInDatabase($userId, 'last_name', 'Member');
        $I->haveUserMetaInDatabase($userId, 'is_vad_member', '0');

        $I->loginAsUserId($userId, $this->editionUrl());
        $I->waitForElement('body', 10);

        $I->see('€ 350,00');
        $I->dontSee('€ 250,00');
        $I->dontSee('Fatal error');
    }
}
