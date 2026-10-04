<?php

declare(strict_types=1);

namespace App\Application\Shared\Contract;

/** Outbound port for build/runtime metadata the framework owns. */
interface ApplicationInfo
{
    public function name(): string;

    public function version(): string;

    public function environment(): string;
}
