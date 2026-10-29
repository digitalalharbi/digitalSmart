<?php

declare(strict_types=1);

namespace App\Infrastructure\System;

use App\Application\System\Contract\ProductionReadiness;
use App\Application\System\Readiness;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Override;
use Throwable;

/**
 * The Laravel implementation of the production readiness check.
 *
 * Every check reads `config()`, not the environment, so it describes what the running application actually uses (including a cached
 * config). A check that cannot be made is reported as a failure of that check, never as a pass, and **no value is ever printed**: the
 * detail names the setting and what is wrong with it, because the output of this command ends up in deploy logs and chat.
 *
 * `fail` means real users would be exposed or the application cannot work; `warn` means something honest but incomplete (email that
 * is only logged, realtime switched off) that the owner should know and that is not a reason to refuse to serve.
 */
final class LaravelProductionReadiness implements ProductionReadiness
{
    /** The password docker-compose.yml ships for local development. */
    private const string COMPOSE_PASSWORD = 'digitalsmart';

    private const int MIN_FREE_BYTES = 2_147_483_648;

    /** @var list<array{key: string, status: string, detail: string}> */
    private array $checks = [];

    /** @return list<array{key: string, status: string, detail: string}> */
    #[Override]
    public function check(string $expectedEnvironment = 'production', bool $connect = true): array
    {
        $this->checks = [];

        $this->application($expectedEnvironment);
        $this->session();
        $this->origins();
        $this->database();
        $this->workers();
        $this->deliveryAndRealtime();

        if ($connect) {
            $this->connections();
        }

        $this->storage();

        return $this->checks;
    }

    private function application(string $expected): void
    {
        $environment = (string) config('app.env');
        $this->record('app.env', $environment === $expected, sprintf('APP_ENV is "%s"; this server is expected to run "%s".', $environment, $expected), 'APP_ENV is as expected.');
        $this->record('app.debug', config('app.debug') === false, 'APP_DEBUG must be false: debug pages print configuration and stack traces to anybody.', 'APP_DEBUG is off.');
        $this->record('app.key', trim((string) config('app.key')) !== '', 'APP_KEY is not set: sessions and stored credentials cannot be encrypted.', 'APP_KEY is set.');

        $url = (string) config('app.url');
        $host = (string) parse_url($url, PHP_URL_HOST);

        if (! str_starts_with($url, 'https://')) {
            $this->add('app.url', Readiness::FAIL, 'APP_URL must be https: session cookies and sign-in travel over it.');
        } elseif ($this->isLocal($host)) {
            $this->add('app.url', Readiness::FAIL, 'APP_URL points at this machine, not at the public address.');
        } else {
            $this->add('app.url', Readiness::PASS, 'APP_URL is an https address.');
        }
    }

    private function session(): void
    {
        $this->record('session.secure', config('session.secure') === true, 'SESSION_SECURE_COOKIE must be true: without it the session cookie is also sent over plain http.', 'Session cookies are secure-only.');

        $domain = config('session.domain');
        $this->record(
            'session.domain',
            ! is_string($domain) || ! $this->isLocal(ltrim($domain, '.')),
            'SESSION_DOMAIN points at a local address, so no real browser would keep the session.',
            'The session cookie domain is not a local address.',
        );
    }

    private function origins(): void
    {
        $stateful = array_values(array_filter(array_map('trim', (array) config('sanctum.stateful')), static fn (string $d): bool => $d !== ''));
        $local = array_values(array_filter($stateful, fn (string $d): bool => $this->isLocal((string) preg_replace('/:\d+$/', '', $d))));

        match (true) {
            $stateful === [] => $this->add('sanctum.stateful', Readiness::FAIL, 'SANCTUM_STATEFUL_DOMAINS is empty: the web app could not sign in.'),
            $local !== [] => $this->add('sanctum.stateful', Readiness::FAIL, sprintf('SANCTUM_STATEFUL_DOMAINS still lists %d local address(es): %s.', count($local), implode(', ', $local))),
            default => $this->add('sanctum.stateful', Readiness::PASS, 'The stateful domains are all non-local.'),
        };

        $origins = array_values(array_filter(array_map('trim', (array) config('cors.allowed_origins')), static fn (string $o): bool => $o !== ''));
        $bad = array_values(array_filter($origins, fn (string $o): bool => $o === '*' || $this->isLocal((string) parse_url($o, PHP_URL_HOST))));

        match (true) {
            $origins === [] => $this->add('cors.allowed_origins', Readiness::FAIL, 'No CORS origin is allowed: the web app could not call the API.'),
            $bad !== [] => $this->add('cors.allowed_origins', Readiness::FAIL, sprintf('CORS allows %d origin(s) that must not reach a real server: %s.', count($bad), implode(', ', $bad))),
            default => $this->add('cors.allowed_origins', Readiness::PASS, 'CORS allows only named, non-local origins.'),
        };
    }

    private function database(): void
    {
        $default = (string) config('database.default');
        $this->record('database.default', $default === 'pgsql', sprintf('The default database connection is "%s"; this product runs on PostgreSQL.', $default), 'PostgreSQL is the default connection.');

        $password = (string) config('database.connections.pgsql.password');
        $this->record(
            'database.password',
            $password !== '' && $password !== self::COMPOSE_PASSWORD,
            $password === '' ? 'The database password is empty.' : 'The database password is the one docker-compose ships for local development.',
            'The database password is set and is not the development default.',
        );

        $host = (string) config('database.connections.pgsql.host');

        if ($this->isLocal($host)) {
            $this->add('database.host', Readiness::WARN, 'The database is on this machine. Fine for a single-box staging; a managed or separate database is safer to restore from.');
        } else {
            $this->add('database.host', Readiness::PASS, 'The database is not on this machine.');
        }
    }

