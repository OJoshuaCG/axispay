<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\ApiKeys\Actions\CreateApiKey;
use App\Modules\ApiKeys\Actions\RevokeApiKey;
use App\Modules\ApiKeys\Data\CreateApiKeyData;
use App\Modules\ApiKeys\Enums\ApiKeyRefusal;
use App\Modules\ApiKeys\Enums\ApiScope;
use App\Modules\ApiKeys\Exceptions\ApiKeyNotAllowedException;
use App\Modules\ApiKeys\Filament\Resources\ApiKeys\ApiKeyResource;
use App\Modules\ApiKeys\Filament\Resources\ApiKeys\Pages\ListApiKeys;
use App\Modules\ApiKeys\Models\ApiKey;
use App\Modules\ApiKeys\Notifications\LiveApiKeyCreatedNotification;
use App\Modules\ApiKeys\Notifications\LiveApiKeyRevokedNotification;
use App\Modules\ApiKeys\Services\ApiKeyAuthenticator;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Exceptions\ReauthenticationRequiredException;
use App\Modules\Identity\Services\ImpersonationState;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\Tenancy\Enums\TenantStatus;
use Filament\Tables\Enums\FiltersLayout;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\ApiTestHelpers;
use Tests\Support\GatewayTestHelpers;

use function Pest\Laravel\get;
use function Pest\Laravel\startSession;
use function Pest\Laravel\withHeaders;

/**
 * Settings → API keys in the tenant panel (plan 10.2, 17.3): `api_keys:manage`,
 * re-authentication, the key shown once, owners e-mailed for live keys,
 * everything audited.
 */
beforeEach(function (): void {
    startSession();
    Notification::fake();
});

it('is only reachable with api_keys:manage', function (): void {
    $tenant = activeTenant();

    actingAsTenantUser(tenantUser($tenant, [SystemRole::LinkCreator]));
    get(appUrl('/settings/api-keys'))->assertForbidden();

    actingAsTenantUser(tenantUser($tenant, [SystemRole::IntegrationManager]));
    get(appUrl('/settings/api-keys'))->assertOk()->assertSee(__('api_keys.plural'), false);
});

it('lists the keys of the current mode with prefix and last four only', function (string $locale): void {
    $tenant = activeTenant();

    app()->setLocale($locale);
    [$test, $testKey] = ApiTestHelpers::key($tenant, livemode: false);
    [$live] = ApiTestHelpers::key($tenant, livemode: true);
    [$revoked] = ApiTestHelpers::key($tenant, attributes: ['revoked_at' => now()]);
    actingAsTenantUser(tenantUser($tenant), livemode: false);

    $component = Livewire::test(ListApiKeys::class)
        ->assertCanSeeTableRecords([$test])
        ->assertCanNotSeeTableRecords([$live, $revoked])
        ->assertSee($test->maskedKey())
        ->assertSee(__('api_keys.page.subheading.test'));

    expect($component->html())->not->toContain($testKey);

    $component->filterTable('status', 'revoked')->assertCanSeeTableRecords([$revoked])->assertCanNotSeeTableRecords([$test]);
    $component->removeTableFilter('status')->assertCanSeeTableRecords([$test, $revoked]);
})->with(['en', 'es']);

it('creates a key, shows it once and stores only its hash', function (): void {
    $tenant = activeTenant();

    $owner = actingAsTenantUser(tenantUser($tenant));
    GatewayTestHelpers::reauthenticated();

    $component = Livewire::test(ListApiKeys::class);
    $component->callAction('create', data: ['name' => 'Online store', 'scopes' => ['links:read', 'links:create']]);
    $component->assertHasNoActionErrors();
    $component->assertActionMounted('showIssuedKey');

    // The modal of this response shows the key...
    $modal = $component->getMountedActionModalHtml();
    assert(is_string($modal));
    preg_match('/axp_test_[0-9A-Za-z]{43}/', $modal, $match);
    $plaintext = $match[0] ?? '';
    expect($plaintext)->toStartWith('axp_test_');
    $component->assertMountedActionModalSee(__('api_keys.issued.warning.test'));

    // ...but the Livewire snapshot, sent back with every later request, never holds it.
    expect((string) json_encode($component->__get('snapshot')))->not->toContain($plaintext);

    $key = ApiKey::query()->sole();
    expect($key->name)->toBe('Online store')
        ->and($key->scopes)->toBe(['links:create', 'links:read'])
        ->and($key->livemode)->toBeFalse()
        ->and($key->created_by_user_id)->toBe($owner->id)
        ->and($key->key_hash)->toBe(hash('sha256', $plaintext))
        ->and(app(ApiKeyAuthenticator::class)->authenticate($plaintext)?->key->id)->toBe($key->id);

    $audit = AuditLog::query()->where('action', AuditAction::ApiKeyCreated->value)->sole();
    expect(json_encode($audit->changes))->not->toContain($plaintext)
        ->and($audit->changes['key_last4'] ?? null)->toBe($key->key_last4);

    // "I have copied the key" erases it from the page state.
    $component->callMountedAction();
    $component->assertActionNotMounted('showIssuedKey');
    expect($component->html())->not->toContain($plaintext)
        ->and((string) json_encode($component->__get('snapshot')))->not->toContain($plaintext);

    Notification::assertNothingSent();
});

