<?php
/**
 * Stride LMS - Development Seed Script
 *
 * Run with: ddev exec wp eval-file scripts/seed.php
 *
 * Creates test data for development:
 * - Users with various roles
 * - LearnDash courses (content)
 * - Editions (scheduled offerings with pricing)
 * - Sessions (individual meeting days)
 * - Registrations (user enrollments)
 * - LearnDash groups (learning paths)
 * - Vouchers (all discount types)
 * - Quotes (all statuses)
 */

if (!defined('ABSPATH')) {
    echo "This script must be run via WP-CLI: ddev exec wp eval-file scripts/seed.php\n";
    exit(1);
}

// Prevent accidental runs in production
if (defined('WP_ENV') && WP_ENV === 'production') {
    echo "ERROR: Cannot run seed script in production!\n";
    exit(1);
}

use ntdst\Stride\core\EditionService;
use ntdst\Stride\core\SessionService;
use ntdst\Stride\core\RegistrationRepository;
use ntdst\Stride\FieldRegistry;

/**
 * Seed Data Generator
 */
class StrideSeedData {

    private array $created = [
        'users' => [],
        'courses' => [],
        'editions' => [],
        'sessions' => [],
        'registrations' => [],
        'groups' => [],
        'vouchers' => [],
        'quotes' => [],
    ];

    private const SEED_META_KEY = '_stride_seed_data';

    private ?EditionService $editionService = null;
    private ?SessionService $sessionService = null;
    private ?RegistrationRepository $regRepo = null;

    public function run(): void {
        echo "\n=== Stride LMS Seed Data Generator ===\n\n";

        $this->initServices();
        $this->createUsers();
        $this->createCourses();
        $this->createGroups();
        $this->createVouchers();
        $this->createQuotes();
        $this->createEnrollments();

        $this->saveSeedManifest();
        $this->printSummary();
    }

    /**
     * Initialize services
     */
    private function initServices(): void {
        if (function_exists('ntdst_get')) {
            $this->editionService = ntdst_get(EditionService::class);
            $this->sessionService = ntdst_get(SessionService::class);
            $this->regRepo = ntdst_get(RegistrationRepository::class);
        }
    }

    /**
     * Create test users with different roles
     */
    private function createUsers(): void {
        echo "Creating users...\n";

        $users = [
            // Admins
            ['login' => 'seed_admin', 'email' => 'admin@seed.test', 'role' => 'administrator', 'first' => 'Admin', 'last' => 'Seed'],

            // Group leaders (instructors)
            ['login' => 'seed_instructor1', 'email' => 'instructor1@seed.test', 'role' => 'group_leader', 'first' => 'Jan', 'last' => 'De Trainer'],
            ['login' => 'seed_instructor2', 'email' => 'instructor2@seed.test', 'role' => 'group_leader', 'first' => 'Marie', 'last' => 'Docent'],

            // Regular students
            ['login' => 'seed_student1', 'email' => 'student1@seed.test', 'role' => 'subscriber', 'first' => 'Pieter', 'last' => 'Janssen'],
            ['login' => 'seed_student2', 'email' => 'student2@seed.test', 'role' => 'subscriber', 'first' => 'Anna', 'last' => 'De Vries'],
            ['login' => 'seed_student3', 'email' => 'student3@seed.test', 'role' => 'subscriber', 'first' => 'Thomas', 'last' => 'Bakker'],
            ['login' => 'seed_student4', 'email' => 'student4@seed.test', 'role' => 'subscriber', 'first' => 'Sophie', 'last' => 'Visser'],
            ['login' => 'seed_student5', 'email' => 'student5@seed.test', 'role' => 'subscriber', 'first' => 'Lucas', 'last' => 'Smit'],

            // Corporate users (from organizations)
            ['login' => 'seed_corp1', 'email' => 'corp1@company-a.test', 'role' => 'subscriber', 'first' => 'Emma', 'last' => 'Corporate', 'company' => 'Company A BV'],
            ['login' => 'seed_corp2', 'email' => 'corp2@company-a.test', 'role' => 'subscriber', 'first' => 'Noah', 'last' => 'Business', 'company' => 'Company A BV'],
            ['login' => 'seed_corp3', 'email' => 'corp3@company-b.test', 'role' => 'subscriber', 'first' => 'Liam', 'last' => 'Enterprise', 'company' => 'Enterprise B NV'],
        ];

        foreach ($users as $userData) {
            $existing = get_user_by('login', $userData['login']);
            if ($existing) {
                echo "  - User {$userData['login']} already exists, skipping\n";
                $this->created['users'][] = $existing->ID;
                continue;
            }

            $userId = wp_insert_user([
                'user_login' => $userData['login'],
                'user_email' => $userData['email'],
                'user_pass' => 'seedpass123',
                'first_name' => $userData['first'],
                'last_name' => $userData['last'],
                'display_name' => $userData['first'] . ' ' . $userData['last'],
                'role' => $userData['role'],
            ]);

            if (is_wp_error($userId)) {
                echo "  ! Failed to create {$userData['login']}: {$userId->get_error_message()}\n";
                continue;
            }

            // Mark as seed data
            update_user_meta($userId, self::SEED_META_KEY, true);

            // Add company if specified
            if (!empty($userData['company'])) {
                update_user_meta($userId, 'company', $userData['company']);
            }

            $this->created['users'][] = $userId;
            echo "  + Created user: {$userData['login']} (ID: {$userId})\n";
        }
    }

