<?php

declare(strict_types=1);

use App\Modules\Access\Actions\ChangeUserRoles;
use App\Modules\Access\Enums\SystemRole;
use App\Modules\Access\Services\RoleGrantGuard;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Actions\AcceptInvitation;
use App\Modules\Identity\Actions\InviteUser;
use App\Modules\Identity\Data\AcceptInvitationData;
use App\Modules\Identity\Data\InviteUserData;
use App\Modules\Identity\Exceptions\EmailNotAvailableException;
use App\Modules\Identity\Exceptions\InvitationNotAllowedException;
use App\Modules\Identity\Exceptions\InvitationNotPendingException;
use App\Modules\Identity\Exceptions\ReauthenticationRequiredException;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserInvitation;
use App\Modules\Identity\Notifications\UserInvitationNotification;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\startSession;
use function Pest\Laravel\travel;

/**
 * Sends an invitation as $inviter and returns the e-mailed signed URL.
 */
function inviteAndCaptureUrl(User $inviter, string $email, SystemRole $role = SystemRole::Viewer): string
{
    Notification::fake();
    startSession();
    app(ReauthenticationWindow::class)->confirm();
    app(TenantContext::class)->set($inviter->tenant_id, false);
    app(InviteUser::class)->handle(new InviteUserData($email, $role), $inviter);

    $url = null;
    Notification::assertSentOnDemand(UserInvitationNotification::class, function (UserInvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable) use (&$url, $email): bool {
        $url = $notification->acceptUrl;

        return $notifiable->routes['mail'] === $email;
    });
    app(TenantContext::class)->clear();

    return (string) $url;
}

function tokenFromUrl(string $url): string
{
    return basename((string) parse_url($url, PHP_URL_PATH));
}

it('stores only the hash of a single-use token and e-mails a signed link', function (): void {
    $owner = tenantUser();
    $url = inviteAndCaptureUrl($owner, 'new@example.com');

    $invitation = app(TenantContext::class)->runAsTenant($owner->tenant_id, false, fn () => UserInvitation::query()->sole());

    expect($invitation->token_hash)->toBe(hash('sha256', tokenFromUrl($url)))
        ->and($url)->toContain('signature=')
        ->and($invitation->expires_at->diffInHours(now(), true))->toBeGreaterThan(71.9);

    expect(AuditLog::query()->withoutGlobalScopes()->where('action', AuditAction::InvitationCreated->value)->sole()->actor_id)->toBe($owner->id);
});

it('requires users:manage to invite', function (): void {
    $viewer = tenantUser(roles: [SystemRole::Viewer]);
    app(TenantContext::class)->set($viewer->tenant_id, false);

    app(InviteUser::class)->handle(new InviteUserData('x@example.com', SystemRole::Viewer), $viewer);
})->throws(AuthorizationException::class);

it('refuses to invite an e-mail already registered on the platform', function (): void {
    $other = tenantUser();
    $owner = tenantUser();
    app(TenantContext::class)->set($owner->tenant_id, false);

    app(InviteUser::class)->handle(new InviteUserData(strtoupper($other->email), SystemRole::Viewer), $owner);
})->throws(EmailNotAvailableException::class);

it('creates the user with the invited role and signs them in, exactly once', function (): void {
    $owner = tenantUser();
    $url = inviteAndCaptureUrl($owner, 'new@example.com', SystemRole::Finance);

    get($url)->assertOk()->assertSee('new@example.com');

    post($url, ['name' => 'New Person', 'password' => 'a-long-password-123', 'password_confirmation' => 'a-long-password-123'])
        ->assertRedirect(route('filament.app.pages.dashboard'));

    $user = app(TenantContext::class)->runAsTenant($owner->tenant_id, false, function (): User {
        $user = User::query()->where('email', 'new@example.com')->sole();
        expect($user->hasRole(SystemRole::Finance->value))->toBeTrue();

        return $user;
    });

    expect(auth('web')->id())->toBe($user->id)
        ->and($user->tenant_id)->toBe($owner->tenant_id);

    auth('web')->logout();

    // Second use of the same link.
    get($url)->assertStatus(410);
    post($url, ['name' => 'Again', 'password' => 'a-long-password-123', 'password_confirmation' => 'a-long-password-123'])->assertStatus(410);

    expect(AuditLog::query()->withoutGlobalScopes()->where('action', AuditAction::InvitationAccepted->value)->count())->toBe(1);
});

it('rejects expired invitations', function (): void {
    $owner = tenantUser();
    $url = inviteAndCaptureUrl($owner, 'late@example.com');

    travel(73)->hours();

    expect(fn () => app(AcceptInvitation::class)->handle(new AcceptInvitationData(tokenFromUrl($url), 'Late', 'a-long-password-123')))
        ->toThrow(InvitationNotPendingException::class);
});

it('revokes the previous pending invitation when a new one is sent', function (): void {
    $owner = tenantUser();
    $first = inviteAndCaptureUrl($owner, 'twice@example.com');
    inviteAndCaptureUrl($owner, 'twice@example.com');

    expect(fn () => app(AcceptInvitation::class)->handle(new AcceptInvitationData(tokenFromUrl($first), 'X', 'a-long-password-123')))
        ->toThrow(InvitationNotPendingException::class);
});

