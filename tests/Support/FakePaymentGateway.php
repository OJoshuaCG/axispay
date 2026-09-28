<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Gateways\Contracts\PaymentGateway;
use App\Modules\Gateways\Data\CheckoutClientConfig;
use App\Modules\Gateways\Data\ConnectedAccountData;
use App\Modules\Gateways\Data\PaymentMethodPreview;
use App\Modules\Gateways\Data\PaymentRequest;
use App\Modules\Gateways\Data\ProviderPayment;
use App\Modules\Gateways\Data\ProviderPaymentFailure;
use App\Modules\Gateways\Data\ProviderRefund;
use App\Modules\Gateways\Data\ProviderWebhookEvent;
use App\Modules\Gateways\Data\RefundRequest;
use App\Modules\Gateways\Data\WebhookSource;
use App\Modules\Gateways\Enums\GatewayProvider;
use App\Modules\Gateways\Enums\ProviderEventKind;
use App\Modules\Gateways\Enums\ProviderPaymentStatus;
use App\Modules\Gateways\Exceptions\GatewayOperationNotImplementedException;
use App\Modules\Gateways\Exceptions\GatewayRequestException;
use App\Modules\Gateways\Exceptions\GatewayUnavailableException;
use App\Modules\Gateways\Exceptions\InvalidWebhookSignatureException;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Services\GatewayFactory;
use Closure;
use LogicException;
use Throwable;

/**
 * Deterministic PaymentGateway for domain tests (plan 12.1): accounts are
 * configured per gateway account ID, failures are queued exceptions, and
 * every call is recorded. No network, no Stripe SDK. Webhooks are "signed"
 * with a shared test secret header.
 */
final class FakePaymentGateway implements PaymentGateway
{
    public const string SIGNATURE = 'fake-signature';

    /** @var array<string, ConnectedAccountData> */
    private array $accounts = [];

    /** @var array<string, Throwable> */
    private array $failures = [];

    /** @var list<string> */
    public array $calls = [];

    /** @var array<string, array<string, mixed>> */
    private array $payments = [];

    /** @var array<string, string> idempotency key => payment ID */
    private array $idempotent = [];

    /** @var array<string, Throwable> */
    private array $nextFailures = [];

    /** @var list<string|null> receipt e-mail of each confirmation */
    public array $receiptEmails = [];

    /** @var list<string> idempotency keys of the confirmations, in order */
    public array $confirmKeys = [];

    /** @var list<string> idempotency keys of the captures, in order */
    public array $captureKeys = [];

    /** @var array<string, true> */
    private array $serverErrorNext = [];

    /** @var array<string, true> keys whose stored answer is a 500 */
    private array $serverErrorKeys = [];

    /** @var array<string, string> idempotency key => fingerprint of its parameters */
    private array $keyParameters = [];

    /** @var array<string, true> methods whose next answer is lost after doing the work */
    private array $loseResponse = [];

    private ProviderPaymentStatus $captureResult = ProviderPaymentStatus::Succeeded;

    public static function install(): self
    {
        $fake = new self;
        app(GatewayFactory::class)->fake($fake);

        return $fake;
    }

    /**
     * @param  list<string>  $currentlyDue
     */
    public function withAccount(string $accountId, bool $chargesEnabled = true, string $country = 'MX', array $currentlyDue = []): self
    {
        $requirements = ConnectedAccountData::emptyRequirements();
        $requirements['currently_due'] = $currentlyDue;
        $requirements['disabled_reason'] = $chargesEnabled ? null : 'requirements.past_due';

        $this->accounts[$accountId] = new ConnectedAccountData($accountId, $country, 'mxn', $chargesEnabled, $chargesEnabled, $chargesEnabled, $requirements);
        unset($this->failures[$accountId]);

        return $this;
    }

    public function failingWith(string $accountId, Throwable $exception): self
    {
        $this->failures[$accountId] = $exception;

        return $this;
    }

    public function provider(): GatewayProvider
    {
        return GatewayProvider::Stripe;
    }

