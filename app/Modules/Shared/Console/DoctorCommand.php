<?php

declare(strict_types=1);

namespace App\Modules\Shared\Console;

use App\Modules\Gateways\Enums\ConnectionMethod;
use App\Modules\Gateways\Services\GatewayWebhookUrls;
use App\Modules\PaymentLinks\Services\PaymentLinkUrl;
use App\Modules\ProviderEvents\Services\ProviderEventActivity;
use App\Modules\Shared\Http\Middleware\UseSurfaceSessionCookie;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-only deployment diagnostics (sessions, cookies, proxies, cache,
 * migrations). Safe in production: it writes nothing and prints no secret.
 * The APP_KEY is shown only as a non-reversible fingerprint (the first 12 hex
 * characters of its SHA-256), so the value can be compared across containers.
 * Stripe webhook secrets are only reported as set, empty or malformed.
 * Exits non-zero when a check fails.
 */
final class DoctorCommand extends Command
{
    private const string OK = 'ok';

    private const string WARN = 'warn';

    private const string ERROR = 'error';

    private const string INFO = 'info';

    protected $signature = 'axispay:doctor';

    protected $description = 'Read-only diagnostics of the deployment configuration (sessions, cookies, proxies, cache, migrations, Stripe webhooks)';

    /** @var list<array{string, string, string}> */
    private array $rows = [];

    public function handle(Migrator $migrator, ProviderEventActivity $activity, GatewayWebhookUrls $urls): int
    {
        $this->checkEnvironment();
        $this->checkAppKey();
        $this->checkCookieSecurity();
        $this->checkSurfaces();
        $this->checkSessionStorage();
        $this->checkCache();
        $this->checkProxies();
        $this->checkTurnstile();
        $this->checkQueue();
        $this->checkMigrations($migrator);
        $this->checkStripeWebhooks($activity, $urls);
        $this->checkContainer();

        $this->table(['Check', 'Status', 'Detail'], array_map(
            static fn (array $row): array => [$row[0], strtoupper($row[1]), $row[2]],
            $this->rows,
        ));

        $errors = count(array_filter($this->rows, static fn (array $row): bool => $row[1] === self::ERROR));
        $warnings = count(array_filter($this->rows, static fn (array $row): bool => $row[1] === self::WARN));

        if ($errors > 0) {
            $this->error("{$errors} check(s) failed, {$warnings} warning(s).");

            return self::FAILURE;
        }

        $this->info("No errors, {$warnings} warning(s).");

        return self::SUCCESS;
    }

    private function checkEnvironment(): void
    {
        $env = self::string(config('app.env'));
        $debug = (bool) config('app.debug');

        $this->row('APP_ENV', $env === 'local' ? self::WARN : self::OK, $env);
        $this->row('APP_DEBUG', $debug && $env !== 'local' ? self::ERROR : self::OK, $debug ? 'true' : 'false');
        $this->row('Configuration cache', self::INFO, app()->configurationIsCached() ? 'cached' : 'not cached');
    }

    private function checkAppKey(): void
    {
        $key = self::string(config('app.key'));

        if ($key === '') {
            $this->row('APP_KEY', self::ERROR, 'missing');

            return;
        }

        // Non-reversible: compare it across containers, it never reveals the key.
        $this->row('APP_KEY fingerprint', self::INFO, substr(hash('sha256', $key), 0, 12).' (must be identical in every container)');
    }

    private function checkCookieSecurity(): void
    {
        $url = self::string(config('app.url'));
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $secure = config('session.secure');
        $secureLabel = $secure === null ? 'unset' : ((bool) $secure ? 'true' : 'false');

        [$status, $detail] = match (true) {
            $scheme === 'http' && (bool) $secure => [self::ERROR, 'APP_URL is http:// but SESSION_SECURE_COOKIE=true: browsers drop the session cookie over HTTP (419 on every form)'],
            $scheme === 'https' && ! (bool) $secure => [self::WARN, 'APP_URL is https:// but SESSION_SECURE_COOKIE is not true: set it to true (plan 23.4)'],
            $scheme !== 'http' && $scheme !== 'https' => [self::ERROR, 'APP_URL has no http:// or https:// scheme'],
            default => [self::OK, 'matches the APP_URL scheme'],
        };

        $this->row('APP_URL', self::INFO, $url);
        $this->row('SESSION_SECURE_COOKIE', $status, "{$secureLabel}: {$detail}");

        $domain = config('session.domain');
        $this->row(
            'SESSION_DOMAIN',
            $domain === null || $domain === '' ? self::OK : self::ERROR,
            $domain === null || $domain === '' ? 'empty (host-only cookies)' : 'must be empty (ADR-0034)',
        );
    }

