<?php

declare(strict_types=1);

namespace App\Application\Integration\Contract;

use App\Application\Integration\DTO\WriteRequest;

/** The writer for a request, resolved once rather than by each caller asking each writer. */
interface ProviderWriterRegistry
{
    public function for(WriteRequest $request): ProviderWriter;
}
