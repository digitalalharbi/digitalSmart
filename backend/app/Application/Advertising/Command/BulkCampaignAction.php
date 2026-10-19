<?php

declare(strict_types=1);

namespace App\Application\Advertising\Command;

use App\Application\Advertising\DTO\CampaignControlFilter;
use App\Application\Advertising\Query\GetCampaignControl;
use App\Application\Agents\Command\ProposeBudgetChange;
use App\Application\Operations\Command\OpenTask;
use App\Application\Operations\DTO\TaskInput;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Exception\AuthorizationDenied;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Agents\ActionRefused;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Integration\WriteOperation;
use App\Domain\Operations\TaskPriority;
use App\Domain\Operations\TaskSubjectType;
use App\Domain\Shared\Exception\DomainException;

/**
 * One action across many campaigns, each one going through the same use case it would go through alone.
 *
 * Nothing is batched past the rules: every campaign is checked for visibility, every request goes through approval,
 * every budget proposal keeps the project's limit and the no-stacking rule, and each campaign answers for itself — a
 * refusal on one is reported against that one and does not stop the rest. Nothing here changes a platform.
 */
final readonly class BulkCampaignAction
{
    public const int MAX = 100;

    public function __construct(
        private GetCampaignControl $campaigns,
        private RequestCampaignStatusChange $statusChange,
        private ProposeBudgetChange $budgetChange,
        private OpenTask $openTask,
        private ActorContext $actor,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  list<string>  $campaignIds
     * @param  'request_pause'|'request_resume'|'request_budget_change'|'create_task'  $action
     * @return array{done: int, refused: int, results: list<array{campaign_id: string, ok: bool, code: string|null}>}
     */
    public function __invoke(array $campaignIds, string $action, string $reason, ?int $changePct = null): array
    {
        $ids = array_slice(array_values(array_unique($campaignIds)), 0, self::MAX);
        $rows = [];

        foreach (($this->campaigns)(new CampaignControlFilter(campaignIds: $ids), self::MAX)->rows as $row) {
            $rows[$row->campaignId] = $row;
        }

        $results = [];

        foreach ($ids as $id) {
            $row = $rows[$id] ?? null;

            if ($row === null) {
                $results[] = ['campaign_id' => $id, 'ok' => false, 'code' => 'not_found'];

                continue;
            }

            try {
                match ($action) {
                    'request_pause' => ($this->statusChange)($id, WriteOperation::PauseCampaign, $reason),
                    'request_resume' => ($this->statusChange)($id, WriteOperation::ResumeCampaign, $reason),
                    'request_budget_change' => $row->budgetMinor === null || $row->budgetMinor <= 0
                        ? throw new ActionRefused('No daily budget to change.', 'no_daily_budget')
                        : ($this->budgetChange)($id, (int) round($row->budgetMinor * (100 + (int) $changePct) / 100), $reason, 'person', byPerson: true),
                    default => ($this->openTask)(new TaskInput(
                        title: mb_substr(sprintf('%s — %s', $reason, $row->campaignName), 0, 160),
                        priority: TaskPriority::Normal,
                        projectId: $row->projectId,
                        subjectType: TaskSubjectType::Campaign,
                        subjectId: $id,
                    )),
                };
                $results[] = ['campaign_id' => $id, 'ok' => true, 'code' => null];
            } catch (ActionRefused $refused) {
                $results[] = ['campaign_id' => $id, 'ok' => false, 'code' => $refused->reasonCode];
            } catch (AuthorizationDenied) {
                $results[] = ['campaign_id' => $id, 'ok' => false, 'code' => 'forbidden'];
            } catch (ResourceNotFound) {
                $results[] = ['campaign_id' => $id, 'ok' => false, 'code' => 'not_found'];
            } catch (DomainException $refused) {
                $results[] = ['campaign_id' => $id, 'ok' => false, 'code' => $refused->errorCode()];
            }
        }

        $done = count(array_filter($results, static fn (array $r): bool => $r['ok']));

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::CampaignBulkAction,
            auditableType: 'campaign',
            auditableId: null,
            summary: sprintf('%s on %d campaign(s): %d done, %d refused', $action, count($ids), $done, count($ids) - $done),
            changes: ['action' => $action, 'change_pct' => $changePct, 'campaigns' => $ids, 'refused' => array_values(array_filter($results, static fn (array $r): bool => ! $r['ok']))],
        ));

        return ['done' => $done, 'refused' => count($ids) - $done, 'results' => $results];
    }
}
