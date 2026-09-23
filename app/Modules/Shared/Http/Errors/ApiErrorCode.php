<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Errors;

/**
 * Catalog of public API error codes (plan section 10.4). Documented in
 * docs/api/openapi.yaml; keep both in sync. Codes are part of the public
 * contract: add new ones, never rename or repurpose existing ones.
 */
enum ApiErrorCode: string
{
    case InvalidApiKey = 'invalid_api_key';
    case InsufficientScope = 'insufficient_scope';
    case TenantSuspended = 'tenant_suspended';
    case GatewayNotReady = 'gateway_not_ready';
    case ParameterMissing = 'parameter_missing';
    case ParameterInvalid = 'parameter_invalid';
    case AmountMustBeString = 'amount_must_be_string';
    case AmountInvalid = 'amount_invalid';
    case AmountBelowMinimum = 'amount_below_minimum';
    case AmountAboveMaximum = 'amount_above_maximum';
    case AmountBelowMinimumAfterConversion = 'amount_below_minimum_after_conversion';
    case CurrencyNotSupported = 'currency_not_supported';
    case ExpirationOutOfRange = 'expiration_out_of_range';
    case FxNotAvailable = 'fx_not_available';
    case FxRateInvalid = 'fx_rate_invalid';
    case MetadataInvalid = 'metadata_invalid';
    case ReturnUrlNotAllowed = 'return_url_not_allowed';
    case PayerFieldInvalid = 'payer_field_invalid';
    case ValidationEndpointNotConfigured = 'validation_endpoint_not_configured';
    case IdempotencyKeyRequired = 'idempotency_key_required';
    case IdempotencyKeyReused = 'idempotency_key_reused';
    case IdempotencyRequestInProgress = 'idempotency_request_in_progress';
    case ResourceNotFound = 'resource_not_found';
    case LinkNotCancelable = 'link_not_cancelable';
    case LinkPaymentInProgress = 'link_payment_in_progress';
    case RefundExceedsAvailable = 'refund_exceeds_available';
    case PaymentNotRefundable = 'payment_not_refundable';
    case RateLimited = 'rate_limited';
    case GatewayError = 'gateway_error';
    case InternalError = 'internal_error';

    // Additions to the initial catalog (Phase 0) for generic HTTP conditions.
    case MethodNotAllowed = 'method_not_allowed';
    case ServiceUnavailable = 'service_unavailable';

    public function httpStatus(): int
    {
        return match ($this) {
            self::InvalidApiKey => 401,
            self::InsufficientScope, self::TenantSuspended => 403,
            self::ResourceNotFound => 404,
            self::MethodNotAllowed => 405,
            self::GatewayNotReady,
            self::IdempotencyRequestInProgress,
            self::LinkNotCancelable,
            self::LinkPaymentInProgress,
            self::PaymentNotRefundable => 409,
            self::IdempotencyKeyReused, self::RefundExceedsAvailable => 422,
            self::RateLimited => 429,
            self::InternalError => 500,
            self::GatewayError => 502,
            self::ServiceUnavailable => 503,
            default => 400,
        };
    }

    public function type(): ApiErrorType
    {
        $status = $this->httpStatus();

        return match (true) {
            $status === 401 => ApiErrorType::Authentication,
            $status === 403 => ApiErrorType::Permission,
            $status === 429 => ApiErrorType::RateLimit,
            $status >= 500 => ApiErrorType::Api,
            default => ApiErrorType::InvalidRequest,
        };
    }

    public function defaultMessage(): string
    {
        return match ($this) {
            self::InvalidApiKey => 'Invalid API key provided.',
            self::InsufficientScope => 'The API key does not have the permission required for this request.',
            self::TenantSuspended => 'The account cannot create resources in its current state.',
            self::GatewayNotReady => 'The payment gateway account is not connected or cannot accept charges.',
            self::ParameterMissing => 'A required parameter is missing.',
            self::ParameterInvalid => 'A parameter has an invalid value.',
            self::AmountMustBeString => 'The amount must be sent as a decimal string.',
            self::AmountInvalid => 'The amount format is invalid for the currency.',
            self::AmountBelowMinimum => 'The amount is below the minimum allowed.',
            self::AmountAboveMaximum => 'The amount is above the maximum allowed.',
            self::AmountBelowMinimumAfterConversion => 'The converted amount is below the minimum allowed.',
            self::CurrencyNotSupported => 'The currency is not supported.',
            self::ExpirationOutOfRange => 'The expiration is out of the allowed range.',
            self::FxNotAvailable => 'Currency conversion is not available for this request.',
            self::FxRateInvalid => 'The exchange rate is outside the accepted range.',
            self::MetadataInvalid => 'The metadata exceeds the limits or contains unsupported types.',
            self::ReturnUrlNotAllowed => 'The return URL domain is not allowed.',
            self::PayerFieldInvalid => 'The payer fields configuration is invalid.',
            self::ValidationEndpointNotConfigured => 'Pre-payment validation was requested but no validation URL is configured for this mode.',
            self::IdempotencyKeyRequired => 'An Idempotency-Key header is required for this request.',
            self::IdempotencyKeyReused => 'The Idempotency-Key was already used with a different request body.',
            self::IdempotencyRequestInProgress => 'A request with the same Idempotency-Key is still in progress.',
            self::ResourceNotFound => 'The requested resource does not exist.',
            self::LinkNotCancelable => 'The payment link can no longer be canceled.',
            self::LinkPaymentInProgress => 'A payment is in progress for this payment link.',
            self::RefundExceedsAvailable => 'The refund amount exceeds the refundable balance.',
            self::PaymentNotRefundable => 'The payment cannot be refunded in its current state.',
            self::RateLimited => 'Too many requests. Retry later.',
            self::GatewayError => 'The payment gateway returned an error.',
            self::InternalError => 'An unexpected error occurred.',
            self::MethodNotAllowed => 'The HTTP method is not allowed for this endpoint.',
            self::ServiceUnavailable => 'The service is temporarily unavailable.',
        };
    }
}
