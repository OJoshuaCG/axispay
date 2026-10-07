<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Fx\Enums\FxMode;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Data\TenantPaymentSettingsData;
use App\Modules\Tenancy\Data\TenantSettings;
use App\Modules\Tenancy\Exceptions\InvalidPaymentSettingsException;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Saves the tenant's "Payment settings" (ADR-0063, ADR-0048): the currency
 * conversion (`fx.*`: on or off, mode, the tenant's fixed rate, the markup
 * and how long a Banxico quote lasts) and the default and maximum
 * expiration of links created without an expiry.
 *
 *  - `settings:manage` on a writable panel (policy; impersonation is denied
 *    there, plan 17.4);
 *  - every value is checked against the platform limits (config/axispay.php):
 *    markup up to `limits.max_fx_markup_bps`, quote validity 5 to 120
 *    minutes (plan 13.1), expiration at least 1 hour and at most the
 *    platform maximum (60 days), the default never above the tenant's
 *    maximum; a `fixed` conversion that is on needs a rate;
 *  - only the `fx` and `links` expiry values change: the amount caps, payer
 *    fields and checkout language of the same JSON document are kept;
 *  - the tenant row is locked while it is merged, so two saves never lose
 *    each other's changes, and the change is audited with the old and new
 *    values (no secret is involved).
 */
final readonly class UpdateTenantPaymentSettings
{
    public const int MIN_QUOTE_VALIDITY_MINUTES = 5;

    public const int MAX_QUOTE_VALIDITY_MINUTES = 120;

    public const int MIN_EXPIRATION_HOURS = 1;

    public function __construct(private AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws InvalidPaymentSettingsException
     */
    public function handle(User $actor, TenantPaymentSettingsData $data): TenantSettings
    {
        Gate::forUser($actor)->authorize('manage', TenantSettings::class);

        $errors = $this->errors($data);

        if ($errors !== []) {
            throw new InvalidPaymentSettingsException($errors);
        }

        return DB::transaction(function () use ($actor, $data): TenantSettings {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($actor->tenant_id);
            $before = $tenant->settings();
            $document = $before->toArray();

            $document['fx'] = [
                ...$document['fx'],
                'conversion_enabled' => $data->fxConversionEnabled,
                'default_mode' => $data->fxDefaultMode->value,
                'fixed_rate' => $data->fxFixedRate?->toString(),
                'markup_bps' => $data->fxMarkupBps,
                'quote_validity_minutes' => $data->fxQuoteValidityMinutes,
            ];
            $document['links'] = [
                ...$document['links'],
                'default_expiration_hours' => $data->defaultExpirationHours,
                'max_expiration_hours' => $data->maxExpirationHours,
            ];

            $after = TenantSettings::fromArray($document);
            $tenant->forceFill(['settings' => $after->toArray()])->save();

            $this->audit->record(AuditAction::TenantPaymentSettingsUpdated, $tenant, [
                'before' => self::summary($before),
                'after' => self::summary($after),
            ], tenantId: $tenant->id, actor: Actor::user($actor->id));

            return $after;
        });
    }

    /**
     * @return array<string, string> field => message
     */
    private function errors(TenantPaymentSettingsData $data): array
    {
        $errors = [];
        $platformMaxHours = config()->integer('axispay.limits.max_expiration_hours');
        $maxMarkup = config()->integer('axispay.limits.max_fx_markup_bps');

        if (! $data->fxDefaultMode->converts()) {
            $errors['fx_default_mode'] = __('fx.settings.errors.mode');
        }

        if ($data->fxConversionEnabled && $data->fxDefaultMode === FxMode::Fixed && $data->fxFixedRate === null) {
            $errors['fx_fixed_rate'] = __('fx.settings.errors.fixed_rate_required');
        }

        if ($data->fxMarkupBps < 0 || $data->fxMarkupBps > $maxMarkup) {
            $errors['fx_markup_bps'] = __('fx.settings.errors.markup', ['max' => $maxMarkup]);
        }

        if ($data->fxQuoteValidityMinutes < self::MIN_QUOTE_VALIDITY_MINUTES || $data->fxQuoteValidityMinutes > self::MAX_QUOTE_VALIDITY_MINUTES) {
            $errors['fx_quote_validity_minutes'] = __('fx.settings.errors.quote_validity', ['min' => self::MIN_QUOTE_VALIDITY_MINUTES, 'max' => self::MAX_QUOTE_VALIDITY_MINUTES]);
        }

        if ($data->maxExpirationHours < self::MIN_EXPIRATION_HOURS || $data->maxExpirationHours > $platformMaxHours) {
            $errors['links_max_expiration_hours'] = __('fx.settings.errors.max_expiration', ['min' => self::MIN_EXPIRATION_HOURS, 'max' => $platformMaxHours, 'days' => intdiv($platformMaxHours, 24)]);
        }

        if ($data->defaultExpirationHours < self::MIN_EXPIRATION_HOURS || $data->defaultExpirationHours > $data->maxExpirationHours) {
            $errors['links_default_expiration_hours'] = __('fx.settings.errors.default_expiration', ['min' => self::MIN_EXPIRATION_HOURS]);
        }

        return $errors;
    }

    /**
     * @return array<string, mixed>
     */
    private static function summary(TenantSettings $settings): array
    {
        return [
            'fx_conversion_enabled' => $settings->fxConversionEnabled,
            'fx_default_mode' => $settings->fxDefaultMode->value,
            'fx_fixed_rate' => $settings->fxFixedRate?->toString(),
            'fx_markup_bps' => $settings->fxMarkupBps,
            'fx_quote_validity_minutes' => $settings->fxQuoteValidityMinutes,
            'links_default_expiration_hours' => $settings->defaultExpirationHours,
            'links_max_expiration_hours' => $settings->maxExpirationHours,
        ];
    }
}
