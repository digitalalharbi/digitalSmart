<?php

declare(strict_types=1);

namespace App\Infrastructure\Integration\Writer;

use App\Application\Integration\Contract\ProviderWriter;
use App\Application\Integration\Contract\ProviderWriterRegistry;
use App\Application\Integration\DTO\WriteRequest;

/**
 * Which writer answers a request.
 *
 * Deliberately **fails closed**: anything the sandbox writer does not claim gets the refusing one. A registry
 * that threw on an unknown provider would be a registry somebody wrapped in a try/catch; one that returned null
 * would be a null check somebody forgot.
 */
final readonly class CampaignWriterRegistry implements ProviderWriterRegistry
{
    public function __construct(
        private SandboxCampaignWriter $sandbox,
        private RefusingCampaignWriter $refusing,
    ) {}

    public function for(WriteRequest $request): ProviderWriter
    {
        return $this->sandbox->handles($request) ? $this->sandbox : $this->refusing;
    }
}
