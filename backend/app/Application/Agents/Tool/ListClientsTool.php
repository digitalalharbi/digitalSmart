<?php

declare(strict_types=1);

namespace App\Application\Agents\Tool;

use App\Application\Agents\Contract\ToolHandler;
use App\Application\Agents\DTO\ToolDefinition;
use App\Application\Client\DTO\ClientFilters;
use App\Application\Client\DTO\ClientView;
use App\Application\Client\Query\ListClients;
use App\Application\Shared\DTO\Pagination;
use App\Domain\Access\Permission;
use App\Domain\Client\ClientStatus;

final readonly class ListClientsTool implements ToolHandler
{
    public function __construct(private ListClients $clients) {}

    public function __invoke(array $input): array
    {
        $page = ($this->clients)(new ClientFilters(statuses: [ClientStatus::Active]), new Pagination((int) ($input['page'] ?? 1), 50));

        return [
            'clients' => array_values(array_map(static fn (ClientView $c): array => ['id' => $c->id, 'name' => $c->name], $page->items)),
            'total' => $page->total,
        ];
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'list_clients',
            description: 'Active clients, fifty to a page: id and name only.',
            action: 'READ_CLIENTS',
            permissions: [Permission::ClientsView],
            risk: 'read',
            requiresApproval: false,
            properties: ['page' => ['type' => 'integer', 'min' => 1, 'max' => 100, 'description' => 'Which page (default 1).']],
        );
    }
}
