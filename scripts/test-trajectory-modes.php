<?php
/**
 * Comprehensive Test Suite for Trajectory Modes
 *
 * Tests all new functionality:
 * - FieldRegistry constants
 * - TrajectoryService mode helpers and CRUD
 * - TrajectoryEnrollmentRepository elective choices
 * - Edge cases and data integrity
 *
 * Usage: ddev exec wp eval-file scripts/test-trajectory-modes.php
 */

use ntdst\Stride\FieldRegistry;
use ntdst\Stride\core\TrajectoryService;
use ntdst\Stride\core\TrajectoryEnrollmentRepository;
use ntdst\Stride\core\EditionService;

/**
 * Simple test runner that works in WP-CLI context
 */
class TrajectoryModeTests {
    private array $tests = [];
    private int $passed = 0;
    private int $failed = 0;

    // Test data storage
    private ?int $selfPacedId = null;
    private ?int $cohortId = null;
    private ?int $expiredCohortId = null;
    private ?int $activeChoiceId = null;
    private ?int $testEnrollmentId = null;
    private ?int $testUserId = null;

    private TrajectoryService $trajectoryService;
    private TrajectoryEnrollmentRepository $enrollmentRepo;

    public function __construct() {
        $this->trajectoryService = ntdst_get(TrajectoryService::class);
        $this->enrollmentRepo = ntdst_get(TrajectoryEnrollmentRepository::class);
    }

    private function test(string $name, callable $fn): void {
        try {
            $result = $fn();
            if ($result === true) {
                $this->tests[$name] = ['status' => 'PASS', 'message' => ''];
                $this->passed++;
            } else {
                $this->tests[$name] = ['status' => 'FAIL', 'message' => $result ?: 'Returned false'];
                $this->failed++;
            }
        } catch (Throwable $e) {
            $this->tests[$name] = ['status' => 'FAIL', 'message' => $e->getMessage()];
            $this->failed++;
        }
    }

    private function assertEqual($expected, $actual, string $context = ''): bool|string {
        if ($expected === $actual) {
            return true;
        }
        return sprintf(
            "Expected %s but got %s%s",
            var_export($expected, true),
            var_export($actual, true),
            $context ? " ($context)" : ''
        );
    }

    public function run(): void {
        echo "=======================================================\n";
        echo "  TRAJECTORY MODES TEST SUITE\n";
        echo "=======================================================\n\n";

        $this->testFieldRegistryConstants();
        $this->testTrajectoryServiceBasic();
        $this->testSelfPacedCrud();
        $this->testCohortCrud();
        $this->testCohortEnrollmentDeadline();
        $this->testCohortChoicePeriod();
        $this->testLinkedEditions();
        $this->testEnrollmentRepository();
        $this->testEdgeCases();
        $this->testDataIntegrity();
        $this->cleanup();
        $this->printResults();
    }

    private function testFieldRegistryConstants(): void {
        echo "--- FieldRegistry Constants ---\n";

        $this->test('FieldRegistry::TRAJECTORY_MODE exists', function() {
            return $this->assertEqual('mode', FieldRegistry::TRAJECTORY_MODE);
        });

        $this->test('FieldRegistry::TRAJECTORY_MODE_SELF_PACED exists', function() {
            return $this->assertEqual('self_paced', FieldRegistry::TRAJECTORY_MODE_SELF_PACED);
        });

        $this->test('FieldRegistry::TRAJECTORY_MODE_COHORT exists', function() {
            return $this->assertEqual('cohort', FieldRegistry::TRAJECTORY_MODE_COHORT);
        });

        $this->test('FieldRegistry::TRAJECTORY_ENROLLMENT_DEADLINE exists', function() {
            return $this->assertEqual('enrollment_deadline', FieldRegistry::TRAJECTORY_ENROLLMENT_DEADLINE);
        });

        $this->test('FieldRegistry::TRAJECTORY_CHOICE_AVAILABLE exists', function() {
            return $this->assertEqual('choice_available_date', FieldRegistry::TRAJECTORY_CHOICE_AVAILABLE);
        });

        $this->test('FieldRegistry::TRAJECTORY_CHOICE_DEADLINE exists', function() {
            return $this->assertEqual('choice_deadline', FieldRegistry::TRAJECTORY_CHOICE_DEADLINE);
        });

        $this->test('FieldRegistry::TRAJECTORY_LINKED_EDITIONS exists', function() {
            return $this->assertEqual('linked_editions', FieldRegistry::TRAJECTORY_LINKED_EDITIONS);
        });
    }

