<?php

declare(strict_types=1);

namespace App\Infrastructure\System;

use App\Application\Shared\Contract\ApplicationInfo;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/** Adapter over Laravel configuration for application metadata. */
final readonly class LaravelApplicationInfo implements ApplicationInfo
{
    public function __construct(private ConfigRepository $config) {}

    public function name(): string
    {
        return $this->string('app.name', 'digitalSmart');
    }

    public function version(): string
    {
        return $this->string('app.version', '0.0.0');
    }

    public function environment(): string
    {
        return $this->string('app.env', 'production');
    }

    private function string(string $key, string $fallback): string
    {
        $value = $this->config->get($key);

        return is_string($value) && $value !== '' ? $value : $fallback;
    }
}
