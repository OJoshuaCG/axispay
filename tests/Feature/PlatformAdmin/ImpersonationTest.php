<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ImpersonationState;
use App\Modules\PlatformAdmin\Actions\StartImpersonation;
use App\Modules\PlatformAdmin\Data\StartedImpersonation;
use App\Modules\PlatformAdmin\Enums\PlatformRole;
use App\Modules\PlatformAdmin\Exceptions\ImpersonationNotAllowedException;
use App\Modules\PlatformAdmin\Models\ImpersonationSession;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\travel;

function startImpersonation(?User $target = null): StartedImpersonation
{
    return app(StartImpersonation::class)->handle(platformAdmin(), $target ?? tenantUser(), 'Customer ticket #123');
}

/**
 * @return array<string, array<string, string>>
 */
function impersonationSession(StartedImpersonation $started): array
{
    return ['paylink.impersonation' => ['id' => $started->session->id, 'platform_admin_id' => $started->session->platform_admin_id]];
}

it('is superadmin only and needs a reason', function (): void {
    $user = tenantUser();

    expect(fn () => app(StartImpersonation::class)->handle(platformAdmin(superadmin: false), $user, 'x'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(StartImpersonation::class)->handle(platformAdmin(), $user, '  '))->toThrow(ImpersonationNotAllowedException::class);
});

it('is audited in the platform log and in the tenant log, time-boxed to 30 minutes', function (): void {
    $started = startImpersonation();

    $entries = AuditLog::query()->withoutGlobalScopes()->where('action', AuditAction::ImpersonationStarted->value)->get();

    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('tenant_id')->all())->toContain(null, $started->session->tenant_id)
        ->and($entries->first()?->changes['reason'] ?? null)->toBe('Customer ticket #123')
        ->and($started->session->expires_at->diffInMinutes(now(), true))->toBeLessThanOrEqual(30.0);
});

it('hands off to the app host with a single-use signed link', function (): void {
    $target = tenantUser();
    $started = startImpersonation($target);

    get($started->handoffUrl)->assertRedirect(route('filament.app.pages.dashboard'));

    expect(auth('web')->id())->toBe($target->id)
        ->and(app(ImpersonationState::class)->impersonationId())->toBe($started->session->id);

    auth('web')->logout();
    get($started->handoffUrl)->assertNotFound();
    get(explode('?', $started->handoffUrl)[0])->assertForbidden();
});

it('shows a banner and is read-only', function (): void {
    $target = tenantUser();
    $viewer = tenantUser($target->tenant, [SystemRole::Viewer]);
    $started = startImpersonation($target);

    actingAs($target, 'web')->withSession(impersonationSession($started));

    get(appUrl('/'))->assertOk()->assertSee(__('platform.impersonation.stop'));

    expect(Gate::forUser($target)->allows('viewAny', User::class))->toBeTrue()
        ->and(Gate::forUser($target)->allows('deactivate', $viewer))->toBeFalse()
        ->and(Gate::forUser($target)->allows('assignRoles', $viewer))->toBeFalse();

    get(appUrl('/profile'))->assertForbidden();
});

it('ends expired sessions, audits them and signs out', function (): void {
    $target = tenantUser();
    $started = startImpersonation($target);

    travel(31)->minutes();
    actingAs($target, 'web')->withSession(impersonationSession($started));

    get(appUrl('/'))->assertRedirect(route('filament.app.auth.login'));

    expect(ImpersonationSession::query()->withoutGlobalScopes()->find($started->session->id)?->end_reason)->toBe('expired')
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', AuditAction::ImpersonationEnded->value)->count())->toBe(2);
});

it('can be stopped from the banner and returns to the admin host', function (): void {
    $target = tenantUser();
    $started = startImpersonation($target);

    actingAs($target, 'web')->withSession(impersonationSession($started));

    post(appUrl('/impersonation/stop'))->assertRedirectContains(config()->string('paylink.surfaces.admin').'/tenants/'.$target->tenant_id);

    expect(ImpersonationSession::query()->withoutGlobalScopes()->find($started->session->id)?->end_reason)->toBe('stopped')
        ->and(auth('web')->check())->toBeFalse();
});

it('ends the impersonation when the platform admin is disabled or no longer a superadmin (M5)', function (string $change): void {
    $target = tenantUser();
    $started = startImpersonation($target);
    $admin = PlatformAdmin::query()->findOrFail($started->session->platform_admin_id);

    $change === 'disabled'
        ? $admin->forceFill(['disabled_at' => now()])->save()
        : $admin->forceFill(['role' => PlatformRole::SupportReadonly])->save();

    actingAs($target, 'web')->withSession(impersonationSession($started));

    get(appUrl('/'))->assertRedirect(route('filament.app.auth.login'));

    expect(ImpersonationSession::query()->withoutGlobalScopes()->find($started->session->id)?->end_reason)->toBe('invalid')
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', AuditAction::ImpersonationEnded->value)->count())->toBe(2);
})->with(['disabled', 'demoted']);
