<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Leads;

use App\Application\Journeys\Query\GetJourney;
use App\Application\Leads\Command\ChangeLeadStage;
use App\Application\Leads\Command\RecordLead;
use App\Application\Leads\Command\SyncCrmDeals;
use App\Application\Leads\DTO\LeadView;
use App\Application\Leads\Query\GetLeadCentre;
use App\Application\Leads\Query\ListLeads;
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

    private function scopeId(Request $request, string $key): ?string
    {
        return $request->string($key)->value() ?: null;
    }
}
