<?php

declare(strict_types=1);

use App\Modules\Webhooks\Data\ValidationHttpResult;
use App\Modules\Webhooks\Enums\ValidationResponseProblem as Problem;
use App\Modules\Webhooks\Services\ValidationResponseParser;

/**
 * Plan 15.8.4: how a merchant's answer to the pre-payment validation is read.
 */
function validationAnswer(string $body, int $status = 200, ?string $contentType = 'application/json', bool $tooLarge = false): ValidationHttpResult
{
    return new ValidationHttpResult(null, $status, $body, $contentType, $tooLarge, 12, false);
}

it('reads approve and reject with their optional fields', function (): void {
    $parser = new ValidationResponseParser;

    $approve = $parser->parse(validationAnswer('{"decision":"approve","unknown":1}'));
    $reject = $parser->parse(validationAnswer('{"decision":"reject","reason_code":"out_of_stock","payer_message":"  Sin stock.  ","cancel_link":true}'));

    expect($approve->valid())->toBeTrue()
        ->and($approve->approved())->toBeTrue()
        ->and($approve->problems)->toBe([])
        ->and($reject->approved())->toBeFalse()
        ->and($reject->decision)->toBe('reject')
        ->and($reject->reasonCode)->toBe('out_of_stock')
        ->and($reject->payerMessage)->toBe('Sin stock.')
        ->and($reject->cancelLink)->toBeTrue();
});

it('refuses what is not a valid answer', function (ValidationHttpResult $result, Problem $problem): void {
    $parsed = (new ValidationResponseParser)->parse($result);

    expect($parsed->valid())->toBeFalse()
        ->and($parsed->decision)->toBeNull()
        ->and($parsed->errors())->toContain($problem);
})->with([
    'status 201' => [validationAnswer('{"decision":"approve"}', 201), Problem::StatusNot200],
    'redirect' => [validationAnswer('', 302), Problem::StatusNot200],
    'over 4 KB' => [validationAnswer('{"decision":"approve","x":"'.str_repeat('a', 4100).'"}'), Problem::BodyTooLarge],
    'cut while reading' => [validationAnswer('', tooLarge: true), Problem::BodyTooLarge],
    'not JSON' => [validationAnswer('approve'), Problem::InvalidJson],
    'a JSON list' => [validationAnswer('["approve"]'), Problem::InvalidJson],
    'empty list' => [validationAnswer('[]'), Problem::InvalidJson],
    'no decision' => [validationAnswer('{"ok":true}'), Problem::DecisionMissing],
    'null decision' => [validationAnswer('{"decision":null}'), Problem::DecisionMissing],
    'unknown decision' => [validationAnswer('{"decision":"APPROVE"}'), Problem::DecisionInvalid],
    'boolean decision' => [validationAnswer('{"decision":true}'), Problem::DecisionInvalid],
]);

it('drops or corrects malformed optional fields as warnings, never failing the call', function (): void {
    $parsed = (new ValidationResponseParser)->parse(validationAnswer(
        json_encode(['decision' => 'reject', 'reason_code' => 'Out-Of-Stock', 'payer_message' => "Line\r\none\u{0007} ".str_repeat('é', 250), 'cancel_link' => 'yes'], JSON_THROW_ON_ERROR),
        contentType: 'text/html',
    ));

    expect($parsed->valid())->toBeTrue()
        ->and($parsed->reasonCode)->toBeNull()
        ->and($parsed->cancelLink)->toBeFalse()
        ->and(mb_strlen((string) $parsed->payerMessage))->toBeLessThanOrEqual(200)
        ->and($parsed->payerMessage)->toStartWith('Line one ')
        ->and($parsed->warnings())->toBe([Problem::ContentTypeNotJson, Problem::ReasonCodeInvalid, Problem::PayerMessageTooLong, Problem::CancelLinkInvalid]);
});

it('ignores a message and cancel_link on approve', function (): void {
    $parsed = (new ValidationResponseParser)->parse(validationAnswer('{"decision":"approve","payer_message":"x","cancel_link":true}'));

    expect($parsed->payerMessage)->toBeNull()
        ->and($parsed->cancelLink)->toBeFalse();
});

it('accepts a JSON content type with parameters or a +json suffix', function (string $contentType): void {
    expect((new ValidationResponseParser)->parse(validationAnswer('{"decision":"approve"}', contentType: $contentType))->warnings())->toBe([]);
})->with(['application/json; charset=utf-8', 'application/vnd.merchant+json']);
