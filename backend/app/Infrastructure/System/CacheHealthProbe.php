<?php

declare(strict_types=1);

namespace App\Infrastructure\System;

use App\Application\Shared\Contract\HealthProbe;
use App\Application\Shared\DTO\ServiceHealth;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Throwable;

/** Round-trips a sentinel value through the configured cache store. */
final readonly class CacheHealthProbe implements HealthProbe
{
    private const SENTINEL_KEY = 'system:health:probe';

    public function __construct(
        private CacheRepository $cache,
        private ConfigRepository $config,
    ) {}

    public function probe(): ServiceHealth
    {
        $store = $this->config->get('cache.default');
        $store = is_string($store) ? $store : 'unknown';

        try {
            $expected = (string) microtime(true);
            $this->cache->put(self::SENTINEL_KEY, $expected, 10);
            $actual = $this->cache->get(self::SENTINEL_KEY);

            if ($actual !== $expected) {
                return ServiceHealth::degraded('cache', sprintf('%s store did not return the value just written', $store));
            }

            return ServiceHealth::ok('cache', $store);
        } catch (Throwable $exception) {
            return ServiceHealth::down('cache', $exception->getMessage());
        }
    }
}
