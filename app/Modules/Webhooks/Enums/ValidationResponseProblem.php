<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Enums;

/**
 * What is wrong with a merchant's answer to a pre-payment validation (plan
 * 15.8.4), as the panel's "Test validation" lists it. Errors make the answer
 * invalid (a failure under the endpoint's policy); warnings are fields that
 * were dropped or corrected and do not fail the call (ADR-0058).
 */
enum ValidationResponseProblem: string
{
    case StatusNot200 = 'status_not_200';
    case BodyTooLarge = 'body_too_large';
    case InvalidJson = 'invalid_json';
    case DecisionMissing = 'decision_missing';
    case DecisionInvalid = 'decision_invalid';
    case ContentTypeNotJson = 'content_type_not_json';
    case ReasonCodeInvalid = 'reason_code_invalid';
    case PayerMessageTooLong = 'payer_message_too_long';
    case PayerMessageInvalid = 'payer_message_invalid';
    case CancelLinkInvalid = 'cancel_link_invalid';

    public function isError(): bool
    {
        return in_array($this, [self::StatusNot200, self::BodyTooLarge, self::InvalidJson, self::DecisionMissing, self::DecisionInvalid], true);
    }

    public function message(): string
    {
        return __('webhooks.validation.problems.'.$this->value);
    }
}
