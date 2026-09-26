<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use LogicException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;

/**
 * Stands in for Stripe's HTTP layer (stripe-php ApiRequestor::setHttpClient):
 * the real SDK code runs, only the wire is fake. Routes are matched by
 * method, path and, optionally, the API key used, so a test can make the
 * restricted key and the publishable key see different accounts. Every
 * request is recorded (headers parsed) for assertions on Stripe-Account,
 * Idempotency-Key, Stripe-Version and Authorization.
 *
 * Unmatched requests fail the test loudly.
 */
final class FakeStripeHttpClient implements ClientInterface
{
    /** @var list<array{method: string, path: string, key: string|null, respond: Closure(array{method: string, path: string, headers: array<string, string>, params: array<mixed>}): array{0: int, 1: array<mixed>}}> */
    private array $routes = [];

    /** @var list<array{method: string, path: string, query: string, headers: array<string, string>, params: array<mixed>}> */
    public array $requests = [];

    private static ?self $current = null;

    public static function install(): self
    {
        self::$current = new self;
        ApiRequestor::setHttpClient(self::$current);

        return self::$current;
    }

    public static function current(): self
    {
        return self::$current ?? throw new LogicException('FakeStripeHttpClient is not installed.');
    }

    public static function uninstall(): void
    {
        self::$current = null;
        ApiRequestor::setHttpClient(new CurlClient);
    }

    /**
     * @param  array<mixed>|Closure(array{method: string, path: string, headers: array<string, string>, params: array<mixed>}): array{0: int, 1: array<mixed>}  $response  body (status 200) or a responder
     */
    public function on(string $method, string $path, array|Closure $response, ?string $apiKey = null, int $status = 200): self
    {
        $respond = $response instanceof Closure ? $response : static fn (): array => [$status, $response];

        // Newest first: a later registration overrides an earlier one.
        array_unshift($this->routes, ['method' => strtolower($method), 'path' => $path, 'key' => $apiKey, 'respond' => $respond]);

        return $this;
    }

    public function error(string $method, string $path, int $status, string $type = 'invalid_request_error', ?string $code = null, ?string $apiKey = null): self
    {
        return $this->on($method, $path, ['error' => array_filter([
            'type' => $type,
            'code' => $code,
            'message' => 'Fake Stripe error.',
        ])], $apiKey, $status);
    }

    /**
     * @return list<array{method: string, path: string, query: string, headers: array<string, string>, params: array<mixed>}>
     */
    public function requestsTo(string $method, string $path): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn (array $request): bool => $request['method'] === strtolower($method) && $request['path'] === $path,
        ));
    }

    /**
     * @param  array<mixed>  $headers
     * @param  array<mixed>  $params
     * @return array{0: string, 1: int, 2: array<string, string>}
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $parts = parse_url($absUrl);
        $path = is_array($parts) ? ($parts['path'] ?? '/') : '/';
        $query = is_array($parts) ? ($parts['query'] ?? '') : '';
        $parsedHeaders = [];

        foreach ($headers as $header) {
            if (is_string($header) && str_contains($header, ':')) {
                [$name, $value] = explode(':', $header, 2);
                $parsedHeaders[strtolower(trim($name))] = trim($value);
            }
        }

        $key = str_starts_with($parsedHeaders['authorization'] ?? '', 'Bearer ') ? substr($parsedHeaders['authorization'], 7) : null;
        $request = [
            'method' => strtolower((string) $method),
            'path' => $path,
            'query' => $query,
            'headers' => $parsedHeaders,
            'params' => is_array($params) ? $params : [],
        ];
        $this->requests[] = $request;

        foreach ($this->routes as $route) {
            if ($route['method'] !== $request['method'] || ! self::matches($route['path'], $path)) {
                continue;
            }

            if ($route['key'] !== null && ! str_starts_with((string) $key, $route['key'])) {
                continue;
            }

            [$status, $body] = ($route['respond'])($request);

            return [json_encode($body, JSON_THROW_ON_ERROR), $status, ['request-id' => 'req_fake']];
        }

        throw new LogicException("Unexpected Stripe request: {$request['method']} {$path}");
    }

    private static function matches(string $pattern, string $path): bool
    {
        if (! str_contains($pattern, '*')) {
            return $pattern === $path;
        }

        return preg_match('#^'.str_replace('\*', '[^/]+', preg_quote($pattern, '#')).'$#', $path) === 1;
    }
}