    /**
     * Create LearnDash courses with editions and sessions
     */
    private function createCourses(): void {
        echo "\nCreating courses with editions and sessions...\n";

        if (!defined('LEARNDASH_VERSION')) {
            echo "  ! LearnDash not active, skipping courses\n";
            return;
        }

        $courses = [
            // In-person courses with multiple editions
            [
                'title' => 'Basisopleiding Veilig Werken',
                'type' => 'in-person',
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+2 weeks')),
                        'end_date' => date('Y-m-d', strtotime('+2 weeks +2 days')),
                        'price' => 450.00,
                        'price_non_member' => 520.00,
                        'capacity' => 20,
                        'venue' => 'Utrecht Centrum',
                        'speakers' => 'Jan De Trainer',
                        'status' => 'open',
                        'sessions' => [
                            ['time' => '09:00-12:30', 'offset' => 0],
                            ['time' => '13:30-17:00', 'offset' => 0],
                            ['time' => '09:00-17:00', 'offset' => 1],
                            ['time' => '09:00-12:00', 'offset' => 2],
                        ],
                    ],
                    [
                        'start_date' => date('Y-m-d', strtotime('+1 month')),
                        'end_date' => date('Y-m-d', strtotime('+1 month +2 days')),
                        'price' => 450.00,
                        'price_non_member' => 520.00,
                        'capacity' => 20,
                        'venue' => 'Amsterdam Zuid',
                        'speakers' => 'Marie Docent',
                        'status' => 'open',
                        'sessions' => [
                            ['time' => '09:00-12:30', 'offset' => 0],
                            ['time' => '13:30-17:00', 'offset' => 0],
                            ['time' => '09:00-17:00', 'offset' => 1],
                            ['time' => '09:00-12:00', 'offset' => 2],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Gevorderde Veiligheidstechnieken',
                'type' => 'in-person',
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+3 weeks')),
                        'end_date' => date('Y-m-d', strtotime('+3 weeks +1 day')),
                        'price' => 650.00,
                        'price_non_member' => 750.00,
                        'capacity' => 15,
                        'venue' => 'Rotterdam Centraal',
                        'speakers' => 'Prof. Dr. Veilig',
                        'status' => 'open',
                        'sessions' => [
                            ['time' => '09:00-17:00', 'offset' => 0],
                            ['time' => '09:00-17:00', 'offset' => 1],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Leidinggevende Veiligheid',
                'type' => 'in-person',
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+6 weeks')),
                        'end_date' => date('Y-m-d', strtotime('+6 weeks')),
                        'price' => 850.00,
                        'price_non_member' => 950.00,
                        'capacity' => 12,
                        'venue' => 'Den Haag Business Center',
                        'speakers' => 'Jan De Trainer, Marie Docent',
                        'status' => 'open',
                        'sessions' => [
                            ['time' => '09:00-17:00', 'offset' => 0],
                        ],
                    ],
                ],
            ],

            // Hybrid course (in-person + online sessions)
            [
                'title' => 'Hybride Veiligheidstraining',
                'type' => 'hybrid',
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+4 weeks')),
                        'end_date' => date('Y-m-d', strtotime('+5 weeks')),
                        'price' => 550.00,
                        'price_non_member' => 650.00,
                        'capacity' => 15,
                        'venue' => 'Utrecht / Online',
                        'speakers' => 'Jan De Trainer',
                        'status' => 'open',
                        'sessions' => [
                            // Day 1: In-person session
                            ['time' => '09:00-17:00', 'offset' => 0, 'type' => FieldRegistry::SESSION_TYPE_IN_PERSON],
                            // Online module 1 (linked to first lesson)
                            ['time' => '00:00-23:59', 'offset' => 3, 'type' => FieldRegistry::SESSION_TYPE_ONLINE, 'lesson_index' => 0],
                            // Online module 2 (linked to second lesson)
                            ['time' => '00:00-23:59', 'offset' => 5, 'type' => FieldRegistry::SESSION_TYPE_ONLINE, 'lesson_index' => 1],
                            // Day 2: In-person session
                            ['time' => '09:00-17:00', 'offset' => 7, 'type' => FieldRegistry::SESSION_TYPE_IN_PERSON],
                            // Assignment (linked to third lesson)
                            ['time' => '00:00-23:59', 'offset' => 8, 'type' => FieldRegistry::SESSION_TYPE_ASSIGNMENT, 'lesson_index' => 2],
                        ],
                    ],
                ],
            ],

