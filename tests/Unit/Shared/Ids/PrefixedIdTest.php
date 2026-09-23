<?php

declare(strict_types=1);

use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Ids\Exceptions\InvalidPrefixedIdException;
use App\Modules\Shared\Ids\PrefixedId;
use App\Modules\Shared\Ids\ResourceType;
use App\Modules\Shared\Ids\Ulid;

/**
 * ADR-020: ULIDs in the database, typed prefixes at the API boundary.
 */
const SAMPLE_ULID = '01J8Z3Q6T4Y0V8KX2M1N5P7R9S';

it('generates canonical uppercase ULIDs', function (): void {
    $ulid = Ulid::generate();

    expect($ulid)->toHaveLength(26)
        ->and($ulid)->toBe(strtoupper($ulid))
        ->and(Ulid::isValid($ulid))->toBeTrue();
});

it('maps every resource type to its documented prefix', function (ResourceType $type, string $prefix): void {
    expect($type->prefix())->toBe($prefix);
})->with([
    [ResourceType::PaymentLink, 'plink_'],
    [ResourceType::Payment, 'pay_'],
    [ResourceType::Refund, 're_'],
    [ResourceType::Event, 'evt_'],
    [ResourceType::ValidationCall, 'val_'],
]);

it('round-trips encode and decode for every resource type', function (ResourceType $type): void {
    $ulid = Ulid::generate();
    $public = PrefixedId::encode($type, $ulid);

    expect($public)->toBe($type->prefix().$ulid)
        ->and(PrefixedId::decode($public, $type))->toBe($ulid)
        ->and((string) PrefixedId::parse($public, $type))->toBe($public);
})->with(ResourceType::cases());

it('rejects malformed or foreign IDs', function (mixed $value): void {
    expect(PrefixedId::tryParse($value, ResourceType::PaymentLink))->toBeNull();
})->with([
    'another type' => ['pay_'.SAMPLE_ULID],
    'prefix that only shares a start' => ['plinks_'.SAMPLE_ULID],
    'uppercase prefix' => ['PLINK_'.SAMPLE_ULID],
    'missing underscore' => ['plink'.SAMPLE_ULID],
    'bare ULID' => [SAMPLE_ULID],
    'lowercase ULID' => ['plink_'.strtolower(SAMPLE_ULID)],
    'too short' => ['plink_'.substr(SAMPLE_ULID, 0, 25)],
    'too long' => ['plink_'.SAMPLE_ULID.'0'],
    'excluded letter I' => ['plink_01J8Z3Q6T4Y0V8KX2M1N5P7R9I'],
    'excluded letter U' => ['plink_01J8Z3Q6T4Y0V8KX2M1N5P7R9U'],
    'first char out of range' => ['plink_81J8Z3Q6T4Y0V8KX2M1N5P7R9S'],
    'trailing newline' => ['plink_'.SAMPLE_ULID."\n"],
    'leading space' => [' plink_'.SAMPLE_ULID],
    'empty' => [''],
    'integer' => [42],
    'null' => [null],
]);

it('treats an invalid ID as a missing resource (404)', function (): void {
    try {
        PrefixedId::decode('pay_'.SAMPLE_ULID, ResourceType::PaymentLink);
    } catch (InvalidPrefixedIdException $e) {
        expect($e->errorCode)->toBe(ApiErrorCode::ResourceNotFound)
            ->and($e->status())->toBe(404);

        return;
    }

    throw new RuntimeException('Expected the ID to be rejected.');
});

it('refuses to encode a non-canonical ULID', function (): void {
    PrefixedId::encode(ResourceType::Payment, strtolower(SAMPLE_ULID));
})->throws(InvalidArgumentException::class);