    private function testTrajectoryServiceBasic(): void {
        echo "\n--- TrajectoryService Basic ---\n";

        $this->test('TrajectoryService can be resolved', function() {
            return $this->trajectoryService instanceof TrajectoryService ? true : 'Not a TrajectoryService instance';
        });

        $this->test('TrajectoryService::isSelfPaced method exists', function() {
            return method_exists($this->trajectoryService, 'isSelfPaced') ? true : 'Method missing';
        });

        $this->test('TrajectoryService::isCohort method exists', function() {
            return method_exists($this->trajectoryService, 'isCohort') ? true : 'Method missing';
        });

        $this->test('TrajectoryService::getLinkedEdition method exists', function() {
            return method_exists($this->trajectoryService, 'getLinkedEdition') ? true : 'Method missing';
        });

        $this->test('TrajectoryService::isEnrollmentOpen method exists', function() {
            return method_exists($this->trajectoryService, 'isEnrollmentOpen') ? true : 'Method missing';
        });

        $this->test('TrajectoryService::isChoicePeriod method exists', function() {
            return method_exists($this->trajectoryService, 'isChoicePeriod') ? true : 'Method missing';
        });
    }

    private function testSelfPacedCrud(): void {
        echo "\n--- TrajectoryService Self-Paced CRUD ---\n";

        $this->test('Create self-paced trajectory', function() {
            $result = $this->trajectoryService->createTrajectory([
                'post_title' => 'TEST: Self-Paced Trajectory',
                'post_status' => 'publish',
                FieldRegistry::TRAJECTORY_MODE => FieldRegistry::TRAJECTORY_MODE_SELF_PACED,
                FieldRegistry::TRAJECTORY_STATUS => FieldRegistry::TRAJECTORY_STATUS_OPEN,
                FieldRegistry::TRAJECTORY_DEADLINE_MONTHS => 18,
                FieldRegistry::TRAJECTORY_DESCRIPTION => 'Test self-paced trajectory',
            ]);

            if (is_wp_error($result)) {
                return $result->get_error_message();
            }

            $this->selfPacedId = $result;
            return is_int($result) && $result > 0 ? true : 'Invalid ID returned';
        });

        $this->test('Self-paced trajectory has correct mode', function() {
            if (!$this->selfPacedId) return 'No trajectory created';

            $traj = $this->trajectoryService->getTrajectory($this->selfPacedId);
            return $this->assertEqual(FieldRegistry::TRAJECTORY_MODE_SELF_PACED, $traj['mode']);
        });

        $this->test('isSelfPaced returns true for self-paced', function() {
            if (!$this->selfPacedId) return 'No trajectory created';
            return $this->assertEqual(true, $this->trajectoryService->isSelfPaced($this->selfPacedId));
        });

        $this->test('isCohort returns false for self-paced', function() {
            if (!$this->selfPacedId) return 'No trajectory created';
            return $this->assertEqual(false, $this->trajectoryService->isCohort($this->selfPacedId));
        });

        $this->test('isEnrollmentOpen returns true for open self-paced', function() {
            if (!$this->selfPacedId) return 'No trajectory created';
            return $this->assertEqual(true, $this->trajectoryService->isEnrollmentOpen($this->selfPacedId));
        });

        $this->test('isChoicePeriod returns false for self-paced', function() {
            if (!$this->selfPacedId) return 'No trajectory created';
            return $this->assertEqual(false, $this->trajectoryService->isChoicePeriod($this->selfPacedId));
        });

        $this->test('Self-paced trajectory has deadline_months', function() {
            if (!$this->selfPacedId) return 'No trajectory created';

            $traj = $this->trajectoryService->getTrajectory($this->selfPacedId);
            return $this->assertEqual(18, $traj['deadline_months']);
        });
    }

