<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| ops:production-check
|--------------------------------------------------------------------------
|
| A read-only answer to "is this server's configuration fit to serve real users?", for the day a staging or production server exists.
| It reads configuration (and, unless told not to, whether the database and Redis answer and whether migrations are pending); it writes
| nothing and changes nothing. A check that cannot be made says so — it never reports a pass it did not earn.
|
*/

/** A configuration that is fit to serve: every check passes, none warns. */
function productionConfig(): void
{
    config([
        'app.env' => 'production',
        'app.debug' => false,
        'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
        'app.url' => 'https://app.digitalsmart.example',
        'session.secure' => true,
        'session.domain' => '.digitalsmart.example',
        'session.driver' => 'redis',
        'sanctum.stateful' => ['app.digitalsmart.example'],
        'cors.allowed_origins' => ['https://app.digitalsmart.example'],
        'database.default' => 'pgsql',
        'database.connections.pgsql.host' => 'db.internal',
        'database.connections.pgsql.password' => 'a-long-random-database-password',
        'queue.default' => 'redis',
        'cache.default' => 'redis',
        'broadcasting.default' => 'reverb',
        'reverb.apps.apps.0.key' => 'reverb-key',
        'reverb.apps.apps.0.secret' => 'reverb-secret',
        'reverb.apps.apps.0.app_id' => 'reverb-id',
        'mail.default' => 'smtp',
    ]);
}

/** @return array{status: string, checks: list<array{key: string, status: string, detail: string}>} */
function readiness(string ...$options): array
{
    $arguments = ['--json' => true, '--skip-connect' => true];

    foreach ($options as $option) {
        $arguments[$option] = true;
    }

    Artisan::call('ops:production-check', $arguments);

    /** @var array{status: string, checks: list<array{key: string, status: string, detail: string}>} $decoded */
    $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    return $decoded;
}

/** @param  array{status: string, checks: list<array{key: string, status: string, detail: string}>}  $report */
function statusOf(array $report, string $key): string
{
    foreach ($report['checks'] as $check) {
        if ($check['key'] === $key) {
            return $check['status'];
        }
    }

    return 'missing';
}

it('passes a configuration fit to serve, with nothing to warn about', function (): void {
    productionConfig();

    $report = readiness();

    expect($report['status'])->toBe('pass')
        ->and(array_unique(array_column($report['checks'], 'status')))->toBe(['pass']);
    $this->artisan('ops:production-check', ['--skip-connect' => true])->assertExitCode(0);
});

it('fails the settings that expose a real server', function (string $key, callable $break): void {
    productionConfig();
    $break();

    $report = readiness();

    expect(statusOf($report, $key))->toBe('fail')->and($report['status'])->toBe('fail');
    $this->artisan('ops:production-check', ['--skip-connect' => true])->assertExitCode(1);
})->with([
    'debug on' => ['app.debug', fn () => config(['app.debug' => true])],
    'not production' => ['app.env', fn () => config(['app.env' => 'local'])],
    'no application key' => ['app.key', fn () => config(['app.key' => ''])],
    'plain http' => ['app.url', fn () => config(['app.url' => 'http://app.digitalsmart.example'])],
    'cookie not secure' => ['session.secure', fn () => config(['session.secure' => false])],
    'cookie secure unset' => ['session.secure', fn () => config(['session.secure' => null])],
    'localhost cookie domain' => ['session.domain', fn () => config(['session.domain' => 'localhost'])],
    'localhost stateful domain' => ['sanctum.stateful', fn () => config(['sanctum.stateful' => ['app.digitalsmart.example', 'localhost:5174']])],
    'no stateful domain' => ['sanctum.stateful', fn () => config(['sanctum.stateful' => []])],
    'wildcard origin' => ['cors.allowed_origins', fn () => config(['cors.allowed_origins' => ['*']])],
    'localhost origin' => ['cors.allowed_origins', fn () => config(['cors.allowed_origins' => ['http://127.0.0.1:5174']])],
    'the compose database password' => ['database.password', fn () => config(['database.connections.pgsql.password' => 'digitalsmart'])],
    'no database password' => ['database.password', fn () => config(['database.connections.pgsql.password' => ''])],
    'queue not redis' => ['queue.default', fn () => config(['queue.default' => 'database'])],
]);

