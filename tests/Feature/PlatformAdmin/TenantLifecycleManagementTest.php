<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Actions\AcceptInvitation;
use App\Modules\Identity\Actions\ResendInvitation;
use App\Modules\Identity\Actions\RevokeInvitation;
use App\Modules\Identity\Data\AcceptInvitationData;
use App\Modules\Identity\Enums\InvitationStatus;
use App\Modules\Identity\Exceptions\EmailNotAvailableException;
use App\Modules\Identity\Exceptions\InvitationNotAllowedException;
use App\Modules\Identity\Exceptions\InvitationNotPendingException;
use App\Modules\Identity\Notifications\UserInvitationNotification;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use App\Modules\Tenancy\Actions\InviteTenantOwner;
use App\Modules\Tenancy\Actions\UpdateTenantProfile;
use App\Modules\Tenancy\Data\UpdateTenantProfileData;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\get;
use function Pest\Laravel\travel;

/*
 * ADR-0043: tenant profile edits, owner invitations, resend and revoke from
 * the platform panel (Actions and policies; the panel is covered in
 * TenantMembersPanelTest).
 */

/**
 * Accept URLs of every invitation e-mail sent so far, in order.
 *
 * @return list<string>
 */
function sentInvitationUrls(): array
{
    $urls = [];

    Notification::assertSentOnDemand(UserInvitationNotification::class, function (UserInvitationNotification $notification) use (&$urls): bool {
        $urls[] = $notification->acceptUrl;

        return true;
    });

    return $urls;
}

/**
 * @return Collection<int, AuditLog>
 */
function platformAuditsOf(AuditAction $action): Collection
{
    return AuditLog::query()->withoutGlobalScope(TenantScope::class)->where('action', $action->value)->get();
}

/**
 * @param  array<string, string|null>  $overrides
 */
function tenantProfile(Tenant $tenant, array $overrides = []): UpdateTenantProfileData
{
    $value = static fn (string $key, ?string $current): ?string => array_key_exists($key, $overrides) ? $overrides[$key] : $current;

    return new UpdateTenantProfileData(
        legalName: (string) $value('legalName', $tenant->legal_name),
        displayName: (string) $value('displayName', $tenant->display_name),
        timezone: (string) $value('timezone', $tenant->timezone),
        defaultLocale: (string) $value('defaultLocale', $tenant->default_locale),
        supportEmail: $value('supportEmail', $tenant->support_email),
    );
}

// --- Profile -----------------------------------------------------------------

it('updates the tenant profile and audits the changed fields without PII values', function (): void {
    $admin = platformAdmin();
    $tenant = activeTenant();

    $updated = app(UpdateTenantProfile::class)->handle($admin, $tenant, tenantProfile($tenant, [
        'displayName' => 'Renamed',
        'timezone' => 'America/Bogota',
        'supportEmail' => 'help@renamed.test',
    ]));

    expect($updated->display_name)->toBe('Renamed')
        ->and($updated->timezone)->toBe('America/Bogota')
        ->and($updated->support_email)->toBe('help@renamed.test')
        ->and($updated->status)->toBe(TenantStatus::Active);

    $audit = platformAuditsOf(AuditAction::TenantUpdated)->sole();

    expect($audit->tenant_id)->toBe($tenant->id)
        ->and($audit->actor_type)->toBe(ActorType::PlatformAdmin)
        ->and($audit->actor_id)->toBe($admin->id)
        ->and($audit->changes['fields'] ?? null)->toEqualCanonicalizing(['display_name', 'timezone', 'support_email'])
        ->and($audit->changes['after'] ?? null)->toBe(['display_name' => 'Renamed', 'timezone' => 'America/Bogota'])
        ->and(json_encode($audit->changes))->not->toContain('help@renamed.test');
});

it('does not audit a profile save without changes', function (): void {
    $tenant = activeTenant();

    app(UpdateTenantProfile::class)->handle(platformAdmin(), $tenant, tenantProfile($tenant));

    expect(platformAuditsOf(AuditAction::TenantUpdated))->toHaveCount(0);
});

it('validates the profile like on creation', function (array $overrides): void {
    /** @var array<string, string|null> $overrides */
    $tenant = activeTenant();

    app(UpdateTenantProfile::class)->handle(platformAdmin(), $tenant, tenantProfile($tenant, $overrides));
})->with([
    'blank legal name' => [['legalName' => '  ']],
    'unknown time zone' => [['timezone' => 'Mars/Olympus']],
    'unsupported locale' => [['defaultLocale' => 'xx']],
    'invalid support e-mail' => [['supportEmail' => 'not-an-email']],
    'display name too long' => [['displayName' => str_repeat('a', 121)]],
])->throws(ValidationException::class);