it('e-mails every owner when a live key is created (plan §17.3)', function (): void {
    $tenant = activeTenant();

    $owner = tenantUser($tenant, [SystemRole::Owner]);
    $secondOwner = tenantUser($tenant, [SystemRole::Owner]);
    $manager = tenantUser($tenant, [SystemRole::IntegrationManager]);
    actingAsTenantUser($manager, livemode: true);
    GatewayTestHelpers::reauthenticated();

    Livewire::test(ListApiKeys::class)
        ->callAction('create', data: ['name' => 'Production ERP', 'scopes' => ['links:create']])
        ->assertHasNoActionErrors();

    expect(ApiKey::query()->sole()->livemode)->toBeTrue();
    Notification::assertSentTo([$owner, $secondOwner], LiveApiKeyCreatedNotification::class, static function (LiveApiKeyCreatedNotification $n) use ($manager): bool {
        return $n->keyName === 'Production ERP' && $n->createdByName === $manager->name;
    });
    Notification::assertNotSentTo($manager, LiveApiKeyCreatedNotification::class);

    $mail = (new LiveApiKeyCreatedNotification('Production ERP', 'Ana'))->toMail($owner);
    expect($mail->subject)->toBe(__('api_keys.mail.live_created.subject'));
});

it('asks for the password again before creating a key', function (): void {
    $tenant = activeTenant();

    actingAsTenantUser(tenantUser($tenant));

    Livewire::test(ListApiKeys::class)
        ->callAction('create', data: ['name' => 'Store', 'scopes' => ['links:read'], 'current_password' => 'wrong-password'])
        ->assertHasActionErrors(['current_password']);

    expect(ApiKey::query()->count())->toBe(0);

    Livewire::test(ListApiKeys::class)
        ->callAction('create', data: ['name' => 'Store', 'scopes' => ['links:read'], 'current_password' => 'password-for-tests'])
        ->assertHasNoActionErrors();

    expect(ApiKey::query()->count())->toBe(1)
        ->and(app(ReauthenticationWindow::class)->isConfirmed())->toBeTrue();
});

it('requires a name and at least one scope', function (): void {
    $tenant = activeTenant();

    actingAsTenantUser(tenantUser($tenant));
    GatewayTestHelpers::reauthenticated();

    Livewire::test(ListApiKeys::class)
        ->callAction('create', data: ['name' => '', 'scopes' => []])
        ->assertHasActionErrors(['name' => 'required', 'scopes' => 'required']);
});

it('revokes a key: the next API request is 401', function (): void {
    $tenant = activeTenant();

    $tenant = ApiTestHelpers::readyTenant();
    [$apiKey, $plaintext] = ApiTestHelpers::key($tenant);
    $owner = actingAsTenantUser(tenantUser($tenant));
    GatewayTestHelpers::reauthenticated();

    withHeaders(ApiTestHelpers::headers($plaintext))->getJson(apiUrl('v1/payment_links'))->assertOk();

    Livewire::test(ListApiKeys::class)->callTableAction('revoke', $apiKey)->assertHasNoTableActionErrors();

    $fresh = ApiKey::query()->findOrFail($apiKey->id);
    expect($fresh->revoked_at)->not->toBeNull()->and($fresh->revoked_by_user_id)->toBe($owner->id);
    expect(AuditLog::query()->where('action', AuditAction::ApiKeyRevoked->value)->count())->toBe(1);

    withHeaders(ApiTestHelpers::headers($plaintext))->getJson(apiUrl('v1/payment_links'))->assertUnauthorized();
});

it('hides the resource from users without the permission', function (): void {
    $tenant = activeTenant();

    actingAsTenantUser(tenantUser($tenant, [SystemRole::Viewer]));

    expect(ApiKeyResource::canViewAny())->toBeFalse();
});

