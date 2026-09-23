<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Exceptions\MissingTenantContextException;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Tests\Fixtures\CountTenantUsersJob;
use Tests\Fixtures\UnscopedUsersJob;

it('fails when a job without tenant context queries a tenant model (plan 26.2 #17)', function (): void {
    User::factory()->count(2)->create();

    expect(fn () => dispatch_sync(new UnscopedUsersJob))->toThrow(MissingTenantContextException::class);
});

it('restores the dispatching tenant context inside a TenantAware job', function (): void {
    [$a, $b] = [activeTenant(), activeTenant()];
    User::factory()->forTenant($a)->count(2)->create();
    User::factory()->forTenant($b)->count(3)->create();

    $context = app(TenantContext::class);
    $context->set($a->id, false);
    $job = new CountTenantUsersJob('probe-count');
    $context->clear();

    dispatch($job);

    expect(Cache::get('probe-count'))->toBe(2)
        ->and($job->tenantId())->toBe($a->id)
        ->and($job->livemode())->toBeFalse()
        ->and($context->hasTenant())->toBeFalse();
});

it('cannot capture a context that does not exist', function (): void {
    expect(fn () => new CountTenantUsersJob('x'))->toThrow(MissingTenantContextException::class);
});
