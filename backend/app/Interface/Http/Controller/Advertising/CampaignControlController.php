<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Advertising;

use App\Application\Advertising\Command\BulkEntityAction;
use App\Application\Advertising\Command\RequestCampaignStatusChange;
use App\Application\Advertising\DTO\CampaignControlFilter;
use App\Application\Advertising\Query\GetCampaignControl;
use App\Application\Agents\Command\ProposeEntityChange;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\Clock;
use App\Application\Tags\Contract\TagStore;
use App\Domain\Advertising\AdEntityType;
use App\Domain\Advertising\CampaignHealth;
use App\Domain\Advertising\CampaignObjective;
use App\Domain\Advertising\EntityStatus;
use App\Domain\Agents\ActionRefused;
use App\Domain\Integration\Provider;
use App\Domain\Integration\WriteOperation;
use App\Domain\Integration\WriteSupport;
use App\Domain\Operations\Exception\InvalidApprovalRequest;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Resource\Advertising\CampaignControlResource;
use App\Interface\Http\Resource\Operations\ApprovalResource;
use BackedEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Campaign Control Center.
 *
 * One place answering "which of our campaigns needs me", across every client, project, provider and
 * account the reader is allowed to see — so that normal monitoring does not mean opening six platforms.
 */
final class CampaignControlController extends Controller
{
    public function index(Request $request, GetCampaignControl $campaigns, ActorContext $actor, TagStore $tags): JsonResponse
    {
        $statuses = $this->enums($request, 'status', EntityStatus::class);

        if ($statuses === false) {
            return $this->unknown('status', array_map(
                static fn (EntityStatus $status): string => $status->value,
                EntityStatus::cases(),
            ));
        }

        $objectives = $this->enums($request, 'objective', CampaignObjective::class);

        if ($objectives === false) {
            return $this->unknown('objective', array_map(
                static fn (CampaignObjective $objective): string => $objective->value,
                CampaignObjective::cases(),
            ));
        }

        $health = $this->enums($request, 'health', CampaignHealth::class);

        if ($health === false) {
            return $this->unknown('health', CampaignHealth::values());
        }

        $search = $request->query('search');
        $sort = $request->query('sort', 'health');

        if (! is_string($sort) || ! in_array($sort, CampaignControlFilter::sorts(), true)) {
            return $this->unknown('sort', CampaignControlFilter::sorts());
        }

        $filter = new CampaignControlFilter(
            clientIds: $this->uuids($request, 'client_id'),
            projectIds: $this->projectScope($request, $actor),
            providers: $this->strings($request, 'provider'),
            externalAccountIds: $this->uuids($request, 'external_account_id'),
            statuses: $statuses,
            objectives: $objectives,
            health: $health,
            search: is_string($search) && trim($search) !== '' ? trim($search) : null,
            sort: $sort,
            tagIds: $this->uuids($request, 'tag_id'),
            campaignIds: $this->uuids($request, 'campaign_id'),
            descending: $request->query('direction') === 'desc',
        );

        $page = $campaigns(
            $filter,
            $this->intOrNull($request, 'limit'),
            $this->intOrNull($request, 'offset') ?? 0,
            $this->intOrNull($request, 'days'),
        );

        /*
         * Tags for the page, in one statement. A campaign carries its own and inherits its project's and its
         * client's, which is how the filter reads them — so the card shows the same set the filter matched on.
         * Internal vocabulary: only a reader who holds `clients.view` is ever sent any.
         */
        $tagMap = [];

        if ($actor->permissions()->has(\App\Domain\Access\Permission::ClientsView)) {
            $entities = [];

            foreach ($page->rows as $row) {
                $entities[] = ['type' => 'campaign', 'id' => $row->campaignId];

                if ($row->projectId !== null) {
                    $entities[] = ['type' => 'project', 'id' => $row->projectId];
                }

                if ($row->clientId !== null) {
                    $entities[] = ['type' => 'client', 'id' => $row->clientId];
                }
            }

            $found = $tags->forEntities($actor->workspaceId()->value, $entities);

            foreach ($page->rows as $row) {
                $merged = [
                    ...($found['campaign:'.$row->campaignId] ?? []),
                    ...($row->projectId === null ? [] : ($found['project:'.$row->projectId] ?? [])),
                    ...($row->clientId === null ? [] : ($found['client:'.$row->clientId] ?? [])),
                ];
                $tagMap[$row->campaignId] = array_values(array_column($merged, null, 'id'));
            }
        }

        $payload = CampaignControlResource::page($page);
        $mayAsk = $actor->permissions()->has(\App\Domain\Access\Permission::ApprovalsRequest);

        foreach ($payload['data'] as $index => $item) {
            $payload['data'][$index]['tags'] = $tagMap[$item['id']] ?? [];
            $payload['data'][$index]['status_action'] = $mayAsk ? $this->statusAction((string) $item['provider'], (string) $item['status']) : null;
        }

        return response()->json($payload);
    }

