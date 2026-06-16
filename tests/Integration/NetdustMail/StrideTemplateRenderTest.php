<?php

declare(strict_types=1);

namespace Tests\Integration\NetdustMail;

use Netdust\Mail\MailService;
use Netdust\Mail\MailTemplateRepository;
use Netdust\Mail\SmartCodeRegistry;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Invoicing\QuoteService;
use Stride\Modules\Mail\StrideMailBridge;

/**
 * Renders EVERY seeded Stride mail template against a realistic context and
 * asserts it actually sends.
 *
 * Why this exists: the mail engine BLOCKS a send when a template contains an
 * unregistered smartcode (ndmail_unparsed_smartcodes). A template/smartcode
 * drift therefore silently stops production mail — no error a user sees, no
 * red test. This suite is the tripwire: if someone renames a smartcode,
 * removes a category, or seeds a template with a typo'd code, one of these
 * renders goes red.
 *
 * Engine semantics (SmartCodeParser): registered codes resolving to null/''
 * fall back to their default (empty allowed) — only UNREGISTERED codes
 * survive parsing and block the send. So `send() === true` plus "no {{ left
 * in output" is exactly the production-readiness contract per template.
 *
 * @group integration
 * @group netdust-mail
 */
class StrideTemplateRenderTest extends \IntegrationTestCase
{
    private MailService $mailService;
    private StrideMailBridge $bridge;

    private static int $editionId = 0;
    private static int $trajectoryId = 0;
    private static int $registrationId = 0;
    private static int $quoteId = 0;
    private static array $registrationIds = [];

    /** @var array<int, array{hook: string, callback: callable, priority: int}> */
    private array $addedFilters = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailService = ntdst_get(MailService::class);
        $this->bridge = ntdst_get(StrideMailBridge::class);
        ntdst_get(SmartCodeRegistry::class)->refresh();

        // Seed the production templates (idempotent: existing slugs skipped).
        $this->bridge->seedTemplates();

        // Guarantee the precondition this suite depends on: every seeded
        // template is ACTIVE. seedTemplates() sets status=active on CREATE, but
        // the `status` field carries a 'draft' default — on a fresh DB (CI) the
        // created rows can read back as 'draft', which makes MailService::send()
        // reject them (ndmail_template_inactive) and turns every render red.
        // The test owns its preconditions: force-activate rather than trust the
        // create-path + default interaction across environments.
        $templateRepo = ntdst_get(MailTemplateRepository::class);
        foreach (array_keys($this->templateDefinitions()) as $slug) {
            $tpl = $templateRepo->findBySlug($slug);
            if ($tpl && ($tpl->fields['status'] ?? '') !== 'active') {
                $templateRepo->update($tpl->ID, ['status' => 'active']);
            }
        }

