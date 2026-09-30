<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Services;

use App\Modules\Legal\Services\TenantLegalDocuments;
use App\Modules\PayerFields\Enums\PayerField;
use App\Modules\PayerFields\Enums\PayerFieldRequirement;
use App\Modules\PaymentLinks\Models\PaymentLink;
use Illuminate\Support\Facades\Log;

/**
 * The payer fields the checkout really collects for a link (plan 11.3, 19.2):
 * the configuration frozen on the link, but only when the tenant has a
 * privacy notice (a text or a link, ADR-0056). Without one the page
 * collects no payer data at all (every field hidden), the omission is
 * logged, and the tenant panel warns on the link (ADR-0051): collecting
 * personal data without the merchant's notice is not allowed, and refusing
 * to show the payment page would be worse for the payer.
 */
final readonly class EffectivePayerFields
{
    public function __construct(private TenantLegalDocuments $legal) {}

    /**
     * @return array<string, string> field => requirement
     */
    public function for(PaymentLink $link): array
    {
        $config = [];

        foreach (PayerField::cases() as $field) {
            $config[$field->value] = (PayerFieldRequirement::tryFrom($link->payer_fields_config[$field->value] ?? '') ?? $field->platformDefault())->value;
        }

        if (! self::collectsAny($config) || $this->hasPrivacyNotice($link->tenant_id)) {
            return $config;
        }

        Log::warning('Payer fields are not collected: the tenant has no privacy notice.', ['payment_link_id' => $link->id]);

        return array_map(static fn (): string => PayerFieldRequirement::Hidden->value, $config);
    }

    /** For the panel: the link asks for payer data the checkout cannot collect. */
    public function missingPrivacyNotice(PaymentLink $link): bool
    {
        return self::collectsAny($link->payer_fields_config) && ! $this->hasPrivacyNotice($link->tenant_id);
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    private static function collectsAny(array $config): bool
    {
        foreach ($config as $requirement) {
            if ($requirement !== PayerFieldRequirement::Hidden->value) {
                return true;
            }
        }

        return false;
    }

    private function hasPrivacyNotice(string $tenantId): bool
    {
        return $this->legal->hasPrivacyNotice($tenantId);
    }
}
