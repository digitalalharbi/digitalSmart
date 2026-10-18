<?php

declare(strict_types=1);

namespace App\Application\Agents\Contract;

use App\Application\Agents\DTO\ToolDefinition;

/**
 * One tool: what it is, and what it does.
 *
 * A handler calls the same use cases the screens call, in the caller's own name — so a tool can never see or do
 * anything its caller could not do by clicking. There is no handler that talks to a provider directly.
 */
interface ToolHandler
{
    /**
     * @param  array<string, mixed>  $input  Already validated against the definition's schema.
     * @return array<string, mixed>
     */
    public function __invoke(array $input): array;

    public function definition(): ToolDefinition;
}
