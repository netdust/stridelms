<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Admin\AdminTrajectoryService;
use Stride\Modules\Trajectory\TrajectoryRepository;
use WP_REST_Request;

/**
 * Characterization safety-net for Task D2 (admin-backend-cleanup): the strangle
 * that drains AdminTrajectoryService::getTrajectories' inline $wpdb COUNT +
 * get_results (self-flagged "DRIFT #2") into
 * TrajectoryRepository::countAdminList / findAdminListRows (INV-3).
 *
 * The relocation is behavior-preserving: the list/count SQL, the WHERE/scope/
 * status/search assembly, the ORDER BY post_date DESC, and the read-model shape
 * must be byte-identical before and after. This test pins the OUTPUT of
 * getTrajectories() across the four parameter branches the WHERE assembly drives
 * — default scope, scope=active, status filter, search — so any drift in the
 * relocated query (a dropped predicate, a changed param order, a different
 * column set, a re-ordered result) goes RED.
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AdminTrajectoryService
 */
final class AdminTrajectoryServiceTest extends IntegrationTestCase
{
    private AdminTrajectoryService $service;
    private TrajectoryRepository $repo;

    /** @var array<string, int> title => post id, for stable identification */
    private array $ids = [];

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = ntdst_get(AdminTrajectoryService::class);
        $this->repo    = ntdst_get(TrajectoryRepository::class);

        // A deterministic fixture with status + title variety. Titles carry a
        // unique run token so search matches only our rows and the suite stays
        // isolated from accumulated seed/test trajectories.
        $token = 'D2CHAR' . substr((string) uniqid(), -6);
        $this->token = $token;

        $fixture = [
            // title-suffix          => status meta (drives the scope/status branch)
            "{$token} Alpha"   => 'open',
            "{$token} Bravo"   => 'completed',
            "{$token} Charlie" => 'archived',
            "{$token} Delta"   => 'full',
            "{$token} Echo"    => '',         // no _ntdst_status row → counts as active
        ];

        foreach ($fixture as $title => $status) {
            $id = wp_insert_post([
                'post_type'    => 'vad_trajectory',
                'post_title'   => $title,
                'post_status'  => 'publish',
                'post_content' => "Body for {$title}",
            ]);
            self::$testPosts[] = $id;
            $this->ids[$title] = $id;

            if ($status !== '') {
                $this->repo->update($id, ['status' => $status]);
            }
        }
    }

    private function call(array $params): array
    {
        $req = new WP_REST_Request('GET', '/stride/v1/admin/trajectories');
        // Always search by our run token so the corpus is exactly our 5 rows,
        // making the assertions deterministic regardless of pre-existing data.
        $req->set_param('search', $this->token);
        $req->set_param('per_page', 100);
        foreach ($params as $k => $v) {
            $req->set_param($k, $v);
        }

        return $this->service->getTrajectories($req)->get_data();
    }

    /** Map an items[] payload to [title => row] for order-independent shape checks. */
    private function byTitle(array $data): array
    {
        $out = [];
        foreach ($data['items'] as $item) {
            $out[$item['title']] = $item;
        }

        return $out;
    }

    public function testDefaultScopeReturnsAllPublishedRows(): void
    {
        $data = $this->call([]);

        $this->assertSame(5, $data['total'], 'default scope = all 5 published trajectories matching the token');
        $this->assertCount(5, $data['items']);
        $this->assertSame(1, $data['page']);
        $this->assertSame(100, $data['perPage']);

        $rows = $this->byTitle($data);
        $this->assertArrayHasKey("{$this->token} Alpha", $rows);
        $this->assertArrayHasKey("{$this->token} Echo", $rows);
    }

    public function testActiveScopeExcludesTerminalStatuses(): void
    {
        $data = $this->call(['scope' => 'active']);

        // active = NOT admin-closed (OfferingStatus::adminClosedValues, F-T2).
        // Bravo(completed) + Charlie(archived) drop; Alpha(open), Delta(full),
        // Echo(no status row → active) remain.
        $this->assertSame(3, $data['total'], 'active scope excludes completed + archived only');
        $titles = array_keys($this->byTitle($data));
        sort($titles);
        $this->assertSame(
            ["{$this->token} Alpha", "{$this->token} Delta", "{$this->token} Echo"],
            $titles,
        );
    }

    public function testStatusFilterMatchesExactMetaValue(): void
    {
        $data = $this->call(['status' => 'completed']);

        $this->assertSame(1, $data['total'], 'status=completed matches the single completed row');
        $this->assertCount(1, $data['items']);
        $this->assertSame("{$this->token} Bravo", $data['items'][0]['title']);
        $this->assertSame('completed', $data['items'][0]['status']);
        $this->assertSame('Afgelopen', $data['items'][0]['statusLabel']);
    }

    public function testSearchNarrowsToMatchingTitleSubstring(): void
    {
        $data = $this->call(['search' => "{$this->token} Alpha"]);

        $this->assertSame(1, $data['total']);
        $this->assertSame("{$this->token} Alpha", $data['items'][0]['title']);
    }

    public function testResultsAreOrderedByPostDateDescending(): void
    {
        $data = $this->call([]);

        $ids = array_map(static fn($i) => $i['id'], $data['items']);
        // Inserted Alpha..Echo in ascending time → post_date DESC means the
        // later inserts come first. Echo was inserted last.
        $this->assertSame(
            $this->ids["{$this->token} Echo"],
            $ids[0],
            'ORDER BY post_date DESC must put the most-recently-created row first',
        );
    }

    public function testItemReadModelShapeIsPreserved(): void
    {
        $data = $this->call(['status' => 'open']);
        $item = $data['items'][0];

        // The read-model keys the grid binds — a relocation must not drop or
        // rename any of them.
        foreach ([
            'id', 'title', 'description', 'status', 'statusLabel', 'mode', 'modeLabel',
            'capacity', 'enrolledCount', 'courseCount', 'courses',
            'price', 'priceFormatted', 'priceNonMember', 'priceNonMemberFormatted',
            'enrollmentDeadline', 'choiceAvailableDate', 'choiceDeadline', 'editUrl',
        ] as $key) {
            $this->assertArrayHasKey($key, $item, "read-model must surface {$key}");
        }

        // The list renders no roster — the per-trajectory enrolledUsers rows
        // (names + e-mails) are fetched ONLY on the detail path. The list
        // payload must not ship them.
        $this->assertArrayNotHasKey('enrolledUsers', $item, 'list payload must not ship roster PII');

        $this->assertSame("{$this->token} Alpha", $item['title']);
        $this->assertSame('Body for ' . "{$this->token} Alpha", $item['description']);
    }
}
