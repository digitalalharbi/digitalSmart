<?php

declare(strict_types=1);

namespace App\Application\Agents\Tool;

/**
 * Who is calling the tool right now, for the duration of one call.
 *
 * Set by the gateway and read by the tools that make proposals, so a proposal says truthfully whether it came from
 * the Analyst, a routine, or an outside AI client — the caller cannot choose the name it is recorded under.
 */
final class ToolCaller
{
    public string $channel = 'internal';

    public string $agent = 'assistant';
}
