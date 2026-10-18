<?php

declare(strict_types=1);

namespace App\Application\Agents\DTO;

use App\Domain\Access\Permission;
use stdClass;

/**
 * One tool, as the registry declares it — the same description an agent, the admin page and an MCP client all read.
 *
 * `risk` is the honest classification: `read` changes nothing; `internal` writes something inside the product that
 * a person can undo (a task); `proposal` asks for a change to somebody's money and is only ever a request — the
 * decision and the provider write are separate acts by people with their own permissions.
 *
 * @phpstan-type Property array{type: 'string'|'integer'|'number'|'boolean', enum?: list<string>, format?: 'uuid'|'date', min?: int, max?: int, description: string}
 */
final readonly class ToolDefinition
{
    /**
     * @param  list<Permission>  $permissions  All of them are required.
     * @param  array<string, array<string, mixed>>  $properties
     * @param  list<string>  $required
     */
    public function __construct(
        public string $name,
        public string $description,
        public string $action,
        public array $permissions,
        public string $risk,
        public bool $requiresApproval,
        public array $properties,
        public array $required = [],
        public int $timeoutSeconds = 20,
        public int $callsPerMinute = 60,
    ) {}

    /**
     * The JSON Schema an MCP client is shown.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->properties === [] ? new stdClass : $this->properties,
            'required' => $this->required,
            'additionalProperties' => false,
        ];
    }
}
