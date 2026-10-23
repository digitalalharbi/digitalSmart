<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Leads;

use App\Application\Journeys\Query\GetJourney;
use App\Application\Leads\Command\AddLeadNote;
use App\Application\Leads\Command\AssignLead;
use App\Application\Leads\Command\ChangeLeadStage;
use App\Application\Leads\Command\MergeLeads;
use App\Application\Leads\Command\RecordLead;
use App\Application\Leads\Command\RecordLeadMilestone;
use App\Application\Leads\Command\SetLeadValue;
use App\Application\Leads\Command\SyncCrmDeals;
use App\Application\Leads\DTO\LeadDuplicateRow;
use App\Application\Leads\DTO\LeadFilter;
use App\Application\Leads\DTO\LeadHistoryEntry;
use App\Application\Leads\DTO\LeadView;
use App\Application\Leads\Query\FindLeadDuplicates;
use App\Application\Leads\Query\GetLeadCentre;
use App\Application\Leads\Query\GetLeadHistory;
use App\Application\Leads\Query\ListLeadOwners;
use App\Application\Leads\Query\ListLeads;
use App\Application\Shared\DTO\OwnerCandidate;
use App\Domain\Leads\LeadScope;
use App\Domain\Leads\LeadStage;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Request\Leads\RecordLeadRequest;
use App\Interface\Http\Resource\Leads\JourneyResource;
use App\Interface\Http\Resource\Leads\LeadResource;
use DateTimeImmutable;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The lead pipeline, and the Lead Center's view of it. */
final class LeadController extends Controller
{
    public function index(Request $request, ListLeads $list): JsonResponse
    {
        $stages = [];

        foreach ($request->input('stage', []) as $value) {
            $stage = is_string($value) ? LeadStage::tryFrom($value) : null;

            if ($stage !== null) {
                $stages[] = $stage;
            }
        }

        return response()->json([
            'data' => array_map(
                static fn (LeadView $lead): array => LeadResource::lead($lead),
                $list(
                    clientId: $this->scopeId($request, 'client_id'),
                    stages: $stages,
                    from: $this->date($request, 'from'),
                    until: $this->date($request, 'until'),
                    limit: (int) ($request->integer('limit') ?: 100),
                    filter: $this->filter($request),
                ),
            ),
        ]);
    }

    public function store(RecordLeadRequest $request, RecordLead $record): JsonResponse
    {
        return response()->json(
            ['data' => LeadResource::lead($record($request->toInput()))],
            Response::HTTP_CREATED,
        );
    }

