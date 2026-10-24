<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Campaign;

use App\Application\Campaign\Command\ManageBlueprint;
use App\Application\Campaign\DTO\BlueprintInput;
use App\Application\Campaign\DTO\BlueprintView;
use App\Application\Campaign\Query\GetCapabilityRegistry;
use App\Application\Campaign\Query\ReadBlueprints;
use App\Domain\Advertising\Blueprint\BlueprintStatus;
use App\Domain\Advertising\Blueprint\LaunchObjective;
use App\Domain\Integration\Capability\ProviderCapabilityRegistry;
use App\Interface\Http\Controller\Controller;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class BlueprintController extends Controller
{
    public function index(Request $request, ReadBlueprints $read): JsonResponse
    {
        $result = $read->list(
            $request->string('status')->toString() ?: null,
            $request->string('project_id')->toString() ?: null,
            $request->integer('page', 1),
        );

        return response()->json(['data' => array_map($this->view(...), $result['rows']), 'meta' => ['total' => $result['total']]]);
    }

    public function show(string $blueprint, ReadBlueprints $read): JsonResponse
    {
        $result = $read->get($blueprint);

        return response()->json(['data' => [...$this->view($result['blueprint']), 'missing' => $result['missing']]]);
    }

    public function translation(string $blueprint, ReadBlueprints $read): JsonResponse
    {
        return response()->json(['data' => $read->translation($blueprint)]);
    }

    public function options(Request $request, ReadBlueprints $read): JsonResponse
    {
        $request->validate(['project_id' => ['required', 'uuid']]);

        return response()->json(['data' => $read->options($request->string('project_id')->toString())]);
    }

    public function capabilities(GetCapabilityRegistry $registry): JsonResponse
    {
        return response()->json(['data' => $registry(), 'meta' => ['verified_on' => ProviderCapabilityRegistry::VERIFIED_ON]]);
    }

    public function store(Request $request, ManageBlueprint $manage): JsonResponse
    {
        return response()->json(['data' => $this->view($manage->create($this->input($request)))], 201);
    }

    public function update(Request $request, string $blueprint, ManageBlueprint $manage): JsonResponse
    {
        return response()->json(['data' => $this->view($manage->update($blueprint, $this->input($request)))]);
    }

    public function submit(string $blueprint, ManageBlueprint $manage): JsonResponse
    {
        return response()->json(['data' => $this->view($manage->submit($blueprint))]);
    }

    public function returnToDraft(Request $request, string $blueprint, ManageBlueprint $manage): JsonResponse
    {
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];

        return response()->json(['data' => $this->view($manage->returnToDraft($blueprint, $reason))]);
    }

    public function cancel(Request $request, string $blueprint, ManageBlueprint $manage): JsonResponse
    {
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];

        return response()->json(['data' => $this->view($manage->cancel($blueprint, $reason))]);
    }

    private function input(Request $request): BlueprintInput
    {
        $v = $request->validate([
            'project_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:160'],
            'objective' => ['required', Rule::in(LaunchObjective::values())],
            'budget_type' => ['required', Rule::in(['daily', 'lifetime'])],
            'budget_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at', Rule::requiredIf($request->input('budget_type') === 'lifetime')],
            'external_account_ids' => ['present', 'array', 'max:20'],
            'external_account_ids.*' => ['uuid'],
            'tracking_link_id' => ['nullable', 'uuid'],
            'landing_url' => ['nullable', 'url:https,http', 'max:2048'],
            'conversion_event_id' => ['nullable', 'uuid'],
            'targeting' => ['present', 'array'],
            'targeting.countries' => ['array', 'max:50'],
            'targeting.countries.*' => ['string', 'size:2', 'alpha'],
            'targeting.age_min' => ['nullable', 'integer', 'min:13', 'max:65'],
            'targeting.age_max' => ['nullable', 'integer', 'min:13', 'max:65', 'gte:targeting.age_min'],
            'targeting.gender' => ['nullable', Rule::in(['all', 'female', 'male'])],
            'targeting.languages' => ['array', 'max:20'],
            'targeting.languages.*' => ['string', 'size:2', 'alpha'],
            'targeting.interests' => ['nullable', 'string', 'max:500'],
            'placement' => ['present', 'array'],
            'placement.mode' => ['nullable', Rule::in(['automatic', 'manual'])],
            'placement.placements' => ['array', 'max:30'],
            'placement.placements.*' => ['string', 'max:60'],
            'ad_copy' => ['present', 'array'],
            'ad_copy.headline' => ['nullable', 'string', 'max:255'],
            'ad_copy.primary_text' => ['nullable', 'string', 'max:2000'],
            'ad_copy.call_to_action' => ['nullable', 'string', 'max:40'],
            'creative_request_ids' => ['present', 'array', 'max:20'],
            'creative_request_ids.*' => ['uuid'],
        ]);

        $targeting = (array) $v['targeting'];
        $targeting['countries'] = array_map('strtoupper', array_values((array) ($targeting['countries'] ?? [])));
        $targeting['languages'] = array_map('strtolower', array_values((array) ($targeting['languages'] ?? [])));

        /** @var array{countries?: list<string>, age_min?: int|null, age_max?: int|null, gender?: string, languages?: list<string>, interests?: string|null} $targeting */
        /** @var array{mode?: string, placements?: list<string>} $placement */
        $placement = (array) $v['placement'];
        /** @var array{headline?: string|null, primary_text?: string|null, call_to_action?: string|null} $adCopy */
        $adCopy = (array) $v['ad_copy'];

        return new BlueprintInput(
            projectId: (string) $v['project_id'],
            name: (string) $v['name'],
            objective: LaunchObjective::from((string) $v['objective']),
            budgetType: (string) $v['budget_type'],
            budgetMinor: (int) $v['budget_minor'],
            currency: strtoupper((string) $v['currency']),
            startsAt: new DateTimeImmutable((string) $v['starts_at']),
            endsAt: isset($v['ends_at']) ? new DateTimeImmutable((string) $v['ends_at']) : null,
            externalAccountIds: array_values(array_map('strval', (array) $v['external_account_ids'])),
            trackingLinkId: isset($v['tracking_link_id']) ? (string) $v['tracking_link_id'] : null,
            landingUrl: isset($v['landing_url']) && $v['landing_url'] !== '' ? (string) $v['landing_url'] : null,
            conversionEventId: isset($v['conversion_event_id']) ? (string) $v['conversion_event_id'] : null,
            targeting: $targeting,
            placement: $placement,
            adCopy: $adCopy,
            creativeRequestIds: array_values(array_map('strval', (array) $v['creative_request_ids'])),
        );
    }

    /** @return array<string, mixed> */
    private function view(BlueprintView $b): array
    {
        return [
            'id' => $b->id,
            'client' => ['id' => $b->clientId, 'name' => $b->clientName],
            'project' => ['id' => $b->projectId, 'name' => $b->projectName],
            'name' => $b->name,
            'objective' => $b->objective,
            'budget_type' => $b->budgetType,
            'budget_minor' => $b->budgetMinor,
            'currency' => $b->currency,
            'starts_at' => $b->startsAt->format(DateTimeInterface::ATOM),
            'ends_at' => $b->endsAt?->format(DateTimeInterface::ATOM),
            'targets' => $b->targets,
            'tracking_link' => $b->trackingLink,
            'landing_url' => $b->landingUrl,
            'destination' => $b->destination(),
            'conversion' => $b->conversion,
            'targeting' => (object) $b->targeting,
            'placement' => (object) $b->placement,
            'ad_copy' => (object) $b->adCopy,
            'creatives' => $b->creatives,
            'status' => $b->status,
            'editable' => BlueprintStatus::from($b->status)->isEditable(),
            'version' => $b->version,
            'status_reason' => $b->statusReason,
            'created_by' => $b->createdBy,
            'submitted_by' => $b->submittedBy,
            'submitted_at' => $b->submittedAt?->format(DateTimeInterface::ATOM),
            'approved_by' => $b->approvedBy,
            'approved_at' => $b->approvedAt?->format(DateTimeInterface::ATOM),
            'updated_at' => $b->updatedAt->format(DateTimeInterface::ATOM),
        ];
    }
}
