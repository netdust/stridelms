<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Tests\TestCase;

/**
 * Contract: templates/dashboard/partials/notification-item.php.
 *
 * The partial maps each notification `type` to a per-type icon + tile
 * background so notifications are scannable at a glance (the `$typeStyles`
 * map). A wrong/unknown type falls back to bell/bg-accent-subtle. An empty
 * notification array renders nothing (early `return`, no fatal).
 *
 * Pure presentation: no DB, no Alpine. `stridence_icon` + `human_time_diff`
 * come from the global stubs in wordpress-stubs.php; the stub for
 * `stridence_icon` echoes `data-icon="<name>"` so the chosen icon is
 * assertable. The tile bg class lives on the icon's wrapping <span>.
 */
class NotificationItemRenderTest extends TestCase
{
    private const PARTIAL = '/web/app/themes/stridence/templates/dashboard/partials/notification-item.php';

    /** @var array<int, string> */
    private array $phpErrors = [];

    /**
     * @param array<string, mixed> $notification
     */
    private function render(array $notification): string
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
                include dirname(__DIR__, 2) . self::PARTIAL;
            })(['notification' => $notification]);
            return (string) ob_get_clean();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            restore_error_handler();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function notification(string $type): array
    {
        return [
            'id'        => 'n1',
            'type'      => $type,
            'title'     => 'X',
            'body'      => '',
            'url'       => '/x',
            'timestamp' => time(),
            'read'      => false,
        ];
    }

    /**
     * @dataProvider mappedTypes
     */
    public function testTypeRendersItsIconAndTileColour(string $type, string $icon, string $bg): void
    {
        $html = $this->render($this->notification($type));

        $this->assertStringContainsString("data-icon=\"{$icon}\"", $html, "type {$type} icon");
        $this->assertStringContainsString($bg, $html, "type {$type} tile bg");
        $this->assertSame([], $this->phpErrors, "type {$type} must render notice-free");
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function mappedTypes(): array
    {
        return [
            'certificate' => ['certificate', 'award',        'bg-success/10'],
            'completion'  => ['completion',  'check-circle', 'bg-success/10'],
            'enrollment'  => ['enrollment',  'check-square', 'bg-accent-subtle'],
            'attendance'  => ['attendance',  'map-pin',      'bg-info/10'],
            'session'     => ['session',     'calendar',     'bg-primary-subtle'],
        ];
    }

    public function testUnknownTypeFallsBackToBell(): void
    {
        $html = $this->render($this->notification('totally-unknown'));

        $this->assertStringContainsString('data-icon="bell"', $html);
        $this->assertStringContainsString('bg-accent-subtle', $html);
        $this->assertSame([], $this->phpErrors, 'Unknown type must render notice-free');
    }

    public function testEmptyNotificationRendersNothing(): void
    {
        $this->assertSame('', trim($this->render([])));
        $this->assertSame([], $this->phpErrors, 'Empty notification must render notice-free');
    }
}
