<?php

declare(strict_types=1);

use Tests\Support\AcceptanceTester;

/**
 * Questionnaire / form-builder acceptance tests.
 *
 * Covers the three contracts of the admin builder (page=stride-questionnaire):
 *
 *  1. PLACEMENT — a group added in the builder lands in the chosen stage and
 *     assignment, through the real admin save path (nonce + sanitization).
 *     WP-internal field names (user_pass, …) are stripped on save.
 *  2. RENDERING — assigned groups render on exactly their stage's form
 *     (interest vs waitlist), and an all-types group renders every field
 *     type on the enrollment wizard.
 *  3. STORAGE — submitted extra-field values land in the registration's
 *     enrollment_data stage envelope; CRM-reserved field names
 *     (EnrollmentService::getUserMetaMapping: phone, organisation, …) are
 *     ALWAYS persisted to wp_usermeta on the participant; missing required
 *     custom fields are refused server-side.
 *
 * Self-sufficient: groups are written to the option directly (render/storage
 * tests) or created through the real builder UI (placement test) — no seed
 * dependency. Field groups live in ONE wp_option
 * (stride_questionnaire_field_groups); _before snapshots the raw option and
 * _after restores it byte-identical.
 */
class QuestionnaireBuilderCest
{
    private const OPTION_NAME = 'stride_questionnaire_field_groups';

    private int $testCourseId;
    private int $testEditionId;
    private int $testUserId;
    private string $testUserEmail;
    private string $stamp;

    private ?string $originalGroupsRaw = null;

    public function _before(AcceptanceTester $I): void
    {
        $this->stamp = time() . '_' . substr(md5((string) microtime(true)), 0, 4);

        // Snapshot the field-groups option (raw/serialized) for restore.
        $raw = $I->grabFromDatabase($I->grabPrefixedTableNameFor('options'), 'option_value', [
            'option_name' => self::OPTION_NAME,
        ]);
        $this->originalGroupsRaw = is_string($raw) ? $raw : null;

        $this->testCourseId = $I->havePostInDatabase([
            'post_type'   => 'sfwd-courses',
            'post_title'  => 'QB Course ' . $this->stamp,
            'post_status' => 'publish',
        ]);
        $this->testEditionId = $I->havePostInDatabase([
            'post_type'   => 'vad_edition',
            'post_title'  => 'QB Edition ' . $this->stamp,
            'post_name'   => 'qb-edition-' . $this->stamp,
            'post_status' => 'publish',
        ]);
        $I->havePostmetaInDatabase($this->testEditionId, '_ntdst_course_id', $this->testCourseId);
        $I->havePostmetaInDatabase($this->testEditionId, '_ntdst_price', 100);
        $I->havePostmetaInDatabase($this->testEditionId, '_ntdst_status', 'open');
        $I->havePostmetaInDatabase($this->testEditionId, '_ntdst_capacity', 20);
        $I->havePostmetaInDatabase($this->testEditionId, '_ntdst_start_date', date('Y-m-d', strtotime('+30 days')));

        $this->testUserEmail = 'qb_' . $this->stamp . '@test.local';
        $this->testUserId = $I->haveUserInDatabase('qb_' . $this->stamp, 'subscriber', [
            'user_email'   => $this->testUserEmail,
            'display_name' => 'QB Tester',
        ]);
        $I->haveUserMetaInDatabase($this->testUserId, 'first_name', 'QB');
        $I->haveUserMetaInDatabase($this->testUserId, 'last_name', 'Tester');
    }

    public function _after(AcceptanceTester $I): void
    {
        $table = $I->grabPrefixedTableNameFor('options');
        $I->dontHaveInDatabase($table, ['option_name' => self::OPTION_NAME]);
        if ($this->originalGroupsRaw !== null) {
            $I->haveInDatabase($table, [
                'option_name'  => self::OPTION_NAME,
                'option_value' => $this->originalGroupsRaw,
                'autoload'     => 'yes',
            ]);
        }

        $I->dontHaveInDatabase($I->grabPrefixedTableNameFor('vad_registrations'), [
            'edition_id' => $this->testEditionId,
        ]);
    }

    private function setEditionStatus(AcceptanceTester $I, string $status): void
    {
        $I->updateInDatabase(
            $I->grabPrefixedTableNameFor('postmeta'),
            ['meta_value' => $status],
            ['post_id' => $this->testEditionId, 'meta_key' => '_ntdst_status'],
        );
    }

