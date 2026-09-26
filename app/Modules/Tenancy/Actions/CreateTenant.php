<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Actions\InviteUser;
use App\Modules\Identity\Data\InviteUserData;
use App\Modules\Identity\Exceptions\EmailNotAvailableException;
use App\Modules\Identity\Services\UserDirectory;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use App\Modules\Tenancy\Data\CreateTenantData;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Creates a tenant in `pending_onboarding` (plan 21.3) and invites its first
 * owner. Superadmin only; audited.
 *
 * The owner e-mail is required (plan 17.2 "minimum one owner per tenant",
 * ADR-0045): a tenant without an owner has nobody who can connect the
 * payment gateway. It must not belong to an existing user, because e-mails
 * are unique across the platform (plan 7.2); that is checked before anything
 * is written, so a refused e-mail never leaves a half-created tenant.
 */
final readonly class CreateTenant
{
    public function __construct(
        private AuditLogger $audit,
        private TenantContext $context,
        private InviteUser $inviteUser,
        private UserDirectory $directory,
    ) {}

    /**
     * @throws ValidationException when the owner e-mail is missing, invalid or already registered
     * @throws EmailNotAvailableException when the e-mail was registered concurrently
     */
    public function handle(PlatformAdmin $actor, CreateTenantData $data): Tenant
    {
        Gate::forUser($actor)->authorize('create', Tenant::class);

        $ownerEmail = UserDirectory::normalize($data->ownerEmail);
        self::validate($ownerEmail);

        if ($this->directory->emailIsRegistered($ownerEmail)) {
            throw ValidationException::withMessages(['owner_email' => __('platform.tenants.errors.owner_email_taken')]);
        }

        return DB::transaction(function () use ($actor, $data, $ownerEmail): Tenant {
            $tenant = new Tenant([
                'legal_name' => $data->legalName,
                'display_name' => $data->displayName,
                'timezone' => $data->timezone,
                'default_locale' => $data->defaultLocale,
                'support_email' => $data->supportEmail,
            ]);
            $tenant->forceFill([
                'status' => TenantStatus::PendingOnboarding,
                'status_changed_at' => now(),
            ])->save();

            $this->audit->record(AuditAction::TenantCreated, $tenant, [
                'display_name' => $tenant->display_name,
                'status' => $tenant->status->value,
            ], tenantId: $tenant->id, actor: Actor::platformAdmin($actor->id));

            $this->context->runAsTenant($tenant->id, false, fn () => $this->inviteUser->handle(
                new InviteUserData(email: $ownerEmail, role: SystemRole::Owner),
                invitedBy: null,
                platformAdmin: $actor,
            ));

            return $tenant;
        });
    }

    /**
     * Same rules as the creation form (TenantResource::form()).
     *
     * @throws ValidationException
     */
    private static function validate(string $ownerEmail): void
    {
        Validator::make(['owner_email' => $ownerEmail], [
            'owner_email' => ['required', 'email', 'max:254'],
        ])->validate();
    }
}
