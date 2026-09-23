<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserInvitation;
use App\Modules\Tenancy\Exceptions\MissingTenantContextException;
use App\Modules\Tenancy\Exceptions\TenantMismatchException;
use App\Modules\Tenancy\Exceptions\TenantReassignmentException;
use App\Modules\Tenancy\TenantContext;

it('throws instead of returning every tenant when no context is set', function (): void {
    User::factory()->create();

    expect(fn () => User::query()->count())->toThrow(MissingTenantContextException::class)
        ->and(fn () => User::query()->find('01J8Z3Q6T4Y0V8KX2M1N5P7R9A'))->toThrow(MissingTenantContextException::class);
});

it('only returns rows of the current tenant', function (): void {
    [$a, $b] = [activeTenant(), activeTenant()];
    $own = User::factory()->forTenant($a)->create();
    $foreign = User::factory()->forTenant($b)->create();

    app(TenantContext::class)->set($a->id, false);

    expect(User::query()->pluck('id')->all())->toBe([$own->id])
        ->and(User::query()->find($foreign->id))->toBeNull();
});

it('fills tenant_id from the context on create', function (): void {
    $tenant = activeTenant();
    app(TenantContext::class)->set($tenant->id, false);

    $invitation = new UserInvitation;
    $invitation->forceFill([
        'email' => 'someone@example.com',
        'role_name' => 'viewer',
        'token_hash' => str_repeat('a', 64),
        'expires_at' => now()->addDay(),
    ])->save();

    expect($invitation->tenant_id)->toBe($tenant->id);
});

it('refuses to create a row without a tenant context', function (): void {
    $invitation = new UserInvitation;
    $invitation->forceFill([
        'email' => 'someone@example.com',
        'role_name' => 'viewer',
        'token_hash' => str_repeat('b', 64),
        'expires_at' => now()->addDay(),
    ]);

    expect(fn () => $invitation->save())->toThrow(MissingTenantContextException::class);
});

it('refuses to write into another tenant from inside a tenant context', function (): void {
    [$a, $b] = [activeTenant(), activeTenant()];
    app(TenantContext::class)->set($a->id, false);

    expect(fn () => User::factory()->forTenant($b)->create())->toThrow(TenantMismatchException::class);
});

it('keeps tenant_id immutable', function (): void {
    [$a, $b] = [activeTenant(), activeTenant()];
    $user = User::factory()->forTenant($a)->create();

    $user->tenant_id = $b->id;

    expect(fn () => $user->save())->toThrow(TenantReassignmentException::class);
});
