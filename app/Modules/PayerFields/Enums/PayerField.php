<?php

declare(strict_types=1);

namespace App\Modules\PayerFields\Enums;

/**
 * Payer field catalog (plan 19.1, ADR-021). Tenants set a default per field
 * and links freeze the effective configuration at creation (Phase 3);
 * collecting and storing the values is Phase 4 / 8.
 */
enum PayerField: string
{
    case Email = 'email';
    case FullName = 'full_name';
    case Phone = 'phone';
    case CompanyName = 'company_name';
    case BillingAddress = 'billing_address';
    case TaxId = 'tax_id';
    case Notes = 'notes';

    /** Plan 19.1: without a tenant setting, e-mail is optional and the rest hidden. */
    public function platformDefault(): PayerFieldRequirement
    {
        return $this === self::Email ? PayerFieldRequirement::Optional : PayerFieldRequirement::Hidden;
    }

    public function label(): string
    {
        return __('payment_links.payer_field.'.$this->value);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $field): string => $field->value, self::cases());
    }
}