            // Online courses (no physical sessions)
            [
                'title' => 'E-learning: Veiligheidsprotocollen',
                'type' => 'online',
                'editions' => [
                    [
                        'start_date' => date('Y-m-d'),
                        'end_date' => date('Y-m-d', strtotime('+1 year')),
                        'price' => 150.00,
                        'price_non_member' => 180.00,
                        'capacity' => 0, // Unlimited
                        'venue' => 'Online',
                        'status' => 'open',
                        'sessions' => [], // No sessions for e-learning
                    ],
                ],
            ],
            [
                'title' => 'E-learning: EHBO Basis',
                'type' => 'online',
                'editions' => [
                    [
                        'start_date' => date('Y-m-d'),
                        'end_date' => date('Y-m-d', strtotime('+1 year')),
                        'price' => 99.00,
                        'price_non_member' => 120.00,
                        'capacity' => 0,
                        'venue' => 'Online',
                        'status' => 'open',
                        'sessions' => [],
                    ],
                ],
            ],
            [
                'title' => 'E-learning: Brandpreventie',
                'type' => 'online',
                'editions' => [
                    [
                        'start_date' => date('Y-m-d'),
                        'end_date' => date('Y-m-d', strtotime('+1 year')),
                        'price' => 125.00,
                        'price_non_member' => 150.00,
                        'capacity' => 0,
                        'venue' => 'Online',
                        'status' => 'open',
                        'sessions' => [],
                    ],
                ],
            ],

            // Special status editions
            [
                'title' => 'Verouderde Training',
                'type' => 'in-person',
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+2 weeks')),
                        'end_date' => date('Y-m-d', strtotime('+2 weeks')),
                        'price' => 300.00,
                        'price_non_member' => 350.00,
                        'capacity' => 10,
                        'venue' => 'Eindhoven',
                        'status' => 'cancelled',
                        'sessions' => [
                            ['time' => '09:00-17:00', 'offset' => 0],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Uitgestelde Workshop',
                'type' => 'in-person',
                'editions' => [
                    [
                        'start_date' => date('Y-m-d', strtotime('+4 weeks')),
                        'end_date' => date('Y-m-d', strtotime('+4 weeks')),
                        'price' => 400.00,
                        'price_non_member' => 460.00,
                        'capacity' => 8,
                        'venue' => 'Groningen',
                        'status' => 'postponed',
                        'sessions' => [
                            ['time' => '10:00-16:00', 'offset' => 0],
                        ],
                    ],
                ],
            ],
        ];

