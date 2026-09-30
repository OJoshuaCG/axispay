<?php

declare(strict_types=1);

use App\Modules\Webhooks\Enums\UnsafeDestinationReason;
use App\Modules\Webhooks\Exceptions\UnsafeDestinationException;
use App\Modules\Webhooks\Services\DestinationGuard;
use Tests\Support\FakeHostResolver;

/**
 * Plan 15.7: the SSRF protection of merchant URLs, at registration and at
 * every delivery. DNS is faked (FakeHostResolver).
 */
function guardReason(string $url, bool $livemode = false): ?UnsafeDestinationReason
{
    try {
        app(DestinationGuard::class)->inspect($url, $livemode);
    } catch (UnsafeDestinationException $e) {
        return $e->reason;
    }

    return null;
}

beforeEach(function (): void {
    config(['axispay.webhooks.allow_http_in_test' => false]);
});

it('accepts a public https URL and pins the resolved addresses', function (): void {
    FakeHostResolver::install(['hooks.example.com' => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']]);

    $destination = app(DestinationGuard::class)->inspect('https://hooks.example.com/axispay?x=1', true);

    expect($destination->host)->toBe('hooks.example.com')
        ->and($destination->port)->toBe(443)
        ->and($destination->addresses)->toBe(['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'])
        ->and($destination->curlResolve())->toBe(['hooks.example.com:443:93.184.216.34,[2606:2800:220:1:248:1893:25c8:1946]']);
});

it('rebuilds the URL from the normalized host, so the sent host is the pinned one', function (): void {
    $resolver = FakeHostResolver::install();

    $destination = app(DestinationGuard::class)->inspect('HTTPS://Hooks.EXAMPLE.com:8443/Path/To?Token=AbC#frag', true);

    expect($destination->url)->toBe('https://hooks.example.com:8443/Path/To?Token=AbC')
        ->and($destination->host)->toBe('hooks.example.com')
        ->and($destination->curlResolve())->toBe(['hooks.example.com:8443:'.FakeHostResolver::PUBLIC_IP])
        ->and($resolver->lookups)->toBe(['hooks.example.com']);
});

it('sends an internationalized host in its ASCII form, the one pinned', function (): void {
    $resolver = FakeHostResolver::install();

    $destination = app(DestinationGuard::class)->inspect('https://Bücher.example.com/x', true);

    expect($destination->url)->toBe('https://xn--bcher-kva.example.com/x')
        ->and($destination->host)->toBe('xn--bcher-kva.example.com')
        ->and($destination->curlResolve())->toBe(['xn--bcher-kva.example.com:443:'.FakeHostResolver::PUBLIC_IP])
        ->and($resolver->lookups)->toBe(['xn--bcher-kva.example.com']);
})->skip(! function_exists('idn_to_ascii'), 'needs ext-intl');

it('adds the root path to a URL without one', function (): void {
    FakeHostResolver::install();

    expect(app(DestinationGuard::class)->inspect('https://hooks.example.com', true)->url)->toBe('https://hooks.example.com/');
});

it('accepts port 8443', function (): void {
    FakeHostResolver::install();

    expect(app(DestinationGuard::class)->inspect('https://hooks.example.com:8443/x', true)->port)->toBe(8443);
});

it('refuses URLs that break the syntax rules', function (string $url, UnsafeDestinationReason $reason): void {
    FakeHostResolver::install();

    expect(guardReason($url, livemode: true))->toBe($reason);
})->with([
    'http in live' => ['http://hooks.example.com/x', UnsafeDestinationReason::SchemeNotAllowed],
    'ftp' => ['ftp://hooks.example.com/x', UnsafeDestinationReason::SchemeNotAllowed],
    'no scheme' => ['hooks.example.com/x', UnsafeDestinationReason::InvalidUrl],
    'credentials' => ['https://user:pass@hooks.example.com/x', UnsafeDestinationReason::CredentialsInUrl],
    'user only' => ['https://user@hooks.example.com/x', UnsafeDestinationReason::CredentialsInUrl],
    'port 80 on https' => ['https://hooks.example.com:80/x', UnsafeDestinationReason::PortNotAllowed],
    'port 22' => ['https://hooks.example.com:22/x', UnsafeDestinationReason::PortNotAllowed],
    'IPv4 literal' => ['https://93.184.216.34/x', UnsafeDestinationReason::IpLiteralHost],
    'IPv6 literal' => ['https://[2606:2800:220:1::1]/x', UnsafeDestinationReason::IpLiteralHost],
    'decimal IPv4' => ['https://2130706433/x', UnsafeDestinationReason::IpLiteralHost],
    'hex IPv4' => ['https://0x7f000001/x', UnsafeDestinationReason::IpLiteralHost],
    'short IPv4' => ['https://127.1/x', UnsafeDestinationReason::IpLiteralHost],
    'localhost' => ['https://localhost/x', UnsafeDestinationReason::ForbiddenHost],
    'single label' => ['https://intranet/x', UnsafeDestinationReason::InvalidUrl],
    'sub.localhost' => ['https://api.localhost/x', UnsafeDestinationReason::ForbiddenHost],
    '.local' => ['https://printer.local/x', UnsafeDestinationReason::ForbiddenHost],
    '.internal' => ['https://metadata.google.internal/x', UnsafeDestinationReason::ForbiddenHost],
    'trailing dot' => ['https://printer.local./x', UnsafeDestinationReason::InvalidUrl],
    'trailing dot, public host' => ['https://hooks.example.com./x', UnsafeDestinationReason::InvalidUrl],
    'space' => ['https://hooks.example.com/a b', UnsafeDestinationReason::InvalidUrl],
    'backslash' => ['https://hooks.example.com\\@evil.example/x', UnsafeDestinationReason::InvalidUrl],
]);

it('refuses URLs longer than 2048 characters', function (): void {
    FakeHostResolver::install();

    expect(guardReason('https://hooks.example.com/'.str_repeat('a', 2048), true))->toBe(UnsafeDestinationReason::TooLong);
});

it('refuses the platform\'s own hosts and the configured suffixes', function (): void {
    FakeHostResolver::install();
    config([
        'axispay.surfaces.api' => 'api.axispay.example',
        'axispay.webhooks.blocked_host_suffixes' => ['corp.example'],
    ]);

    expect(guardReason('https://api.axispay.example/x', true))->toBe(UnsafeDestinationReason::ForbiddenHost)
        ->and(guardReason('https://deep.api.axispay.example/x', true))->toBe(UnsafeDestinationReason::ForbiddenHost)
        ->and(guardReason('https://intranet.corp.example/x', true))->toBe(UnsafeDestinationReason::ForbiddenHost)
        ->and(guardReason('https://hooks.example.com/x', true))->toBeNull();
});

it('refuses a host that resolves to a blocked address', function (string $ip): void {
    FakeHostResolver::install(['hooks.example.com' => [$ip]]);

    expect(guardReason('https://hooks.example.com/x', true))->toBe(UnsafeDestinationReason::ForbiddenAddress);
})->with([
    'this network' => ['0.1.2.3'],
    'private 10' => ['10.0.0.5'],
    'CGNAT' => ['100.64.0.1'],
    'loopback' => ['127.0.0.1'],
    'cloud metadata' => ['169.254.169.254'],
    'private 172' => ['172.16.0.1'],
    'private 172 upper' => ['172.31.255.255'],
    'IETF' => ['192.0.0.8'],
    'private 192' => ['192.168.1.1'],
    'benchmark' => ['198.18.0.1'],
    'multicast' => ['224.0.0.1'],
    'reserved' => ['240.0.0.1'],
    'broadcast' => ['255.255.255.255'],
    'IPv6 loopback' => ['::1'],
    'IPv6 unspecified' => ['::'],
    'IPv6 unique local' => ['fd00::1'],
    'IPv6 metadata' => ['fd00:ec2::254'],
    'IPv6 link-local' => ['fe80::1'],
    'IPv4-mapped loopback' => ['::ffff:127.0.0.1'],
    'IPv4-mapped public' => ['::ffff:93.184.216.34'],
    'NAT64' => ['64:ff9b::a00:1'],
    'IPv6 multicast' => ['ff02::1'],
]);

it('refuses a host when ANY of its addresses is blocked', function (): void {
    FakeHostResolver::install(['hooks.example.com' => ['93.184.216.34', '10.0.0.1']]);

    expect(guardReason('https://hooks.example.com/x', true))->toBe(UnsafeDestinationReason::ForbiddenAddress);
});

it('refuses a host that does not resolve', function (): void {
    FakeHostResolver::install(['nowhere.example.com' => []]);

    expect(guardReason('https://nowhere.example.com/x', true))->toBe(UnsafeDestinationReason::UnresolvableHost);
});

it('keeps public addresses next to the blocked ranges allowed', function (string $ip): void {
    FakeHostResolver::install(['hooks.example.com' => [$ip]]);

    expect(guardReason('https://hooks.example.com/x', true))->toBeNull();
})->with([
    '172.32.0.1' => ['172.32.0.1'],
    '100.128.0.1' => ['100.128.0.1'],
    '8.8.8.8' => ['8.8.8.8'],
    'public IPv6' => ['2606:4700:4700::1111'],
]);

it('allows http only in test mode and only when the platform enables it', function (): void {
    FakeHostResolver::install();

    expect(guardReason('http://hooks.example.com/x', livemode: false))->toBe(UnsafeDestinationReason::SchemeNotAllowed);

    config(['axispay.webhooks.allow_http_in_test' => true]);

    expect(guardReason('http://hooks.example.com/x', livemode: false))->toBeNull()
        ->and(guardReason('http://hooks.example.com/x', livemode: true))->toBe(UnsafeDestinationReason::SchemeNotAllowed)
        // Still no private destinations over http.
        ->and(guardReason('http://printer.local/x', livemode: false))->toBe(UnsafeDestinationReason::ForbiddenHost);
});
