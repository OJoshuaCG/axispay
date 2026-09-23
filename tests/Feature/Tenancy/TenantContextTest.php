<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Exceptions\MissingTenantContextException;
use App\Modules\Tenancy\Scopes\TenantScope;
use App\Modules\Tenancy\TenantContext;

it('is scoped: a fresh instance per request or job', function (): void {
    $context = app(TenantContext::class);
    $context->set(activeTenant()->id, false);

    app()->forgetScopedInstances();

    expect(app(TenantContext::class))->not->toBe($context)
        ->and(app(TenantContext::class)->hasTenant())->toBeFalse();
});

it('fails closed without a tenant', function (): void {
    $context = app(TenantContext::class);

    expect(fn () => $context->idOrFail())->toThrow(MissingTenantContextException::class)
        ->and(fn () => $context->livemode())->toThrow(MissingTenantContextException::class);
});

it('rejects non-canonical tenant ids', function (): void {
    expect(fn () => app(TenantContext::class)->set('not-a-ulid', false))->toThrow(InvalidArgumentException::class);
});

it('runs a callback as another tenant and restores the previous state', function (): void {
    [$a, $b] = [activeTenant(), activeTenant()];
    $context = app(TenantContext::class);
    $context->set($a->id, true);

    $seen = $context->runAsTenant($b->id, false, fn (): array => [$context->idOrFail(), $context->livemode()]);

    expect($seen)->toBe([$b->id, false])
        ->and($context->idOrFail())->toBe($a->id)
        ->and($context->livemode())->toBeTrue();
});

it('audits every entry into the platform context and requires a reason', function (): void {
    [$a, $b] = [activeTenant(), activeTenant()];
    User::factory()->forTenant($a)->create();
    User::factory()->forTenant($b)->create();
    $context = app(TenantContext::class);

    expect(fn () => $context->runAsPlatform('  ', fn () => null))->toThrow(InvalidArgumentException::class);

    $count = $context->runAsPlatform('nightly usage report', fn (): int => User::query()->count());

    expect($count)->toBe(2)
        ->and($context->isPlatformMode())->toBeFalse();

    $entry = AuditLog::query()->withoutGlobalScope(TenantScope::class)->where('action', AuditAction::PlatformContextEntered->value)->sole();

    expect($entry->tenant_id)->toBeNull()
        ->and($entry->changes)->toBe(['reason' => 'nightly usage report']);
});