    private function testCohortCrud(): void {
        echo "\n--- TrajectoryService Cohort CRUD ---\n";

        $futureDate = date('Y-m-d', strtotime('+30 days'));
        $choiceStart = date('Y-m-d', strtotime('+7 days'));
        $choiceEnd = date('Y-m-d', strtotime('+21 days'));

        $this->test('Create cohort trajectory', function() use ($futureDate, $choiceStart, $choiceEnd) {
            $result = $this->trajectoryService->createTrajectory([
                'post_title' => 'TEST: Cohort Trajectory',
                'post_status' => 'publish',
                FieldRegistry::TRAJECTORY_MODE => FieldRegistry::TRAJECTORY_MODE_COHORT,
                FieldRegistry::TRAJECTORY_STATUS => FieldRegistry::TRAJECTORY_STATUS_OPEN,
                FieldRegistry::TRAJECTORY_ENROLLMENT_DEADLINE => $futureDate,
                FieldRegistry::TRAJECTORY_CHOICE_AVAILABLE => $choiceStart,
                FieldRegistry::TRAJECTORY_CHOICE_DEADLINE => $choiceEnd,
                FieldRegistry::TRAJECTORY_DESCRIPTION => 'Test cohort trajectory',
            ]);

            if (is_wp_error($result)) {
                return $result->get_error_message();
            }

            $this->cohortId = $result;
            return is_int($result) && $result > 0 ? true : 'Invalid ID returned';
        });

        $this->test('Cohort trajectory has correct mode', function() {
            if (!$this->cohortId) return 'No trajectory created';

            $traj = $this->trajectoryService->getTrajectory($this->cohortId);
            return $this->assertEqual(FieldRegistry::TRAJECTORY_MODE_COHORT, $traj['mode']);
        });

        $this->test('isSelfPaced returns false for cohort', function() {
            if (!$this->cohortId) return 'No trajectory created';
            return $this->assertEqual(false, $this->trajectoryService->isSelfPaced($this->cohortId));
        });

        $this->test('isCohort returns true for cohort', function() {
            if (!$this->cohortId) return 'No trajectory created';
            return $this->assertEqual(true, $this->trajectoryService->isCohort($this->cohortId));
        });

        $this->test('Cohort has enrollment_deadline', function() use ($futureDate) {
            if (!$this->cohortId) return 'No trajectory created';

            $traj = $this->trajectoryService->getTrajectory($this->cohortId);
            return $this->assertEqual($futureDate, $traj['enrollment_deadline']);
        });

        $this->test('Cohort has choice dates', function() use ($choiceStart, $choiceEnd) {
            if (!$this->cohortId) return 'No trajectory created';

            $traj = $this->trajectoryService->getTrajectory($this->cohortId);
            $startOk = $this->assertEqual($choiceStart, $traj['choice_available_date']);
            if ($startOk !== true) return $startOk;

            return $this->assertEqual($choiceEnd, $traj['choice_deadline']);
        });

        $this->test('isEnrollmentOpen true when deadline in future', function() {
            if (!$this->cohortId) return 'No trajectory created';
            return $this->assertEqual(true, $this->trajectoryService->isEnrollmentOpen($this->cohortId));
        });
    }

    private function testCohortEnrollmentDeadline(): void {
        echo "\n--- Cohort Enrollment Deadline ---\n";

        $pastDate = date('Y-m-d', strtotime('-7 days'));

        $this->test('Create cohort with past enrollment deadline', function() use ($pastDate) {
            $result = $this->trajectoryService->createTrajectory([
                'post_title' => 'TEST: Expired Cohort',
                'post_status' => 'publish',
                FieldRegistry::TRAJECTORY_MODE => FieldRegistry::TRAJECTORY_MODE_COHORT,
                FieldRegistry::TRAJECTORY_STATUS => FieldRegistry::TRAJECTORY_STATUS_OPEN,
                FieldRegistry::TRAJECTORY_ENROLLMENT_DEADLINE => $pastDate,
            ]);

            if (is_wp_error($result)) {
                return $result->get_error_message();
            }

            $this->expiredCohortId = $result;
            return true;
        });

        $this->test('isEnrollmentOpen false when deadline passed', function() {
            if (!$this->expiredCohortId) return 'No trajectory created';
            return $this->assertEqual(false, $this->trajectoryService->isEnrollmentOpen($this->expiredCohortId));
        });
    }

