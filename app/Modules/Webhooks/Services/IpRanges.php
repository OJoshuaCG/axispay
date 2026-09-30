<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

/**
 * Address ranges a merchant URL may never reach (plan 15.7 point 3):
 * private, loopback, link-local (cloud metadata included), CGNAT, multicast,
 * documentation and reserved ranges, and the IPv6 forms that embed an IPv4
 * address (mapped, NAT64, 6to4, Teredo).
 */
final class IpRanges
{
    /** @var list<string> */
    public const array BLOCKED_V4 = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '255.255.255.255/32',
    ];

    /** @var list<string> */
    public const array BLOCKED_V6 = [
        '::/128',
        '::1/128',
        // IPv4-compatible (deprecated) and IPv4-mapped addresses.
        '::/96',
        '::ffff:0:0/96',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '100::/64',
        '2001::/32',
        '2001:db8::/32',
        '2002::/16',
        'fc00::/7',
        'fe80::/10',
        'fec0::/10',
        'ff00::/8',
    ];

    private function __construct() {}

    public static function isBlocked(string $ip): bool
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return true;
        }

        $ranges = strlen($packed) === 4 ? self::BLOCKED_V4 : self::BLOCKED_V6;

        foreach ($ranges as $range) {
            if (self::contains($range, $packed)) {
                return true;
            }
        }

        // Belt and braces: PHP's own list of private and reserved ranges.
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private static function contains(string $cidr, string $packed): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $networkPacked = inet_pton($network);

        if ($networkPacked === false || strlen($networkPacked) !== strlen($packed)) {
            return false;
        }

        $bits = (int) $bits;
        $fullBytes = intdiv($bits, 8);

        if (strncmp($networkPacked, $packed, $fullBytes) !== 0) {
            return false;
        }

        $remaining = $bits % 8;

        if ($remaining === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remaining)) & 0xFF;

        return (ord($networkPacked[$fullBytes]) & $mask) === (ord($packed[$fullBytes]) & $mask);
    }
}