    private function workers(): void
    {
        $this->record('queue.default', config('queue.default') === 'redis', sprintf('QUEUE_CONNECTION is "%s"; Horizon supervises Redis queues, and syncs, exports and launches run on them.', (string) config('queue.default')), 'Queues run on Redis.');

        if (config('cache.default') === 'redis') {
            $this->add('cache.default', Readiness::PASS, 'The cache is Redis.');
        } else {
            $this->add('cache.default', Readiness::WARN, sprintf('CACHE_STORE is "%s"; Redis is the supported cache and the only one rate limits and locks are tested against.', (string) config('cache.default')));
        }
    }

    private function deliveryAndRealtime(): void
    {
        $mailer = (string) config('mail.default');

        if (in_array($mailer, ['log', 'array'], true)) {
            $this->add('mail.default', Readiness::WARN, sprintf('MAIL_MAILER is "%s": email is not delivered. Invitations and notices stay AWAITING_CREDENTIALS until a sender is configured.', $mailer));
        } else {
            $this->add('mail.default', Readiness::PASS, 'A real mailer is configured (delivery itself is not verified here).');
        }

        $broadcast = (string) config('broadcasting.default');
        $app = (array) config('reverb.apps.apps.0');
        $complete = ($app['key'] ?? null) && ($app['secret'] ?? null) && ($app['app_id'] ?? null);

        match (true) {
            $broadcast !== 'reverb' => $this->add('broadcasting.default', Readiness::WARN, sprintf('BROADCAST_CONNECTION is "%s": pages will not update live; every change still arrives on the next read.', $broadcast)),
            ! $complete => $this->add('broadcasting.default', Readiness::WARN, 'Reverb is selected but its app id, key or secret is missing, so no socket can connect.'),
            default => $this->add('broadcasting.default', Readiness::PASS, 'Realtime is configured with Reverb.'),
        };
    }

    private function connections(): void
    {
        try {
            DB::select('select 1');
            $this->add('database.reachable', Readiness::PASS, 'The database answers.');
        } catch (Throwable $e) {
            $this->add('database.reachable', Readiness::FAIL, 'The database does not answer: '.$this->safe($e));

            return;
        }

        try {
            /** @var \Illuminate\Database\Migrations\Migrator $migrator */
            $migrator = app('migrator');
            $files = $migrator->getMigrationFiles($migrator->paths() === [] ? [database_path('migrations')] : [...$migrator->paths(), database_path('migrations')]);
            $pending = array_diff(array_keys($files), $migrator->getRepository()->getRan());
            $this->record('database.migrations', $pending === [], sprintf('%d migration(s) have not been run: run them before serving.', count($pending)), 'No migration is waiting.');
        } catch (Throwable $e) {
            $this->add('database.migrations', Readiness::FAIL, 'Could not tell whether migrations are pending: '.$this->safe($e));
        }

        $usesRedis = in_array('redis', [config('queue.default'), config('cache.default'), config('session.driver'), config('broadcasting.default')], true);

        if (! $usesRedis) {
            return;
        }

        try {
            Redis::connection()->ping();
            $this->add('redis.reachable', Readiness::PASS, 'Redis answers.');
        } catch (Throwable $e) {
            $this->add('redis.reachable', Readiness::FAIL, 'Redis does not answer: '.$this->safe($e));
        }
    }

    private function storage(): void
    {
        $path = storage_path();

        if (! is_writable($path)) {
            $this->add('storage.writable', Readiness::FAIL, 'The storage directory is not writable: logs, exports and uploads would fail.');

            return;
        }

        $free = @disk_free_space($path);

        match (true) {
            $free === false => $this->add('storage.writable', Readiness::WARN, 'Storage is writable, but the free space could not be read.'),
            $free < self::MIN_FREE_BYTES => $this->add('storage.writable', Readiness::FAIL, sprintf('Only %.1f GB is free where storage lives; a deploy refuses to start below 2 GB.', $free / 1_073_741_824)),
            default => $this->add('storage.writable', Readiness::PASS, 'Storage is writable with room to spare.'),
        };
    }

    private function record(string $key, bool $ok, string $failure, string $success): void
    {
        $this->add($key, $ok ? Readiness::PASS : Readiness::FAIL, $ok ? $success : $failure);
    }

    private function add(string $key, string $status, string $detail): void
    {
        $this->checks[] = ['key' => $key, 'status' => $status, 'detail' => $detail];
    }

    private function isLocal(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        return $host === '' || $host === 'localhost' || $host === '::1' || str_starts_with($host, '127.') || str_ends_with($host, '.localhost') || str_ends_with($host, '.test');
    }

    /** An exception's class and the start of its message — never a connection string, which can carry a password. */
    private function safe(Throwable $e): string
    {
        return class_basename($e).': '.mb_substr((string) preg_replace('/\s+/', ' ', strtok($e->getMessage(), "\n") ?: ''), 0, 120);
    }
}
