<?php

declare(strict_types=1);

use App\Application\Shared\Contract\ApplicationInfo;
use App\Application\Shared\Contract\HealthProbe;
use App\Application\Shared\DTO\ServiceHealth;
use App\Application\System\Query\GetSystemHealth;
use App\Domain\Shared\ValueObject\ServiceStatus;

/**
 * The use case is pure orchestration: these tests run with no framework,
 * no database and no network.
 */
function fakeInfo(): ApplicationInfo
{
    return new class implements ApplicationInfo
    {
        public function name(): string
        {
            return 'digitalSmart';
        }

        public function version(): string
        {
            return '1.2.3';
        }

        public function environment(): string
        {
            return 'testing';
        }
    };
}

function probeReturning(ServiceHealth $health): HealthProbe
{
    return new class($health) implements HealthProbe
    {
        public function __construct(private ServiceHealth $health) {}

        public function probe(): ServiceHealth
        {
            return $this->health;
        }
    };
}

it('reports application metadata from the injected info port', function (): void {
    $report = (new GetSystemHealth(fakeInfo(), []))();

    expect($report->application)->toBe('digitalSmart')
        ->and($report->version)->toBe('1.2.3')
        ->and($report->environment)->toBe('testing')
        ->and($report->services)->toBe([]);
});

it('collects every probe result in order', function (): void {
    $report = (new GetSystemHealth(fakeInfo(), [
        probeReturning(ServiceHealth::ok('database', 'pgsql 16')),
        probeReturning(ServiceHealth::ok('cache', 'redis')),
    ]))();

    expect($report->services)->toHaveCount(2)
        ->and($report->services[0]->name)->toBe('database')
        ->and($report->services[1]->name)->toBe('cache');
});

it('is operational when every probe is ok', function (): void {
    $report = (new GetSystemHealth(fakeInfo(), [
        probeReturning(ServiceHealth::ok('database')),
        probeReturning(ServiceHealth::ok('cache')),
    ]))();

    expect($report->status())->toBe(ServiceStatus::Ok)
        ->and($report->status()->isOperational())->toBeTrue();
});

it('degrades, but stays operational, when a non-critical probe degrades', function (): void {
    $report = (new GetSystemHealth(fakeInfo(), [
        probeReturning(ServiceHealth::ok('database')),
        probeReturning(ServiceHealth::degraded('queue', 'backlog is 5000 jobs')),
    ]))();

    expect($report->status())->toBe(ServiceStatus::Degraded)
        ->and($report->status()->isOperational())->toBeTrue();
});

it('reports the worst status when probes disagree', function (): void {
    $report = (new GetSystemHealth(fakeInfo(), [
        probeReturning(ServiceHealth::ok('cache')),
        probeReturning(ServiceHealth::degraded('queue', 'no workers')),
        probeReturning(ServiceHealth::down('database', 'connection refused')),
    ]))();

    expect($report->status())->toBe(ServiceStatus::Down)
        ->and($report->status()->isOperational())->toBeFalse();
});