it('warns, and does not fail, about what is honest but incomplete', function (string $key, callable $break): void {
    productionConfig();
    $break();

    $report = readiness();

    // Warnings are things the owner should know — email not sent, realtime off — not reasons to refuse to run.
    expect(statusOf($report, $key))->toBe('warn')->and($report['status'])->toBe('warn');
    $this->artisan('ops:production-check', ['--skip-connect' => true])->assertExitCode(0);
    $this->artisan('ops:production-check', ['--skip-connect' => true, '--strict' => true])->assertExitCode(1);
})->with([
    'mail only logs' => ['mail.default', fn () => config(['mail.default' => 'log'])],
    'realtime off' => ['broadcasting.default', fn () => config(['broadcasting.default' => 'null'])],
    'reverb without keys' => ['broadcasting.default', fn () => config(['reverb.apps.apps.0.secret' => null])],
    'cache not redis' => ['cache.default', fn () => config(['cache.default' => 'database'])],
    'database on this machine' => ['database.host', fn () => config(['database.connections.pgsql.host' => '127.0.0.1'])],
]);

it('can expect a staging environment instead of production', function (): void {
    productionConfig();
    config(['app.env' => 'staging']);

    expect(statusOf(readiness(), 'app.env'))->toBe('fail')
        ->and(statusOf(readinessExpecting('staging'), 'app.env'))->toBe('pass');
});

/** @return array{status: string, checks: list<array{key: string, status: string, detail: string}>} */
function readinessExpecting(string $environment): array
{
    Artisan::call('ops:production-check', ['--json' => true, '--skip-connect' => true, '--expect-env' => $environment]);

    /** @var array{status: string, checks: list<array{key: string, status: string, detail: string}>} $decoded */
    $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    return $decoded;
}

it('names what is wrong and never prints a secret value', function (): void {
    productionConfig();
    config([
        'app.debug' => true,
        'database.connections.pgsql.password' => 'digitalsmart',
        'app.key' => 'base64:'.base64_encode(str_repeat('s', 32)),
    ]);

    Artisan::call('ops:production-check', ['--json' => true, '--skip-connect' => true]);
    $output = Artisan::output();

    expect($output)->toContain('app.debug')
        ->and($output)->not->toContain('digitalsmart"')
        ->and($output)->not->toContain(base64_encode(str_repeat('s', 32)))
        ->and($output)->not->toContain('a-long-random-database-password');
});

it('checks that the database answers and that no migration is waiting, for real', function (): void {
    // The test database is reachable and migrated. Nothing in this test uses redis, so the redis probe has nothing to ask.
    config(['queue.default' => 'sync', 'cache.default' => 'array', 'session.driver' => 'array', 'broadcasting.default' => 'null']);

    Artisan::call('ops:production-check', ['--json' => true]);
    /** @var array{status: string, checks: list<array{key: string, status: string, detail: string}>} $report */
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect(statusOf($report, 'database.reachable'))->toBe('pass')
        ->and(statusOf($report, 'database.migrations'))->toBe('pass')
        ->and(statusOf($report, 'redis.reachable'))->toBe('missing');
});

it('says a database it cannot reach is a failure, and does not call the migration check it could not make a pass', function (): void {
    $original = config('database.default');
    config([
        'queue.default' => 'sync', 'cache.default' => 'array', 'session.driver' => 'array', 'broadcasting.default' => 'null',
        // A throwaway connection, so the framework's own transaction on the real one is left alone.
        'database.connections.unreachable' => [...(array) config('database.connections.pgsql'), 'host' => '127.0.0.1', 'port' => '1'],
        'database.default' => 'unreachable',
    ]);

    try {
        Artisan::call('ops:production-check', ['--json' => true]);
        /** @var array{status: string, checks: list<array{key: string, status: string, detail: string}>} $report */
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    } finally {
        config(['database.default' => $original]);
        DB::purge('unreachable');
    }

    expect(statusOf($report, 'database.reachable'))->toBe('fail')
        ->and(statusOf($report, 'database.migrations'))->toBe('missing');
});

it('fails a default connection that is not PostgreSQL', function (): void {
    productionConfig();
    $original = config('database.default');
    config(['database.default' => 'sqlite']);

    try {
        $status = statusOf(readiness(), 'database.default');
    } finally {
        config(['database.default' => $original]);
    }

    expect($status)->toBe('fail');
});

it('does not touch the database or redis when told to skip the connection checks', function (): void {
    productionConfig();
    config(['database.connections.pgsql.host' => 'host.that.does.not.exist.invalid']);

    $report = readiness();

    expect(statusOf($report, 'database.reachable'))->toBe('missing');
});