    /**
     * Move a lead.
     *
     * A disqualification without a reason is refused by the use case with a message that says what to add.
     */
    public function stage(string $lead, Request $request, ChangeLeadStage $change): JsonResponse
    {
        $stage = LeadStage::tryFrom($request->string('stage')->value());

        if ($stage === null) {
            return response()->json([
                'message' => 'Unknown stage.',
                'code' => 'invalid_stage',
                'allowed' => LeadStage::values(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'data' => LeadResource::lead($change(
                $lead,
                $stage,
                $request->string('reason')->value() ?: null,
                $request->string('note')->value() ?: null,
            )),
        ]);
    }

    /**
     * Put a lead in somebody's name, or in nobody's.
     *
     * The owner has to be somebody who can actually open the lead — see `AssignLead`, where the reason is that a
     * lead carries a prospect's personal data. `GET /api/leads/{lead}/owners` is the list that cannot be wrong.
     */
    public function assign(string $lead, Request $request, AssignLead $assign): JsonResponse
    {
        $ownerId = $request->string('owner_id')->value();

        return response()->json([
            'data' => LeadResource::lead($assign($lead, $ownerId === '' ? null : $ownerId)),
        ]);
    }

    /** Who this lead can be put in the name of. */
    public function owners(string $lead, ListLeadOwners $list): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                static fn (OwnerCandidate $candidate): array => [
                    'id' => $candidate->id,
                    'name' => $candidate->name,
                ],
                $list($lead),
            ),
        ]);
    }

    /**
     * What somebody says this lead is worth.
     *
     * An empty `value_minor` clears it, which is not the same as zero: null means nobody has said, and zero is a
     * claim that the lead is worth nothing. Nothing here is ever inferred from an average.
     */
    public function value(string $lead, Request $request, SetLeadValue $setValue): JsonResponse
    {
        $raw = $request->input('value_minor');

        /*
         * An absent or empty field clears the value. `null` and `0` are different claims — nobody has said,
         * versus somebody saying it is worth nothing — so an empty string is read as the first and never coerced
         * into the second.
         */
        $isCleared = $raw === null || $raw === '';

        if (! $isCleared && ! is_numeric($raw)) {
            return response()->json([
                'message' => 'A value has to be a number of minor units — 25000 for 250.00.',
                'code' => 'invalid_lead_value',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'data' => LeadResource::lead($setValue(
                $lead,
                $isCleared ? null : (int) $raw,
                $request->string('currency')->value() ?: null,
            )),
        ]);
    }

    /**
     * What has happened to this lead, newest first.
     *
     * `leads.view`, not `leads.manage`: reading the history is how a pipeline is reviewed, and the people who
     * read a pipeline are not only the people who write to it.
     */
    public function history(string $lead, GetLeadHistory $history): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                static fn (LeadHistoryEntry $entry): array => LeadResource::historyEntry($entry),
                $history($lead),
            ),
        ]);
    }

    /** Write down what happened. Goes into the lead's own history with its author and its time. */
    public function milestone(string $lead, Request $request, RecordLeadMilestone $record): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', RecordLeadMilestone::TYPES)],
            'note' => ['nullable', 'string', 'max:500'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $record(
            $lead,
            (string) $data['type'],
            isset($data['note']) ? (string) $data['note'] : null,
            isset($data['occurred_at']) ? new DateTimeImmutable((string) $data['occurred_at']) : null,
        );

        return response()->json(['data' => ['recorded' => true]], Response::HTTP_CREATED);
    }

    public function note(string $lead, Request $request, AddLeadNote $addNote): JsonResponse
    {
        return response()->json([
            'data' => LeadResource::lead($addNote($lead, $request->string('note')->value())),
        ]);
    }

    /**
     * The leads that might be the same person as this one.
     *
     * Exact matches on a normalised contact point and nothing else — no fuzzy names, no score. Each row says
     * which contact point matched, because the reader is about to do something irreversible.
     */
    public function duplicates(string $lead, FindLeadDuplicates $find): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                static fn (LeadDuplicateRow $row): array => LeadResource::duplicate($row),
                $find($lead),
            ),
        ]);
    }

    /**
     * Put two leads' histories on one record.
     *
     * The surviving stage is **required** and has to be one of the two leads' current stages. A merge is not a
     * place to move a lead through a pipeline: letting a third stage through here would qualify a lead with no
     * qualifying event behind it, and the review that asks "who decided this, and when" would find nothing.
     *
     * Which record survives is not the caller's to choose — see `LeadMergePlan`. The refusals come back as
     * `leads_cannot_be_merged` with a sentence naming what to do instead.
     */
    public function merge(string $lead, Request $request, MergeLeads $merge): JsonResponse
    {
        $other = $request->string('lead_id')->value();

        if ($other === '') {
            return response()->json([
                'message' => 'Say which other lead this is the same person as.',
                'code' => 'lead_required',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $stage = LeadStage::tryFrom($request->string('stage')->value());

        if ($stage === null) {
            return response()->json([
                'message' => 'Say which stage the merged lead stays in. It has to be one the two are already '
                    .'in, so that no pipeline move happens without being recorded as one.',
                'code' => 'invalid_stage',
                'allowed' => LeadStage::values(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'data' => LeadResource::lead($merge(
                $lead,
                $other,
                $stage,
                $request->string('note')->value() ?: null,
            )),
        ]);
    }

    /**
     * Read a CRM's deals as leads.
     *
     * The client is a parameter rather than something read from the CRM, and that is a limitation stated rather
     * than hidden: a HubSpot portal is one company's CRM, so mapping its deals onto an agency's several clients
     * needs a binding somebody made deliberately. Nothing infers a client from a company name.
     *
     * The response is the sync's own figures — read, recorded, and **left at their stage**. The last is not
     * padding: a bridge that reported only the stages it understood would look like one that read almost nothing.
     */
    public function syncCrm(Request $request, SyncCrmDeals $sync): JsonResponse
    {
        $clientId = $request->string('client_id')->value();

        if ($clientId === '') {
            return response()->json([
                'message' => 'Say which client these deals belong to. A CRM portal is one company\'s and a '
                    .'workspace has several clients, so nothing here can infer it.',
                'code' => 'client_required',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $since = $request->string('since')->value();

        $result = $sync($clientId, $since === '' ? null : new DateTimeImmutable($since));

        return response()->json(['data' => $result]);
    }

    /**
     * The Lead Center.
     *
     * Leads on one side, counted signals on the other, and no ratio between them.
     */
    public function centre(Request $request, GetLeadCentre $centre): JsonResponse
    {
        return response()->json([
            'data' => LeadResource::centre($centre(
                $this->scopeId($request, 'client_id'),
                $request->integer('days') ?: null,
                $this->filter($request),
            )),
        ]);
    }

    /**
     * Everything recorded for the person this lead belongs to.
     *
     * The response states what is missing from it: the marketing touches before a conversion are not available
     * to this product, and a reader has to know they are absent rather than nonexistent.
     */
    public function journey(string $lead, GetJourney $journey): JsonResponse
    {
        return response()->json(['data' => JourneyResource::journey($journey($lead))]);
    }

    private function date(Request $request, string $key): ?DateTimeImmutable
    {
        $value = $request->string($key)->value();

        if ($value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value.($key === 'until' ? ' 23:59:59' : ' 00:00:00'));
        } catch (Exception) {
            return null;
        }
    }

    /** Who and what to narrow to — shared by the list and the centre so their figures describe the same people. */
    private function filter(Request $request): LeadFilter
    {
        return new LeadFilter(
            scope: LeadScope::tryFrom($request->string('scope')->value()) ?? LeadScope::All,
            projectId: $this->scopeId($request, 'project_id'),
            ownerId: $this->scopeId($request, 'owner_id'),
            sourceKind: $this->scopeId($request, 'source_kind'),
            campaign: $this->scopeId($request, 'campaign'),
        );
    }

    private function scopeId(Request $request, string $key): ?string
    {
        return $request->string($key)->value() ?: null;
    }
}
