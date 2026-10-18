<?php

declare(strict_types=1);

namespace App\Application\Agents\Tool;

use App\Application\Agents\Contract\ToolHandler;
use App\Application\Agents\DTO\ToolDefinition;
use App\Application\Attention\DTO\AttentionItem;
use App\Application\Attention\Query\GetAttentionQueue;

final readonly class GetAttentionItemsTool implements ToolHandler
{
    public function __construct(private GetAttentionQueue $queue) {}

    public function __invoke(array $input): array
    {
        $queue = ($this->queue)([], (int) ($input['limit'] ?? 20));

        return [
            'total' => $queue->total,
            'items' => array_map(static fn (AttentionItem $i): array => [
                'stream' => $i->stream->value,
                'severity' => $i->severity->value,
                'message' => $i->message,
                'entity' => $i->entityLabel,
                'client' => $i->clientName,
                'project' => $i->projectName,
            ], $queue->items),
        ];
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'get_attention_items',
            description: 'What needs a person, worst first: the same queue the Today screen shows.',
            action: 'READ_ATTENTION',
            permissions: [],
            risk: 'read',
            requiresApproval: false,
            properties: ['limit' => ['type' => 'integer', 'min' => 1, 'max' => 50, 'description' => 'How many (default 20).']],
        );
    }
}