    public function retrieveAccount(GatewayConnection $connection): ConnectedAccountData
    {
        $id = (string) $connection->provider_account_id;
        $this->calls[] = 'retrieveAccount:'.$id;

        if (isset($this->failures[$id])) {
            throw $this->failures[$id];
        }

        return $this->accounts[$id] ?? throw new LogicException("FakePaymentGateway has no account {$id}.");
    }

    public function clientConfig(GatewayConnection $connection): CheckoutClientConfig
    {
        return $connection->isApiKey()
            ? new CheckoutClientConfig((string) $connection->credentials_publishable, null)
            : new CheckoutClientConfig('pk_test_fake_platform', $connection->provider_account_id);
    }

    /**
     * Confirmation tokens of the fake: `ctoken_<scenario>[_suffix]` with
     * scenario success | decline | funds | threeds | processing | succeed
     * (authorized AND captured by the gateway at once, like automatic capture).
     */
    public function inspectPaymentMethod(GatewayConnection $connection, string $confirmationToken): PaymentMethodPreview
    {
        $this->calls[] = 'inspectPaymentMethod:'.$confirmationToken;
        $this->throwIfFailing('inspectPaymentMethod');

        if (str_starts_with($confirmationToken, 'ctoken_bogus')) {
            throw new GatewayRequestException('Fake: no such confirmation token.', 'resource_missing', null, 404);
        }

        $last4 = self::scenario($confirmationToken) === 'decline' ? '0002' : '4242';

        return new PaymentMethodPreview('MX', 'visa', $last4, 'fp_fake_'.$last4);
    }

    public function createOrUpdatePayment(GatewayConnection $connection, PaymentRequest $request): ProviderPayment
    {
        $this->calls[] = 'createOrUpdatePayment:'.$request->idempotencyKey;
        $this->throwIfFailing('createOrUpdatePayment');
        $this->throwIfServerError('createOrUpdatePayment', $request->idempotencyKey);

        // Like Stripe: a key reused with other parameters is refused.
        $fingerprint = hash('sha256', (string) json_encode([$request->amountMinor, $request->currency, $request->description, $request->metadata, $request->providerPaymentId]));

        if (isset($this->keyParameters[$request->idempotencyKey]) && $this->keyParameters[$request->idempotencyKey] !== $fingerprint) {
            throw new GatewayRequestException('Keys for idempotent requests can only be used with the same parameters.', 'idempotency_key_in_use', null, 400);
        }

        $this->keyParameters[$request->idempotencyKey] = $fingerprint;

        if (isset($this->idempotent[$request->idempotencyKey])) {
            return $this->toPayment($this->payments[$this->idempotent[$request->idempotencyKey]]);
        }

        $id = $request->providerPaymentId ?? 'pi_fake_'.(count($this->payments) + 1).'_'.bin2hex(random_bytes(4));
        $this->payments[$id] = [
            ...($this->payments[$id] ?? ['status' => ProviderPaymentStatus::RequiresPaymentMethod, 'failure' => null, 'created' => now()->getTimestamp()]),
            'id' => $id,
            'amount' => $request->amountMinor,
            'currency' => $request->currency,
            'metadata' => $request->metadata,
            'connection' => $connection->id,
        ];
        $this->idempotent[$request->idempotencyKey] = $id;
        $this->throwIfLost('createOrUpdatePayment');

        return $this->toPayment($this->payments[$id]);
    }

