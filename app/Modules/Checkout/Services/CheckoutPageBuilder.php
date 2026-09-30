<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Services;

use App\Modules\Checkout\Actions\ReadCheckoutStatus;
use App\Modules\Checkout\Data\CheckoutPage;
use App\Modules\Checkout\Enums\CheckoutPhase;
use App\Modules\Checkout\Enums\CheckoutState;
use App\Modules\Gateways\Sandbox\SandboxMode;
use App\Modules\Gateways\Services\GatewayFactory;
use App\Modules\Legal\Services\TenantLegalDocuments;
use App\Modules\PayerFields\Enums\PayerField;
use App\Modules\PayerFields\Enums\PayerFieldRequirement;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Tenancy\Services\TenantAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Builds the payment page of a link (plan 11.2, 11.3). The gateway SDK is
 * initialized from CheckoutClientConfig (plan 12.4.1): platform key +
 * connected account for the Connect methods, the merchant's own publishable
 * key and no account for api_key. The view never knows the connection
 * method, and no secret key ever reaches it.
 */
final readonly class CheckoutPageBuilder
{
    public function __construct(
        private GatewayFactory $gateways,
        private TenantAccess $access,
        private TurnstileVerifier $turnstile,
        private CheckoutRateLimiter $limiter,
        private LinkDeclineCounter $declines,
        private CheckoutUrls $urls,
        private CheckoutFonts $fonts,
        private EffectivePayerFields $effectiveFields,
        private ReadCheckoutStatus $status,
        private CheckoutConnection $connection,
        private TenantLegalDocuments $legal,
        private CheckoutMerchantLogo $merchantLogo,
    ) {}

    public function build(PaymentLink $link, ?CheckoutPhase $phase = null, bool $paidInThisSession = false, int $sessionDeclines = 0): CheckoutPage
    {
        $tenantId = $link->tenant_id;
        $timezone = $this->access->timezone($tenantId);
        $state = $this->status->state($link);
        $fields = $this->effectiveFields->for($link);
        $noticeHours = config()->integer('axispay.checkout.expiry_notice_hours');

        $expiresSoon = $link->status->isShareable() && $link->expires_at->lessThan(CarbonImmutable::now()->addHours($noticeHours))
            ? $link->expires_at->setTimezone($timezone)
            : null;

        return new CheckoutPage(
            state: $state,
            token: $link->public_token,
            merchant: $this->access->displayName($tenantId),
            merchantLogo: $this->merchantLogo->for($link),
            supportEmail: $this->access->supportEmail($tenantId),
            legal: $this->legal->all($tenantId),
            description: $link->description,
            money: $link->money(),
            expiresSoonAt: $expiresSoon,
            paidAt: $link->paid_at?->setTimezone($timezone),
            returnUrl: $link->return_url,
            paidInThisSession: $paidInThisSession,
            payerFields: self::payerFields($fields),
            client: $state === CheckoutState::Active ? $this->client($link, $fields, $sessionDeclines) : null,
            sandbox: SandboxMode::enabled(),
            phase: $phase,
        );
    }

    /**
     * @param  array<string, string>  $config
     * @return list<array{field: string, required: bool}>
     */
    private static function payerFields(array $config): array
    {
        $fields = [];

        foreach (PayerField::cases() as $field) {
            $requirement = PayerFieldRequirement::tryFrom($config[$field->value] ?? '') ?? $field->platformDefault();

            if ($requirement !== PayerFieldRequirement::Hidden) {
                $fields[] = ['field' => $field->value, 'required' => $requirement === PayerFieldRequirement::Required];
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, mixed>|null
     */
    private function client(PaymentLink $link, array $fields, int $sessionDeclines): ?array
    {
        // An active page implies a connection that can charge (ReadCheckoutStatus::state()).
        $connection = $this->connection->chargeable();

        if ($connection === null) {
            return null;
        }

        $config = $this->gateways->for($connection->provider)->clientConfig($connection);
        $money = $link->money();

        return [
            'publishableKey' => $config->publishableKey,
            'stripeAccount' => $config->accountId,
            'amount' => $money->minorAmount,
            'currency' => strtolower($money->currency->value),
            'locale' => CheckoutLocale::stripe(app()->getLocale()),
            'fonts' => $this->fonts->stripeFonts(),
            'endpoints' => [
                'attempts' => $this->urls->attempts($link),
                'continue' => $this->urls->continue($link),
                'status' => $this->urls->status($link),
                'complete' => $this->urls->complete($link),
                'sandboxNextAction' => SandboxMode::enabled() ? $this->urls->sandboxNextAction($link) : null,
            ],
            'turnstile' => $this->turnstileConfig($link, $sessionDeclines),
            'pausedMinutes' => $this->limiter->pausedMinutes($link),
            'poll' => [
                'intervalMs' => max(1, config()->integer('axispay.checkout.poll_interval_seconds')) * 1000,
                'maxMs' => max(1, config()->integer('axispay.checkout.poll_max_seconds')) * 1000,
            ],
            'billingDetailsNever' => self::collectedBillingDetails($fields),
        ];
    }

    /**
     * @return array{enabled: bool, siteKey: string|null, required: bool}
     */
    private function turnstileConfig(PaymentLink $link, int $sessionDeclines): array
    {
        $siteKey = $this->turnstile->siteKey();
        $required = $this->declines->turnstileRequired($link, $sessionDeclines);

        // The page cannot pay without the widget; production refuses to boot
        // without the keys, so this only happens in a misconfigured non-production.
        if ($required && $siteKey === null) {
            Log::error('Turnstile is required on a payment page but TURNSTILE_SITE_KEY is not configured.', ['payment_link_id' => $link->id]);
        }

        return ['enabled' => TurnstileVerifier::enabled(), 'siteKey' => $siteKey, 'required' => $required];
    }

    /**
     * Billing details the page collects itself (required fields only): the
     * Payment Element does not ask for them again, and the page passes them
     * with the confirmation token (docs/frontend/checkout-design.md, Stripe Appearance).
     *
     * @param  array<string, string>  $fields
     * @return list<string>
     */
    private static function collectedBillingDetails(array $fields): array
    {
        $map = ['email' => 'email', 'full_name' => 'name', 'phone' => 'phone', 'billing_address' => 'address'];
        $never = [];

        foreach ($map as $field => $stripeName) {
            if (($fields[$field] ?? null) === PayerFieldRequirement::Required->value) {
                $never[] = $stripeName;
            }
        }

        return $never;
    }
}
