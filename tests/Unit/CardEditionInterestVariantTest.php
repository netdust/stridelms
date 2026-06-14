<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The card interest variant is gated by the EFFECTIVE status (Announcement),
 * NOT by date-absence — so a dateless ONLINE edition (status Open) renders a
 * normal enroll card, while a dateless KLASSIKAAL edition (status Announcement)
 * renders the interest variant. (Stefan, 2026-06-14.)
 *
 * This proves the AF-5 denial path: online dateless editions are normal
 * enrollables, never interest cards.
 */
final class CardEditionInterestVariantTest extends TestCase
{
    /**
     * Render the real pure-renderer partial with $args in scope. The card
     * calls no services — the WP/theme functions it needs (esc_url, esc_html,
     * esc_html_e, __, esc_attr, get_permalink, get_post_status,
     * stridence_template_part, stride_format_money) are provided by
     * tests/Stubs/wordpress-stubs.php.
     *
     * @param array<string,mixed> $args
     */
    private function render(array $args): string
    {
        ob_start();
        (function (array $args): void {
            include dirname(__DIR__, 2) . '/web/app/themes/stridence/partials/card-edition.php';
        })($args);
        return (string) ob_get_clean();
    }

    public function test_announcement_dateless_klassikaal_renders_interest_variant(): void
    {
        $html = $this->render([
            'edition' => ['id' => 1, 'start_date' => null, 'price' => 0, 'course_id' => 0],
            'status'  => 'announcement',
        ]);
        $this->assertStringContainsString('Geen datum', $html);
        $this->assertStringContainsString('Toon interesse', $html);
    }

    public function test_open_dateless_online_renders_normal_enroll_not_interest(): void
    {
        $html = $this->render([
            'edition' => ['id' => 2, 'start_date' => null, 'price' => 0, 'course_id' => 0],
            'status'  => 'open',
        ]);
        $this->assertStringNotContainsString('Toon interesse', $html, 'online dateless must NOT get interest CTA');
        $this->assertStringNotContainsString('Geen datum — toon interesse', $html);
        // It is a normal enrollable card — assert the default CTA label.
        $this->assertStringContainsString('Bekijk editie', $html);
    }

    public function test_open_dated_renders_normal_card(): void
    {
        $soon = date('Y-m-d', strtotime('+5 days'));
        $html = $this->render([
            'edition' => ['id' => 3, 'start_date' => $soon, 'price' => 0, 'course_id' => 0],
            'status'  => 'open',
        ]);
        $this->assertStringNotContainsString('Toon interesse', $html);
        $this->assertStringContainsString('Bekijk editie', $html);
    }
}