    /**
     * Ask for a campaign to be paused or resumed. Writes an approval request and nothing else.
     *
     * 201 with the request, so the caller can link to it; 422 with a `code` for the refusals that are about the
     * request (a platform this product cannot write to, a campaign in the wrong state), 404 for one the reader
     * may not see.
     */
    public function requestStatusChange(string $campaign, Request $request, RequestCampaignStatusChange $ask, Clock $clock): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'in:pause,resume'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        try {
            $view = $ask(
                $campaign,
                $validated['action'] === 'pause' ? WriteOperation::PauseCampaign : WriteOperation::ResumeCampaign,
                trim((string) $validated['reason']),
            );
        } catch (InvalidApprovalRequest $refusal) {
            return response()->json([
                'message' => $refusal->getMessage(),
                'code' => $refusal->reasonCode,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['data' => ApprovalResource::request($view, $clock->now())], Response::HTTP_CREATED);
    }

    /**
     * Ask for one ad group or ad to be paused or resumed — the same approval as a campaign's, at the entity's own
     * level, refused where the platform does not document that change there.
     */
    public function requestEntityChange(string $level, string $entity, Request $request, ProposeEntityChange $ask, Clock $clock): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'in:pause,resume'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        try {
            $view = $ask(AdEntityType::from($level), $entity, $validated['action'] === 'pause' ? 'pause' : 'resume', null, trim((string) $validated['reason']));
        } catch (InvalidApprovalRequest $refusal) {
            return response()->json(['message' => $refusal->getMessage(), 'code' => $refusal->reasonCode], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ActionRefused $refusal) {
            return response()->json(['message' => $refusal->getMessage(), 'code' => $refusal->reasonCode], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['data' => ApprovalResource::request($view, $clock->now())], Response::HTTP_CREATED);
    }

    /** One action across many ad groups or ads — each through its own use case, each refusal named. */
    public function bulkEntities(Request $request, BulkEntityAction $bulk): JsonResponse
    {
        $validated = $request->validate([
            'level' => ['required', 'in:ad_group,ad'],
            'ids' => ['required', 'array', 'min:1', 'max:'.BulkEntityAction::MAX],
            'ids.*' => ['uuid'],
            'action' => ['required', 'in:request_pause,request_resume,request_budget_change,create_task,add_to_rule'],
            'reason' => ['required_unless:action,add_to_rule', 'nullable', 'string', 'min:3', 'max:1000'],
            'change_pct' => ['required_if:action,request_budget_change', 'nullable', 'integer', 'between:-50,50', 'not_in:0'],
            'rule_id' => ['required_if:action,add_to_rule', 'nullable', 'uuid'],
        ]);

        /** @var 'request_pause'|'request_resume'|'request_budget_change'|'create_task'|'add_to_rule' $action */
        $action = (string) $validated['action'];

        return response()->json(['data' => $bulk(
            AdEntityType::from((string) $validated['level']),
            array_values(array_map('strval', (array) $validated['ids'])),
            $action,
            trim((string) ($validated['reason'] ?? '')),
            isset($validated['change_pct']) ? (int) $validated['change_pct'] : null,
            isset($validated['rule_id']) ? (string) $validated['rule_id'] : null,
        )]);
    }

    /**
     * Read repeated enum parameters, or `false` when one of them is not a member.
     *
     * `false` rather than silently dropping it: a filter the server did not understand returns a page the
     * reader believes is narrowed and is not, which is worse than an error.
     *
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return list<T>|false
     */
    private function enums(Request $request, string $key, string $enum): array|false
    {
        $values = [];

        foreach ($this->strings($request, $key) as $value) {
            $case = $enum::tryFrom($value);

            if ($case === null) {
                return false;
            }

            $values[] = $case;
        }

        return $values;
    }

    /** @return list<string> */
    private function strings(Request $request, string $key): array
    {
        $raw = $request->query($key, $request->query($key.'s', []));
        $values = [];

        foreach (is_array($raw) ? $raw : [$raw] as $value) {
            if (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * The projects the page is narrowed to.
     *
     * `mine=1` means *the ones I am assigned to*, which for somebody who can see every project is a real
     * narrowing and for somebody scoped is a no-op. An empty assignment is **none**, not "no filter":
     * collapsing the two would show a newly-added media buyer the whole agency under a "My campaigns" title.
     *
     * @return list<string>
     */
    private function projectScope(Request $request, ActorContext $actor): array
    {
        $explicit = $this->uuids($request, 'project_id');

        if (! $request->boolean('mine')) {
            return $explicit;
        }

        $mine = $actor->assignedProjectIds();

        if ($mine === []) {
            return ['00000000-0000-0000-0000-000000000000'];
        }

        if ($explicit === []) {
            return $mine;
        }

        $both = array_values(array_intersect($explicit, $mine));

        return $both === [] ? ['00000000-0000-0000-0000-000000000000'] : $both;
    }

    /** @return list<string> */
    private function uuids(Request $request, string $key): array
    {
        return array_values(array_filter(
            $this->strings($request, $key),
            static fn (string $value): bool => preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                $value,
            ) === 1,
        ));
    }

    /** @param list<string> $allowed */
    private function unknown(string $field, array $allowed): JsonResponse
    {
        return response()->json([
            'message' => sprintf('Unknown %s.', str_replace('_', ' ', $field)),
            'code' => 'invalid_'.$field,
            'allowed' => $allowed,
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function intOrNull(Request $request, string $key): ?int
    {
        $value = $request->query($key);

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Whether a card may offer to *ask* for a change, and which: pause for what is spending, resume for what is
     * paused, and nothing where the platform's write is not documented — a control that could only ever be refused.
     */
    private function statusAction(string $provider, string $status): ?string
    {
        $platform = Provider::tryFrom($provider);

        if ($platform === null || $platform->writeSupport(WriteOperation::PauseCampaign) !== WriteSupport::Documented) {
            return null;
        }

        $state = EntityStatus::tryFrom($status);

        return match (true) {
            $state === null => null,
            $state->isSpending() => 'pause',
            $state === EntityStatus::Paused => 'resume',
            default => null,
        };
    }
}
