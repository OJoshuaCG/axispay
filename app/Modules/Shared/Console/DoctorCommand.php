<?php

declare(strict_types=1);

namespace App\Modules\Shared\Console;

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
 * Exits non-zero when a check fails.
 */
final class DoctorCommand extends Command
{
    private const string OK = 'ok';

    private const string WARN = 'warn';

    private const string ERROR = 'error';

    private const string INFO = 'info';

    protected $signature = 'axispay:doctor';

    protected $description = 'Read-only diagnostics of the deployment configuration (sessions, cookies, proxies, cache, migrations)';

    /** @var list<array{string, string, string}> */
    private array $rows = [];

    public function handle(Migrator $migrator): int
    {
        $this->checkEnvironment();
        $this->checkAppKey();
        $this->checkCookieSecurity();
        $this->checkSurfaces();
        $this->checkSessionStorage();
        $this->checkCache();
        $this->checkProxies();
        $this->checkQueue();
        $this->checkMigrations($migrator);
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

        [$status, $detail] = match (true) {
            $proxies === '' => [app()->environment('local') ? self::OK : self::WARN, 'unset: no proxy is trusted (behind Traefik, HTTPS and client IPs are not detected)'],
            in_array($proxies, ['*', '**'], true) => [self::WARN, "{$proxies}: trusts every client; only safe if port 8080 is reachable from Traefik alone"],
            default => [self::OK, $proxies],
        };

        $this->row('TRUSTED_PROXIES', $status, $detail);
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

    private static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
