<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

use App\Modules\Webhooks\Contracts\HostResolver;
use App\Modules\Webhooks\Data\SafeDestination;
use App\Modules\Webhooks\Enums\UnsafeDestinationReason;
use App\Modules\Webhooks\Exceptions\UnsafeDestinationException;

/**
 * SSRF protection of every merchant URL (plan 15.7), applied when the URL is
 * registered AND before every delivery or call (webhooks and, later, the
 * pre-payment validation):
 *
 *  1. `https`; `http` only in test mode when the platform allows it;
 *  2. no credentials, allowed port only, at most 2048 characters, and a
 *     hostname (never an IP literal, in any notation);
 *  3. no localhost, *.local, *.internal or the platform's own hosts (and
 *     the extra suffixes of `axispay.webhooks.blocked_host_suffixes`); every
 *     A/AAAA address must be outside the blocked ranges (IpRanges);
 *  4. the caller connects to the returned addresses only (SafeDestination).
 *
 * The returned URL is rebuilt from the normalized host (ASCII, lowercase), so
 * the host the client sends is exactly the host pinned to the validated
 * addresses: a spelling the pin would not match (a trailing dot, another
 * case, a Unicode name) never reaches the network. A trailing dot is refused
 * outright. Callers store and send that URL, never the raw input.
 *
 * DNS time counts against the caller's budget: with a deadline, no further
 * lookup starts once it has passed (a single lookup is bounded only by the
 * system resolver's own timeout, which PHP cannot shorten).
 */
final readonly class DestinationGuard
{
    private const array FORBIDDEN_SUFFIXES = ['localhost', 'local', 'internal', 'localdomain', 'home.arpa', 'arpa'];

    public function __construct(private HostResolver $resolver) {}

    /**
     * @throws UnsafeDestinationException
     */
    public function inspect(string $url, bool $livemode, ?float $deadline = null): SafeDestination
    {
        $url = trim($url);

        if (strlen($url) > config()->integer('axispay.webhooks.url_max')) {
            throw new UnsafeDestinationException(UnsafeDestinationReason::TooLong);
        }

        // Control characters and spaces never belong to a URL and confuse parsers.
        if ($url === '' || preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            throw new UnsafeDestinationException(UnsafeDestinationReason::InvalidUrl);
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeDestinationException(UnsafeDestinationReason::InvalidUrl);
        }

        $scheme = strtolower($parts['scheme']);
        $this->checkScheme($scheme, $livemode);

        if (isset($parts['user']) || isset($parts['pass']) || str_contains($this->authority($url), '@')) {
            throw new UnsafeDestinationException(UnsafeDestinationReason::CredentialsInUrl);
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $this->checkPort($scheme, $port);

        // `example.com.` names the same host but escapes a pin on `example.com`.
        if (str_ends_with($parts['host'], '.')) {
            throw new UnsafeDestinationException(UnsafeDestinationReason::InvalidUrl);
        }

        $host = $this->normalizeHost($parts['host']);
        $this->checkHostname($host);

        $addresses = $this->resolver->resolve($host, $deadline);

        if ($addresses === []) {
            throw new UnsafeDestinationException(UnsafeDestinationReason::UnresolvableHost);
        }

        foreach ($addresses as $address) {
            if (IpRanges::isBlocked($address)) {
                throw new UnsafeDestinationException(UnsafeDestinationReason::ForbiddenAddress);
            }
        }

        $canonical = $this->rebuild($scheme, $host, $parts);

        // The ASCII form of an international domain can be longer than the input.
        if (strlen($canonical) > config()->integer('axispay.webhooks.url_max')) {
            throw new UnsafeDestinationException(UnsafeDestinationReason::TooLong);
        }

        return new SafeDestination($canonical, $host, $port, array_values($addresses));
    }

    /**
     * The URL sent and stored: the normalized scheme and host, the explicit
     * port if any, the path and query as given; the fragment is never sent.
     *
     * @param  array<string, int|string>  $parts
     */
    private function rebuild(string $scheme, string $host, array $parts): string
    {
        $url = $scheme.'://'.$host;

        if (isset($parts['port'])) {
            $url .= ':'.$parts['port'];
        }

        $url .= isset($parts['path']) && $parts['path'] !== '' ? (string) $parts['path'] : '/';

        if (isset($parts['query'])) {
            $url .= '?'.$parts['query'];
        }

        return $url;
    }

    /** Monotonic seconds, the clock of the `$deadline` passed to inspect(). */
    public static function clock(): float
    {
        return hrtime(true) / 1e9;
    }

    /** The `user:pass@host:port` part of the URL. */
    private function authority(string $url): string
    {
        $rest = (string) preg_replace('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', '', $url);

        $end = strcspn($rest, '/?#');

        return substr($rest, 0, $end);
    }

    private function checkScheme(string $scheme, bool $livemode): void
    {
        if ($scheme === 'https') {
            return;
        }

        if ($scheme === 'http' && ! $livemode && config()->boolean('axispay.webhooks.allow_http_in_test')) {
            return;
        }

        throw new UnsafeDestinationException(UnsafeDestinationReason::SchemeNotAllowed);
    }

    private function checkPort(string $scheme, int $port): void
    {
        $allowed = config()->array($scheme === 'https' ? 'axispay.webhooks.allowed_ports' : 'axispay.webhooks.allowed_http_ports');

        if (! in_array($port, $allowed, true)) {
            throw new UnsafeDestinationException(UnsafeDestinationReason::PortNotAllowed);
        }
    }

    private function normalizeHost(string $host): string
    {
        // An IPv6 literal (`[::1]`).
        if (str_starts_with($host, '[')) {
            throw new UnsafeDestinationException(UnsafeDestinationReason::IpLiteralHost);
        }

        $host = strtolower($host);

        if (preg_match('/[^\x21-\x7E]/', $host) === 1) {
            $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46) : false;

            if (! is_string($ascii) || $ascii === '') {
                throw new UnsafeDestinationException(UnsafeDestinationReason::InvalidUrl);
            }

            $host = strtolower($ascii);
        }

        return $host;
    }

    private function checkHostname(string $host): void
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            throw new UnsafeDestinationException(UnsafeDestinationReason::IpLiteralHost);
        }

        $labels = explode('.', $host);
        $last = end($labels);

        // Numeric, octal or hexadecimal forms (`2130706433`, `0x7f.1`,
        // `127.1`) that resolvers and clients read as addresses.
        if ($last === '' || preg_match('/^(?:0x[0-9a-f]*|[0-9]+)$/', $last) === 1) {
            throw new UnsafeDestinationException(UnsafeDestinationReason::IpLiteralHost);
        }

        foreach ($this->forbiddenSuffixes() as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                throw new UnsafeDestinationException(UnsafeDestinationReason::ForbiddenHost);
            }
        }

        if (strlen($host) > 253 || count($labels) < 2 || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/', $host) !== 1) {
            throw new UnsafeDestinationException(UnsafeDestinationReason::InvalidUrl);
        }

    }

    /**
     * @return list<string>
     */
    private function forbiddenSuffixes(): array
    {
        $suffixes = self::FORBIDDEN_SUFFIXES;

        foreach ([...config()->array('axispay.surfaces'), ...config()->array('axispay.webhooks.blocked_host_suffixes')] as $host) {
            if (is_string($host) && $host !== '') {
                $suffixes[] = strtolower(rtrim((string) preg_replace('/:\d+$/', '', $host), '.'));
            }
        }

        return array_values(array_unique($suffixes));
    }
}
