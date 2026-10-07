<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Models\Refund;
use App\Modules\Webhooks\Enums\WebhookEventType;
use App\Modules\Webhooks\Models\WebhookEvent;
use Illuminate\Testing\TestResponse;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\withHeaders;

/**
 * Shared helpers of the refund tests (CRX-13, CRX-14): a captured payment of
 * a ready tenant, the refund endpoints and what the integrator was told.
 */
final class RefundTestHelpers
{
    /**
     * A paid payment (USD 1,500.00, captured) of a ready tenant, with an API key.
     *
     * @return array{0: PaymentLink, 1: FakePaymentGateway, 2: PaymentAttempt, 3: string}
     */
    public static function scenario(): array
    {
        [$tenant, $link, $fake] = CheckoutTestHelpers::scenario();
        [, $key] = ApiTestHelpers::key($tenant);
        CheckoutTestHelpers::pay($link)->assertOk();
        $attempt = CheckoutTestHelpers::attempts($link)[0];
        expect($attempt->status)->toBe(PaymentAttemptStatus::Succeeded);

        return [$link, $fake, $attempt, $key];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    public static function post(string $key, array $body, ?string $idempotencyKey = 'refund-key-1'): TestResponse
    {
        return withHeaders(ApiTestHelpers::headers($key, $idempotencyKey))->postJson(apiUrl('v1/refunds'), $body);
    }

    /**
     * @return TestResponse<Response>
     */
    public static function get(string $key, string $id): TestResponse
    {
        return withHeaders(ApiTestHelpers::headers($key))->getJson(apiUrl('v1/refunds/'.$id));
    }

    /**
     * @return TestResponse<Response>
     */
    public static function list(string $key, string $query = ''): TestResponse
    {
        return withHeaders(ApiTestHelpers::headers($key))->getJson(apiUrl('v1/refunds'.($query !== '' ? '?'.$query : '')));
    }

    /**
     * @return list<Refund>
     */
    public static function of(PaymentLink $link): array
    {
        return CheckoutTestHelpers::inTenant($link, static fn (): array => array_values(Refund::query()->orderBy('id')->get()->all()));
    }

    /**
     * @return list<array<mixed>> decoded refund webhook bodies of a type, oldest first
     */
    public static function webhookBodies(PaymentLink $link, WebhookEventType $type): array
    {
        $events = WebhookTestHelpers::in($link->tenant_id, $link->livemode, static fn (): array => WebhookEvent::query()->orderBy('id')->get()->all());

        return array_values(array_map(
            static fn (WebhookEvent $event): array => jsonArray($event->payload),
            array_filter($events, static fn (WebhookEvent $event): bool => $event->type === $type),
        ));
    }

    /**
     * The `id` of a created resource.
     *
     * @param  TestResponse<Response>  $response
     */
    public static function idOf(TestResponse $response): string
    {
        $id = $response->json('id');

        return is_string($id) ? $id : throw new LogicException('The response has no id.');
    }

    /** The link's (only) payment, as it is now. */
    public static function attempt(PaymentLink $link): PaymentAttempt
    {
        return CheckoutTestHelpers::attempts($link)[0];
    }
}