    private function testCohortChoicePeriod(): void {
        echo "\n--- Cohort Choice Period ---\n";

        $this->test('Create cohort in active choice period', function() {
            $result = $this->trajectoryService->createTrajectory([
                'post_title' => 'TEST: Active Choice Period',
                'post_status' => 'publish',
                FieldRegistry::TRAJECTORY_MODE => FieldRegistry::TRAJECTORY_MODE_COHORT,
                FieldRegistry::TRAJECTORY_STATUS => FieldRegistry::TRAJECTORY_STATUS_OPEN,
                FieldRegistry::TRAJECTORY_CHOICE_AVAILABLE => date('Y-m-d', strtotime('-7 days')),
                FieldRegistry::TRAJECTORY_CHOICE_DEADLINE => date('Y-m-d', strtotime('+7 days')),
            ]);

            if (is_wp_error($result)) {
                return $result->get_error_message();
            }

            $this->activeChoiceId = $result;
            return true;
        });

        $this->test('isChoicePeriod true when in choice window', function() {
            if (!$this->activeChoiceId) return 'No trajectory created';
            return $this->assertEqual(true, $this->trajectoryService->isChoicePeriod($this->activeChoiceId));
        });

        $this->test('isChoicePeriod false when before choice window', function() {
            if (!$this->cohortId) return 'No trajectory created';
            // $cohortId has choice_available in future
            return $this->assertEqual(false, $this->trajectoryService->isChoicePeriod($this->cohortId));
        });
    }

    private function testLinkedEditions(): void {
        echo "\n--- Linked Editions ---\n";

        $this->test('Create cohort with linked editions', function() {
            if (!$this->cohortId) return 'No trajectory created';

            // First add some course requirements
            $this->trajectoryService->updateTrajectory($this->cohortId, [
                FieldRegistry::TRAJECTORY_COURSES => [
                    ['course_id' => 100, 'group' => 'Basis'],
                    ['course_id' => 200, 'group' => 'Basis'],
                ],
            ]);

            // Now link editions
            $result = $this->trajectoryService->setLinkedEditions($this->cohortId, [
                ['course_id' => 100, 'edition_id' => 1000],
                ['course_id' => 200, 'edition_id' => 2000],
            ]);

            if (is_wp_error($result)) {
                return $result->get_error_message();
            }

            return true;
        });

        $this->test('getLinkedEdition returns correct edition', function() {
            if (!$this->cohortId) return 'No trajectory created';

            $editionId = $this->trajectoryService->getLinkedEdition($this->cohortId, 100);
            return $this->assertEqual(1000, $editionId);
        });

        $this->test('getLinkedEdition returns null for unlinked course', function() {
            if (!$this->cohortId) return 'No trajectory created';

            $editionId = $this->trajectoryService->getLinkedEdition($this->cohortId, 999);
            return $this->assertEqual(null, $editionId);
        });

        $this->test('getLinkedEditions returns all links', function() {
            if (!$this->cohortId) return 'No trajectory created';

            $links = $this->trajectoryService->getLinkedEditions($this->cohortId);
            if (count($links) !== 2) {
                return "Expected 2 links, got " . count($links);
            }
            return true;
        });

        $this->test('setLinkedEditions fails for self-paced', function() {
            if (!$this->selfPacedId) return 'No trajectory created';

            $result = $this->trajectoryService->setLinkedEditions($this->selfPacedId, [
                ['course_id' => 100, 'edition_id' => 1000],
            ]);

            return is_wp_error($result) ? true : 'Should have returned WP_Error';
        });
    }