        if (!self::$editionId) {
            $this->createFixtures();
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->addedFilters as $filter) {
            remove_filter($filter['hook'], $filter['callback'], $filter['priority']);
        }
        $this->addedFilters = [];

        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        global $wpdb;
        foreach (self::$registrationIds as $id) {
            $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $id]);
        }
        self::$registrationIds = [];
        self::$editionId = self::$trajectoryId = self::$registrationId = self::$quoteId = 0;

        parent::tearDownAfterClass();
    }

    /**
     * One realistic object graph: edition (dates/venue/price), trajectory,
     * a confirmed registration and a quote hanging off it.
     */
    private function createFixtures(): void
    {
        self::$editionId = wp_insert_post([
            'post_type'   => 'vad_edition',
            'post_title'  => 'Render Edition',
            'post_status' => 'publish',
        ]);
        self::$testPosts[] = self::$editionId;
        update_post_meta(self::$editionId, '_ntdst_status', 'open');
        update_post_meta(self::$editionId, '_ntdst_start_date', date('Y-m-d', strtotime('+30 days')));
        update_post_meta(self::$editionId, '_ntdst_end_date', date('Y-m-d', strtotime('+31 days')));
        update_post_meta(self::$editionId, '_ntdst_venue', 'Render Venue');
        update_post_meta(self::$editionId, '_ntdst_price', '150');

        self::$trajectoryId = wp_insert_post([
            'post_type'   => 'vad_trajectory',
            'post_title'  => 'Render Trajectory',
            'post_status' => 'publish',
        ]);
        self::$testPosts[] = self::$trajectoryId;

        $regId = ntdst_get(RegistrationRepository::class)->create([
            'user_id'    => self::$testUserId,
            'edition_id' => self::$editionId,
            'status'     => 'confirmed',
        ]);
        $this->assertIsInt($regId, 'fixture registration must be created');
        self::$registrationId = $regId;
        self::$registrationIds[] = $regId;

        $quoteId = ntdst_get(QuoteService::class)->createQuote(
            (int) self::$testUserId,
            self::$registrationId,
            self::$editionId,
            [[
                'id'         => self::$editionId,
                'type'       => 'edition',
                'title'      => 'Render Edition',
                'quantity'   => 1,
                'unit_price' => \Stride\Domain\Money::cents(15000),
            ]],
        );
        $this->assertIsInt($quoteId, 'fixture quote must be created');
        self::$quoteId = $quoteId;
        self::$testPosts[] = $quoteId;
    }

    /**
     * The full context every Stride trigger could supply — each template
     * only reads the slice it needs.
     */
    private function renderContext(): array
    {
        return [
            'user_id'         => (int) self::$testUserId,
            'edition_id'      => self::$editionId,
            'registration_id' => self::$registrationId,
            'quote_id'        => self::$quoteId,
            'trajectory_id'   => self::$trajectoryId,
            'name'            => 'Render Tester',
            'email'           => 'render-test@example.test',
            'registration'    => ['name' => 'Render Tester', 'email' => 'render-test@example.test'],
        ];
    }

    private function interceptMail(callable $callback): void
    {
        $filter = function ($null, $atts) use ($callback) {
            $callback($atts);
            return true;
        };
        add_filter('pre_wp_mail', $filter, 10, 2);
        $this->addedFilters[] = ['hook' => 'pre_wp_mail', 'callback' => $filter, 'priority' => 10];
    }

    /**
     * @return array<string, mixed> slug => definition
     */
    private function templateDefinitions(): array
    {
        $method = new \ReflectionMethod(StrideMailBridge::class, 'getTemplateDefinitions');

        return $method->invoke($this->bridge);
    }

    /**
     * @test
     */
    public function everySeededTemplateExistsAndIsActive(): void
    {
        foreach (array_keys($this->templateDefinitions()) as $slug) {
            $template = get_page_by_path($slug, OBJECT, 'ndmail_template');
            $this->assertNotNull($template, "seeded template '{$slug}' must exist as ndmail_template");
            $this->assertSame('publish', $template->post_status, "template '{$slug}' must be published");
        }
    }

    /**
     * @test
     */
    public function everySeededTemplateRendersAndSendsWithRealisticContext(): void
    {
        $context = $this->renderContext();
        $failures = [];

        foreach (array_keys($this->templateDefinitions()) as $slug) {
            $sent = null;
            $this->interceptMail(function (array $atts) use (&$sent) {
                $sent = $atts;
            });

            $result = $this->mailService->send($slug, $context, ['to' => 'render-test@example.test']);

            if (is_wp_error($result)) {
                $failures[] = "{$slug}: [{$result->get_error_code()}] {$result->get_error_message()}";
                continue;
            }
            if ($sent === null) {
                $failures[] = "{$slug}: send() returned truthy but no mail reached wp_mail";
                continue;
            }
            if (trim((string) $sent['subject']) === '') {
                $failures[] = "{$slug}: rendered subject is empty";
            }
            if (str_contains((string) $sent['subject'], '{{') || str_contains((string) $sent['message'], '{{')) {
                $failures[] = "{$slug}: unrendered smartcode left in output";
            }
        }

        $this->assertSame(
            [],
            $failures,
            "Templates that fail to render/send:\n - " . implode("\n - ", $failures)
        );
    }
}
