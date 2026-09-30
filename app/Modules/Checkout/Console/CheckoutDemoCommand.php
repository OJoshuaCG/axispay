<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Console;

use App\Modules\Audit\Data\Actor;
use App\Modules\Checkout\Services\CheckoutUrls;
use App\Modules\Gateways\Enums\ConnectionMethod;
use App\Modules\Gateways\Enums\ConnectionStatus;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Sandbox\SandboxMode;
use App\Modules\Legal\Enums\LegalDocumentFormat;
use App\Modules\Legal\Enums\LegalDocumentKind;
use App\Modules\Legal\Models\TenantLegalDocument;
use App\Modules\PaymentLinks\Actions\CancelPaymentLink;
use App\Modules\PaymentLinks\Actions\CreatePaymentLink;
use App\Modules\PaymentLinks\Actions\ExpirePaymentLink;
use App\Modules\PaymentLinks\Data\CancelPaymentLinkData;
use App\Modules\PaymentLinks\Data\CreationContext;
use App\Modules\PaymentLinks\Enums\CreatedVia;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\PaymentLinks\Services\PaymentLinkInputParser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Local development and browser tests only (ADR-0051): with the checkout
 * sandbox on, creates a demo tenant with a sandbox Stripe connection (test
 * mode) and payment links in every page state, and prints their URLs.
 * Refuses to run when the sandbox is off.
 */
final class CheckoutDemoCommand extends Command
{
    private const string DEMO_TERMS = <<<'MD'
        # Términos y condiciones de Tienda Demo

        Estos términos son un **ejemplo** del modo sandbox.

        ## Pagos

        - El cargo se hace en la moneda del enlace.
        - Consulta dudas en [soporte](mailto:soporte@demo.test).
        MD;

    protected $signature = 'axispay:checkout:demo {--json : Print the URLs as JSON}';

    protected $description = 'Create sandbox payment links to try the checkout without Stripe keys (local/testing only).';

    public function handle(TenantContext $context, PaymentLinkInputParser $parser, CreatePaymentLink $create, ExpirePaymentLink $expire, CancelPaymentLink $cancel, CheckoutUrls $urls): int
    {
        if (! SandboxMode::enabled()) {
            $this->components->error('The checkout sandbox is off. Set AXISPAY_CHECKOUT_SANDBOX=true (APP_ENV local or testing only).');

            return self::FAILURE;
        }

        $tenant = Tenant::query()->firstOrNew(['legal_name' => 'AxisPay Demo Store S.A. de C.V.']);
        $tenant->fill([
            'display_name' => 'Tienda Demo',
            'support_email' => 'soporte@demo.test',
            'allowed_return_domains' => ['demo.test'],
        ]);
        $tenant->forceFill(['status' => TenantStatus::Active])->save();

        $links = $context->runAsTenant($tenant->id, false, function () use ($parser, $create, $expire, $cancel): array {
            // ADR-0056: a privacy notice (a link, so payer fields are collected) and terms (a text, the checkout's dialog).
            foreach ([
                [LegalDocumentKind::Privacy, LegalDocumentFormat::Url, null, 'https://demo.test/privacidad'],
                [LegalDocumentKind::Terms, LegalDocumentFormat::Text, self::DEMO_TERMS, null],
            ] as [$kind, $format, $body, $url]) {
                $document = TenantLegalDocument::query()->where('kind', $kind->value)->first() ?? (new TenantLegalDocument)->forceFill(['kind' => $kind]);
                $document->forceFill(['format' => $format, 'body' => $body, 'url' => $url])->save();
            }

            if (! GatewayConnection::query()->current()->exists()) {
                (new GatewayConnection)->forceFill([
                    'connection_method' => ConnectionMethod::PlatformOnboarding,
                    'provider_account_id' => 'acct_sandbox'.Str::random(10),
                    'country' => 'MX',
                    'default_currency' => 'mxn',
                    'status' => ConnectionStatus::Active,
                    'charges_enabled' => true,
                    'payouts_enabled' => true,
                    'details_submitted' => true,
                    'connected_at' => now(),
                ])->save();
            }

            $make = fn (array $input): PaymentLink => $create->handle($parser->parse($input), new CreationContext(CreatedVia::Api, Actor::system()));

            $links = [
                'active' => $make(['amount' => '1500.00', 'currency' => 'MXN', 'description' => 'Pedido #A-1029 — 2 artículos', 'payer_fields' => ['email' => 'required', 'full_name' => 'optional', 'phone' => 'optional'], 'return_url' => 'https://demo.test/gracias', 'locale' => 'es']),
                'active_en' => $make(['amount' => '89.90', 'currency' => 'USD', 'description' => 'Order #B-2231 — annual plan', 'payer_fields' => ['email' => 'optional'], 'locale' => 'en']),
                'all_fields' => $make(['amount' => '2400.00', 'currency' => 'MXN', 'description' => 'Servicio de mantenimiento — factura septiembre', 'payer_fields' => ['email' => 'required', 'full_name' => 'required', 'phone' => 'required', 'company_name' => 'optional', 'billing_address' => 'required', 'tax_id' => 'optional', 'notes' => 'optional'], 'expires_in_hours' => 5]),
                'expired' => $make(['amount' => '350.00', 'currency' => 'MXN', 'description' => 'Pedido vencido']),
                'canceled' => $make(['amount' => '420.00', 'currency' => 'MXN', 'description' => 'Pedido cancelado']),
            ];

            $links['expired']->forceFill(['expires_at' => now()->subMinute()])->save();
            $expire->handle($links['expired']->id);
            $cancel->handle($links['canceled'], new CancelPaymentLinkData('Demo'), Actor::system());

            return $links;
        });

        $out = array_map(static fn (PaymentLink $link): string => $urls->page($link), $links);

        if ($this->option('json') === true) {
            $this->line((string) json_encode($out, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        foreach ($out as $name => $url) {
            $this->components->twoColumnDetail($name, $url);
        }

        return self::SUCCESS;
    }
}
