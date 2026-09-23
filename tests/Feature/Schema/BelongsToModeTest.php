<?php

declare(strict_types=1);

use App\Modules\Tenancy\Exceptions\MissingTenantContextException;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\ModeProbe;

/**
 * BelongsToMode (plan 6.2, rules.md rule 4). No Phase 1 business table has a
 * `livemode` column yet, so this uses a probe table. No RefreshDatabase: DDL
 * commits implicitly in MariaDB, so the table is created and dropped here.
 */
beforeEach(function (): void {
    Schema::dropIfExists(ModeProbe::TABLE);
    Schema::create(ModeProbe::TABLE, function (Blueprint $table): void {
        $table->ulidAscii('id')->primary();
        $table->foreignUlidAscii('tenant_id');
        $table->boolean('livemode');
        $table->string('label', 50);
        $table->datetimes(6);
    });
});

afterEach(function (): void {
    Schema::dropIfExists(ModeProbe::TABLE);
});

const MODE_TENANT_A = '01J8Z3Q6T4Y0V8KX2M1N5P7R9A';
const MODE_TENANT_B = '01J8Z3Q6T4Y0V8KX2M1N5P7R9B';

it('fills livemode from the context and never mixes test and live rows', function (): void {
    $context = app(TenantContext::class);

    $context->set(MODE_TENANT_A, false);
    $test = ModeProbe::query()->create(['label' => 'test']);

    $context->set(MODE_TENANT_A, true);
    $live = ModeProbe::query()->create(['label' => 'live']);

    expect($test->livemode)->toBeFalse()
        ->and($live->livemode)->toBeTrue()
        ->and(ModeProbe::query()->pluck('label')->all())->toBe(['live']);

    $context->set(MODE_TENANT_A, false);
    expect(ModeProbe::query()->pluck('label')->all())->toBe(['test']);

    $context->set(MODE_TENANT_B, false);
    expect(ModeProbe::query()->count())->toBe(0);
});

it('keeps livemode immutable', function (): void {
    app(TenantContext::class)->set(MODE_TENANT_A, false);
    $probe = ModeProbe::query()->create(['label' => 'test']);

    $probe->livemode = true;

    expect(fn () => $probe->save())->toThrow(LogicException::class);
});

it('fails closed without a context', function (): void {
    expect(fn () => ModeProbe::query()->count())->toThrow(MissingTenantContextException::class);
});
