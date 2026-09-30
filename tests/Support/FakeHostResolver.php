<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Webhooks\Contracts\HostResolver;

/**
 * DNS for the webhook tests: every host resolves to one public address unless a test maps it to others (an empty list: NXDOMAIN).
 */
final class FakeHostResolver implements HostResolver
{
    public const string PUBLIC_IP = '93.184.216.34';

    /** @var list<string> */
    public array $lookups = [];

    /**
     * @param  array<string, list<string>>  $hosts
     */
    public function __construct(public array $hosts = []) {}

    /**
     * @param  array<string, list<string>>  $hosts
     */
    public static function install(array $hosts = []): self
    {
        $resolver = new self($hosts);
        app()->instance(HostResolver::class, $resolver);

        return $resolver;
    }

    /** Seconds each lookup pretends to take (to test the DNS budget). */
    public float $lookupSeconds = 0.0;

    public function resolve(string $host, ?float $deadline = null): array
    {
        $this->lookups[] = $host;

        if ($this->lookupSeconds > 0) {
            usleep((int) ($this->lookupSeconds * 1_000_000));
        }

        return $this->hosts[$host] ?? [self::PUBLIC_IP];
    }
}