    /**
     * Replace the field-groups option directly (render/storage tests own
     * their fixture; the builder-save test goes through the admin UI instead).
     */
    private function putGroups(AcceptanceTester $I, array $groups): void
    {
        $table = $I->grabPrefixedTableNameFor('options');
        $I->dontHaveInDatabase($table, ['option_name' => self::OPTION_NAME]);
        $I->haveInDatabase($table, [
            'option_name'  => self::OPTION_NAME,
            'option_value' => serialize($groups),
            'autoload'     => 'no',
        ]);
    }

    /**
     * The all-types group mirroring the seed matrix's qg_enrollment_seed:
     * every field type plus two CRM-reserved names (phone, organisation).
     */
    private function allTypesEnrollmentGroup(): array
    {
        return [
            'id'          => 'qg_alltypes_' . $this->stamp,
            'label'       => 'Extra inschrijvingsvragen',
            'stage'       => 'enrollment_personal',
            'assignments' => [$this->testEditionId],
            'fields'      => [
                ['label' => 'Toelichting', 'name' => 'intro_desc', 'type' => 'description',
                 'description' => 'Deze gegevens gebruiken we om de opleiding af te stemmen.'],
                ['label' => 'Telefoonnummer', 'name' => 'phone', 'type' => 'text', 'required' => true],
                ['label' => 'Organisatie', 'name' => 'organisation', 'type' => 'text', 'required' => true],
                ['label' => 'Motivatie', 'name' => 'motivatie', 'type' => 'textarea', 'required' => true],
                ['label' => 'Functie', 'name' => 'functie', 'type' => 'select', 'required' => true,
                 'options' => 'Leerkracht, Sportcoach, Andere'],
                ['label' => 'Ervaring met jeugdsport', 'name' => 'ervaring', 'type' => 'radio', 'required' => true,
                 'options' => 'Geen, 1-3 jaar, Meer dan 3 jaar'],
                ['label' => 'Ik wil de nieuwsbrief ontvangen', 'name' => 'nieuwsbrief', 'type' => 'checkbox', 'required' => false],
                ['label' => 'Hoe schat je je voorkennis in?', 'name' => 'voorkennis_schaal', 'type' => 'scale',
                 'required' => true, 'min' => 1, 'max' => 5],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function grabGroups(AcceptanceTester $I): array
    {
        $raw = (string) $I->grabFromDatabase($I->grabPrefixedTableNameFor('options'), 'option_value', [
            'option_name' => self::OPTION_NAME,
        ]);
        $groups = unserialize($raw);

        return is_array($groups) ? $groups : [];
    }

    // =========================================================================
    // 1. PLACEMENT — builder save path
    // =========================================================================

    /**
     * @test
     */
    public function builderSavesGroupToChosenStageAndStripsDeniedNames(AcceptanceTester $I): void
    {
        $I->wantTo('add a field group via the admin builder and find it stored on the chosen stage');

        $adminId = $I->grabAdminUserId();
        $I->loginAsUserId($adminId, '/wp/wp-admin/admin.php?page=stride-questionnaire');
        $I->waitForElement('.qb-app', 10);
        $I->wait(1); // Alpine boot

        // Build the JSON payload from the live builder state plus our group,
        // write it into the form's hidden input DIRECTLY (the :value binding
        // only refreshes on Alpine's next tick — too late for a same-tick
        // submit), then submit the real form so nonce + handleSave run.
        $editionId = $this->testEditionId;
        $stamp = $this->stamp;
        $I->executeJS(<<<JS
            const comp = Alpine.\$data(document.querySelector('.qb-app'));
            const groups = [...comp.groups, {
                id: 'qg_new_acceptance',
                label: 'Acceptance Testgroep {$stamp}',
                stage: 'interest',
                assignments: [{$editionId}],
                fields: [
                    { label: 'T-shirt maat', name: 'tshirt_maat', type: 'text', required: true },
                    { label: 'Hacked', name: 'user_pass', type: 'text', required: false },
                ],
            }];
            const form = document.querySelector('.qb-app form');
            form.querySelector('input[name="stride_questionnaire_groups_json"]').value = JSON.stringify(groups);
            form.submit();
        JS);
        $I->wait(2);

        $saved = null;
        foreach ($this->grabGroups($I) as $group) {
            if (($group['label'] ?? '') === 'Acceptance Testgroep ' . $this->stamp) {
                $saved = $group;
                break;
            }
        }

        \PHPUnit\Framework\Assert::assertNotNull($saved, 'builder-saved group must be in the option');
        \PHPUnit\Framework\Assert::assertSame('interest', $saved['stage'], 'group must keep its chosen stage');
        \PHPUnit\Framework\Assert::assertContains($this->testEditionId, array_map('intval', $saved['assignments'] ?? []), 'group must keep its edition assignment');

        $names = array_column($saved['fields'] ?? [], 'name');
        \PHPUnit\Framework\Assert::assertContains('tshirt_maat', $names, 'custom field must survive the save');
        \PHPUnit\Framework\Assert::assertNotContains('user_pass', $names, 'WP-internal field names must be stripped on save');
    }

    // =========================================================================
    // 2. RENDERING — stage placement + storage of the rendered field
    // =========================================================================

    /**
     * @test
     */
    public function groupRendersOnItsOwnStageOnlyAndValueLandsInEnvelope(AcceptanceTester $I): void
    {
        $I->wantTo('see a group on its assigned stage only, and its submitted value in the stage envelope');

        $this->setEditionStatus($I, 'announcement');
        $this->putGroups($I, [
            [
                'id' => 'qg_int_' . $this->stamp, 'label' => 'Interesse extra',
                'stage' => 'interest', 'assignments' => [$this->testEditionId],
                'fields' => [
                    ['label' => 'Hoe ken je ons?', 'name' => 'bron', 'type' => 'text', 'required' => false],
                ],
            ],
            [
                'id' => 'qg_wl_' . $this->stamp, 'label' => 'Wachtlijst extra',
                'stage' => 'waitlist', 'assignments' => [$this->testEditionId],
                'fields' => [
                    ['label' => 'Voorkeursperiode', 'name' => 'voorkeursperiode', 'type' => 'text', 'required' => false],
                ],
            ],
        ]);

        // Stage placement is server-rendered — assert against page source
        // (parts of the form sit behind Alpine templates until boot).
        $I->amOnPage('/interesse/?editie=' . $this->testEditionId);
        $I->waitForElement('#interest_name', 10);
        $source = $I->grabPageSource();
        \PHPUnit\Framework\Assert::assertStringContainsString('Hoe ken je ons?', $source, 'interest group must render on the interest form');
        \PHPUnit\Framework\Assert::assertStringNotContainsString('Voorkeursperiode', $source, 'waitlist group must NOT render on the interest form');

        $I->amOnPage('/wachtlijst/?editie=' . $this->testEditionId);
        $I->waitForElement('#waitlist_name', 10);
        $source = $I->grabPageSource();
        \PHPUnit\Framework\Assert::assertStringContainsString('Voorkeursperiode', $source, 'waitlist group must render on the waitlist form');
        \PHPUnit\Framework\Assert::assertStringNotContainsString('Hoe ken je ons?', $source, 'interest group must NOT render on the waitlist form');

        // Submit the interest form with the extra field filled → value must
        // land inside the interest stage envelope.
        $email = 'qb_interest_' . $this->stamp . '@test.local';
        $I->amOnPage('/interesse/?editie=' . $this->testEditionId);
        $I->waitForElement('#interest_name', 10);
        $I->wait(1); // Alpine boot (extra fields are x-model bound)
        $I->fillField('#interest_name', 'QB Interest');
        $I->fillField('#interest_email', $email);
        $I->fillField('#extra_field_bron', 'Via een collega');
        $I->click('button[type="submit"]');
        $I->waitForText('Je interesse is geregistreerd', 10);

        $raw = (string) $I->grabFromDatabase($I->grabPrefixedTableNameFor('vad_registrations'), 'enrollment_data', [
            'edition_id' => $this->testEditionId,
        ]);
        $data = json_decode($raw, true);
        \PHPUnit\Framework\Assert::assertSame(
            'Via een collega',
            $data['interest']['data']['bron'] ?? null,
            'extra field value must be stored inside the interest stage envelope'
        );
    }

    /**
     * @test
     */
    public function allFieldTypesRenderOnEnrollmentForm(AcceptanceTester $I): void
    {
        $I->wantTo('see every field type of an assigned group on the enrollment wizard');

        $this->putGroups($I, [$this->allTypesEnrollmentGroup()]);

        $I->loginAsUserId($this->testUserId, '/edities/' . $this->testEditionId . '/inschrijving/');
        $I->waitForElement('form', 10);

        // Fields are server-rendered into the wizard regardless of the active
        // step (steps are x-show hidden) — assert against the page source.
        $source = $I->grabPageSource();
        foreach (
            [
                'Motivatie',                        // textarea
                'Functie',                          // select
                'Sportcoach',                       // select option
                'Ervaring met jeugdsport',          // radio
                'Hoe schat je je voorkennis in?',   // scale
                'Ik wil de nieuwsbrief ontvangen',  // checkbox
                'extra_field_motivatie',            // dynamic-field input id
            ] as $expected
        ) {
            \PHPUnit\Framework\Assert::assertStringContainsString(
                $expected,
                $source,
                "enrollment form must render '{$expected}' from the assigned field group"
            );
        }
    }

    // =========================================================================
    // 3. STORAGE — envelope + reserved CRM fields to wp_usermeta
    // =========================================================================

    private function submitEnrollment(AcceptanceTester $I, array $extraFields): void
    {
        $I->executeJS("
            window.__qbResult = null;
            ntdstAPI.call('stride_submit_enrollment', {
                item_type: 'edition',
                edition_id: {$this->testEditionId},
                enrollment_type: 'self',
                first_name: 'QB',
                last_name: 'Tester',
                email: '{$this->testUserEmail}',
                phone: '',
                terms_accepted: true,
                extra_fields: " . json_encode($extraFields) . ",
            }).then(r => window.__qbResult = { ok: true })
              .catch(e => window.__qbResult = { error: e.message || 'refused' });
        ");
        $I->waitForJS('return window.__qbResult !== null;', 10);
    }

    /**
     * @test
     */
    public function enrollmentStoresCustomFieldsInEnvelopeAndReservedFieldsAsUserMeta(AcceptanceTester $I): void
    {
        $I->wantTo('verify custom answers land in enrollment_data and CRM-reserved fields land in wp_usermeta');

        $this->putGroups($I, [$this->allTypesEnrollmentGroup()]);

        $I->loginAsUserId($this->testUserId, '/edities/' . $this->testEditionId . '/inschrijving/');
        $I->waitForElement('form', 10);

        $this->submitEnrollment($I, [
            'motivatie'         => 'Ik wil jeugdsport veiliger maken',
            'functie'           => 'Sportcoach',
            'ervaring'          => '1-3 jaar',
            'voorkennis_schaal' => '4',
            'nieuwsbrief'       => true,
            'phone'             => '+32477112233',          // reserved → usermeta 'phone'
            'organisation'      => 'QB Sportclub vzw',      // reserved → usermeta 'organisation'
        ]);

        $ok = (bool) $I->executeJS('return !!(window.__qbResult && window.__qbResult.ok);');
        $error = (string) $I->executeJS('return window.__qbResult && window.__qbResult.error || "";');
        \PHPUnit\Framework\Assert::assertTrue($ok, 'enrollment with all required custom fields must be accepted, got: ' . $error);

        // Registration row + custom answers in the personal stage envelope.
        $raw = (string) $I->grabFromDatabase($I->grabPrefixedTableNameFor('vad_registrations'), 'enrollment_data', [
            'user_id'    => $this->testUserId,
            'edition_id' => $this->testEditionId,
        ]);
        $data = json_decode($raw, true);
        $personal = $data['enrollment_personal']['data'] ?? [];
        \PHPUnit\Framework\Assert::assertSame('Ik wil jeugdsport veiliger maken', $personal['motivatie'] ?? null);
        \PHPUnit\Framework\Assert::assertSame('Sportcoach', $personal['functie'] ?? null);
        \PHPUnit\Framework\Assert::assertSame('1-3 jaar', $personal['ervaring'] ?? null);

        // CRM-reserved names must ALWAYS persist to the participant's usermeta.
        $I->seeInDatabase($I->grabPrefixedTableNameFor('usermeta'), [
            'user_id'    => $this->testUserId,
            'meta_key'   => 'phone',
            'meta_value' => '+32477112233',
        ]);
        $I->seeInDatabase($I->grabPrefixedTableNameFor('usermeta'), [
            'user_id'    => $this->testUserId,
            'meta_key'   => 'organisation',
            'meta_value' => 'QB Sportclub vzw',
        ]);
    }

    /**
     * @test
     */
    public function serverRefusesEnrollmentMissingRequiredCustomField(AcceptanceTester $I): void
    {
        $I->wantTo('verify the server refuses an enrollment missing a required custom field');

        $this->putGroups($I, [$this->allTypesEnrollmentGroup()]);

        $I->loginAsUserId($this->testUserId, '/edities/' . $this->testEditionId . '/inschrijving/');
        $I->waitForElement('form', 10);

        // Everything valid EXCEPT the required 'motivatie' is missing.
        $this->submitEnrollment($I, [
            'functie'           => 'Sportcoach',
            'ervaring'          => '1-3 jaar',
            'voorkennis_schaal' => '4',
            'phone'             => '+32477112233',
            'organisation'      => 'QB Sportclub vzw',
        ]);

        $refused = (bool) $I->executeJS('return !!(window.__qbResult && window.__qbResult.error);');
        \PHPUnit\Framework\Assert::assertTrue($refused, 'missing required custom field must refuse the enrollment');

        $I->dontSeeInDatabase($I->grabPrefixedTableNameFor('vad_registrations'), [
            'user_id'    => $this->testUserId,
            'edition_id' => $this->testEditionId,
        ]);
    }
}