    private function checkSurfaces(): void
    {
        $surfaces = config('axispay.surfaces');
        $surfaces = is_array($surfaces) ? $surfaces : [];
        $hosts = [];

        foreach ($surfaces as $surface => $host) {
            $host = self::string($host);
            $hosts[] = $host;
            $local = str_ends_with($host, '.localhost') && ! app()->environment('local');
            $this->row("Host {$surface}", $local ? self::WARN : self::OK, $host.($local ? ' (default value: set AXISPAY_'.strtoupper((string) $surface).'_HOST)' : ''));
        }

        if (count($hosts) !== count(array_unique($hosts))) {
            $this->row('Surface hosts', self::ERROR, 'two surfaces share the same host');
        }

        $appUrlHost = parse_url(self::string(config('app.url')), PHP_URL_HOST);

        if (is_string($appUrlHost) && ! in_array($appUrlHost, $hosts, true)) {
            $this->row('APP_URL host', self::WARN, "{$appUrlHost} is not one of the surface hosts");
        }

        $cookies = [];

        foreach (['admin', 'app'] as $panel) {
            $host = self::string($surfaces[$panel] ?? '');
            $cookie = UseSurfaceSessionCookie::cookieFor($host);
            $cookies[] = $cookie;
            $this->row("Session cookie on {$panel}", $cookie === null ? self::ERROR : self::OK, $cookie === null ? "no cookie name for {$host}" : "{$host} uses {$cookie}");
        }

        if ($cookies[0] !== null && $cookies[0] === $cookies[1]) {
            $this->row('Session cookies', self::ERROR, 'the admin and app panels share one cookie name');
        }

        $this->checkPayBaseUrl();
    }

    /**
     * Payment links and every checkout request use PaymentLinkUrl::base():
     * `https://` + the pay host, or AXISPAY_PAY_BASE_URL when set. A server
     * without TLS (APP_URL http://) that leaves it empty hands out https://
     * links whose payment requests fail; the opposite serves them over
     * plain HTTP.
     */
    private function checkPayBaseUrl(): void
    {
        $base = PaymentLinkUrl::base();
        $configured = self::string(config('axispay.links.public_base_url'));
        $payScheme = parse_url($base, PHP_URL_SCHEME);
        $appScheme = parse_url(self::string(config('app.url')), PHP_URL_SCHEME);
        $source = $configured === '' ? 'AXISPAY_PAY_BASE_URL empty: https:// + pay host' : 'from AXISPAY_PAY_BASE_URL';

        [$status, $detail] = match (true) {
            $payScheme !== 'http' && $payScheme !== 'https' => [self::ERROR, 'AXISPAY_PAY_BASE_URL has no http:// or https:// scheme'],
            ! is_string($appScheme) || $appScheme === $payScheme => [self::OK, "matches the APP_URL scheme ({$source})"],
            $appScheme === 'http' => [self::WARN, "APP_URL is http:// but payment links use https:// ({$source}): without TLS the checkout's payment requests fail; set AXISPAY_PAY_BASE_URL=http://<pay host>"],
            default => [self::WARN, "APP_URL is https:// but payment links use http:// ({$source}): leave AXISPAY_PAY_BASE_URL empty where TLS is served"],
        };

        $this->row('Pay links base URL', self::INFO, $base);
        $this->row('Pay links scheme', $status, $detail);
    }