    private function testEnrollmentRepository(): void {
        echo "\n--- TrajectoryEnrollmentRepository ---\n";

        $this->test('TrajectoryEnrollmentRepository can be resolved', function() {
            return $this->enrollmentRepo instanceof TrajectoryEnrollmentRepository ? true : 'Not a TrajectoryEnrollmentRepository instance';
        });

        $this->test('elective_choices column exists', function() {
            global $wpdb;
            $table = $wpdb->prefix . 'vad_trajectory_enrollments';
            $columns = $wpdb->get_results("SHOW COLUMNS FROM $table");
            $columnNames = array_column($columns, 'Field');
            return in_array('elective_choices', $columnNames) ? true : 'Column missing';
        });

        $this->test('Create enrollment with elective_choices', function() {
            if (!$this->cohortId) return 'No trajectory created';

            // Use a test user ID (create temp user)
            $this->testUserId = wp_create_user('test_traj_user_' . time(), 'password123', 'test_traj_' . time() . '@test.com');
            if (is_wp_error($this->testUserId)) {
                return $this->testUserId->get_error_message();
            }

            $result = $this->enrollmentRepo->create([
                'trajectory_id' => $this->cohortId,
                'user_id' => $this->testUserId,
                'status' => TrajectoryEnrollmentRepository::STATUS_ACTIVE,
                'elective_choices' => [
                    ['course_id' => 300, 'group' => 'Keuze'],
                    ['course_id' => 400, 'group' => 'Keuze'],
                ],
            ]);

            if (is_wp_error($result)) {
                wp_delete_user($this->testUserId);
                return $result->get_error_message();
            }

            $this->testEnrollmentId = $result;
            return is_int($result) && $result > 0 ? true : 'Invalid ID returned';
        });

        $this->test('getElectiveChoices returns saved choices', function() {
            if (!$this->testEnrollmentId) return 'No enrollment created';

            $choices = $this->enrollmentRepo->getElectiveChoices($this->testEnrollmentId);

            if (!is_array($choices)) {
                return 'Choices is not an array: ' . var_export($choices, true);
            }

            if (count($choices) !== 2) {
                return "Expected 2 choices, got " . count($choices);
            }

            return true;
        });

        $this->test('setElectiveChoices updates choices', function() {
            if (!$this->testEnrollmentId) return 'No enrollment created';

            $newChoices = [
                ['course_id' => 500, 'group' => 'Keuze Updated'],
            ];

            $result = $this->enrollmentRepo->setElectiveChoices($this->testEnrollmentId, $newChoices);

            if (is_wp_error($result)) {
                return $result->get_error_message();
            }

            $retrieved = $this->enrollmentRepo->getElectiveChoices($this->testEnrollmentId);

            if (count($retrieved) !== 1) {
                return "Expected 1 choice after update, got " . count($retrieved);
            }

            if ((int)$retrieved[0]['course_id'] !== 500) {
                return "Expected course_id 500, got " . $retrieved[0]['course_id'];
            }

            return true;
        });

        $this->test('setElectiveChoices sanitizes data', function() {
            if (!$this->testEnrollmentId) return 'No enrollment created';

            $dirtyChoices = [
                ['course_id' => '  123  ', 'group' => '<script>alert("xss")</script>Keuze'],
                ['course_id' => '', 'group' => 'Empty'], // Should be filtered out
            ];

            $result = $this->enrollmentRepo->setElectiveChoices($this->testEnrollmentId, $dirtyChoices);

            if (is_wp_error($result)) {
                return $result->get_error_message();
            }

            $retrieved = $this->enrollmentRepo->getElectiveChoices($this->testEnrollmentId);

            // Should only have 1 choice (empty course_id filtered)
            if (count($retrieved) !== 1) {
                return "Expected 1 choice (empty filtered), got " . count($retrieved);
            }

            // course_id should be integer
            if ($retrieved[0]['course_id'] !== 123) {
                return "Expected sanitized course_id 123, got " . var_export($retrieved[0]['course_id'], true);
            }

            // group should be sanitized
            if (strpos($retrieved[0]['group'], '<script>') !== false) {
                return "XSS not sanitized from group";
            }

            return true;
        });

        $this->test('get() includes elective_choices in result', function() {
            if (!$this->testEnrollmentId) return 'No enrollment created';

            $enrollment = $this->enrollmentRepo->get($this->testEnrollmentId);

            if (!array_key_exists('elective_choices', $enrollment)) {
                return 'elective_choices key missing from get() result';
            }

            return is_array($enrollment['elective_choices']) ? true : 'elective_choices should be array';
        });
    }