    public function confirmPayment(GatewayConnection $connection, string $providerPaymentId, string $confirmationToken, string $idempotencyKey, string $returnUrl, ?string $receiptEmail = null): ProviderPayment
    {
        $this->calls[] = 'confirmPayment:'.$providerPaymentId;
        $this->receiptEmails[] = $receiptEmail;
        $this->confirmKeys[] = $idempotencyKey;
        $this->throwIfFailing('confirmPayment');

        // Like Stripe: a key reused with other parameters is refused.
        $fingerprint = hash('sha256', (string) json_encode([$providerPaymentId, $confirmationToken, $returnUrl, $receiptEmail]));

        if (isset($this->keyParameters[$idempotencyKey]) && $this->keyParameters[$idempotencyKey] !== $fingerprint) {
            throw new GatewayRequestException('Keys for idempotent requests can only be used with the same parameters.', 'idempotency_key_in_use', null, 400);
        }

        $this->keyParameters[$idempotencyKey] = $fingerprint;

        if (isset($this->idempotent[$idempotencyKey])) {
            return $this->toPayment($this->payments[$providerPaymentId]);
        }

        $this->idempotent[$idempotencyKey] = $providerPaymentId;

        // Like the Stripe adapter: an already confirmed payment is returned as it is.
        if (! in_array($this->payments[$providerPaymentId]['status'], [ProviderPaymentStatus::RequiresPaymentMethod, ProviderPaymentStatus::RequiresConfirmation], true)) {
            return $this->toPayment($this->payments[$providerPaymentId]);
        }

        $payment = &$this->payments[$providerPaymentId];
        $payment['failure'] = null;
        $scenario = self::scenario($confirmationToken);
        $payment['last4'] = $scenario === 'decline' ? '0002' : '4242';
        $payment['status'] = match ($scenario) {
            'threeds' => ProviderPaymentStatus::RequiresAction,
            'processing' => ProviderPaymentStatus::Processing,
            'decline', 'funds' => ProviderPaymentStatus::RequiresPaymentMethod,
            'succeed' => ProviderPaymentStatus::Succeeded,
            default => ProviderPaymentStatus::RequiresCapture,
        };

        if ($scenario === 'decline' || $scenario === 'funds') {
            $payment['failure'] = new ProviderPaymentFailure('ch_fake_'.bin2hex(random_bytes(4)), 'card_declined', $scenario === 'funds' ? 'insufficient_funds' : 'generic_decline', 'Your card was declined.');
        }

        $result = $this->toPayment($payment);
        unset($payment);
        $this->throwIfLost('confirmPayment');

        return $result;
    }

    public function retrievePayment(GatewayConnection $connection, string $providerPaymentId): ProviderPayment
    {
        $this->calls[] = 'retrievePayment:'.$providerPaymentId;
        $this->throwIfFailing('retrievePayment');

        return $this->toPayment($this->payments[$providerPaymentId] ?? throw new LogicException("FakePaymentGateway has no payment {$providerPaymentId}."));
    }

    public function capturePayment(GatewayConnection $connection, string $providerPaymentId, string $idempotencyKey): ProviderPayment
    {
        $this->calls[] = 'capturePayment:'.$providerPaymentId;
        $this->captureKeys[] = $idempotencyKey;
        $this->throwIfFailing('capturePayment');
        $this->throwIfServerError('capturePayment', $idempotencyKey);

        if (! isset($this->idempotent[$idempotencyKey])) {
            $this->idempotent[$idempotencyKey] = $providerPaymentId;

            if ($this->payments[$providerPaymentId]['status'] !== ProviderPaymentStatus::RequiresCapture) {
                throw new GatewayRequestException('Fake: not capturable.', 'payment_intent_unexpected_state', null, 400);
            }

            $this->payments[$providerPaymentId]['status'] = $this->captureResult;
        }

        return $this->toPayment($this->payments[$providerPaymentId]);
    }

    public function cancelPayment(GatewayConnection $connection, string $providerPaymentId, string $idempotencyKey): ProviderPayment
    {
        $this->calls[] = 'cancelPayment:'.$providerPaymentId;
        $this->throwIfFailing('cancelPayment');
        $this->throwIfServerError('cancelPayment', $idempotencyKey);

        $status = $this->payments[$providerPaymentId]['status'];

        if ($status !== ProviderPaymentStatus::Succeeded && $status !== ProviderPaymentStatus::Processing) {
            $this->payments[$providerPaymentId]['status'] = ProviderPaymentStatus::Canceled;
        }

        return $this->toPayment($this->payments[$providerPaymentId]);
    }

