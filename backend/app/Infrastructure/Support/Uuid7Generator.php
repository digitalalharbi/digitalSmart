<?php

declare(strict_types=1);

namespace App\Infrastructure\Support;

use App\Application\Shared\Contract\IdentifierGenerator;
use Illuminate\Support\Str;

final readonly class Uuid7Generator implements IdentifierGenerator
{
    public function generate(): string
    {
        return (string) Str::uuid7();
    }
}