        foreach ($courses as $courseData) {
            // Check if course already exists
            $existing = get_page_by_title($courseData['title'], OBJECT, 'sfwd-courses');
            if ($existing) {
                echo "  - Course '{$courseData['title']}' already exists, skipping\n";
                $this->created['courses'][] = $existing->ID;

                // Track existing editions for this course
                if ($this->editionService) {
                    $editions = $this->editionService->getEditionsForCourse($existing->ID);
                    foreach ($editions as $edition) {
                        $this->created['editions'][] = $edition['id'];
                    }
                }
                continue;
            }

            $courseId = wp_insert_post([
                'post_title' => $courseData['title'],
                'post_type' => 'sfwd-courses',
                'post_status' => 'publish',
                'post_content' => $this->generateCourseContent($courseData),
            ]);

            if (is_wp_error($courseId)) {
                echo "  ! Failed to create course: {$courseId->get_error_message()}\n";
                continue;
            }

            // Mark as seed data
            update_post_meta($courseId, self::SEED_META_KEY, true);

            // LearnDash course settings (open for testing)
            $courseSettings = [
                'course_price_type' => 'open',
            ];
            update_post_meta($courseId, '_sfwd-courses', $courseSettings);

            // Custom Stride meta
            update_post_meta($courseId, '_course_type', $courseData['type']);

            $this->created['courses'][] = $courseId;
            echo "  + Created course: {$courseData['title']} (ID: {$courseId})\n";

            // Create lessons for the course
            $lessonIds = $this->createLessonsForCourse($courseId, $courseData['title']);

            // Create editions for this course
            $this->createEditionsForCourse($courseId, $courseData, $lessonIds);
        }
    }

    /**
     * Create editions and sessions for a course
     *
     * @param int $courseId Course ID
     * @param array $courseData Course data including editions
     * @param array $lessonIds Available lesson IDs for this course
     */
    private function createEditionsForCourse(int $courseId, array $courseData, array $lessonIds = []): void {
        if (!$this->editionService || empty($courseData['editions'])) {
            return;
        }

        foreach ($courseData['editions'] as $editionData) {
            $editionId = $this->editionService->createEdition([
                'course_id' => $courseId,
                'start_date' => $editionData['start_date'],
                'end_date' => $editionData['end_date'],
                'price' => $editionData['price'],
                'price_non_member' => $editionData['price_non_member'],
                'capacity' => $editionData['capacity'],
                'venue' => $editionData['venue'],
                'speakers' => $editionData['speakers'] ?? '',
                'status' => $editionData['status'],
                'invoice_enabled' => true,
                'certificate_enabled' => true,
            ]);

            if (is_wp_error($editionId)) {
                echo "    ! Failed to create edition: {$editionId->get_error_message()}\n";
                continue;
            }

            // Mark as seed data
            update_post_meta($editionId, self::SEED_META_KEY, true);

            $this->created['editions'][] = $editionId;
            echo "    + Created edition: {$editionData['start_date']} at {$editionData['venue']} (ID: {$editionId})\n";

            // Create sessions for this edition
            $this->createSessionsForEdition($editionId, $editionData, $lessonIds);
        }
    }

    /**
     * Create sessions for an edition
     *
     * @param int $editionId Edition ID
     * @param array $editionData Edition data including sessions
     * @param array $lessonIds Available lesson IDs for hybrid sessions
     */
    private function createSessionsForEdition(int $editionId, array $editionData, array $lessonIds = []): void {
        if (!$this->sessionService || empty($editionData['sessions'])) {
            return;
        }

        foreach ($editionData['sessions'] as $sessionData) {
            // Parse time range
            $times = explode('-', $sessionData['time']);
            $startTime = trim($times[0]);
            $endTime = trim($times[1] ?? '17:00');

            // Calculate date based on offset from start_date
            $sessionDate = date('Y-m-d', strtotime($editionData['start_date'] . " +{$sessionData['offset']} days"));

            // Determine session type and linked lessons
            $sessionType = $sessionData['type'] ?? FieldRegistry::SESSION_TYPE_IN_PERSON;
            $sessionLessonIds = [];

            // For online/assignment types, link to specific lessons if provided
            if (in_array($sessionType, [FieldRegistry::SESSION_TYPE_ONLINE, FieldRegistry::SESSION_TYPE_ASSIGNMENT])) {
                if (!empty($sessionData['lesson_ids'])) {
                    $sessionLessonIds = $sessionData['lesson_ids'];
                } elseif (isset($sessionData['lesson_index']) && isset($lessonIds[$sessionData['lesson_index']])) {
                    // Link to a specific lesson by index
                    $sessionLessonIds = [$lessonIds[$sessionData['lesson_index']]];
                }
            }

            $createData = [
                'edition_id' => $editionId,
                'date' => $sessionDate,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'location' => $editionData['venue'],
                FieldRegistry::SESSION_TYPE => $sessionType,
                FieldRegistry::SESSION_LESSON_IDS => $sessionLessonIds,
            ];

            $sessionId = $this->sessionService->createSession($createData);

            if (is_wp_error($sessionId)) {
                echo "      ! Failed to create session: {$sessionId->get_error_message()}\n";
                continue;
            }

            // Mark as seed data
            update_post_meta($sessionId, self::SEED_META_KEY, true);

            $this->created['sessions'][] = $sessionId;

            $duration = $this->sessionService->getSessionDuration($sessionId);
            $typeEmoji = match($sessionType) {
                FieldRegistry::SESSION_TYPE_ONLINE => '💻',
                FieldRegistry::SESSION_TYPE_ASSIGNMENT => '📝',
                default => '🏢',
            };
            $lessonInfo = !empty($sessionLessonIds) ? ' (linked to ' . count($sessionLessonIds) . ' lessons)' : '';
            echo "      + Created {$typeEmoji} session: {$sessionDate} {$startTime}-{$endTime} ({$duration}h){$lessonInfo}\n";
        }
    }

    /**
     * Create lessons for a course
     *
     * @return array Created lesson IDs
     */
    private function createLessonsForCourse(int $courseId, string $courseTitle): array {
        $lessonIds = [];
        $lessons = [
            'Introductie en Overzicht',
            'Kernconcepten',
            'Praktische Toepassing',
            'Evaluatie en Afronding',
        ];

        foreach ($lessons as $index => $lessonTitle) {
            $lessonId = wp_insert_post([
                'post_title' => $lessonTitle,
                'post_type' => 'sfwd-lessons',
                'post_status' => 'publish',
                'post_content' => "Les content voor: {$lessonTitle}\n\nDit is onderdeel van de cursus: {$courseTitle}",
                'menu_order' => $index + 1,
            ]);

            if (!is_wp_error($lessonId)) {
                update_post_meta($lessonId, self::SEED_META_KEY, true);
                update_post_meta($lessonId, 'course_id', $courseId);

                // LearnDash lesson settings
                update_post_meta($lessonId, '_sfwd-lessons', [
                    'sfwd-lessons_course' => $courseId,
                ]);

                $lessonIds[] = $lessonId;
                echo "      + Created lesson: {$lessonTitle} (ID: {$lessonId})\n";
            }
        }

        // Set up LearnDash course steps (required for LD to recognize lesson-course association)
        if (!empty($lessonIds) && class_exists('LDLMS_Factory_Post')) {
            $courseStepsObj = LDLMS_Factory_Post::course_steps($courseId);
            if ($courseStepsObj) {
                // Build hierarchical steps array (lessons at top level, no topics/quizzes)
                $steps = [];
                foreach ($lessonIds as $lessonId) {
                    $steps[$lessonId] = [];
                }
                $courseStepsObj->set_steps($steps);
                echo "      + Linked " . count($lessonIds) . " lessons to course via course steps\n";
            }
        }

        return $lessonIds;
    }

    /**
     * Create LearnDash groups (trajectories/learning paths)
     */
    private function createGroups(): void {
        echo "\nCreating groups (trajectories)...\n";

        if (!defined('LEARNDASH_VERSION')) {
            echo "  ! LearnDash not active, skipping groups\n";
            return;
        }

        $groups = [
            [
                'title' => 'Traject: Veiligheidsspecialist',
                'description' => 'Volledig leertraject om veiligheidsspecialist te worden.',
                'price' => 1200.00,
                'member_price' => 999.00,
            ],
            [
                'title' => 'Traject: Teamleider Veiligheid',
                'description' => 'Leertraject voor leidinggevenden in veiligheidsfuncties.',
                'price' => 1800.00,
                'member_price' => 1500.00,
            ],
            [
                'title' => 'Traject: EHBO & Brandveiligheid',
                'description' => 'Gecombineerd traject voor basis veiligheidscertificering.',
                'price' => 350.00,
                'member_price' => 280.00,
            ],
        ];

        foreach ($groups as $groupData) {
            $existing = get_page_by_title($groupData['title'], OBJECT, 'groups');
            if ($existing) {
                echo "  - Group '{$groupData['title']}' already exists, skipping\n";
                $this->created['groups'][] = $existing->ID;
                continue;
            }

            $groupId = wp_insert_post([
                'post_title' => $groupData['title'],
                'post_type' => 'groups',
                'post_status' => 'publish',
                'post_content' => $groupData['description'],
            ]);

            if (is_wp_error($groupId)) {
                echo "  ! Failed to create group: {$groupId->get_error_message()}\n";
                continue;
            }

            update_post_meta($groupId, self::SEED_META_KEY, true);
            update_post_meta($groupId, '_group_price', $groupData['price']);
            update_post_meta($groupId, '_group_member_price', $groupData['member_price']);

            // Associate some courses with this group
            if (!empty($this->created['courses']) && function_exists('ld_update_course_group_access')) {
                $coursesToAssign = array_slice($this->created['courses'], 0, 3);
                foreach ($coursesToAssign as $courseId) {
                    ld_update_course_group_access($courseId, $groupId, false);
                }
            }

            $this->created['groups'][] = $groupId;
            echo "  + Created group: {$groupData['title']} (ID: {$groupId})\n";
        }
    }

    /**
     * Create vouchers with different configurations using VoucherService
     */
    private function createVouchers(): void {
        echo "\nCreating vouchers...\n";

        // Use VoucherService if available (new architecture)
        $voucherService = null;
        if (function_exists('ntdst_get') && class_exists(\Stride\Modules\Invoicing\VoucherService::class)) {
            $voucherService = ntdst_get(\Stride\Modules\Invoicing\VoucherService::class);
        }

        $vouchers = [
            // Full discount (100% off)
            [
                'code' => 'TEST-FULL-FREE',
                'discount_type' => \Stride\Domain\DiscountType::Full->value,
                'discount_value' => 0,
                'usage_limit' => 10,
            ],
            // Fixed amount discount (€50 in cents)
            [
                'code' => 'TEST-50-EURO',
                'discount_type' => \Stride\Domain\DiscountType::Fixed->value,
                'discount_value' => 5000, // €50 in cents
                'usage_limit' => 5,
            ],
            // Percentage discount
            [
                'code' => 'TEST-20-PERCENT',
                'discount_type' => \Stride\Domain\DiscountType::Percentage->value,
                'discount_value' => 20,
                'usage_limit' => 100,
            ],
        ];

        foreach ($vouchers as $data) {
            if ($voucherService) {
                // Use VoucherService (new architecture)
                $existing = $voucherService->getVoucherByCode($data['code']);
                if ($existing) {
                    echo "  - Voucher {$data['code']} already exists, skipping\n";
                    $this->created['vouchers'][] = $existing['id'];
                    continue;
                }

                $result = $voucherService->createVoucher($data);
                if (is_wp_error($result)) {
                    echo "  ! ERROR creating voucher {$data['code']}: " . $result->get_error_message() . "\n";
                } else {
                    // Mark as seed data
                    update_post_meta($result, self::SEED_META_KEY, true);
                    $this->created['vouchers'][] = $result;
                    echo "  + Created voucher: {$data['code']} (ID: {$result})\n";
                }
            } else {
                // Fallback to direct post creation (legacy)
                $existing = get_posts([
                    'post_type' => 'vad_voucher',
                    'meta_key' => 'code',
                    'meta_value' => $data['code'],
                    'posts_per_page' => 1,
                ]);

                if (!empty($existing)) {
                    echo "  - Voucher '{$data['code']}' already exists, skipping\n";
                    $this->created['vouchers'][] = $existing[0]->ID;
                    continue;
                }

                $voucherId = wp_insert_post([
                    'post_title' => $data['code'],
                    'post_type' => 'vad_voucher',
                    'post_status' => 'publish',
                ]);

                if (is_wp_error($voucherId)) {
                    echo "  ! Failed to create voucher: {$voucherId->get_error_message()}\n";
                    continue;
                }

                update_post_meta($voucherId, self::SEED_META_KEY, true);
                update_post_meta($voucherId, 'code', $data['code']);
                update_post_meta($voucherId, 'discount_type', $data['discount_type']);
                update_post_meta($voucherId, 'discount_value', $data['discount_value']);
                update_post_meta($voucherId, 'usage_limit', $data['usage_limit']);
                update_post_meta($voucherId, 'used_count', 0);
                update_post_meta($voucherId, 'status', 'active');

                $this->created['vouchers'][] = $voucherId;
                echo "  + Created voucher: {$data['code']} (ID: {$voucherId})\n";
            }
        }
    }

    /**
     * Create quotes in various statuses
     */
    private function createQuotes(): void {
        echo "\nCreating quotes...\n";

        if (empty($this->created['users']) || empty($this->created['editions'])) {
            echo "  ! Need users and editions first, skipping quotes\n";
            return;
        }

        $statuses = ['draft', 'draft', 'sent', 'sent', 'sent', 'exported', 'exported'];
        $quoteNumber = 1;

        foreach ($this->created['users'] as $userId) {
            $user = get_user_by('ID', $userId);
            if (!$user || strpos($user->user_login, 'seed_student') === false && strpos($user->user_login, 'seed_corp') === false) {
                continue;
            }

            // Create 1-2 quotes per student
            $numQuotes = rand(1, 2);
            for ($i = 0; $i < $numQuotes; $i++) {
                $editionId = $this->created['editions'][array_rand($this->created['editions'])];
                $edition = $this->editionService ? $this->editionService->getEdition($editionId) : null;
                $status = $statuses[array_rand($statuses)];
                $price = (float) ($edition['price'] ?? 299.00);
                $tax = round($price * 0.21, 2);
                $total = $price + $tax;

                $quoteNumberFormatted = sprintf('SEED-%04d', $quoteNumber);

                $quoteId = wp_insert_post([
                    'post_title' => "Quote {$quoteNumberFormatted} - {$user->display_name}",
                    'post_type' => 'vad_quote',
                    'post_status' => 'publish',
                ]);

                if (is_wp_error($quoteId)) {
                    echo "  ! Failed to create quote: {$quoteId->get_error_message()}\n";
                    continue;
                }

                update_post_meta($quoteId, self::SEED_META_KEY, true);
                update_post_meta($quoteId, '_quote_number', $quoteNumberFormatted);
                update_post_meta($quoteId, '_quote_user_id', $userId);
                update_post_meta($quoteId, '_quote_item_type', 'edition');
                update_post_meta($quoteId, '_quote_item_id', $editionId);
                update_post_meta($quoteId, '_quote_status', $status);
                update_post_meta($quoteId, '_quote_subtotal', $price);
                update_post_meta($quoteId, '_quote_tax', $tax);
                update_post_meta($quoteId, '_quote_total', $total);
                update_post_meta($quoteId, '_quote_valid_until', date('Y-m-d', strtotime('+30 days')));
                update_post_meta($quoteId, '_quote_created_at', current_time('mysql'));

                // Items as JSON
                $items = [
                    [
                        'type' => 'edition',
                        'id' => $editionId,
                        'title' => $edition['title'] ?? 'Edition',
                        'price' => $price,
                        'quantity' => 1,
                    ],
                ];
                update_post_meta($quoteId, '_quote_items', wp_json_encode($items));

                // Billing info
                $billing = [
                    'name' => $user->display_name,
                    'email' => $user->user_email,
                    'company' => get_user_meta($userId, 'company', true) ?: '',
                    'address' => 'Teststraat 123',
                    'city' => 'Amsterdam',
                    'postal_code' => '1234 AB',
                ];
                update_post_meta($quoteId, '_quote_billing', wp_json_encode($billing));

                // Status-specific fields
                if ($status === 'sent' || $status === 'exported') {
                    update_post_meta($quoteId, '_quote_sent_at', current_time('mysql'));
                    update_post_meta($quoteId, '_quote_last_sent_to', $user->user_email);
                }
                if ($status === 'exported') {
                    update_post_meta($quoteId, '_quote_exported_at', current_time('mysql'));
                }

                $this->created['quotes'][] = $quoteId;
                echo "  + Created quote: {$quoteNumberFormatted} ({$status}) for {$user->user_login}\n";
                $quoteNumber++;
            }
        }
    }

    /**
     * Create registrations (enrollments) via RegistrationRepository
     */
    private function createEnrollments(): void {
        echo "\nCreating registrations...\n";

        if (empty($this->created['users']) || empty($this->created['editions'])) {
            echo "  ! Need users and editions first, skipping registrations\n";
            return;
        }

        if (!$this->regRepo) {
            echo "  ! RegistrationRepository not available, skipping\n";
            return;
        }

        // Enroll each student in 1-3 random editions
        foreach ($this->created['users'] as $userId) {
            $user = get_user_by('ID', $userId);
            if (!$user || strpos($user->user_login, 'seed_student') === false && strpos($user->user_login, 'seed_corp') === false) {
                continue;
            }

            // Only use open editions
            $openEditions = array_filter($this->created['editions'], function($editionId) {
                $edition = $this->editionService ? $this->editionService->getEdition($editionId) : null;
                return $edition && ($edition['status'] ?? '') === 'open';
            });

            if (empty($openEditions)) {
                continue;
            }

            $openEditions = array_values($openEditions);
            $numEditions = rand(1, min(3, count($openEditions)));
            $editionKeys = array_rand($openEditions, $numEditions);
            if (!is_array($editionKeys)) {
                $editionKeys = [$editionKeys];
            }

            foreach ($editionKeys as $key) {
                $editionId = $openEditions[$key];

                // Check if already registered
                $userRegs = $this->regRepo->getByUser($userId);
                $alreadyRegistered = false;
                foreach ($userRegs as $reg) {
                    if (($reg['edition_id'] ?? 0) === $editionId) {
                        $alreadyRegistered = true;
                        break;
                    }
                }
                if ($alreadyRegistered) {
                    continue;
                }

                // Determine enrollment path (organization users still use individual path)
                $path = RegistrationRepository::PATH_INDIVIDUAL;

                $registrationId = $this->regRepo->create([
                    'user_id' => $userId,
                    'edition_id' => $editionId,
                    'status' => RegistrationRepository::STATUS_CONFIRMED,
                    'enrollment_path' => $path,
                    'notes' => 'Seeded registration',
                ]);

                if (is_wp_error($registrationId)) {
                    echo "  ! Failed to create registration: {$registrationId->get_error_message()}\n";
                    continue;
                }

                $this->created['registrations'][] = $registrationId;
                $edition = $this->editionService->getEdition($editionId);
                echo "  + Registered {$user->user_login} for edition {$editionId} ({$edition['title']})\n";

                // Grant LearnDash access to the linked course
                if ($this->editionService && function_exists('ld_update_course_access')) {
                    $courseId = $this->editionService->getCourseId($editionId);
                    if ($courseId) {
                        ld_update_course_access($userId, $courseId, false);
                    }
                }
            }
        }
    }

    /**
     * Generate course content/description
     */
    private function generateCourseContent(array $courseData): string {
        $content = "<h2>{$courseData['title']}</h2>\n\n";
        $content .= "<p>Dit is een {$courseData['type']} cursus.</p>\n\n";

        if ($courseData['type'] === 'in-person' && !empty($courseData['editions'])) {
            $content .= "<h3>Beschikbare data</h3>\n";
            $content .= "<p>Bekijk de beschikbare edities en schrijf je in via het inschrijfformulier.</p>\n";
        }

        return $content;
    }

    /**
     * Save manifest of created data for cleanup
     */
    private function saveSeedManifest(): void {
        update_option('stride_seed_manifest', $this->created);
        update_option('stride_seed_timestamp', current_time('mysql'));
    }

    /**
     * Print summary
     */
    private function printSummary(): void {
        echo "\n=== Seed Complete ===\n\n";
        echo "Created:\n";
        echo "  - Users: " . count($this->created['users']) . "\n";
        echo "  - Courses: " . count($this->created['courses']) . "\n";
        echo "  - Editions: " . count($this->created['editions']) . "\n";
        echo "  - Sessions: " . count($this->created['sessions']) . "\n";
        echo "  - Registrations: " . count($this->created['registrations']) . "\n";
        echo "  - Groups: " . count($this->created['groups']) . "\n";
        echo "  - Vouchers: " . count($this->created['vouchers']) . "\n";
        echo "  - Quotes: " . count($this->created['quotes']) . "\n";
        echo "\n";
        echo "Test credentials:\n";
        echo "  - All seed users have password: seedpass123\n";
        echo "  - Admin: seed_admin@seed.test\n";
        echo "  - Students: seed_student1@seed.test through seed_student5@seed.test\n";
        echo "\n";
        echo "Voucher codes:\n";
        echo "  - TEST-FULL-FREE (100% off, 10 uses)\n";
        echo "  - TEST-50-EURO (EUR50 off, 5 uses)\n";
        echo "  - TEST-20-PERCENT (20% off, 100 uses)\n";
        echo "\n";
        echo "To remove seed data: ddev exec wp eval-file scripts/unseed.php --force\n";
    }
}

// Run the seeder
$seeder = new StrideSeedData();
$seeder->run();
