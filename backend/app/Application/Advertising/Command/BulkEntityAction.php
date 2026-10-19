<?php

declare(strict_types=1);

namespace App\Application\Advertising\Command;

use App\Application\Advertising\DTO\CampaignControlFilter;
use App\Application\Advertising\DTO\EntityControlRow;
use App\Application\Advertising\Query\GetCampaignControl;
use App\Application\Agents\Command\ProposeEntityChange;
use App\Application\Automation\Command\SaveRule;
use App\Application\Automation\Contract\AutomationRuleRepository;
use App\Application\Automation\DTO\RuleInput;
use App\Application\Operations\Command\OpenTask;
use App\Application\Operations\DTO\TaskInput;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Exception\AuthorizationDenied;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Advertising\AdEntityType;
use App\Domain\Agents\ActionRefused;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Operations\Exception\InvalidApprovalRequest;
use App\Domain\Operations\TaskPriority;
use App\Domain\Operations\TaskSubjectType;
use App\Domain\Shared\Exception\DomainException;

/**
 * One action across many ad groups or ads, each through its own use case — so every limit, permission and refusal
 * that applies to one applies to each, and the answer says, per entity, what was done and what was refused.
 *
 * - `request_pause` / `request_resume` / `request_budget_change`: a proposal each, through the same approval as a
 *   campaign's — nothing changes on a platform.
 * - `create_task`: a task on the board about each, so the work is somebody's.
 * - `add_to_rule`: the entities join an existing rule's scope; a rule judges one level, so its level must match.
 *
 * Tagging goes through the tags endpoint, which already takes many at once. One audit entry for the whole act.
 */
final readonly class BulkEntityAction
{
    public const int MAX = 100;

    public function __construct(
        private GetCampaignControl $campaigns,
        private ProposeEntityChange $propose,
        private OpenTask $openTask,
        private AutomationRuleRepository $rules,
        private SaveRule $saveRule,
        private ActorContext $actor,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  list<string>  $ids
     * @param  'request_pause'|'request_resume'|'request_budget_change'|'create_task'|'add_to_rule'  $action
     * @return array{done: int, refused: int, results: list<array{id: string, ok: bool, code: string|null}>}
     */
    public function __invoke(AdEntityType $level, array $ids, string $action, string $reason, ?int $changePct = null, ?string $ruleId = null): array
    {
        $ids = array_slice(array_values(array_unique($ids)), 0, self::MAX);
        $rows = [];

        foreach ($this->campaigns->assessEntities(
            $level,
            new CampaignControlFilter,
            $level === AdEntityType::AdGroup ? $ids : [],
            $level === AdEntityType::Ad ? $ids : [],
        ) as $row) {
            $rows[$row->id] = $row;
        }

        $results = $action === 'add_to_rule'
            ? $this->addToRule($level, $ids, $rows, (string) $ruleId)
            : array_map(fn (string $id): array => $this->one($level, $id, $rows[$id] ?? null, $action, $reason, $changePct), $ids);

        $done = count(array_filter($results, static fn (array $r): bool => $r['ok']));

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::CampaignBulkAction,
            auditableType: $level->value,
            auditableId: null,
            summary: sprintf('%s on %d %s: %d done, %d refused', $action, count($ids), $level === AdEntityType::Ad ? 'ad(s)' : 'ad group(s)', $done, count($ids) - $done),
            changes: ['action' => $action, 'level' => $level->value, 'change_pct' => $changePct, 'rule_id' => $ruleId, 'entities' => $ids, 'refused' => array_values(array_filter($results, static fn (array $r): bool => ! $r['ok']))],
        ));

        return ['done' => $done, 'refused' => count($results) - $done, 'results' => $results];
    }

    /** @return array{id: string, ok: bool, code: string|null} */
    private function one(AdEntityType $level, string $id, ?EntityControlRow $row, string $action, string $reason, ?int $changePct): array
    {
        if ($row === null) {
            return ['id' => $id, 'ok' => false, 'code' => 'not_found'];
        }

        try {
            match ($action) {
                'request_pause' => ($this->propose)($level, $id, 'pause', null, $reason),
                'request_resume' => ($this->propose)($level, $id, 'resume', null, $reason),
                'request_budget_change' => $row->budgetMinor === null || $row->budgetMinor <= 0
                    ? throw ActionRefused::notDaily()
                    : ($this->propose)($level, $id, 'budget', (int) round($row->budgetMinor * (100 + (int) $changePct) / 100), $reason),
                default => ($this->openTask)(new TaskInput(
                    title: mb_substr(sprintf('%s — %s › %s', $reason, $row->parentLabel() ?? $row->campaignName, $row->name), 0, 160),
                    priority: TaskPriority::Normal,
                    projectId: $row->projectId,
                    subjectType: $level === AdEntityType::Ad ? TaskSubjectType::Ad : TaskSubjectType::AdGroup,
                    subjectId: $id,
                )),
            };

            return ['id' => $id, 'ok' => true, 'code' => null];
        } catch (ActionRefused $refused) {
            return ['id' => $id, 'ok' => false, 'code' => $refused->reasonCode];
        } catch (InvalidApprovalRequest $refused) {
            return ['id' => $id, 'ok' => false, 'code' => $refused->reasonCode];
        } catch (AuthorizationDenied) {
            return ['id' => $id, 'ok' => false, 'code' => 'forbidden'];
        } catch (ResourceNotFound) {
            return ['id' => $id, 'ok' => false, 'code' => 'not_found'];
        } catch (DomainException $refused) {
            return ['id' => $id, 'ok' => false, 'code' => $refused->errorCode()];
        }
    }

    /**
     * @param  list<string>  $ids
     * @param  array<string, EntityControlRow>  $rows
     * @return list<array{id: string, ok: bool, code: string|null}>
     */
    private function addToRule(AdEntityType $level, array $ids, array $rows, string $ruleId): array
    {
        $rule = $this->rules->find($this->actor->workspaceId()->value, $ruleId) ?? throw ResourceNotFound::of('Rule');

        if ($rule->level() !== $level) {
            return array_map(static fn (string $id): array => ['id' => $id, 'ok' => false, 'code' => 'level_mismatch'], $ids);
        }

        $visible = array_values(array_filter($ids, static fn (string $id): bool => isset($rows[$id])));
        $merge = static fn (array $existing): array => array_values(array_unique([...$existing, ...$visible]));

        ($this->saveRule)(RuleInput::fromView(
            $rule,
            adGroupIds: $level === AdEntityType::AdGroup ? $merge($rule->adGroupIds) : null,
            adIds: $level === AdEntityType::Ad ? $merge($rule->adIds) : null,
        ), $rule->id);

        return array_map(static fn (string $id): array => isset($rows[$id])
            ? ['id' => $id, 'ok' => true, 'code' => null]
            : ['id' => $id, 'ok' => false, 'code' => 'not_found'], $ids);
    }
}
