<?php

declare(strict_types=1);

// The partial renders an inline icon for action_required/completing via the
// theme helper; the unit suite loads no theme, so provide a minimal GLOBAL stub.
namespace {
    if (!function_exists('stridence_icon')) {
        function stridence_icon(string $name, string $class = ''): string
        {
            return '<svg data-icon="' . $name . '"></svg>';
        }
    }
}

namespace Stride\Tests\Unit\Theme {

    use Stride\Domain\OfferingStatus;
    use Stride\Tests\TestCase;

    /**
     * Contract: partials/badge-status.php (Helder Tij redesign).
     *
     * The partial derives a badge VARIANT from the status string and renders a
     * pill using the Tailwind badge token utilities from tailwind.config.js
     * (bg-badge-*-bg / text-badge-*-text — variant recipes live inline in the
     * partial, NOT in components.css). Status is never colour-alone: the Dutch
     * label is always rendered. Unknown statuses fall back to the neutral
     * (cancelled) pair without emitting a PHP notice, escaped at the sink.
     */
    class BadgeStatusPartialTest extends TestCase
    {
        private const PARTIAL = '/web/app/themes/stridence/partials/badge-status.php';

        /** @var array<int, string> */
        private array $phpErrors = [];

        private function renderBadge(array $args): string
        {
            $this->phpErrors = [];

            set_error_handler(function (int $errno, string $errstr): bool {
                $this->phpErrors[] = $errstr;
                return true;
            });

            $level = ob_get_level();

            try {
                ob_start();
                (static function (array $args): void {
                    include dirname(__DIR__, 3) . self::PARTIAL;
                })($args);
                return (string) ob_get_clean();
            } finally {
                while (ob_get_level() > $level) {
                    ob_end_clean();
                }
                restore_error_handler();
            }
        }

        public function testTrajectoryVariantRendersAccentPairWithTrajectLabel(): void
        {
            $html = $this->renderBadge(['status' => 'trajectory']);

            $this->assertStringContainsString('bg-accent-subtle', $html);
            $this->assertStringContainsString('text-accent-hover', $html);
            $this->assertStringContainsString('Traject', $html);
        }

        public function testEnrolledVariantRendersOnlinePairWithIngeschrevenLabel(): void
        {
            $html = $this->renderBadge(['status' => 'enrolled']);

            $this->assertStringContainsString('bg-badge-online-bg', $html);
            $this->assertStringContainsString('text-badge-online-text', $html);
            $this->assertStringContainsString('Ingeschreven', $html);
        }

        public function testUnknownStatusFallsBackToNeutralVariantWithoutPhpNotice(): void
        {
            $html = $this->renderBadge(['status' => 'totally_bogus_status']);

            $this->assertStringContainsString('bg-badge-cancelled-bg', $html);
            $this->assertStringContainsString('text-badge-cancelled-text', $html);
            $this->assertStringContainsString('Totally_bogus_status', $html, 'Label always rendered — never colour-alone');
            $this->assertSame([], $this->phpErrors, 'Unknown status must not raise PHP notices/warnings');
        }

        public function testUnknownStatusLabelIsEscapedAtSink(): void
        {
            $html = $this->renderBadge(['status' => '<script>alert(1)</script>']);

            $this->assertStringNotContainsString('<script>', $html);
            $this->assertStringContainsString('&lt;script&gt;', $html);
        }

        public function testEveryOfferingStatusMapsToAVariantColourPairWithItsLabel(): void
        {
            foreach (OfferingStatus::cases() as $case) {
                $html = $this->renderBadge(['status' => $case->value]);

                $this->assertMatchesRegularExpression(
                    '/bg-(badge-(open|few|full|cancelled|online|free)-bg|accent-subtle)/',
                    $html,
                    "OfferingStatus::{$case->name} must map to a variant background"
                );
                $this->assertStringContainsString(
                    $case->label(),
                    $html,
                    "OfferingStatus::{$case->name} must keep its existing semantic label"
                );
                $this->assertSame([], $this->phpErrors, "OfferingStatus::{$case->name} must render notice-free");
            }
        }

