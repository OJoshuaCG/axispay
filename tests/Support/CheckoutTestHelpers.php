<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Legal\Enums\LegalDocumentFormat;
use App\Modules\Legal\Enums\LegalDocumentKind;
use App\Modules\Legal\Models\TenantLegalDocument;
use App\Modules\Legal\Services\TenantLegalDocuments;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Closure;
use Database\Factories\GatewayConnectionFactory;
use Database\Factories\PaymentLinkFactory;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * Shared helpers of the checkout and payments tests (Phase 4).
 */
final class CheckoutTestHelpers
{
    /**
     * A tenant ready to charge in test mode, one link and the fake gateway.
     * The connected account is in the United States unless `$connection` says
     * otherwise: USD links are then charged as they are, whatever the card
     * (the conversion rule needs a Mexican account, ADR-0063).
     *
     * @param  (Closure(PaymentLinkFactory): PaymentLinkFactory)|null  $link
     * @param  (Closure(GatewayConnectionFactory): GatewayConnectionFactory)|null  $connection
     * @return array{0: Tenant, 1: PaymentLink, 2: FakePaymentGateway}
     */
    public static function scenario(?Closure $link = null, ?Closure $connection = null): array
    {
        $tenant = Tenant::factory()->status(TenantStatus::Active)->create(['display_name' => 'Tienda Demo', 'support_email' => 'soporte@demo.test']);
        self::legalDocument($tenant, LegalDocumentKind::Privacy, url: 'https://demo.test/privacidad');
        GatewayTestHelpers::connection($tenant, false, static fn (GatewayConnectionFactory $factory): GatewayConnectionFactory => ($connection ?? static fn (GatewayConnectionFactory $f): GatewayConnectionFactory => $f)($factory->state(['country' => 'US'])));
        $fake = FakePaymentGateway::install();

        return [$tenant, ApiTestHelpers::link($tenant, false, $link), $fake];
    }

    /**
     * Publishes one of the tenant's legal documents (ADR-0056): a link when
     * $url is given, otherwise a text with $body.
     */
    public static function legalDocument(Tenant $tenant, LegalDocumentKind $kind, ?string $body = null, ?string $url = null): TenantLegalDocument
    {
        return app(TenantContext::class)->runAsTenant($tenant->id, false, static function () use ($tenant, $kind, $body, $url): TenantLegalDocument {
            $document = TenantLegalDocument::query()->where('kind', $kind->value)->first() ?? (new TenantLegalDocument)->forceFill(['tenant_id' => $tenant->id, 'kind' => $kind]);
            $document->forceFill([
                'format' => $url !== null ? LegalDocumentFormat::Url : LegalDocumentFormat::Text,
                'body' => $url !== null ? null : ($body ?? 'Texto de ejemplo.'),
                'url' => $url,
            ])->save();
            app(TenantLegalDocuments::class)->forget($tenant->id);

            return $document;
        });
    }

    /** Removes one of the tenant's legal documents. */
    public static function removeLegalDocument(Tenant $tenant, LegalDocumentKind $kind): void
    {
        app(TenantContext::class)->runAsTenant($tenant->id, false, static function () use ($kind): void {
            TenantLegalDocument::query()->where('kind', $kind->value)->delete();
        });
        app(TenantLegalDocuments::class)->forget($tenant->id);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    public static function pay(PaymentLink $link, string $token = 'ctoken_success', array $body = []): TestResponse
    {
        return postJson(payUrl('/l/'.$link->public_token.'/attempts'), [
            'confirmation_token' => $token,
            'payer' => ['email' => 'ana@example.com'],
            ...$body,
        ], ['User-Agent' => 'Mozilla/5.0 (Test)']);
    }

    /**
     * @return TestResponse<Response>
     */
    public static function continue(PaymentLink $link): TestResponse
    {
        return postJson(payUrl('/l/'.$link->public_token.'/attempts/continue'));
    }

    /**
     * @return TestResponse<Response>
     */
    public static function status(PaymentLink $link): TestResponse
    {
        return getJson(payUrl('/l/'.$link->public_token.'/status'));
    }

    public static function freshLink(PaymentLink $link): PaymentLink
    {
        return app(TenantContext::class)->runAsTenant($link->tenant_id, $link->livemode, static fn (): PaymentLink => PaymentLink::query()->findOrFail($link->id));
    }

    /**
     * @return list<PaymentAttempt>
     */
    public static function attempts(PaymentLink $link): array
    {
        return app(TenantContext::class)->runAsTenant($link->tenant_id, $link->livemode, static fn (): array => array_values(PaymentAttempt::query()->where('payment_link_id', $link->id)->orderBy('id')->get()->all()));
    }

    public static function connectionOf(PaymentLink $link): GatewayConnection
    {
        return app(TenantContext::class)->runAsTenant($link->tenant_id, $link->livemode, static fn (): GatewayConnection => GatewayConnection::query()->current()->firstOrFail());
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function inTenant(PaymentLink $link, Closure $callback): mixed
    {
        return app(TenantContext::class)->runAsTenant($link->tenant_id, $link->livemode, $callback);
    }
}
