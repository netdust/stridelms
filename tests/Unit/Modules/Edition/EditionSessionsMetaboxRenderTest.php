<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Modules\Edition;

use Stride\Modules\Edition\Admin\OfferingSidebarPartial;
use Stride\Modules\Edition\EditionCPT;
use Stride\Tests\TestCase;
use WP_Post;

/**
 * Render-assertion guard for Task 1.2 (gate-deadlines-reminders plan):
 * the `gate_deadline` / `post_gate_deadline` inputs must render inside
 * OfferingSidebarPartial (the actual class rendering the questionnaire/
 * documents "Tijdens inschrijving" and "Na afloop" gate groups on the
 * edition sidebar metabox — ground-truthed; the plan's file reference to
 * EditionSessionsMetabox.php was drift).
 *
 * Tier B: presentational markup guard only — asserts esc_attr'd value,
 * correct `name="ntdst_fields[...]"`, and that the field lives inside the
 * conditionally-hidden wrapper toggled by the existing enable checkbox
 * (this codebase's toggle convention is jQuery + inline style, NOT
 * Alpine x-show — no Alpine is enqueued on this admin screen).
 */
class EditionSessionsMetaboxRenderTest extends TestCase
{
    private function renderSidebar(int $postId, array $meta): string
    {
        global $_test_posts, $_test_data_manager_meta;

        $_test_posts[$postId] = new WP_Post([
            'ID' => $postId,
            'post_type' => EditionCPT::POST_TYPE,
            'post_status' => 'publish',
        ]);
        $_test_data_manager_meta[EditionCPT::POST_TYPE][$postId] = $meta;

        $post = $_test_posts[$postId];

        ob_start();
        OfferingSidebarPartial::render($post, EditionCPT::POST_TYPE);
        return (string) ob_get_clean();
    }

    public function testGateDeadlineInputRendersWithEscapedValueAndCorrectName(): void
    {
        $html = $this->renderSidebar(9001, [
            'requires_questionnaire' => true,
            'gate_deadline' => '2026-08-01',
        ]);

        $this->assertStringContainsString('name="ntdst_fields[gate_deadline]"', $html);
        $this->assertStringContainsString('value="2026-08-01"', $html);
    }

    public function testGateDeadlineValueIsEscapedAgainstInjection(): void
    {
        $html = $this->renderSidebar(9002, [
            'requires_documents' => true,
            'gate_deadline' => '"><script>alert(1)</script>',
        ]);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function testGateDeadlineWrapperIsHiddenWhenNeitherQuestionnaireNorDocumentsEnabled(): void
    {
        $html = $this->renderSidebar(9003, [
            'requires_questionnaire' => false,
            'requires_documents' => false,
            'gate_deadline' => '2026-08-01',
        ]);

        $this->assertMatchesRegularExpression(
            '/id="stride-gate-deadline"[^>]*display:none;/',
            $html,
            'Gate deadline wrapper must be hidden (inline display:none) when neither gate task is enabled — this metabox has no Alpine loaded, so the toggle is jQuery + inline style, mirroring documents_instruction.',
        );
    }

    public function testGateDeadlineWrapperIsVisibleWhenQuestionnaireEnabled(): void
    {
        $html = $this->renderSidebar(9004, [
            'requires_questionnaire' => true,
            'requires_documents' => false,
            'gate_deadline' => '2026-08-01',
        ]);

        $this->assertMatchesRegularExpression(
            '/id="stride-gate-deadline"(?!.*display:none;)[^>]*>/',
            $html,
        );
    }

    public function testPostGateDeadlineInputRendersWithEscapedValueAndCorrectName(): void
    {
        $html = $this->renderSidebar(9005, [
            'post_requires_evaluation' => true,
            'post_gate_deadline' => '2026-09-15',
        ]);

        $this->assertStringContainsString('name="ntdst_fields[post_gate_deadline]"', $html);
        $this->assertStringContainsString('value="2026-09-15"', $html);
    }

    public function testPostGateDeadlineWrapperIsHiddenWhenNeitherPostTaskEnabled(): void
    {
        $html = $this->renderSidebar(9006, [
            'post_requires_evaluation' => false,
            'post_requires_documents' => false,
            'post_gate_deadline' => '2026-09-15',
        ]);

        $this->assertMatchesRegularExpression(
            '/id="stride-post-gate-deadline"[^>]*display:none;/',
            $html,
        );
    }

    public function testPostGateDeadlineWrapperIsVisibleWhenPostDocumentsEnabled(): void
    {
        $html = $this->renderSidebar(9007, [
            'post_requires_evaluation' => false,
            'post_requires_documents' => true,
            'post_gate_deadline' => '2026-09-15',
        ]);

        $this->assertMatchesRegularExpression(
            '/id="stride-post-gate-deadline"(?!.*display:none;)[^>]*>/',
            $html,
        );
    }
}