        public function testCancelledOfferingStatusUsesNeutralCancelledPairPerDesign(): void
        {
            // Design sheet: Geannuleerd = neutral gray pair. OfferingStatus'
            // legacy frontendBadgeClass() says badge-full (red) — the partial
            // must override that one case to honour the Helder Tij table.
            $html = $this->renderBadge(['status' => 'cancelled']);

            $this->assertStringContainsString('bg-badge-cancelled-bg', $html);
            $this->assertStringContainsString('text-badge-cancelled-text', $html);
            $this->assertStringContainsString('Geannuleerd', $html);
        }

        public function testRegistrationStatusesKeepTheirLabelsAndMapToVariants(): void
        {
            $expected = [
                'confirmed'         => ['bg-badge-open-bg', 'Bevestigd'],
                'pending'           => ['bg-badge-few-bg', 'In behandeling'],
                'awaiting_approval' => ['bg-badge-few-bg', 'In afwachting'],
                'vol'               => ['bg-badge-full-bg', 'Volzet'],
                'online'            => ['bg-badge-online-bg', 'Online'],
                'free'              => ['bg-badge-free-bg', 'Gratis'],
            ];

            foreach ($expected as $status => [$variantClass, $label]) {
                $html = $this->renderBadge(['status' => $status]);

                $this->assertStringContainsString($variantClass, $html, "Status '{$status}' variant");
                $this->assertStringContainsString($label, $html, "Status '{$status}' label");
            }
        }

        public function testActionRequiredKeepsAlertIconAndFewVariant(): void
        {
            $html = $this->renderBadge(['status' => 'action_required']);

            $this->assertStringContainsString('alert-circle', $html);
            $this->assertStringContainsString('bg-badge-few-bg', $html);
            $this->assertStringContainsString('Voltooi inschrijving', $html);
        }

        public function testCompletingKeepsAlertIconAndFewVariant(): void
        {
            $html = $this->renderBadge(['status' => 'completing']);

            $this->assertStringContainsString('alert-circle', $html);
            $this->assertStringContainsString('bg-badge-few-bg', $html);
            $this->assertStringContainsString('Rond af', $html);
        }

        public function testOpenWithZeroSpotsStaysOpenVariantNotFew(): void
        {
            // Boundary pin: spots=0 means "no spots data worth flagging" —
            // the few-spots auto-detect only fires for 1..5, never 0.
            $html = $this->renderBadge(['status' => 'open', 'spots' => 0]);

            $this->assertStringContainsString('bg-badge-open-bg', $html);
            $this->assertStringNotContainsString('bg-badge-few-bg', $html);
            $this->assertStringNotContainsString('Nog ', $html);
            $this->assertStringContainsString('Open voor inschrijving', $html);
        }

        public function testOpenWithSixSpotsStaysOpenVariantNotFew(): void
        {
            // Boundary pin: 5 is the last "few" value; 6 renders plain open.
            $html = $this->renderBadge(['status' => 'open', 'spots' => 6]);

            $this->assertStringContainsString('bg-badge-open-bg', $html);
            $this->assertStringNotContainsString('bg-badge-few-bg', $html);
            $this->assertStringNotContainsString('Nog ', $html);
        }

        public function testOpenWithFewSpotsAutoDetectsFewVariantWithSpotCount(): void
        {
            $html = $this->renderBadge(['status' => 'open', 'spots' => 3]);

            $this->assertStringContainsString('bg-badge-few-bg', $html);
            $this->assertStringContainsString('Nog 3 plaatsen', $html);

            $singular = $this->renderBadge(['status' => 'open', 'spots' => 1]);
            $this->assertStringContainsString('Nog 1 plaats', $singular);
        }

        public function testDefaultSizeUsesCanonicalRecipeAndSmUsesCardRecipe(): void
        {
            $default = $this->renderBadge(['status' => 'open']);
            $this->assertStringContainsString('text-[12px]', $default);
            $this->assertStringContainsString('px-[11px]', $default);
            $this->assertStringContainsString('rounded-full', $default);
            $this->assertStringContainsString('font-bold', $default);

            $small = $this->renderBadge(['status' => 'open', 'size' => 'sm']);
            $this->assertStringContainsString('text-[11px]', $small);
            $this->assertStringContainsString('px-[9px]', $small);
            $this->assertStringContainsString('py-[3px]', $small);
        }
    }
}
