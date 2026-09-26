<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * Every Filament simple page shows exactly ONE control bar (language + theme).
 * Regression: the 2FA set-up page of a signed-in person rendered the guest
 * bar (SIMPLE_LAYOUT_START) AND Filament's signed-in header, whose user menu
 * carries panel-controls (USER_MENU_BEFORE) with the test/live selector.
 */

/**
 * @return array{theme: int, language: int, guestBar: int, userHeader: int, modeSwitch: int}
 */
function simplePageControlCounts(string $html): array
{
    return [
        'theme' => substr_count($html, 'data-panel-theme-control'),
        // The language switcher is a POST form to /locale.
        'language' => substr_count($html, '/locale"'),
        'guestBar' => substr_count($html, 'class="pl-simple-controls"'),
        'userHeader' => substr_count($html, 'fi-simple-layout-header'),
        'modeSwitch' => substr_count($html, 'pl-mode-switch'),
    ];
}

it('shows only the guest bar on the sign-in pages of both panels', function (string $host): void {
    $html = (string) get($host === 'app' ? appUrl('/login') : adminUrl('/login'))->assertOk()->getContent();

    expect(simplePageControlCounts($html))->toBe(['theme' => 1, 'language' => 1, 'guestBar' => 1, 'userHeader' => 0, 'modeSwitch' => 0]);
})->with(['app', 'admin']);

it('shows one bar, without the test/live selector, on the 2FA set-up page of the tenant panel', function (): void {
    actingAs(tenantUser(roles: [SystemRole::Owner], twoFactor: false), 'web');

    $html = (string) get(appUrl('/multi-factor-authentication/set-up'))->assertOk()->getContent();

    expect(simplePageControlCounts($html))->toBe(['theme' => 1, 'language' => 1, 'guestBar' => 0, 'userHeader' => 1, 'modeSwitch' => 0]);
});

it('shows one bar on the mandatory 2FA set-up page of the platform panel', function (): void {
    actingAs(platformAdmin(twoFactor: false), 'platform');

    $html = (string) get(adminUrl('/multi-factor-authentication/set-up'))->assertOk()->getContent();

    expect(simplePageControlCounts($html))->toBe(['theme' => 1, 'language' => 1, 'guestBar' => 0, 'userHeader' => 1, 'modeSwitch' => 0]);
});

it('keeps the test/live selector in the topbar of regular pages', function (): void {
    actingAs(tenantUser(), 'web');

    get(appUrl('/'))->assertOk()->assertSee('pl-mode-switch', false);
});
