<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Data;

/**
 * A merchant URL that passed the SSRF protection (plan 15.7), with the
 * addresses it resolved to. The request connects to these addresses only
 * (anti DNS rebinding, point 4): the resolution is pinned in the client.
 */
final readonly class SafeDestination
{
    /**
     * @param  list<string>  $addresses
     */
    public function __construct(
        public string $url,
        public string $host,
        public int $port,
        public array $addresses,
    ) {}

    /**
     * The CURLOPT_RESOLVE entry pinning the host to the validated addresses.
     *
     * @return list<string>
     */
    public function curlResolve(): array
    {
        $addresses = array_map(
            static fn (string $ip): string => str_contains($ip, ':') ? '['.$ip.']' : $ip,
            $this->addresses,
        );

        return [$this->host.':'.$this->port.':'.implode(',', $addresses)];
    }
}
