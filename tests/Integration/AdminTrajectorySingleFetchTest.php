<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Admin\AdminTrajectoryService;
use Stride\Modules\Edition\EditionCPT;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Trajectory\TrajectoryCPT;
use Stride\Modules\Trajectory\TrajectoryRepository;
use WP_REST_Request;

/**
 * Characterization safety-net for Task S5 (admin-backend-cleanup CLUSTER F):
 * AdminTrajectoryService::getTrajectory is refactored to fetch the single
 * trajectory via TrajectoryRepository::findById + the shared per-item formatter,
 * instead of re-running the whole getTrajectories list pipeline (count +
 * list-rows + batch-meta + 50-enrollment-per-trajectory + edition/user batch)
 * scoped to the target's title and linear-scanning for the id.
 *
 * The refactor is behavior-preserving: getTrajectory's OUTPUT (the slide-over
 * read-model) must stay byte-identical. This test pins it two ways:
 *   1. PARITY — getTrajectory($id) equals the matching getTrajectories() list
 *      item for that id, merged with the slide-over `registrations` remap
 *      (single + list paths agree — pattern_trajectory_edition_parity).
 *   2. findById returns the single row for a valid id, null for an unknown id.
 *
 * RED before the refactor only on findById (the method doesn't exist yet). The
 * parity assertion is the behavior-preserving guard that must stay GREEN through
 * the refactor (any drift in the relocated assembly goes RED here).
 *
 * Run: ddev exec bash -c "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AdminTrajectorySingleFetch"
 */
final class AdminTrajectorySingleFetchTest extends IntegrationTestCase
{
    private AdminTrajectoryService $service;
    private TrajectoryRepository $repo;

    /** @var array<int> trajectory post ids created by this fixture */
    private array $trajIds = [];

    private int $targetId = 0;
    private string $token = '';

    /** @var array<int> registration row ids to clean up after each test */
    private array $regIds = [];
    /** @var array<int> user ids to clean up after each test */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = ntdst_get(AdminTrajectoryService::class);
        $this->repo    = ntdst_get(TrajectoryRepository::class);

        $token = 'S5FETCH' . substr((string) uniqid(), -6);
        $this->token = $token;

        // An edition the trajectory's required course points at, so the
        // courses-with-details assembly path (edition title enrichment) is
        // exercised — the heaviest part of the per-item formatter.
        $editionId = wp_insert_post([
            'post_type'   => 'vad_edition',
            'post_title'  => "{$token} Edition",
            'post_status' => 'publish',
        ]);
        self::$testPosts[] = $editionId;

        // The target trajectory with rich meta (status, mode, capacity, price,
        // courses) so the formatter shapes every field.
        $this->targetId = wp_insert_post([
            'post_type'    => 'vad_trajectory',
            'post_title'   => "{$token} Target",
            'post_status'  => 'publish',
            'post_content' => "Body for {$token} Target",
        ]);
        self::$testPosts[] = $this->targetId;
        $this->trajIds[] = $this->targetId;
        $this->repo->update($this->targetId, ['status' => 'open']);
        update_post_meta($this->targetId, '_ntdst_mode', 'cohort');
        update_post_meta($this->targetId, '_ntdst_capacity', 12);
        update_post_meta($this->targetId, '_ntdst_price', 350);
        update_post_meta($this->targetId, '_ntdst_courses', wp_json_encode([
            ['edition_id' => $editionId, 'type' => 'required'],
        ]));

        // A confirmed trajectory-parent registration so enrolledUsers/registrations
        // are non-empty (the remap path in getTrajectory).
        $repo = ntdst_get(RegistrationRepository::class);
        $userId = wp_create_user("{$token}_enrollee", 'pass123', "{$token}_e@test.local");
        $this->userIds[] = (int) $userId;
        $reg = $repo->create([
            'user_id'       => $userId,
            'trajectory_id' => $this->targetId,
            'status'        => 'confirmed',
        ]);
        if (is_int($reg)) {
            $this->regIds[] = $reg;
        }

        // A couple of decoy trajectories sharing the token so the title-LIKE
        // narrowing in the OLD path returned more than one row (proving the
        // id-match disambiguation still works post-refactor).
        foreach (['Decoy1', 'Decoy2'] as $suffix) {
            $id = wp_insert_post([
                'post_type'   => 'vad_trajectory',
                'post_title'  => "{$token} {$suffix}",
                'post_status' => 'publish',
            ]);
            self::$testPosts[] = $id;
            $this->trajIds[] = $id;
        }
    }

    protected function tearDown(): void
    {
        global $wpdb;
        foreach ($this->regIds as $regId) {
            $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $regId]);
        }
        if (!empty($this->userIds)) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            foreach ($this->userIds as $uid) {
                wp_delete_user($uid);
            }
        }
        $this->regIds = [];
        $this->userIds = [];
        parent::tearDown();
    }

    public function testFindByIdReturnsTheRowForAValidIdAndNullForUnknown(): void
    {
        $row = $this->repo->findById($this->targetId);
        $this->assertNotNull($row, 'findById returns the row for a valid published trajectory');
        $this->assertSame($this->targetId, (int) $row->ID);
        $this->assertSame("{$this->token} Target", $row->post_title);

        $this->assertNull(
            $this->repo->findById(99999999),
            'findById returns null for a non-existent / non-trajectory id',
        );
    }

    public function testGetTrajectoryOutputMatchesTheListItemForThatId(): void
    {
        // The list item for the target id (the pre-refactor source of truth).
        $listReq = new WP_REST_Request('GET', '/stride/v1/admin/trajectories');
        $listReq->set_param('search', $this->token);
        $listReq->set_param('per_page', 100);
        $listData = $this->service->getTrajectories($listReq)->get_data();

        $listItem = null;
        foreach ($listData['items'] as $item) {
            if ($item['id'] === $this->targetId) {
                $listItem = $item;
                break;
            }
        }
        $this->assertNotNull($listItem, 'the target must appear in the list output');

        // The single-fetch endpoint output.
        $singleReq = new WP_REST_Request('GET', "/stride/v1/admin/trajectories/{$this->targetId}");
        $singleReq->set_param('id', $this->targetId);
        $singleResp = $this->service->getTrajectory($singleReq);
        $this->assertNotInstanceOf(\WP_Error::class, $singleResp);
        $singleData = $singleResp->get_data();

        // getTrajectory returns the list item PLUS a `registrations` remap of
        // enrolledUsers. Strip the single-only key, then the remainder must equal
        // the list item byte-for-byte (single + list paths agree).
        $this->assertArrayHasKey('registrations', $singleData, 'slide-over registrations remap present');
        unset($singleData['registrations']);
        $this->assertSame(
            $listItem,
            $singleData,
            'getTrajectory output must be byte-identical to the matching list item',
        );
    }

    public function testGetTrajectoryRegistrationsRemapMatchesEnrolledUsers(): void
    {
        $singleReq = new WP_REST_Request('GET', "/stride/v1/admin/trajectories/{$this->targetId}");
        $singleReq->set_param('id', $this->targetId);
        $singleData = $this->service->getTrajectory($singleReq)->get_data();

        // One confirmed enrollee → one registration row with the Dutch label.
        $this->assertCount(1, $singleData['registrations']);
        $reg = $singleData['registrations'][0];
        $this->assertSame('confirmed', $reg['status']);
        // 'confirmed' is not in the slide-over label map → ucfirst fallback.
        $this->assertSame('Confirmed', $reg['status_label']);
    }
}