it('rejects a link without a valid signature', function (): void {
    $owner = tenantUser();
    $url = inviteAndCaptureUrl($owner, 'unsigned@example.com');

    get(explode('?', $url)[0])->assertForbidden();
});

it('enforces the password policy, including the breached-password check', function (): void {
    config(['paylink.passwords.check_uncompromised' => true]);
    // SHA-1 of "password-password" is reported as breached by the fake HIBP range API.
    $hash = strtoupper(sha1('password-password'));
    Http::fake(['api.pwnedpasswords.com/*' => Http::response(substr($hash, 5).':12345', 200)]);

    $owner = tenantUser();
    $url = inviteAndCaptureUrl($owner, 'weak@example.com');

    post($url, ['name' => 'Weak', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
    post($url, ['name' => 'Weak', 'password' => 'password-password', 'password_confirmation' => 'password-password'])->assertSessionHasErrors('password');
});

it('refuses an invitation with a role the inviter could not grant (H1: admin invites owner)', function (): void {
    $admin = tenantUser(roles: [SystemRole::Admin]);

    foreach ([SystemRole::Owner, SystemRole::IntegrationManager] as $role) {
        expect(fn () => inviteAndCaptureUrl($admin, 'escalate@example.com', $role))->toThrow(InvitationNotAllowedException::class);
    }

    expect(app(TenantContext::class)->runAsTenant($admin->tenant_id, false, fn () => UserInvitation::query()->count()))->toBe(0);
});

it('lets an owner invite another owner (H1)', function (): void {
    $owner = tenantUser();
    $url = inviteAndCaptureUrl($owner, 'co-owner@example.com', SystemRole::Owner);

    $user = app(AcceptInvitation::class)->handle(new AcceptInvitationData(tokenFromUrl($url), 'Co Owner', 'a-long-password-123'));

    expect(app(TenantContext::class)->runAsTenant($owner->tenant_id, false, fn (): bool => $user->refresh()->hasRole('owner')))->toBeTrue();
});

it('refuses acceptance when the inviter lost the permissions meanwhile (H1)', function (): void {
    $owner = tenantUser();
    $admin = tenantUser($owner->tenant, [SystemRole::Admin]);
    $url = inviteAndCaptureUrl($admin, 'finance@example.com', SystemRole::Finance);

    // The admin is demoted to viewer before the invitee accepts.
    actingAsTenantUser($owner);
    app(ChangeUserRoles::class)->handle($owner, $admin, [SystemRole::Viewer]);
    app(TenantContext::class)->clear();

    expect(fn () => app(AcceptInvitation::class)->handle(new AcceptInvitationData(tokenFromUrl($url), 'F', 'a-long-password-123')))
        ->toThrow(InvitationNotPendingException::class);
    expect(AuditLog::query()->withoutGlobalScopes()->where('action', AuditAction::InvitationRevoked->value)->count())->toBe(1);
});

it('requires re-authentication to invite with a sensitive role (H1)', function (): void {
    $owner = tenantUser();
    startSession();
    session()->forget(ReauthenticationWindow::SESSION_KEY);
    app(TenantContext::class)->set($owner->tenant_id, false);

    expect(fn () => app(InviteUser::class)->handle(new InviteUserData('fin@example.com', SystemRole::Finance), $owner))
        ->toThrow(ReauthenticationRequiredException::class);

    // A non-sensitive role needs no re-authentication.
    Notification::fake();
    app(InviteUser::class)->handle(new InviteUserData('viewer@example.com', SystemRole::Viewer), $owner);
    expect(UserInvitation::query()->where('email', 'viewer@example.com')->exists())->toBeTrue();
});

it('throttles invitations per tenant and audits refusals without the e-mail (M7)', function (): void {
    config(['paylink.invitations.max_per_hour' => 2]);
    Notification::fake();
    $other = tenantUser();
    $owner = tenantUser();
    app(TenantContext::class)->set($owner->tenant_id, false);

    expect(fn () => app(InviteUser::class)->handle(new InviteUserData($other->email, SystemRole::Viewer), $owner))
        ->toThrow(EmailNotAvailableException::class);

    $refusal = AuditLog::query()->where('action', AuditAction::InvitationRefused->value)->sole();
    expect($refusal->changes)->toBe(['recipient_hash' => hash('sha256', $other->email), 'reason' => 'email_not_available']);

    app(InviteUser::class)->handle(new InviteUserData('one@example.com', SystemRole::Viewer), $owner);

    expect(fn () => app(InviteUser::class)->handle(new InviteUserData('two@example.com', SystemRole::Viewer), $owner))
        ->toThrow(InvitationNotAllowedException::class);
});

it('only offers roles the inviter can grant in the invite form (H1)', function (): void {
    $admin = actingAsTenantUser(tenantUser(roles: [SystemRole::Admin]));

    expect(array_keys(app(RoleGrantGuard::class)->grantableRoleOptions($admin)))
        ->toBe(['admin', 'finance', 'link_creator', 'viewer']);
});
