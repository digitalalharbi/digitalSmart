<?php

declare(strict_types=1);

namespace App\Application\Agents\Tool;

use App\Application\Agents\Contract\ToolHandler;
use App\Application\Agents\DTO\ToolDefinition;
use App\Application\Operations\Command\OpenTask;
use App\Application\Operations\DTO\TaskInput;
use App\Domain\Access\Permission;
use App\Domain\Operations\TaskPriority;

/** Put a task on the board. Internal and reversible, so it needs no approval — it is what routine follow-up is made of. */
final readonly class CreateTaskTool implements ToolHandler
{
    public function __construct(private OpenTask $open) {}

    public function __invoke(array $input): array
    {
        $task = ($this->open)(new TaskInput(
            title: mb_substr((string) $input['title'], 0, 160),
            priority: TaskPriority::Normal,
            projectId: isset($input['project_id']) ? (string) $input['project_id'] : null,
        ));

        return ['task' => ['id' => $task->id, 'title' => $task->title]];
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'create_task',
            description: 'Add a task to the board, optionally against a project.',
            action: 'CREATE_TASK',
            permissions: [Permission::TasksManage],
            risk: 'internal',
            requiresApproval: false,
            properties: [
                'title' => ['type' => 'string', 'description' => 'What needs doing.'],
                'project_id' => ['type' => 'string', 'format' => 'uuid', 'description' => 'The project it belongs to.'],
            ],
            required: ['title'],
        );
    }
}