    private function testEdgeCases(): void {
        echo "\n--- Edge Cases ---\n";

        $this->test('isSelfPaced on non-existent trajectory returns true', function() {
            return $this->assertEqual(true, $this->trajectoryService->isSelfPaced(999999));
        });

        $this->test('isCohort on non-existent trajectory returns false', function() {
            return $this->assertEqual(false, $this->trajectoryService->isCohort(999999));
        });

        $this->test('isEnrollmentOpen on non-existent trajectory returns false', function() {
            return $this->assertEqual(false, $this->trajectoryService->isEnrollmentOpen(999999));
        });

        $this->test('getLinkedEdition returns null for self-paced', function() {
            if (!$this->selfPacedId) return 'No trajectory created';
            return $this->assertEqual(null, $this->trajectoryService->getLinkedEdition($this->selfPacedId, 100));
        });

        $this->test('Empty mode treated as self-paced', function() {
            // Find a trajectory without mode set (legacy)
            $allTrajectories = $this->trajectoryService->getAllTrajectories();
            foreach ($allTrajectories as $traj) {
                if (empty($traj['mode']) || $traj['mode'] === '' || $traj['mode'] === FieldRegistry::TRAJECTORY_MODE_SELF_PACED) {
                    $isSP = $this->trajectoryService->isSelfPaced($traj['id']);
                    return $this->assertEqual(true, $isSP, 'Legacy trajectory ' . $traj['id']);
                }
            }
            return true; // No legacy trajectories to test
        });

        $this->test('Closed trajectory enrollment not open', function() {
            if (!$this->selfPacedId) return 'No trajectory created';

            // Update to closed
            $this->trajectoryService->updateTrajectory($this->selfPacedId, [
                FieldRegistry::TRAJECTORY_STATUS => FieldRegistry::TRAJECTORY_STATUS_CLOSED,
            ]);

            // Clear cache
            TrajectoryService::invalidateCache($this->selfPacedId);

            $isOpen = $this->trajectoryService->isEnrollmentOpen($this->selfPacedId);

            // Restore to open
            $this->trajectoryService->updateTrajectory($this->selfPacedId, [
                FieldRegistry::TRAJECTORY_STATUS => FieldRegistry::TRAJECTORY_STATUS_OPEN,
            ]);
            TrajectoryService::invalidateCache($this->selfPacedId);

            return $this->assertEqual(false, $isOpen);
        });
    }

    private function testDataIntegrity(): void {
        echo "\n--- Data Integrity ---\n";

        $this->test('Trajectory data survives cache clear', function() {
            if (!$this->cohortId) return 'No trajectory created';

            // Get data
            $before = $this->trajectoryService->getTrajectory($this->cohortId);

            // Clear all caches
            TrajectoryService::invalidateCache();
            wp_cache_flush();

            // Get again
            $after = $this->trajectoryService->getTrajectory($this->cohortId);

            if ($before['mode'] !== $after['mode']) {
                return "Mode changed after cache clear";
            }

            if ($before['enrollment_deadline'] !== $after['enrollment_deadline']) {
                return "Enrollment deadline changed after cache clear";
            }

            return true;
        });

        $this->test('Linked editions persist after update', function() {
            if (!$this->cohortId) return 'No trajectory created';

            // Update something else
            $this->trajectoryService->updateTrajectory($this->cohortId, [
                FieldRegistry::TRAJECTORY_DESCRIPTION => 'Updated description',
            ]);

            TrajectoryService::invalidateCache($this->cohortId);

            // Check linked editions still there
            $links = $this->trajectoryService->getLinkedEditions($this->cohortId);

            return count($links) === 2 ? true : "Expected 2 linked editions, got " . count($links);
        });
    }

    private function cleanup(): void {
        echo "\n--- Cleanup ---\n";

        // Delete test trajectories
        $cleanup = [];
        if ($this->selfPacedId) $cleanup[] = $this->selfPacedId;
        if ($this->cohortId) $cleanup[] = $this->cohortId;
        if ($this->expiredCohortId) $cleanup[] = $this->expiredCohortId;
        if ($this->activeChoiceId) $cleanup[] = $this->activeChoiceId;

        foreach ($cleanup as $id) {
            wp_delete_post($id, true);
        }

        // Delete test enrollment
        if ($this->testEnrollmentId) {
            $this->enrollmentRepo->delete($this->testEnrollmentId);
        }

        // Delete test user
        if ($this->testUserId) {
            wp_delete_user($this->testUserId);
        }

        echo "Cleaned up " . count($cleanup) . " test trajectories\n";
    }

    private function printResults(): void {
        echo "\n=======================================================\n";
        echo "  TEST RESULTS\n";
        echo "=======================================================\n\n";

        foreach ($this->tests as $name => $result) {
            $status = $result['status'];
            $icon = $status === 'PASS' ? "\342\234\223" : "\342\234\227";
            $color = $status === 'PASS' ? "\033[32m" : "\033[31m";
            $reset = "\033[0m";

            echo "{$color}{$icon} [{$status}]{$reset} {$name}";
            if ($result['message']) {
                echo "\n    -> {$result['message']}";
            }
            echo "\n";
        }

        echo "\n-------------------------------------------------------\n";
        echo "Total: " . count($this->tests) . " | Passed: {$this->passed} | Failed: {$this->failed}\n";
        echo "-------------------------------------------------------\n";

        if ($this->failed > 0) {
            exit(1);
        }
    }
}

// Run tests
$runner = new TrajectoryModeTests();
$runner->run();
