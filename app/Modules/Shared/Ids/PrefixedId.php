<?php

declare(strict_types=1);

namespace App\Modules\Shared\Ids;

use App\Modules\Shared\Ids\Exceptions\InvalidPrefixedIdException;
use InvalidArgumentException;
use Stringable;

/**
 * Typed public identifier such as `plink_01J8Z3Q6T4Y0V8KX2M1N5P7R9S`.
 *
 * The database stores only the ULID; the prefix is added and validated at the
 * HTTP boundary (ADR-020). Parsing is strict: exact lowercase prefix, one
 * underscore, canonical uppercase ULID. A wrong prefix is treated as a missing
 * resource (`404 resource_not_found`, plan section 10.1).
 */
final readonly class PrefixedId implements Stringable
{
    private function __construct(
        public ResourceType $type,
        public string $ulid,
    ) {}

    public static function for(ResourceType $type, string $ulid): self
    {
        if (! Ulid::isValid($ulid)) {
            throw new InvalidArgumentException('Cannot build a prefixed ID from a non-canonical ULID.');
        }

        return new self($type, $ulid);
    }

    public static function encode(ResourceType $type, string $ulid): string
    {
        return self::for($type, $ulid)->toString();
    }

    /**
     * @throws InvalidPrefixedIdException
     */
    public static function parse(mixed $value, ResourceType $expected): self
    {
        return self::tryParse($value, $expected) ?? throw InvalidPrefixedIdException::for($expected);
    }

    /**
     * Returns the bare ULID for database lookups.
     *
     * @throws InvalidPrefixedIdException
     */
    public static function decode(mixed $value, ResourceType $expected): string
    {
        return self::parse($value, $expected)->ulid;
    }

    public static function tryParse(mixed $value, ResourceType $expected): ?self
    {
        if (! is_string($value) || ! str_starts_with($value, $expected->prefix())) {
            return null;
        }

        $ulid = substr($value, strlen($expected->prefix()));

        return Ulid::isValid($ulid) ? new self($expected, $ulid) : null;
    }

    public function toString(): string
    {
        return $this->type->prefix().$this->ulid;
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
