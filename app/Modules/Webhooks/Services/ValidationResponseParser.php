<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

use App\Modules\Webhooks\Data\ParsedValidationResponse;
use App\Modules\Webhooks\Data\ValidationHttpResult;
use App\Modules\Webhooks\Enums\ValidationResponseProblem as Problem;

/**
 * Reads a merchant's answer to a pre-payment validation (plan 15.8.4):
 *
 *  - HTTP 200 only (3xx included in the failures: redirects are not
 *    followed), at most 4 KB, a JSON object;
 *  - `decision` is `approve` or `reject`; anything else is invalid;
 *  - `reason_code` (`[a-z0-9_]`, 64 at most), `payer_message` (plain text,
 *    200 at most) and `cancel_link` (boolean) are optional: a malformed one
 *    is dropped or corrected and reported as a warning, never a failure
 *    (ADR-0058). The message is kept on one line, without control
 *    characters, and escaped wherever it is shown;
 *  - unknown fields are ignored (future compatibility).
 *
 * The same rules serve the real call and "Test validation".
 */
final class ValidationResponseParser
{
    public function parse(ValidationHttpResult $result): ParsedValidationResponse
    {
        $problems = [];

        if ($result->status !== 200) {
            return self::invalid([Problem::StatusNot200]);
        }

        if ($result->tooLarge || strlen($result->body) > self::maxBytes()) {
            return self::invalid([Problem::BodyTooLarge]);
        }

        if (! self::isJsonContentType($result->contentType)) {
            $problems[] = Problem::ContentTypeNotJson;
        }

        $decoded = json_decode($result->body, true);

        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded)) || trim($result->body)[0] !== '{') {
            return self::invalid([...$problems, Problem::InvalidJson]);
        }

        if (! array_key_exists('decision', $decoded) || $decoded['decision'] === null) {
            return self::invalid([...$problems, Problem::DecisionMissing]);
        }

        $decision = $decoded['decision'];

        if ($decision !== 'approve' && $decision !== 'reject') {
            return self::invalid([...$problems, Problem::DecisionInvalid]);
        }

        $reasonCode = self::reasonCode($decoded['reason_code'] ?? null, $problems);

        if ($decision === 'approve') {
            return new ParsedValidationResponse('approve', $reasonCode, null, false, $problems);
        }

        $payerMessage = self::payerMessage($decoded['payer_message'] ?? null, $problems);
        $cancelLink = self::cancelLink($decoded['cancel_link'] ?? null, $problems);

        return new ParsedValidationResponse('reject', $reasonCode, $payerMessage, $cancelLink, $problems);
    }

    /**
     * @param  list<Problem>  $problems
     */
    private static function invalid(array $problems): ParsedValidationResponse
    {
        return new ParsedValidationResponse(null, null, null, false, $problems);
    }

    /**
     * @param  list<Problem>  $problems
     */
    private static function reasonCode(mixed $value, array &$problems): ?string
    {
        if ($value === null) {
            return null;
        }

        $max = max(1, config()->integer('axispay.pre_payment_validation.reason_code_max'));

        if (! is_string($value) || preg_match('/^[a-z0-9_]{1,'.$max.'}$/', $value) !== 1) {
            $problems[] = Problem::ReasonCodeInvalid;

            return null;
        }

        return $value;
    }

    /**
     * @param  list<Problem>  $problems
     */
    private static function payerMessage(mixed $value, array &$problems): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            $problems[] = Problem::PayerMessageInvalid;

            return null;
        }

        // Plain text on one line: line breaks become spaces, other control
        // characters go away.
        $text = (string) preg_replace('/[\r\n\t]+/u', ' ', mb_scrub($value, 'UTF-8'));
        $text = trim((string) preg_replace('/\p{C}/u', '', $text));

        if ($text === '') {
            return null;
        }

        $max = max(1, config()->integer('axispay.pre_payment_validation.payer_message_max'));

        if (mb_strlen($text) > $max) {
            $problems[] = Problem::PayerMessageTooLong;
            $text = rtrim(mb_substr($text, 0, $max));
        }

        return $text;
    }

    /**
     * @param  list<Problem>  $problems
     */
    private static function cancelLink(mixed $value, array &$problems): bool
    {
        if ($value === null) {
            return false;
        }

        if (! is_bool($value)) {
            $problems[] = Problem::CancelLinkInvalid;

            return false;
        }

        return $value;
    }

    private static function isJsonContentType(?string $contentType): bool
    {
        if ($contentType === null) {
            return false;
        }

        $type = strtolower(trim(explode(';', $contentType)[0]));

        return $type === 'application/json' || (str_starts_with($type, 'application/') && str_ends_with($type, '+json'));
    }

    public static function maxBytes(): int
    {
        return max(1, config()->integer('axispay.pre_payment_validation.max_response_bytes'));
    }
}