    private function checkSessionStorage(): void
    {
        $driver = self::string(config('session.driver'));
        $lifetime = self::string(config('session.lifetime'));

        $status = match ($driver) {
            'database', 'redis' => self::OK,
            'array', 'cookie' => self::ERROR,
            default => self::WARN,
        };
        $this->row('Session driver', $status, $driver.($status === self::WARN ? ' (not shared between containers)' : '')." (lifetime {$lifetime} min)");

        if ($driver !== 'database') {
            return;
        }

        $table = self::string(config('session.table'));
        $connection = config('session.connection');

        if ($table !== 'sessions') {
            $this->row('Session table', self::WARN, "custom SESSION_TABLE {$table}: not checked");

            return;
        }

        try {
            $count = DB::connection(is_string($connection) ? $connection : null)->table('sessions')->count();
            $this->row('Session table', self::OK, "sessions reachable, {$count} row(s)");
        } catch (Throwable $e) {
            $this->row('Session table', self::ERROR, 'sessions not reachable ('.$e::class.')');
        }
    }

    private function checkCache(): void
    {
        $store = self::string(config('cache.default'));

        try {
            // A read: proves the store is reachable without writing to it.
            Cache::store()->get('axispay:doctor:probe');
            $this->row('Cache store', $store === 'array' ? self::WARN : self::OK, "{$store} reachable");
        } catch (Throwable $e) {
            $this->row('Cache store', self::ERROR, "{$store} not reachable (".$e::class.')');
        }
    }

    private function checkProxies(): void
    {
        $proxies = trim(self::string(config('trustedproxy.proxies')));

        $production = app()->environment('production');

        [$status, $detail] = match (true) {
            $proxies === '' => [$production ? self::ERROR : (app()->environment('local') ? self::OK : self::WARN), 'unset: no proxy is trusted (behind Traefik, HTTPS and client IPs are not detected; the checkout would rate-limit every payer as one IP)'],
            in_array($proxies, ['*', '**'], true) => [$production ? self::ERROR : self::WARN, "{$proxies}: trusts every client, who can then fake their IP (checkout rate limits); set Traefik's network range"],
            default => [self::OK, $proxies],
        };

        $this->row('TRUSTED_PROXIES', $status, $detail);
    }

    /** ADR-0051: without Turnstile a link stops taking payments after its first decline. */
    private function checkTurnstile(): void
    {
        // Switched off (ADR-0052): a warning, never an error, in any environment.
        if (! config()->boolean('services.turnstile.enabled')) {
            $this->row('Turnstile', self::WARN, 'Turnstile disabled (AXISPAY_TURNSTILE_ENABLED=false): the bot check after a decline is off; the other card-testing limits still apply');

            return;
        }

        $missing = array_keys(array_filter([
            'TURNSTILE_SITE_KEY' => trim(self::string(config('services.turnstile.site_key'))) === '',
            'TURNSTILE_SECRET_KEY' => trim(self::string(config('services.turnstile.secret_key'))) === '',
        ]));

        [$status, $detail] = $missing === []
            ? [self::OK, 'site and secret keys set']
            : [app()->environment('production') ? self::ERROR : self::WARN, implode(', ', $missing).' unset: links stop taking payments after their first decline'];

        $this->row('Turnstile', $status, $detail);
    }

    private function checkQueue(): void
    {
        $queue = self::string(config('queue.default'));

        $this->row('Queue connection', $queue === 'sync' && ! app()->environment('local') ? self::WARN : self::OK, $queue);
    }

