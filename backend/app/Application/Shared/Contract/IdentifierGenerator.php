<?php

declare(strict_types=1);

namespace App\Application\Shared\Contract;

/** Generates UUIDv7 strings, so entity creation needs no framework helper. */
interface IdentifierGenerator
{
    public function generate(): string;
}
