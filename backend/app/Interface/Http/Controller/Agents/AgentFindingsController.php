<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Agents;

use App\Application\Agents\Agent\AgentRouter;
use App\Application\Agents\Command\AskAgents;
use App\Application\Agents\Query\GetAgentFindings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AgentFindingsController
{
    public function index(Request $request, GetAgentFindings $findings): JsonResponse
    {
        $agent = $request->query('agent');
        $agent = is_string($agent) && in_array($agent, AgentRouter::AGENTS, true) ? $agent : null;

        return new JsonResponse(['data' => array_map(self::shape(...), $findings($agent))]);
    }

    public function ask(Request $request, AskAgents $ask): JsonResponse
    {
        /** @var array{question: string} $input */
        $input = $request->validate(['question' => ['required', 'string', 'max:500']]);
        $answer = $ask($input['question']);

        return new JsonResponse(['data' => [
            'agents' => $answer['agents'],
            'proposed' => $answer['proposed'],
            'findings' => array_map(self::shape(...), $answer['findings']),
        ]]);
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    private static function shape(array $f): array
    {
        return [
            'id' => $f['id'],
            'agent' => $f['agent'],
            'reason' => ['code' => $f['code'], 'params' => (object) $f['params']],
            'message' => $f['message'],
            'severity' => $f['severity'],
            'subject' => ['kind' => $f['subject_kind'], 'id' => $f['subject_id'], 'label' => $f['subject_label']],
            'client' => $f['client_id'] === null ? null : ['id' => $f['client_id'], 'name' => $f['client_name']],
            'project' => $f['project_id'] === null ? null : ['id' => $f['project_id'], 'name' => $f['project_name']],
            'evidence' => (object) $f['evidence'],
            'task_id' => $f['task_id'],
            'since' => $f['first_seen_at'],
        ];
    }
}