    /** Test seam: the gateway's payment changes behind our back (webhook tests). */
    public function setPaymentStatus(string $providerPaymentId, ProviderPaymentStatus $status, ?ProviderPaymentFailure $failure = null): self
    {
        $this->payments[$providerPaymentId]['status'] = $status;
        $this->payments[$providerPaymentId]['failure'] = $failure;

        return $this;
    }

    /** Test seam: a payment created outside the checkout (reconciliation, foreign objects). */
    public function seedPayment(string $providerPaymentId, ProviderPaymentStatus $status, int $amount, string $currency, ?string $attemptId, ?int $createdAt = null): self
    {
        $this->payments[$providerPaymentId] = ['id' => $providerPaymentId, 'status' => $status, 'failure' => null, 'amount' => $amount, 'currency' => $currency, 'metadata' => $attemptId !== null ? ['axispay_attempt_id' => $attemptId] : [], 'connection' => null, 'created' => $createdAt ?? now()->getTimestamp()];

        return $this;
    }

    /** Test seam: the next call of `$method` does its work, then its answer is lost (network). */
    public function loseNextResponse(string $method): self
    {
        $this->loseResponse[$method] = true;

        return $this;
    }

    private function throwIfLost(string $method): void
    {
        if (isset($this->loseResponse[$method])) {
            unset($this->loseResponse[$method]);

            throw new GatewayUnavailableException('Fake: the answer was lost.');
        }
    }

    /**
     * Test seam: the next call of `$method` answers a 500, and, like Stripe,
     * every later call under the same idempotency key answers it again.
     */
    public function serverErrorOnNext(string $method): self
    {
        $this->serverErrorNext[$method] = true;

        return $this;
    }

    private function throwIfServerError(string $method, string $idempotencyKey): void
    {
        if (isset($this->serverErrorNext[$method])) {
            unset($this->serverErrorNext[$method]);
            $this->serverErrorKeys[$idempotencyKey] = true;
        }

        if (isset($this->serverErrorKeys[$idempotencyKey])) {
            throw new GatewayUnavailableException('Fake: a stored server error.', 'api_error', null, 500);
        }
    }

    /** Test seam: the next call of `$method` throws. */
    public function failNext(string $method, Throwable $exception): self
    {
        $this->nextFailures[$method] = $exception;

        return $this;
    }

    /** Test seam: what a capture turns the payment into (succeeded by default). */
    public function capturesAs(ProviderPaymentStatus $status): self
    {
        $this->captureResult = $status;

        return $this;
    }

    public function paymentStatus(string $providerPaymentId): ?ProviderPaymentStatus
    {
        $status = $this->payments[$providerPaymentId]['status'] ?? null;

        return $status instanceof ProviderPaymentStatus ? $status : null;
    }

    /** @return list<string> */
    public function callsTo(string $method): array
    {
        return array_values(array_filter($this->calls, static fn (string $call): bool => str_starts_with($call, $method.':')));
    }

    /** @var array<string, Closure(): void> */
    private array $before = [];

    /** Test seam: run `$callback` right before the next call of `$method` does its work. */
    public function beforeNext(string $method, Closure $callback): self
    {
        $this->before[$method] = $callback;

        return $this;
    }

    private function throwIfFailing(string $method): void
    {
        if (isset($this->before[$method])) {
            $callback = $this->before[$method];
            unset($this->before[$method]);
            $callback();
        }

        if (isset($this->nextFailures[$method])) {
            $exception = $this->nextFailures[$method];
            unset($this->nextFailures[$method]);

            throw $exception;
        }
    }

