<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use App\Modules\Tenancy\Data\UpdateTenantProfileData;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Locales;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Edits a tenant's profile from the platform panel (ADR-0043): legal and
 * display name, time zone, default language and support e-mail. Superadmin
 * only, under a row lock, audited as `tenant.updated`.
 *
 * The audit entry lists the changed field names; before/after values are
 * kept for the non-personal fields only. The support e-mail is PII (plan
 * 23.3), so only its field name is recorded.
 */
final readonly class UpdateTenantProfile
{
    /** Fields whose values are never written to the audit entry. */
    private const array PERSONAL_FIELDS = ['support_email'];

    public function __construct(private AuditLogger $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(PlatformAdmin $actor, Tenant $tenant, UpdateTenantProfileData $data): Tenant
    {
        Gate::forUser($actor)->authorize('update', $tenant);

        $attributes = $data->attributes();
        self::validate($attributes);

        return DB::transaction(function () use ($actor, $tenant, $attributes): Tenant {
            $locked = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $locked->fill($attributes);
            $changed = array_keys($locked->getDirty());

            if ($changed === []) {
                return $locked;
            }

            $public = array_values(array_diff($changed, self::PERSONAL_FIELDS));
            $before = array_intersect_key($locked->getOriginal(), array_flip($public));
            $after = array_intersect_key($locked->getAttributes(), array_flip($public));

            $locked->save();

            $this->audit->record(AuditAction::TenantUpdated, $locked, array_filter([
                'fields' => $changed,
                'before' => $before,
                'after' => $after,
            ], static fn (array $value): bool => $value !== []), tenantId: $locked->id, actor: Actor::platformAdmin($actor->id));

            return $locked;
        });
    }

    /**
     * Same rules as the creation form (TenantResource::form()).
     *
     * @param  array<string, string|null>  $attributes
     *
     * @throws ValidationException
     */
    private static function validate(array $attributes): void
    {
        Validator::make($attributes, [
            'legal_name' => ['required', 'string', 'max:200'],
            'display_name' => ['required', 'string', 'max:120'],
            'timezone' => ['required', Rule::in(DateTimeZone::listIdentifiers())],
            'default_locale' => ['required', Rule::in(array_keys(Locales::supported()))],
            'support_email' => ['nullable', 'email', 'max:254'],
        ])->validate();
    }
}
