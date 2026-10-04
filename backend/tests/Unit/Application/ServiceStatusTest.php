<?php

declare(strict_types=1);

use App\Domain\Shared\ValueObject\ServiceStatus;

it('orders severity from ok to down', function (): void {
    expect(ServiceStatus::Ok->severity())->toBeLessThan(ServiceStatus::Degraded->severity())
        ->and(ServiceStatus::Degraded->severity())->toBeLessThan(ServiceStatus::Down->severity());
});

it('returns ok when asked for the worst of nothing', function (): void {
    expect(ServiceStatus::worst())->toBe(ServiceStatus::Ok);
});

it('picks the worst status regardless of argument order', function (): void {
    expect(ServiceStatus::worst(ServiceStatus::Down, ServiceStatus::Ok))->toBe(ServiceStatus::Down)
        ->and(ServiceStatus::worst(ServiceStatus::Ok, ServiceStatus::Down))->toBe(ServiceStatus::Down)
        ->and(ServiceStatus::worst(ServiceStatus::Ok, ServiceStatus::Degraded))->toBe(ServiceStatus::Degraded);
});

it('treats only down as non-operational', function (): void {
    expect(ServiceStatus::Ok->isOperational())->toBeTrue()
        ->and(ServiceStatus::Degraded->isOperational())->toBeTrue()
        ->and(ServiceStatus::Down->isOperational())->toBeFalse();
});