it('lets only superadmins edit, and never a closed tenant', function (PlatformAdmin $admin, TenantStatus $status): void {
    $tenant = activeTenant($status);

    app(UpdateTenantProfile::class)->handle($admin, $tenant, tenantProfile($tenant, ['displayName' => 'X']));
})->with([
    'support staff' => [fn (): PlatformAdmin => platformAdmin(superadmin: false), TenantStatus::Active],
    'closed tenant' => [fn (): PlatformAdmin => platformAdmin(), TenantStatus::Closed],
])->throws(AuthorizationException::class);

// --- Invite owner -------------------------------------------------------------

it('invites an owner to an existing tenant through InviteUser, attributed to the platform admin', function (): void {
    Notification::fake();
    $admin = platformAdmin();
    $tenant = activeTenant(TenantStatus::PendingOnboarding);

    $invitation = app(InviteTenantOwner::class)->handle($admin, $tenant, 'Owner@Late.test');

    expect($invitation->tenant_id)->toBe($tenant->id)
        ->and($invitation->email)->toBe('owner@late.test')
        ->and($invitation->role_name)->toBe(SystemRole::Owner->value)
        ->and($invitation->invited_by_user_id)->toBeNull()
        ->and($invitation->status())->toBe(InvitationStatus::Pending);

    $audit = platformAuditsOf(AuditAction::InvitationCreated)->sole();
    expect($audit->tenant_id)->toBe($tenant->id)
        ->and($audit->actor_type)->toBe(ActorType::PlatformAdmin)
        ->and($audit->actor_id)->toBe($admin->id)
        ->and($audit->changes)->toMatchArray(['role' => 'owner', 'source' => 'platform']);

    expect(sentInvitationUrls())->toHaveCount(1);
});

it('refuses an owner invitation to an address registered on the platform', function (): void {
    Notification::fake();
    $existing = tenantUser();

    expect(fn () => app(InviteTenantOwner::class)->handle(platformAdmin(), activeTenant(), $existing->email))
        ->toThrow(EmailNotAvailableException::class);

    expect(platformAuditsOf(AuditAction::InvitationRefused)->sole()->actor_type)->toBe(ActorType::PlatformAdmin);
});

it('applies the per-tenant invitation throttle to platform invitations', function (): void {
    Notification::fake();
    config(['axispay.invitations.max_per_hour' => 1]);
    $tenant = activeTenant();

    app(InviteTenantOwner::class)->handle(platformAdmin(), $tenant, 'first@owner.test');

    expect(fn () => app(InviteTenantOwner::class)->handle(platformAdmin(), $tenant, 'second@owner.test'))
        ->toThrow(InvitationNotAllowedException::class);
});

it('lets only superadmins invite, and never into a closed tenant', function (PlatformAdmin $admin, TenantStatus $status): void {
    app(InviteTenantOwner::class)->handle($admin, activeTenant($status), 'x@owner.test');
})->with([
    'support staff' => [fn (): PlatformAdmin => platformAdmin(superadmin: false), TenantStatus::Active],
    'closed tenant' => [fn (): PlatformAdmin => platformAdmin(), TenantStatus::Closed],
])->throws(AuthorizationException::class);

// --- Resend ------------------------------------------------------------------

it('resends with a new token and expiry, so the old link stops working and the new one works', function (): void {
    Notification::fake();
    $admin = platformAdmin();
    $tenant = activeTenant();
    $original = app(InviteTenantOwner::class)->handle($admin, $tenant, 'owner@resend.test');
    travel(2)->hours();

    $resent = app(ResendInvitation::class)->handle($admin, $original);
    [$oldUrl, $newUrl] = sentInvitationUrls();

    expect($resent->id)->toBe($original->id)
        ->and($resent->token_hash)->not->toBe($original->token_hash)
        ->and($resent->expires_at->greaterThan($original->expires_at))->toBeTrue()
        ->and($resent->token_hash)->toBe(hash('sha256', basename((string) parse_url($newUrl, PHP_URL_PATH))));

    get($oldUrl)->assertStatus(410);
    get($newUrl)->assertOk()->assertSee('owner@resend.test');

    $audit = platformAuditsOf(AuditAction::InvitationResent)->sole();
    expect($audit->tenant_id)->toBe($tenant->id)
        ->and($audit->actor_id)->toBe($admin->id)
        ->and($audit->changes)->toMatchArray(['previous_status' => 'pending', 'role' => 'owner'])
        ->and(json_encode($audit->changes))->not->toContain('owner@resend.test');
});

