<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

use App\Modules\Webhooks\Contracts\HostResolver;

/**
 * System DNS resolution of A and AAAA records (CNAMEs are followed by the
 * resolver). A lookup error resolves to nothing, which the guard refuses.
 *
 * `dns_get_record` has no timeout of its own: each lookup is bounded by the
 * system resolver (resolv.conf `timeout`/`attempts`). With a deadline, the
 * AAAA lookup is skipped once the A lookup has used the budget; the caller
 * then reports a timeout (ADR-0057, ADR-0058).
 */
final class DnsHostResolver implements HostResolver
{
    public function resolve(string $host, ?float $deadline = null): array
    {
        $addresses = [];

        foreach ([[DNS_A, 'ip'], [DNS_AAAA, 'ipv6']] as [$type, $field]) {
            if ($deadline !== null && DestinationGuard::clock() >= $deadline) {
                break;
            }

            $records = @dns_get_record($host, $type);

            foreach (is_array($records) ? $records : [] as $record) {
                $address = $record[$field] ?? null;

                if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false) {
                    $addresses[] = $address;
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
