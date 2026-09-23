<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Exceptions\AuditLogImmutableException;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Auth\AuditedAppAuthentication;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;

it('is append-only at the model level', function (): void {
    $entry = app(AuditLogger::class)->record(AuditAction::TenantCreated, platform: true);

    expect(fn () => $entry->forceFill(['action' => 'x'])->save())->toThrow(AuditLogImmutableException::class)
        ->and(fn () => $entry->delete())->toThrow(AuditLogImmutableException::class);
});

it('is append-only at the database level (triggers)', function (): void {
    $entry = app(AuditLogger::class)->record(AuditAction::TenantCreated, platform: true);

    expect(fn () => AuditLog::query()->withoutGlobalScopes()->whereKey($entry->id)->update(['action' => 'tampered']))->toThrow(QueryException::class)
        ->and(fn () => AuditLog::query()->withoutGlobalScopes()->whereKey($entry->id)->delete())->toThrow(QueryException::class);
});

it('redacts secrets and PII and records request metadata', function (): void {
    $tenant = activeTenant();
    app(TenantContext::class)->set($tenant->id, false);

    $entry = app(AuditLogger::class)->record(AuditAction::InvitationCreated, changes: [
        'email' => 'someone@example.com',
        'password' => 'hunter2',
        'note' => 'contact me at someone@example.com',
        'role' => 'viewer',
    ]);

    expect($entry->tenant_id)->toBe($tenant->id)
        ->and($entry->changes)->toBe(['email' => '[REDACTED]', 'password' => '[REDACTED]', 'note' => 'contact me at [REDACTED]', 'role' => 'viewer'])
        ->and($entry->request_id)->toStartWith('req_');
});

it('keeps platform events out of the tenant view', function (): void {
    $tenant = activeTenant();
    app(AuditLogger::class)->record(AuditAction::PlatformAdminCreated, platform: true);
    app(AuditLogger::class)->record(AuditAction::TenantCreated, tenantId: $tenant->id);

    app(TenantContext::class)->set($tenant->id, false);

    expect(AuditLog::query()->pluck('action')->all())->toBe([AuditAction::TenantCreated->value]);
});

it('records successful and failed sign-ins on both guards without storing the e-mail', function (): void {
    $user = tenantUser();
    $admin = platformAdmin();

    Auth::guard('web')->attempt(['email' => $user->email, 'password' => 'password-for-tests']);
    Auth::guard('web')->attempt(['email' => $user->email, 'password' => 'wrong']);
    Auth::guard('platform')->attempt(['email' => $admin->email, 'password' => 'password-for-tests']);
    Auth::guard('platform')->attempt(['email' => 'nobody@example.com', 'password' => 'wrong']);

    $entries = AuditLog::query()->withoutGlobalScopes()->whereIn('action', [AuditAction::Login->value, AuditAction::LoginFailed->value])->get();

    expect($entries->where('action', AuditAction::Login->value)->pluck('tenant_id')->all())->toEqualCanonicalizing([$user->tenant_id, null])
        ->and($entries->where('action', AuditAction::LoginFailed->value))->toHaveCount(2)
        ->and($entries->pluck('changes')->toJson())->not->toContain($user->email)
        ->and($entries->where('action', AuditAction::LoginFailed->value)->firstWhere('tenant_id', $user->tenant_id)?->changes['login_hash'] ?? null)
        ->toBe(hash('sha256', mb_strtolower($user->email)));

    expect($user->refresh()->last_login_at)->not->toBeNull();
});

it('records enabling and disabling 2FA', function (): void {
    $user = tenantUser(twoFactor: false);
    $provider = app(AuditedAppAuthentication::class);

    $provider->saveSecret($user, 'JBSWY3DPEHPK3PXP');
    $provider->saveRecoveryCodes($user, ['a-b', 'c-d']);
    $provider->saveSecret($user, null);

    $actions = AuditLog::query()->withoutGlobalScopes()->where('subject_id', $user->id)->orderBy('created_at')->pluck('action')->all();

    expect($actions)->toBe([AuditAction::TwoFactorEnabled->value, AuditAction::TwoFactorDisabled->value])
        ->and($user->refresh()->two_factor_secret)->toBeNull();
});
