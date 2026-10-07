<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Checkout\Actions\RotateReturnSigningSecret;
use App\Modules\Checkout\Models\ReturnSigningSecret;
use App\Modules\Checkout\Services\ReturnSigningSecrets;
use App\Modules\Identity\Exceptions\ReauthenticationRequiredException;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Filament\Pages\TenantPaymentSettings;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Support\GatewayTestHelpers;

use function Pest\Laravel\startSession;

/*
 * The per-tenant secret that signs the proof of the return to the merchant
 * (spec B7, ADR-0064): one per tenant and mode, created the first time it is
 * needed, rotated without downtime like the validation secret (the previous
 * one keeps signing for 24 hours), shown once, with `settings:manage` and
 * re-authentication.
 */

beforeEach(function (): void {
    startSession();
});

/**
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function inReturnTenant(Tenant $tenant, Closure $callback, bool $livemode = false): mixed
{
    return app(TenantContext::class)->runAsTenant($tenant->id, $livemode, $callback);
}

it('creates the secret on first use, once per tenant and mode, and returns the same one afterwards', function (): void {
    $tenant = activeTenant();

    $first = inReturnTenant($tenant, static fn (): array => app(ReturnSigningSecrets::class)->signingSecrets());
    $again = inReturnTenant($tenant, static fn (): array => app(ReturnSigningSecrets::class)->signingSecrets());
    $live = inReturnTenant($tenant, static fn (): array => app(ReturnSigningSecrets::class)->signingSecrets(), livemode: true);

    expect($first)->toHaveCount(1)
        ->and($first[0])->toMatch('/^rsec_[0-9a-f]{64}$/')
        ->and($again)->toBe($first)
        ->and($live)->toHaveCount(1)
        ->and($live)->not->toBe($first)
        ->and(ReturnSigningSecret::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(2);
});

it('keeps the previous secret signing for 24 hours after a rotation', function (): void {
    Carbon::setTestNow('2026-10-09 12:00:00');
    $tenant = activeTenant();

    $old = inReturnTenant($tenant, static fn (): string => app(ReturnSigningSecrets::class)->signingSecrets()[0]);
    $new = inReturnTenant($tenant, static fn (): string => app(ReturnSigningSecrets::class)->rotate());

    expect($new)->not->toBe($old)->and($new)->toMatch('/^rsec_[0-9a-f]{64}$/')
        ->and(inReturnTenant($tenant, static fn (): array => app(ReturnSigningSecrets::class)->signingSecrets()))->toBe([$new, $old])
        ->and(inReturnTenant($tenant, static fn (): array => app(ReturnSigningSecrets::class)->signingSecrets(now()->addHours(25)->toImmutable())))->toBe([$new]);

    // Rotating again inside the window drops the oldest secret.
    $newest = inReturnTenant($tenant, static fn (): string => app(ReturnSigningSecrets::class)->rotate());
    expect(inReturnTenant($tenant, static fn (): array => app(ReturnSigningSecrets::class)->signingSecrets()))->toBe([$newest, $new]);
    Carbon::setTestNow();
});

it('rotates for a settings manager after re-authentication, audits it and never records the secret', function (): void {
    $tenant = activeTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));
    GatewayTestHelpers::reauthenticated();
    $old = inReturnTenant($tenant, static fn (): string => app(ReturnSigningSecrets::class)->signingSecrets()[0]);

    $new = app(RotateReturnSigningSecret::class)->handle($owner);

    $entry = AuditLog::query()->where('action', AuditAction::ReturnSecretRotated->value)->sole();

    expect($new)->not->toBe($old)
        ->and(inReturnTenant($tenant, static fn (): array => app(ReturnSigningSecrets::class)->signingSecrets()))->toBe([$new, $old])
        ->and((string) json_encode($entry->changes))->not->toContain($new);
    expect((string) json_encode($entry->changes))->not->toContain($old);
});

it('refuses to rotate without re-authentication, without settings:manage and on a read-only tenant', function (): void {
    $tenant = activeTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));

    expect(fn () => app(RotateReturnSigningSecret::class)->handle($owner))->toThrow(ReauthenticationRequiredException::class);

    GatewayTestHelpers::reauthenticated();
    $viewer = actingAsTenantUser(tenantUser($tenant, [SystemRole::Viewer]));
    expect(fn () => app(RotateReturnSigningSecret::class)->handle($viewer))->toThrow(AuthorizationException::class);

    $suspended = activeTenant(TenantStatus::Suspended);
    $suspendedOwner = actingAsTenantUser(tenantUser($suspended));
    GatewayTestHelpers::reauthenticated();
    expect(fn () => app(RotateReturnSigningSecret::class)->handle($suspendedOwner))->toThrow(AuthorizationException::class);
});

it('offers "Rotate return secret" on the payment settings page and shows the new secret once', function (): void {
    $tenant = activeTenant();
    actingAsTenantUser(tenantUser($tenant));
    GatewayTestHelpers::reauthenticated();
    $old = inReturnTenant($tenant, static fn (): string => app(ReturnSigningSecrets::class)->signingSecrets()[0]);

    Livewire::test(TenantPaymentSettings::class)
        ->assertActionVisible('rotateReturnSecret')
        ->callAction('rotateReturnSecret')
        ->assertHasNoActionErrors()
        ->assertActionMounted('showIssuedSecret');

    $secrets = inReturnTenant($tenant, static fn (): array => app(ReturnSigningSecrets::class)->signingSecrets());

    expect($secrets)->toHaveCount(2)->and($secrets[1])->toBe($old);
});

it('hides the rotate action from users who cannot manage the settings', function (): void {
    $tenant = activeTenant();
    actingAsTenantUser(tenantUser($tenant, [SystemRole::Owner]));

    Livewire::test(TenantPaymentSettings::class)->assertActionVisible('rotateReturnSecret');

    $suspended = activeTenant(TenantStatus::Suspended);
    actingAsTenantUser(tenantUser($suspended));

    Livewire::test(TenantPaymentSettings::class)->assertActionHidden('rotateReturnSecret');
});