it('resends an expired invitation with a fresh 72-hour window', function (): void {
    Notification::fake();
    $admin = platformAdmin();
    $invitation = app(InviteTenantOwner::class)->handle($admin, activeTenant(), 'late@resend.test');
    travel(73)->hours();

    expect($invitation->refresh()->status())->toBe(InvitationStatus::Expired);

    $resent = app(ResendInvitation::class)->handle($admin, $invitation);

    expect($resent->status())->toBe(InvitationStatus::Pending)
        ->and($resent->expires_at->diffInHours(now(), true))->toBeGreaterThan(71.9);
});

it('does not resend accepted or revoked invitations', function (string $final): void {
    Notification::fake();
    $admin = platformAdmin();
    $tenant = activeTenant();
    $invitation = app(InviteTenantOwner::class)->handle($admin, $tenant, 'final@resend.test');

    if ($final === 'revoked') {
        app(RevokeInvitation::class)->handle($admin, $invitation);
    } else {
        app(AcceptInvitation::class)->handle(new AcceptInvitationData(
            token: basename((string) parse_url(sentInvitationUrls()[0], PHP_URL_PATH)),
            name: 'Final Owner',
            password: 'a-long-password-123',
        ));
    }

    app(ResendInvitation::class)->handle($admin, $invitation->refresh());
})->with(['accepted', 'revoked'])->throws(InvitationNotPendingException::class);

it('throttles resends with the tenant invitation limit', function (): void {
    Notification::fake();
    config(['axispay.invitations.max_per_hour' => 2]);
    $admin = platformAdmin();
    $invitation = app(InviteTenantOwner::class)->handle($admin, activeTenant(), 'loop@resend.test');

    app(ResendInvitation::class)->handle($admin, $invitation);

    expect(fn () => app(ResendInvitation::class)->handle($admin, $invitation))->toThrow(InvitationNotAllowedException::class);
});

it('lets only superadmins resend', function (): void {
    Notification::fake();
    $invitation = app(InviteTenantOwner::class)->handle(platformAdmin(), activeTenant(), 'x@resend.test');

    app(ResendInvitation::class)->handle(platformAdmin(superadmin: false), $invitation);
})->throws(AuthorizationException::class);

// --- Revoke ------------------------------------------------------------------

it('revokes a pending invitation, audited, and its link stops working', function (): void {
    Notification::fake();
    $admin = platformAdmin();
    $tenant = activeTenant();
    $invitation = app(InviteTenantOwner::class)->handle($admin, $tenant, 'gone@revoke.test');

    $revoked = app(RevokeInvitation::class)->handle($admin, $invitation);

    expect($revoked->status())->toBe(InvitationStatus::Revoked);
    get(sentInvitationUrls()[0])->assertStatus(410);

    $audit = platformAuditsOf(AuditAction::InvitationRevoked)->sole();
    expect($audit->tenant_id)->toBe($tenant->id)
        ->and($audit->actor_id)->toBe($admin->id)
        ->and($audit->changes)->toMatchArray(['reason' => 'revoked', 'source' => 'platform']);
});

it('revokes pending invitations only', function (): void {
    Notification::fake();
    $admin = platformAdmin();
    $invitation = app(InviteTenantOwner::class)->handle($admin, activeTenant(), 'old@revoke.test');
    travel(73)->hours();

    app(RevokeInvitation::class)->handle($admin, $invitation);
})->throws(InvitationNotPendingException::class);

it('still lets a superadmin revoke a pending invitation after closing the tenant', function (): void {
    Notification::fake();
    $admin = platformAdmin();
    $tenant = activeTenant();
    $invitation = app(InviteTenantOwner::class)->handle($admin, $tenant, 'closing@revoke.test');
    $tenant->forceFill(['status' => TenantStatus::Closed])->save();

    expect(app(RevokeInvitation::class)->handle($admin, $invitation)->status())->toBe(InvitationStatus::Revoked);
});

it('lets only superadmins revoke', function (): void {
    Notification::fake();
    $invitation = app(InviteTenantOwner::class)->handle(platformAdmin(), activeTenant(), 'x@revoke.test');

    app(RevokeInvitation::class)->handle(platformAdmin(superadmin: false), $invitation);
})->throws(AuthorizationException::class);

// --- Policy ------------------------------------------------------------------

it('never lets anyone delete a tenant', function (): void {
    $tenant = activeTenant();

    expect(platformAdmin()->can('delete', $tenant))->toBeFalse()
        ->and(platformAdmin()->can('deleteAny', Tenant::class))->toBeFalse()
        ->and(platformAdmin(superadmin: false)->can('viewMembers', $tenant))->toBeTrue();
});