    private function checkMigrations(Migrator $migrator): void
    {
        try {
            if (! $migrator->repositoryExists()) {
                $this->row('Migrations', self::ERROR, 'the migrations table does not exist');

                return;
            }

            $files = $migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]);
            $pending = array_diff(array_keys($files), $migrator->getRepository()->getRan());
            $this->row('Migrations', $pending === [] ? self::OK : self::ERROR, count($pending).' pending');
        } catch (Throwable $e) {
            $this->row('Migrations', self::ERROR, 'database not reachable ('.$e::class.')');
        }
    }

    /**
     * ADR-0050: incoming Stripe webhooks are mandatory. Per mode: the Connect
     * signing secret is set while the mode is in use, when the last event
     * arrived, and whether connections that can charge have gone silent.
     */
    private function checkStripeWebhooks(ProviderEventActivity $activity, GatewayWebhookUrls $urls): void
    {
        $events = config('axispay.gateways.stripe.connect_webhook_events');
        $events = is_array($events) ? implode(', ', array_map(self::string(...), $events)) : '';

        $this->row('Stripe Connect destination', self::INFO, 'API version '.self::string(config('services.stripe.api_version')).", connected-accounts events: {$events}");

        foreach ([false, true] as $livemode) {
            try {
                $this->checkStripeMode($activity, $urls, $livemode);
            } catch (Throwable $e) {
                $this->row('Stripe webhooks ('.self::mode($livemode).')', self::ERROR, 'database not reachable ('.$e::class.')');
            }
        }
    }

    private function checkStripeMode(ProviderEventActivity $activity, GatewayWebhookUrls $urls, bool $livemode): void
    {
        $mode = self::mode($livemode);
        $variable = 'STRIPE_'.strtoupper($mode).'_CONNECT_WEBHOOK_SECRET';
        $url = $urls->connect($livemode);

        $connectEnabled = ConnectionMethod::PlatformOnboarding->isEnabled() || ConnectionMethod::OAuth->isEnabled();
        $platformKey = self::string(config("services.stripe.{$mode}.secret")) !== '';
        $hasConnections = $activity->hasConnectConnections($livemode);
        // Only its shape is read: the value is never printed.
        $secret = self::string(config("services.stripe.{$mode}.connect_webhook_secret"));

        [$status, $detail] = match (true) {
            ! $hasConnections && ! ($connectEnabled && $platformKey) => [self::INFO, 'mode not in use (no Connect method with a platform secret key, no Connect connection)'],
            $secret === '' => [self::ERROR, "{$variable} is empty but the mode is in use (".($hasConnections ? 'Connect connections exist' : 'a Connect method is enabled with a platform secret key')."): every Connect event is rejected. Create the webhook destination for {$url} and set the variable"],
            ! str_starts_with($secret, 'whsec_') => [self::ERROR, "{$variable} is not a webhook signing secret (whsec_...)"],
            default => [self::OK, "signing secret set; destination {$url}"],
        };

        $this->row("Stripe Connect webhook ({$mode})", $status, $detail);

        $last = $activity->lastReceivedAt($livemode);
        $this->row(
            "Last Stripe event ({$mode})",
            self::INFO,
            $last === null ? 'none received yet' : $last->utc()->format('Y-m-d H:i:s').' UTC ('.$last->diffForHumans().')',
        );

        $days = ProviderEventActivity::silenceDays();
        $since = $activity->silenceThreshold();
        $chargeable = $activity->chargeableConnectConnections($livemode);

        if ($chargeable > 0) {
            $silent = ! $activity->connectEventSince($livemode, $since);

            $this->row(
                "Stripe Connect events ({$mode})",
                $silent ? self::WARN : self::OK,
                $silent
                    ? "{$chargeable} Connect connection(s) can charge but no event reached {$url} in the last {$days} days: check the {$mode} webhook destination in the Stripe Dashboard (URL, connected-accounts events, event list, not disabled)"
                    : "received in the last {$days} days",
            );
        }

        $silentApiKey = $activity->silentApiKeyConnections($livemode, $since);

        if ($silentApiKey > 0) {
            $this->row(
                "Stripe api_key events ({$mode})",
                self::WARN,
                "{$silentApiKey} api_key connection(s) can charge but received no event in the last {$days} days: their endpoint on the merchant account may be missing or disabled, or AXISPAY_STRIPE_WEBHOOK_BASE_URL is wrong (a quiet account also shows here)",
            );
        }
    }

    private function checkContainer(): void
    {
        $role = getenv('CONTAINER_ROLE');

        if (! is_string($role) || $role === '') {
            $file = @file_get_contents('/tmp/axispay-role');
            $role = is_string($file) && trim($file) !== '' ? trim($file) : 'unknown';
        }

        $hostname = gethostname();

        $this->row('Container', self::INFO, 'hostname '.(is_string($hostname) ? $hostname : 'unknown').", role {$role}");
    }

    private function row(string $check, string $status, string $detail): void
    {
        $this->rows[] = [$check, $status, $detail];
    }

    private static function mode(bool $livemode): string
    {
        return $livemode ? 'live' : 'test';
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