it('refuses create and revoke during impersonation (plan §17.4)', function (): void {
    $tenant = activeTenant();

    [$apiKey] = ApiTestHelpers::key($tenant);
    $owner = actingAsTenantUser(tenantUser($tenant));
    request()->setLaravelSession(app('session.store'));
    app(ImpersonationState::class)->start('01J8Z3Q6T4Y0V8KX2M1N5P7R9S', '01J8Z3Q6T4Y0V8KX2M1N5P7R9T');

    expect($owner->can('create', ApiKey::class))->toBeFalse()
        ->and($owner->can('revoke', $apiKey))->toBeFalse()
        ->and($owner->can('viewAny', ApiKey::class))->toBeTrue();
});

it('e-mails every owner when a live key is revoked, never for a test key', function (): void {
    $tenant = ApiTestHelpers::readyTenant(livemode: true);
    $owner = tenantUser($tenant, [SystemRole::Owner]);
    $manager = tenantUser($tenant, [SystemRole::IntegrationManager]);
    [$live] = ApiTestHelpers::key($tenant, livemode: true, attributes: ['name' => 'Production ERP']);
    [$test] = ApiTestHelpers::key($tenant, livemode: false);

    actingAsTenantUser($manager, livemode: true);
    GatewayTestHelpers::reauthenticated();
    app(RevokeApiKey::class)->handle($manager, $live);
    app(RevokeApiKey::class)->handle($manager, $live);

    Notification::assertSentToTimes($owner, LiveApiKeyRevokedNotification::class, 1);
    Notification::assertSentTo($owner, LiveApiKeyRevokedNotification::class, static fn (LiveApiKeyRevokedNotification $n): bool => $n->keyName === 'Production ERP' && $n->revokedByName === $manager->name);
    Notification::assertNotSentTo($manager, LiveApiKeyRevokedNotification::class);

    actingAsTenantUser($manager, livemode: false);
    app(RevokeApiKey::class)->handle($manager, $test);
    Notification::assertSentToTimes($owner, LiveApiKeyRevokedNotification::class, 1);

    $notification = new LiveApiKeyRevokedNotification('Production ERP', 'Ana');
    expect($notification->afterCommit)->toBeTrue()
        ->and($notification)->toBeInstanceOf(ShouldQueue::class);

    foreach (['en', 'es'] as $locale) {
        app()->setLocale($locale);
        $mail = $notification->toMail($owner);
        expect($mail->subject)->toBe(__('api_keys.mail.live_revoked.subject'))
            ->and(implode(' ', array_map(static fn (mixed $line): string => is_string($line) ? $line : '', $mail->introLines)))->toContain('Production ERP')->toContain('Ana');
    }
});

it('refuses new keys for a suspended or closed tenant, but still lets it revoke', function (TenantStatus $status): void {
    $tenant = activeTenant();
    [$apiKey] = ApiTestHelpers::key($tenant);
    $owner = actingAsTenantUser(tenantUser($tenant));
    GatewayTestHelpers::reauthenticated();
    $tenant->forceFill(['status' => $status])->save();

    expect($owner->can('create', ApiKey::class))->toBeFalse()
        ->and($owner->can('revoke', $apiKey))->toBeTrue();

    Livewire::test(ListApiKeys::class)->assertActionHidden('create');

    $e = thrownBy(ApiKeyNotAllowedException::class, fn () => app(CreateApiKey::class)->handle($owner, new CreateApiKeyData('Store', [ApiScope::LinksRead])));
    expect($e->userMessage())->not->toBe('api_keys.errors.tenant_read_only');
    expect($e->reason)->toBe(ApiKeyRefusal::TenantReadOnly)
        ->and($e->userMessage())->toBe(__('api_keys.errors.tenant_read_only'))
        ->and(ApiKey::query()->count())->toBe(1);

    app(RevokeApiKey::class)->handle($owner, $apiKey);
    expect(ApiKey::query()->findOrFail($apiKey->id)->isRevoked())->toBeTrue();
})->with([TenantStatus::Suspended, TenantStatus::Closed]);

it('refuses to revoke during impersonation at the action level (plan §17.4)', function (): void {
    $tenant = activeTenant();
    [$apiKey] = ApiTestHelpers::key($tenant);
    $owner = actingAsTenantUser(tenantUser($tenant));
    request()->setLaravelSession(app('session.store'));
    GatewayTestHelpers::reauthenticated();
    app(ImpersonationState::class)->start('01J8Z3Q6T4Y0V8KX2M1N5P7R9S', '01J8Z3Q6T4Y0V8KX2M1N5P7R9T');

    expect(fn () => app(RevokeApiKey::class)->handle($owner, $apiKey))->toThrow(AuthorizationException::class);
    expect(ApiKey::query()->findOrFail($apiKey->id)->isRevoked())->toBeFalse();
});

