<?php

declare(strict_types=1);

namespace App\Application\System\Contract;

/**
 * Whether this server's configuration is fit to serve real users. Read-only: it describes, it never changes, and it never returns a
 * secret value — only the name of the setting and what is wrong with it.
 */
interface ProductionReadiness
{
    /** @return list<array{key: string, status: string, detail: string}> */
    public function check(string $expectedEnvironment = 'production', bool $connect = true): array;
}
