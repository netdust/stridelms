<?php
/**
 * Orchestrates the matrix: taxonomy terms → users →
 * courses (lessons → editions → sessions → registrations → quotes) →
 * questionnaire groups (after courses: assignments resolve course titles) →
 * trajectories → vouchers → manifest. Idempotent: users/courses/lessons/
 * editions check existence by natural key (login / title / derived edition
 * title) and a re-run reconstructs the full manifest + covers; sessions ride
 * the edition gate (existing edition → its seed sessions are looked up, not
 * recreated). Builders still pending (Tasks 5-8: registrations, quotes,
 * questionnaire groups, trajectories, vouchers) must follow the same pattern.
 */
final class StrideSeedRunner
{
    public const SEED_META_KEY = '_stride_seed_data';

    private array $created = [
        'users' => [], 'courses' => [], 'lessons' => [], 'editions' => [],
        'sessions' => [], 'registrations' => [], 'trajectories' => [],
        'vouchers' => [], 'quotes' => [], 'questionnaire_groups' => [],
    ];
    /** @var array<string,int> login => user_id, plus 'admin' => 1 */
    private array $userMap = ['admin' => 1];
    /** @var array<string,string[]> entity-id-keyed covers index for the manifest */
    private array $covers = [];

    public function __construct(private array $matrix, private StrideSeedBuilders $builders) {}

    public function run(): void
    {
        echo "\n=== Stride LMS Feature-Matrix Seed ===\n\n";
        $this->builders->ensureTaxonomyTerms();
        foreach ($this->matrix['users'] as $u) {
            $id = $this->builders->buildUser($u);
            if ($id) { $this->userMap[$u['login']] = $id; $this->created['users'][] = $id; }
        }
        foreach ($this->matrix['courses'] as $course) {
            $result = $this->builders->buildCourse($course, $this->userMap);
            $this->merge($result);   // merges courses/lessons/editions/sessions/registrations/quotes + covers
        }
        // After courses: group assignments reference courses by title.
        $this->created['questionnaire_groups'] = $this->builders->buildQuestionnaireGroups(
            $this->matrix['questionnaire_groups']
        );
        foreach ($this->matrix['questionnaire_groups'] as $g) {
            $this->covers["qgroup:{$g['id']}"] = $g['covers'] ?? [];
        }
        foreach ($this->matrix['trajectories'] as $t) {
            $id = $this->builders->buildTrajectory($t, $this->created['courses']);
            if ($id) { $this->created['trajectories'][] = $id; $this->covers["trajectory:$id"] = $t['covers'] ?? []; }
        }
        foreach ($this->matrix['vouchers'] as $v) {
            $id = $this->builders->buildVoucher($v, $this->created['editions']);
            if ($id) { $this->created['vouchers'][] = $id; $this->covers["voucher:$id"] = $v['covers'] ?? []; }
        }
        update_option('stride_company_details', $this->matrix['company_details']);
        update_option('stride_seed_manifest', $this->created);
        update_option('stride_seed_covers', $this->covers);   // NEW: verify script reads this
        update_option('stride_seed_timestamp', current_time('mysql'));
        $this->printSummary();
    }

    private function merge(array $result): void
    {
        foreach ($result['created'] as $kind => $ids) {
            $this->created[$kind] = array_merge($this->created[$kind] ?? [], $ids);
        }
        $this->covers = array_merge($this->covers, $result['covers']);
    }

    private function printSummary(): void
    {
        echo "\n=== Seed Complete ===\n";
        foreach ($this->created as $kind => $ids) {
            echo sprintf("  - %s: %d\n", $kind, count($ids));
        }
        $allTags = array_unique(array_merge([], ...array_values($this->covers)));
        echo "  Dimensions covered: " . count($allTags) . "\n";
        echo "\nVerify:  ddev exec wp eval-file scripts/seed-verify.php\n";
        echo "Cleanup: ddev exec bash -c 'FORCE_UNSEED=1 wp eval-file scripts/unseed.php'\n";
    }
}
