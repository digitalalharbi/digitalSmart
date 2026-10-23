<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Tracking;

use App\Application\Leads\Automation\LeadAutomationSettings;
use App\Application\Leads\Command\SaveLeadAutomation;
use App\Application\Leads\Query\GetLeadFollowUp;
use App\Application\Tracking\Exception\TrackingRefused;
use App\Domain\Leads\Automation\AssignmentMode;
use App\Interface\Http\Controller\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** How a project handles a new lead, and what its team still has to answer. */
final class LeadAutomationController extends Controller
{
    public function show(Request $request, GetLeadFollowUp $followUp): JsonResponse
    {
        $data = $request->validate(['project_id' => ['required', 'uuid']]);
        $r = $followUp((string) $data['project_id']);

        return response()->json(['data' => [
            'settings' => $this->settings($r['settings']),
            'owners' => array_map(static fn ($o): array => ['id' => $o->id, 'name' => $o->name], $r['owners']),
            'timezone' => $r['timezone'],
            'groups' => $r['groups'],
        ]]);
    }

    public function update(Request $request, SaveLeadAutomation $save): JsonResponse
    {
        $data = $request->validate([
            'project_id' => ['required', 'uuid'],
            'enabled' => ['required', 'boolean'],
            'assignment_mode' => ['required', 'string', 'in:'.implode(',', AssignmentMode::values())],
            'owner_id' => ['nullable', 'uuid'],
            'create_task' => ['required', 'boolean'],
            'task_title_ar' => ['nullable', 'string', 'max:160'],
            'task_title_en' => ['nullable', 'string', 'max:160'],
            'task_priority' => ['nullable', 'string', 'in:low,normal,high,urgent'],
            'notify_owner' => ['required', 'boolean'],
            'sla_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
        ]);
        $text = static fn (string $k): ?string => isset($data[$k]) && is_string($data[$k]) && trim($data[$k]) !== '' ? trim($data[$k]) : null;

        try {
            $saved = $save(new LeadAutomationSettings(
                (string) $data['project_id'], (bool) $data['enabled'], AssignmentMode::from((string) $data['assignment_mode']),
                $text('owner_id'), (bool) $data['create_task'], $text('task_title_ar'), $text('task_title_en'),
                $text('task_priority') ?? 'high', (bool) $data['notify_owner'],
                isset($data['sla_minutes']) ? (int) $data['sla_minutes'] : null,
            ));
        } catch (TrackingRefused $e) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, $e->getMessage());
        }

        return response()->json(['data' => $this->settings($saved)]);
    }

    /** @return array<string, mixed> */
    private function settings(LeadAutomationSettings $s): array
    {
        return [
            'project_id' => $s->projectId, 'enabled' => $s->enabled, 'assignment_mode' => $s->mode->value, 'owner_id' => $s->ownerId,
            'create_task' => $s->createTask, 'task_title_ar' => $s->taskTitleAr, 'task_title_en' => $s->taskTitleEn,
            'task_priority' => $s->taskPriority, 'notify_owner' => $s->notifyOwner, 'sla_minutes' => $s->slaMinutes,
        ];
    }
}