    private static function scenario(string $token): string
    {
        return preg_match('/^ctoken_(?:sandbox_)?(success|decline|funds|threeds|processing|succeed)/', $token, $match) === 1 ? $match[1] : 'success';
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    private function toPayment(array $payment): ProviderPayment
    {
        $status = $payment['status'];
        assert($status instanceof ProviderPaymentStatus);
        $metadata = is_array($payment['metadata'] ?? null) ? $payment['metadata'] : [];
        $failure = $payment['failure'] ?? null;
        $id = is_string($payment['id'] ?? null) ? $payment['id'] : '';
        $amount = is_int($payment['amount'] ?? null) ? $payment['amount'] : 0;
        $currency = is_string($payment['currency'] ?? null) ? $payment['currency'] : 'MXN';
        $last4 = is_string($payment['last4'] ?? null) ? $payment['last4'] : '4242';

        return new ProviderPayment(
            providerPaymentId: $id,
            status: $status,
            amountMinor: $amount,
            currency: $currency,
            amountCapturableMinor: $status === ProviderPaymentStatus::RequiresCapture ? $amount : 0,
            clientSecret: $status === ProviderPaymentStatus::RequiresAction ? $id.'_secret_fake' : null,
            cardPreview: new PaymentMethodPreview('MX', 'visa', $last4, 'fp_fake_'.$last4),
            failure: $status === ProviderPaymentStatus::RequiresPaymentMethod && $failure instanceof ProviderPaymentFailure ? $failure : null,
            attemptReference: isset($metadata['axispay_attempt_id']) && is_string($metadata['axispay_attempt_id']) ? $metadata['axispay_attempt_id'] : null,
            createdAt: is_int($payment['created'] ?? null) ? $payment['created'] : null,
        );
    }

    public function refund(GatewayConnection $connection, RefundRequest $request): ProviderRefund
    {
        throw GatewayOperationNotImplementedException::for(__FUNCTION__, 'Phase 7');
    }

    public function retrieveRefund(GatewayConnection $connection, string $providerRefundId): ProviderRefund
    {
        throw GatewayOperationNotImplementedException::for(__FUNCTION__, 'Phase 7');
    }

    public function eventKind(string $providerEventType, bool $direct): ProviderEventKind
    {
        return match ($providerEventType) {
            'account.updated' => ProviderEventKind::AccountUpdated,
            'account.application.deauthorized' => $direct ? ProviderEventKind::Unhandled : ProviderEventKind::AccountDeauthorized,
            'payment_intent.amount_capturable_updated', 'payment_intent.canceled', 'payment_intent.payment_failed',
            'payment_intent.processing', 'payment_intent.requires_action', 'payment_intent.succeeded' => ProviderEventKind::PaymentUpdated,
            default => ProviderEventKind::Unhandled,
        };
    }

    public function reduceWebhookPayload(string $payload): string
    {
        $event = json_decode($payload, true);

        return (string) json_encode(is_array($event) ? ['id' => $event['id'] ?? null, 'type' => $event['type'] ?? null, 'axispay_reduced' => true] : []);
    }

    public function parseWebhook(string $rawBody, array $headers, WebhookSource $source): ProviderWebhookEvent
    {
        $signature = $headers['stripe-signature'] ?? null;
        $signature = is_array($signature) ? ($signature[0] ?? null) : $signature;

        if ($signature !== self::SIGNATURE) {
            throw new InvalidWebhookSignatureException('Fake signature mismatch.');
        }

        $event = json_decode($rawBody, true);

        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null)) {
            throw new InvalidWebhookSignatureException('Malformed fake event.');
        }

        $account = $source->connection !== null
            ? $source->connection->provider_account_id
            : (is_string($event['account'] ?? null) ? $event['account'] : null);

        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        $object = is_array($data['object'] ?? null) ? $data['object'] : [];
        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
        $reference = $metadata['axispay_attempt_id'] ?? null;

        return new ProviderWebhookEvent(
            $event['id'],
            $event['type'],
            $this->eventKind($event['type'], $source->isDirect()),
            $account,
            ($event['livemode'] ?? false) === true,
            is_string($object['id'] ?? null) ? $object['id'] : null,
            $rawBody,
            $this->reduceWebhookPayload($rawBody),
            is_string($reference) ? $reference : null,
        );
    }
}
