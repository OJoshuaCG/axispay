<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Services;

use App\Modules\Checkout\Actions\ReadCheckoutStatus;
use App\Modules\Checkout\Data\CheckoutPage;
use App\Modules\Checkout\Enums\CheckoutState;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Sandbox\SandboxMode;
use App\Modules\Gateways\Services\GatewayFactory;
use App\Modules\PayerFields\Enums\PayerField;
use App\Modules\PayerFields\Enums\PayerFieldRequirement;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantAccess;
use Carbon\CarbonImmutable;

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
        private CardTestingGuard $guard,
        private CheckoutUrls $urls,
        private CheckoutFonts $fonts,
    ) {}

    public function build(PaymentLink $link, ?string $phase = null, bool $paidInThisSession = false, int $sessionDeclines = 0): CheckoutPage
    {
        $tenant = Tenant::query()->findOrFail($link->tenant_id);
        $timezone = $this->access->timezone($tenant->id);
        $state = ReadCheckoutStatus::state($link);
        $noticeHours = config()->integer('axispay.checkout.expiry_notice_hours');

        $expiresSoon = $link->status->isShareable() && $link->expires_at->lessThan(CarbonImmutable::now()->addHours($noticeHours))
            ? $link->expires_at->setTimezone($timezone)
            : null;

        return new CheckoutPage(
            state: $state,
            token: $link->public_token,
            merchant: $tenant->display_name,
            supportEmail: $tenant->support_email,
            privacyUrl: $tenant->privacy_notice_url,
            description: $link->description,
            money: $link->money(),
            expiresSoonAt: $expiresSoon,
            paidAt: $link->paid_at?->setTimezone($timezone),
            returnUrl: $link->return_url,
            paidInThisSession: $paidInThisSession,
            payerFields: self::payerFields($link),
            client: $state === CheckoutState::Active ? $this->client($link, $sessionDeclines) : null,
            sandbox: SandboxMode::enabled(),
            phase: $phase,
        );
    }

    /**
     * @return list<array{field: string, required: bool}>
     */
    private static function payerFields(PaymentLink $link): array
    {
        $fields = [];

        foreach (PayerField::cases() as $field) {
            $requirement = PayerFieldRequirement::tryFrom($link->payer_fields_config[$field->value] ?? '') ?? $field->platformDefault();

            if ($requirement !== PayerFieldRequirement::Hidden) {
                $fields[] = ['field' => $field->value, 'required' => $requirement === PayerFieldRequirement::Required];
            }
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function client(PaymentLink $link, int $sessionDeclines): ?array
    {
        $connection = GatewayConnection::query()->current()->first();

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
            'turnstile' => [
                'siteKey' => $this->turnstile->siteKey(),
                'required' => $this->guard->turnstileRequired($link, $sessionDeclines),
            ],
            'pausedMinutes' => $this->guard->pausedMinutes($link),
            'poll' => [
                'intervalMs' => max(1, config()->integer('axispay.checkout.poll_interval_seconds')) * 1000,
                'maxMs' => max(1, config()->integer('axispay.checkout.poll_max_seconds')) * 1000,
            ],
            'billingDetailsNever' => self::collectedBillingDetails($link),
        ];
    }

    /**
     * Billing details the page collects itself (required fields only): the
     * Payment Element does not ask for them again, and the page passes them
     * with the confirmation token (DESIGN.md, Stripe Appearance).
     *
     * @return list<string>
     */
    private static function collectedBillingDetails(PaymentLink $link): array
    {
        $map = ['email' => 'email', 'full_name' => 'name', 'phone' => 'phone', 'billing_address' => 'address'];
        $never = [];

        foreach ($map as $field => $stripeName) {
            if (($link->payer_fields_config[$field] ?? null) === PayerFieldRequirement::Required->value) {
                $never[] = $stripeName;
            }
        }

        return $never;
    }
}
