<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Questionnaire;

use Stride\Modules\Questionnaire\QuestionnaireRepository;
use Stride\Tests\TestCase;

/**
 * Unit tests for QuestionnaireRepository
 *
 * Covers reading/writing field groups from wp_options and the various
 * assignment-matching strategies (direct ID, wildcard editions/trajectories).
 */
class QuestionnaireRepositoryTest extends TestCase
{
    private QuestionnaireRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new QuestionnaireRepository();
    }

    // -------------------------------------------------------------------------
    // getAllGroups
    // -------------------------------------------------------------------------

    /** @test */
    public function testGetAllGroupsReturnsEmptyArrayWhenNoGroups(): void
    {
        $groups = $this->repo->getAllGroups();

        $this->assertIsArray($groups);
        $this->assertEmpty($groups);
    }

    /** @test */
    public function testGetAllGroupsReturnsEmptyArrayWhenOptionIsNotArray(): void
    {
        update_option(QuestionnaireRepository::OPTION_KEY, 'corrupted');

        $groups = $this->repo->getAllGroups();

        $this->assertIsArray($groups);
        $this->assertEmpty($groups);
    }

    // -------------------------------------------------------------------------
    // saveGroups / getAllGroups round-trip
    // -------------------------------------------------------------------------

    /** @test */
    public function testSaveAndRetrieveGroups(): void
    {
        $groups = [
            [
                'id'          => 'fg_1',
                'label'       => 'Evaluatie docent',
                'stage'       => 'evaluation',
                'assignments' => [123, '_all_editions'],
                'fields'      => [
                    [
                        'type'     => 'scale',
                        'label'    => 'Kennis',
                        'name'     => 'kennis',
                        'min'      => 1,
                        'max'      => 5,
                        'required' => true,
                    ],
                ],
            ],
        ];

        $this->repo->saveGroups($groups);
        $retrieved = $this->repo->getAllGroups();

        $this->assertCount(1, $retrieved);
        $this->assertSame('fg_1', $retrieved[0]['id']);
        $this->assertSame('Evaluatie docent', $retrieved[0]['label']);
        $this->assertSame('evaluation', $retrieved[0]['stage']);
        $this->assertSame([123, '_all_editions'], $retrieved[0]['assignments']);
        $this->assertCount(1, $retrieved[0]['fields']);
        $this->assertSame('kennis', $retrieved[0]['fields'][0]['name']);
    }

    /** @test */
    public function testSaveGroupsOverwritesPreviousGroups(): void
    {
        $this->repo->saveGroups([['id' => 'fg_old', 'label' => 'Old', 'stage' => 'intake', 'assignments' => [], 'fields' => []]]);
        $this->repo->saveGroups([['id' => 'fg_new', 'label' => 'New', 'stage' => 'intake', 'assignments' => [], 'fields' => []]]);

        $retrieved = $this->repo->getAllGroups();

        $this->assertCount(1, $retrieved);
        $this->assertSame('fg_new', $retrieved[0]['id']);
    }

    // -------------------------------------------------------------------------
    // getGroupsForPost — assignment matching
    // -------------------------------------------------------------------------

    /** @test */
    public function testGetGroupsForPostMatchesDirectPostId(): void
    {
        $edition = $this->createEdition(['ID' => 500, 'post_type' => 'vad_edition']);

        $this->repo->saveGroups([
            $this->makeGroup('fg_direct', 'evaluation', [500]),
            $this->makeGroup('fg_other', 'evaluation', [999]),
        ]);

        $matched = $this->repo->getGroupsForPost(500, 'vad_edition');

        $this->assertCount(1, $matched);
        $this->assertSame('fg_direct', $matched[0]['id']);
    }

    /** @test */
    public function testGetGroupsForPostMatchesWildcardAllEditions(): void
    {
        $edition = $this->createEdition(['ID' => 501, 'post_type' => 'vad_edition']);

        $this->repo->saveGroups([
            $this->makeGroup('fg_wildcard', 'evaluation', ['_all_editions']),
            $this->makeGroup('fg_other', 'evaluation', ['_all_trajectories']),
        ]);

        $matched = $this->repo->getGroupsForPost(501, 'vad_edition');

        $this->assertCount(1, $matched);
        $this->assertSame('fg_wildcard', $matched[0]['id']);
    }

    /** @test */
    public function testGetGroupsForPostMatchesWildcardAllTrajectories(): void
    {
        $this->repo->saveGroups([
            $this->makeGroup('fg_traj', 'intake', ['_all_trajectories']),
            $this->makeGroup('fg_edition', 'intake', ['_all_editions']),
        ]);

        $matched = $this->repo->getGroupsForPost(600, 'vad_trajectory');

        $this->assertCount(1, $matched);
        $this->assertSame('fg_traj', $matched[0]['id']);
    }

    /** @test */
    public function testGetGroupsForPostAutoDetectsPostType(): void
    {
        // Register a post so get_post_type() can resolve it
        $this->createEdition(['ID' => 502, 'post_type' => 'vad_edition']);

        $this->repo->saveGroups([
            $this->makeGroup('fg_auto', 'evaluation', ['_all_editions']),
        ]);

        // Pass no postType — should auto-detect via get_post_type()
        $matched = $this->repo->getGroupsForPost(502);

        $this->assertCount(1, $matched);
        $this->assertSame('fg_auto', $matched[0]['id']);
    }

    /** @test */
    public function testGetGroupsForPostReturnsEmptyWhenNoMatch(): void
    {
        $this->repo->saveGroups([
            $this->makeGroup('fg_1', 'evaluation', [999]),
        ]);

        $matched = $this->repo->getGroupsForPost(123, 'vad_edition');

        $this->assertEmpty($matched);
    }

    // -------------------------------------------------------------------------
    // getGroupsForStage
    // -------------------------------------------------------------------------

    /** @test */
    public function testGetGroupsForStageFiltersCorrectly(): void
    {
        $this->repo->saveGroups([
            $this->makeGroup('fg_eval', 'evaluation', ['_all_editions']),
            $this->makeGroup('fg_intake', 'intake', ['_all_editions']),
            $this->makeGroup('fg_enroll', 'enrollment_personal', ['_all_editions']),
        ]);

        $matched = $this->repo->getGroupsForStage(100, 'evaluation', 'vad_edition');

        $this->assertCount(1, $matched);
        $this->assertSame('fg_eval', $matched[0]['id']);
    }

    /** @test */
    public function testGetGroupsForStageReturnsMultipleGroupsForSameStage(): void
    {
        $this->repo->saveGroups([
            $this->makeGroup('fg_eval_a', 'evaluation', ['_all_editions']),
            $this->makeGroup('fg_eval_b', 'evaluation', [100]),
            $this->makeGroup('fg_intake', 'intake', ['_all_editions']),
        ]);

        $matched = $this->repo->getGroupsForStage(100, 'evaluation', 'vad_edition');

        $this->assertCount(2, $matched);
        $ids = array_column($matched, 'id');
        $this->assertContains('fg_eval_a', $ids);
        $this->assertContains('fg_eval_b', $ids);
    }

    /** @test */
    public function testGetGroupsForStageReturnsEmptyWhenStageHasNoGroups(): void
    {
        $this->repo->saveGroups([
            $this->makeGroup('fg_eval', 'evaluation', ['_all_editions']),
        ]);

        $matched = $this->repo->getGroupsForStage(100, 'interest', 'vad_edition');

        $this->assertEmpty($matched);
    }

    // -------------------------------------------------------------------------
    // getFlatFieldsForStage
    // -------------------------------------------------------------------------

    /** @test */
    public function testGetFlatFieldsForStage(): void
    {
        $fields1 = [
            ['type' => 'scale', 'label' => 'Kennis', 'name' => 'kennis', 'min' => 1, 'max' => 5, 'required' => true],
            ['type' => 'description', 'label' => 'Sectie intro'],
        ];
        $fields2 = [
            ['type' => 'textarea', 'label' => 'Opmerkingen', 'name' => 'comments', 'required' => false],
        ];

        $group1 = $this->makeGroup('fg_eval_a', 'evaluation', ['_all_editions'], $fields1);
        $group2 = $this->makeGroup('fg_eval_b', 'evaluation', ['_all_editions'], $fields2);

        $this->repo->saveGroups([$group1, $group2]);

        $flat = $this->repo->getFlatFieldsForStage(100, 'evaluation', 'vad_edition');

        $this->assertCount(3, $flat);
        $this->assertSame('kennis', $flat[0]['name']);
        $this->assertSame('description', $flat[1]['type']);
        $this->assertSame('comments', $flat[2]['name']);
    }

    /** @test */
    public function testGetFlatFieldsForStageReturnsEmptyWhenNoGroupsMatch(): void
    {
        $this->repo->saveGroups([
            $this->makeGroup('fg_eval', 'evaluation', ['_all_editions']),
        ]);

        $flat = $this->repo->getFlatFieldsForStage(100, 'interest', 'vad_edition');

        $this->assertEmpty($flat);
    }

    /** @test */
    public function testGetFlatFieldsForStageReturnsEmptyWhenGroupHasNoFields(): void
    {
        $this->repo->saveGroups([
            $this->makeGroup('fg_empty', 'evaluation', ['_all_editions'], []),
        ]);

        $flat = $this->repo->getFlatFieldsForStage(100, 'evaluation', 'vad_edition');

        $this->assertEmpty($flat);
    }

    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    /** @test */
    public function testStagesConstantContainsAllExpectedStages(): void
    {
        $expected = ['interest', 'enrollment_personal', 'enrollment_billing', 'intake', 'evaluation'];
        $this->assertSame($expected, QuestionnaireRepository::STAGES);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<mixed> $assignments
     * @param array<mixed> $fields
     * @return array<string, mixed>
     */
    private function makeGroup(
        string $id,
        string $stage,
        array $assignments,
        array $fields = []
    ): array {
        return [
            'id'          => $id,
            'label'       => 'Test Group ' . $id,
            'stage'       => $stage,
            'assignments' => $assignments,
            'fields'      => $fields,
        ];
    }
}
