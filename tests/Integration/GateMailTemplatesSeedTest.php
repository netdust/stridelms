<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Mail\StrideMailBridge;

/**
 * Task 5.3 — seed test for the three gate-mail templates
 * (stride-gate-todo, stride-gate-reminder, stride-gate-deadline-tomorrow)
 * and the '4' -> '5' maybeSeedTemplates() version bump.
 *
 * Per the ship note in seedTemplates(): the bump alone seeds nothing —
 * seedTemplates() skips slugs that already exist. The bump is what makes
 * maybeSeedTemplates() re-run at all; the three NEW slugs seed because they
 * don't exist yet on a fresh DB. Both halves are asserted here.
 *
 * gotcha_ci_green_local_red: on a fresh DB, MailTemplateRepository::create()
 * can read back a draft/empty-subject post if the Data Manager model isn't
 * registered with the correct meta_prefix yet. Assert `publish` status AND
 * a NON-EMPTY subject so this test would actually catch that failure mode.
 *
 * Run: STRIDE_TEST_DB_DISPOSABLE=1 ddev exec vendor/bin/phpunit \
 *   -c phpunit-integration.xml.dist --filter GateMailTemplatesSeedTest
 */
final class GateMailTemplatesSeedTest extends IntegrationTestCase
{
    private const OPTION = 'stride_mail_templates_seeded';

    /** @var array<int, string> slugs newly created by this test, to clean up */
    private array $createdSlugs = [
        'stride-gate-todo',
        'stride-gate-reminder',
        'stride-gate-deadline-tomorrow',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        delete_option(self::OPTION);
    }

    protected function tearDown(): void
    {
        delete_option(self::OPTION);
        parent::tearDown();
    }

    private function templatePost(string $slug): ?\WP_Post
    {
        $post = get_page_by_path($slug, OBJECT, 'ndmail_template');

        return $post instanceof \WP_Post ? $post : null;
    }

    /** @test */
    public function seedingAtVersionFiveCreatesAllThreeGateTemplatesPublishedWithNonEmptySubject(): void
    {
        $bridge = ntdst_get(StrideMailBridge::class);
        $bridge->maybeSeedTemplates();

        $this->assertSame('5', get_option(self::OPTION), 'Seed version option must be bumped to 5');

        foreach ($this->createdSlugs as $slug) {
            $post = $this->templatePost($slug);
            $this->assertNotNull($post, "Template '$slug' must exist as an ndmail_template post after seeding");
            $this->assertSame('publish', $post->post_status, "Template '$slug' must be published, not draft (gotcha_ci_green_local_red)");

            $subject = (string) get_post_meta($post->ID, '_ndmail_subject', true);
            $this->assertNotSame('', $subject, "Template '$slug' must have a non-empty subject (gotcha_ci_green_local_red)");

            $this->assertNotSame('', trim($post->post_content), "Template '$slug' must have a non-empty body");
        }
    }

    /** @test */
    public function seedingDoesNotTouchAPreExistingTemplate(): void
    {
        $before = $this->templatePost('stride-enrollment-created-user');
        $this->assertNotNull($before, 'Pre-existing seeded template must already exist before this test seeds again');
        $beforeModified = $before->post_modified_gmt;
        $beforeId = $before->ID;

        $bridge = ntdst_get(StrideMailBridge::class);
        $bridge->maybeSeedTemplates();

        $after = $this->templatePost('stride-enrollment-created-user');
        $this->assertNotNull($after);
        $this->assertSame($beforeId, $after->ID, 'Existing template post identity must be unchanged');
        $this->assertSame($beforeModified, $after->post_modified_gmt, 'Existing template must not be re-written by the version bump');
    }
}