it('refuses to revoke without re-authentication', function (): void {
    $tenant = activeTenant();
    [$apiKey] = ApiTestHelpers::key($tenant);
    $owner = actingAsTenantUser(tenantUser($tenant));

    expect(fn () => app(RevokeApiKey::class)->handle($owner, $apiKey))->toThrow(ReauthenticationRequiredException::class);
    expect(ApiKey::query()->findOrFail($apiKey->id)->isRevoked())->toBeFalse();
});

it('shows the new key with a copy button that reads it from the page, and confirms after closing', function (): void {
    $tenant = activeTenant();
    actingAsTenantUser(tenantUser($tenant), livemode: true);
    GatewayTestHelpers::reauthenticated();
    app()->setLocale('es');

    $component = Livewire::test(ListApiKeys::class);
    $component->callAction('create', data: ['name' => 'ERP', 'scopes' => ['links:read']]);
    $modal = $component->getMountedActionModalHtml();
    assert(is_string($modal));

    expect($modal)->toContain('data-copy-issued-key')
        ->toContain('data-issued-key')
        ->toContain('select-all')
        ->toContain(e(__('api_keys.issued.warning.live')))
        // The copy button reads the key from the page: the key is not in any script.
        ->and(preg_match('/x-on:click="[^"]*axp_live_/', $modal))->toBe(0)
        ->and((int) strpos($modal, 'data-copy-issued-key'))->toBeLessThan((int) strpos($modal, 'fi-modal-footer'));

    $key = ApiKey::query()->sole();
    $component->callMountedAction();
    $component->assertNotified(__('api_keys.notifications.created', ['name' => 'ERP', 'key' => $key->maskedKey()]));
});

it('names the key, its last use and the owner e-mail in the revoke dialog', function (): void {
    $tenant = activeTenant();
    [$used] = ApiTestHelpers::key($tenant, livemode: true, attributes: ['name' => 'ERP', 'last_used_at' => now()->subMinutes(12)]);
    [$unused] = ApiTestHelpers::key($tenant, attributes: ['name' => 'Tienda']);
    app()->setLocale('es');

    expect(ApiKeyResource::revokeDescription($used))->toContain('«ERP»')->toContain($used->maskedKey())->toContain('Se usó por última vez')->toContain(__('api_keys.actions.revoke_live_note'));
    expect(ApiKeyResource::revokeDescription($unused))->toContain('Nunca se ha usado');
    expect(str_contains(ApiKeyResource::revokeDescription($unused), (string) __('api_keys.actions.revoke_live_note')))->toBeFalse();
});

it('shows scope labels, a mobile summary column and an empty-state create button', function (): void {
    $tenant = activeTenant();
    actingAsTenantUser(tenantUser($tenant));

    Livewire::test(ListApiKeys::class)->assertTableEmptyStateActionsExistInOrder(['createFromEmptyState']);
    $table = ApiTestHelpers::tableOf(ListApiKeys::class);

    expect($table->getColumn('summary')?->getHiddenFrom())->toBe('md')
        ->and($table->getColumn('scopes')?->getVisibleFrom())->toBe('lg')
        ->and($table->getColumn('last_used_at')?->getVisibleFrom())->toBe('md');

    [$key] = ApiTestHelpers::key($tenant, scopes: [ApiScope::LinksCreate]);
    Livewire::test(ListApiKeys::class)->assertSee(ApiScope::LinksCreate->label());
});

it('lists every scope as plain text, or one "All permissions" badge', function (): void {
    $tenant = activeTenant();
    [$all] = ApiTestHelpers::key($tenant);
    [$some] = ApiTestHelpers::key($tenant, scopes: [ApiScope::LinksCreate, ApiScope::LinksRead, ApiScope::LinksCancel]);
    actingAsTenantUser(tenantUser($tenant));
    app()->setLocale('es');

    expect(ApiKeyResource::scopeLabels($all))->toBe(['Todos los permisos'])
        ->and(ApiKeyResource::scopeLabels($some))->toBe([ApiScope::LinksCreate->label(), ApiScope::LinksRead->label(), ApiScope::LinksCancel->label()]);

    $html = Livewire::test(ListApiKeys::class)->html();
    expect($html)->toContain('Todos los permisos')->toContain(ApiScope::LinksCancel->label())
        ->and($html)->not->toContain('fi-ta-text-list-limited-message');
    expect(ApiTestHelpers::tableOf(ListApiKeys::class)->getFiltersLayout())->toBe(FiltersLayout::Modal);
});

it('asks for the password with specific wording when it is missing', function (): void {
    actingAsTenantUser(tenantUser(activeTenant()));
    app()->setLocale('es');

    Livewire::test(ListApiKeys::class)
        ->callAction('create', data: ['name' => 'ERP', 'scopes' => ['links:read'], 'current_password' => ''])
        ->assertHasActionErrors(['current_password' => __('identity.reauthentication.required')]);
});
