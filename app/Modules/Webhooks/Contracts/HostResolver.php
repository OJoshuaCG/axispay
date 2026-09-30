<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Contracts;

/**
 * Resolves a hostname to every IPv4 and IPv6 address it has (A and AAAA).
 * Injectable so the SSRF protection (plan 15.7) is testable without DNS.
 */
interface HostResolver
{
    /**
     * @param  float|null  $deadline  DestinationGuard::clock() time after which no further lookup starts
     * @return list<string> IP addresses in text form; empty when the name does not resolve
     */
    public function resolve(string $host, ?float $deadline = null): array;
}
