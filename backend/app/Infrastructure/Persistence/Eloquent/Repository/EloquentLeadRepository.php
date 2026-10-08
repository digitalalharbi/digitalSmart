<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Leads\Contract\LeadRepository;
use App\Application\Leads\DTO\LeadInput;
use App\Application\Leads\DTO\LeadView;
use App\Application\Shared\Contract\Clock;
use App\Domain\Commerce\AttributionEvidence;
use App\Domain\Leads\LeadSourceKind;
use App\Domain\Leads\LeadStage;
use App\Domain\Shared\ValueObject\ContactPoint;
use App\Infrastructure\Persistence\Eloquent\Model\Lead;
use App\Infrastructure\Persistence\Eloquent\Model\LeadEvent;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

final readonly class EloquentLeadRepository implements LeadRepository
{
    /**
     * How long two enquiries from the same contact are treated as one lead.
     *
     * A day, and the number is a judgement rather than a constant of nature. Within it, the same phone
     * submitting twice is almost always one person resubmitting a form or following up a message; beyond it,
     * the same phone is a new enquiry about something else. Both errors cost something — splitting doubles a
     * client's lead count and halves their cost per lead, merging loses a real enquiry — and a day is where
     * an agency's own instinct puts the line.
     */
    private const int DEDUPLICATION_WINDOW_HOURS = 24;

    /** @var list<string> */
    private const array RELATIONS = ['client:id,name', 'owner:id,name'];

    public function __construct(private Clock $clock) {}

    public function record(string $workspaceId, LeadInput $input): LeadView
    {
        $contact = ContactPoint::of($input->email, $input->phone);

        return DB::transaction(function () use ($workspaceId, $input, $contact): LeadView {
            $existing = $this->findExisting($workspaceId, $input, $contact);

            if ($existing instanceof Lead) {
                /*
                 * Already known. The newer arrival's message and attribution are kept where the original had
                 * none — a follow-up often carries the campaign parameters the first submission lost — and
                 * the stage is left alone, because a human owns that.
                 */
                $this->enrich($existing, $input, $contact);

                return $this->toView($this->reload($existing));
            }

            /** @var Lead $lead */
            $lead = Lead::query()->create([
                'workspace_id' => $workspaceId,
                'client_id' => $input->clientId,
                'project_id' => $input->projectId,
                'source_kind' => $input->sourceKind->value,
                'source_detail' => $input->sourceDetail,
                'store_order_id' => $input->storeOrderId,
                'measurement_source_id' => $input->measurementSourceId,
                'external_id' => $input->externalId,
                'name' => $input->name,
                'email' => $input->email,
                'phone' => $input->phone,
                // Normalised only for recognising the same person again, never shown.
                'email_key' => $contact->email,
                'phone_key' => $contact->phone,
                'message' => $input->message,
                /*
                 * `New`, unless the system that owns this lead stated a stage. A CRM-sourced lead arrives at the
                 * stage its CRM says it is in; everything else starts where a person will move it from.
                 */
                'stage' => ($input->crmStage ?? LeadStage::New)->value,
                /*
                 * The CRM's own closing time, which the schema requires of a closed lead. Not the sync's clock: a
                 * deal may have closed last week, and stamping "now" on it would make every turnaround figure
                 * wrong by however long this integration took to arrive.
                 */
                'closed_at' => $input->crmStage?->isClosed() === true ? $input->crmClosedAt : null,
                'value_minor' => $input->valueMinor,
                'currency' => $input->valueMinor === null ? null : $input->currency,
                'attribution_evidence' => $input->attribution->evidence->value,
                'utm_source' => $input->attribution->source,
                'utm_medium' => $input->attribution->medium,
                'utm_campaign' => $input->attribution->campaign,
                'utm_content' => $input->attribution->content,
                'utm_term' => $input->attribution->term,
                'click_identifier' => $input->attribution->clickIdentifier,
                'landing_page' => $input->landingPage,
                'referrer' => $input->attribution->referrer,
                'received_at' => $input->receivedAt,
            ]);

            $this->recordEvent($lead, 'received', occurredAt: $input->receivedAt);

            return $this->toView($this->reload($lead));
        });
    }

    public function find(string $workspaceId, ?array $visibleProjectIds, string $id): ?LeadView
    {
        $lead = $this->visibleQuery($workspaceId, $visibleProjectIds)
            ->with(self::RELATIONS)
            ->whereKey($id)
            ->first();

        return $lead instanceof Lead ? $this->toView($lead) : null;
    }

    /** @return list<LeadView> */
    public function visibleTo(
        string $workspaceId,
        ?array $visibleProjectIds,
        ?string $clientId = null,
        array $stages = [],
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $until = null,
        int $limit = 100,
    ): array {
        $query = $this->visibleQuery($workspaceId, $visibleProjectIds)->with(self::RELATIONS);

        if ($clientId !== null) {
            $query->where('client_id', $clientId);
        }

        if ($stages !== []) {
            $query->whereIn('stage', array_map(static fn (LeadStage $stage): string => $stage->value, $stages));
        }

        if ($from !== null) {
            $query->where('received_at', '>=', $from);
        }

        if ($until !== null) {
            $query->where('received_at', '<=', $until);
        }

        $leads = $query->orderByDesc('received_at')->limit(max(1, min($limit, 500)))->get();

        return array_values($leads->map(fn (Lead $lead): LeadView => $this->toView($lead))->all());
    }

    public function changeStage(
        string $workspaceId,
        string $id,
        LeadStage $stage,
        string $actorId,
        ?string $reason = null,
        ?string $note = null,
    ): LeadView {
        return DB::transaction(function () use ($workspaceId, $id, $stage, $actorId, $reason, $note): LeadView {
            /** @var Lead $lead */
            $lead = Lead::query()->where('workspace_id', $workspaceId)->whereKey($id)->firstOrFail();

            /** @var LeadStage $from */
            $from = $lead->getAttribute('stage');
            $now = $this->clock->now();

            $attributes = [
                'stage' => $stage->value,
                /*
                 * Only a disqualification keeps a reason. Carrying one onto a later stage would leave "not a
                 * real enquiry" attached to a lead that was subsequently won.
                 */
                'disqualified_reason' => $stage === LeadStage::Disqualified ? $reason : null,
            ];

            /*
             * The timestamps are facts about the transition, set here rather than by a caller. The database
             * refuses a closed lead with no closing time, so a caller that forgot would fail — which is
             * better than a pipeline where half the rows cannot be timed.
             */
            if ($stage->isReached() && $lead->getAttribute('first_contacted_at') === null) {
                $attributes['first_contacted_at'] = $now;
            }

            if ($stage === LeadStage::Qualified && $lead->getAttribute('qualified_at') === null) {
                $attributes['qualified_at'] = $now;
            }

            $attributes['closed_at'] = $stage->isClosed()
                ? ($lead->getAttribute('closed_at') ?? $now)
                /*
                 * Reopened. The closing time goes, because a lead that is open has not closed — and leaving a
                 * stale one would make every cycle-time figure quietly wrong.
                 */
                : null;

            $lead->update($attributes);

            $this->recordEvent(
                $lead,
                'stage_changed',
                actorId: $actorId,
                fromStage: $from,
                toStage: $stage,
                note: $note ?? $reason,
            );

            return $this->toView($this->reload($lead));
        });
    }

    public function assign(string $workspaceId, string $id, ?string $ownerId, string $actorId): LeadView
    {
        return DB::transaction(function () use ($workspaceId, $id, $ownerId, $actorId): LeadView {
            /** @var Lead $lead */
            $lead = Lead::query()->where('workspace_id', $workspaceId)->whereKey($id)->firstOrFail();

            $lead->update(['owner_id' => $ownerId]);
            $this->recordEvent($lead, 'assigned', actorId: $actorId);

            return $this->toView($this->reload($lead));
        });
    }

    public function setValue(
        string $workspaceId,
        string $id,
        ?int $valueMinor,
        ?string $currency,
        string $actorId,
    ): LeadView {
        return DB::transaction(function () use ($workspaceId, $id, $valueMinor, $currency, $actorId): LeadView {
            /** @var Lead $lead */
            $lead = Lead::query()->where('workspace_id', $workspaceId)->whereKey($id)->firstOrFail();

            $lead->update([
                'value_minor' => $valueMinor,
                // A value with no currency is a number with no unit, and the schema refuses it.
                'currency' => $valueMinor === null ? null : strtoupper((string) $currency),
            ]);

            $this->recordEvent($lead, 'value_set', actorId: $actorId);

            return $this->toView($this->reload($lead));
        });
    }

    public function addNote(string $workspaceId, string $id, string $note, string $actorId): LeadView
    {
        return DB::transaction(function () use ($workspaceId, $id, $note, $actorId): LeadView {
            /** @var Lead $lead */
            $lead = Lead::query()->where('workspace_id', $workspaceId)->whereKey($id)->firstOrFail();

            $this->recordEvent($lead, 'note_added', actorId: $actorId, note: $note);

            return $this->toView($this->reload($lead));
        });
    }

    /**
     * A lead this product has already recorded, if any.
     *
     * Two routes: the provider's own id, which is exact, and a normalised contact point inside the
     * deduplication window, which is a judgement. Nothing matches on a name — two people called Mohammed
     * Al-Harbi are two people, and merging them would put one client's enquiry in another's record.
     */
    private function findExisting(string $workspaceId, LeadInput $input, ContactPoint $contact): ?Lead
    {
        if ($input->externalId !== null && $input->externalId !== '') {
            $byExternalId = Lead::query()
                ->where('workspace_id', $workspaceId)
                ->where('source_kind', $input->sourceKind->value)
                ->where('external_id', $input->externalId)
                ->first();

            if ($byExternalId instanceof Lead) {
                return $byExternalId;
            }
        }

        $key = $contact->matchKey();

        if ($key === null) {
            /*
             * No way to reach this person, so no way to recognise them again. Recorded as a new lead: an
             * anonymous enquiry is still an enquiry, and refusing it would lose a real one.
             */
            return null;
        }

        $since = $input->receivedAt->modify('-'.self::DEDUPLICATION_WINDOW_HOURS.' hours');

        $match = Lead::query()
            ->where('workspace_id', $workspaceId)
            ->where('client_id', $input->clientId)
            ->where('received_at', '>=', $since)
            ->where(function (Builder $scope) use ($contact): void {
                if ($contact->phone !== null) {
                    $scope->orWhere('phone_key', $contact->phone);
                }

                if ($contact->email !== null) {
                    $scope->orWhere('email_key', $contact->email);
                }
            })
            ->orderByDesc('received_at')
            ->first();

        return $match instanceof Lead ? $match : null;
    }

    /**
     * Fill in what the first arrival did not carry.
     *
     * Only ever fills blanks. A follow-up often arrives with the campaign parameters the first submission
     * lost — an in-app browser strips them, then the customer opens the site properly — and taking them is
     * strictly better than a lead stuck as unattributed. Overwriting a value that was already there would be
     * the opposite: the first touch is the one that earned the credit.
     */
    private function enrich(Lead $lead, LeadInput $input, ContactPoint $contact): void
    {
        $attributes = [];

        foreach ([
            'name' => $input->name,
            'email' => $input->email,
            'phone' => $input->phone,
            'email_key' => $contact->email,
            'phone_key' => $contact->phone,
            'message' => $input->message,
            'landing_page' => $input->landingPage,
        ] as $column => $value) {
            if ($value !== null && $value !== '' && $lead->getAttribute($column) === null) {
                $attributes[$column] = $value;
            }
        }

        /** @var AttributionEvidence $current */
        $current = $lead->getAttribute('attribution_evidence');

        /*
         * Attribution is replaced only when the new arrival is *stronger*, and then wholly rather than field
         * by field. A half-merged attribution — this submission's click id beside the previous one's campaign
         * — would be a link to a campaign nobody can verify, which is precisely what the evidence levels
         * exist to prevent.
         */
        if ($input->attribution->evidence->confidence() > $current->confidence()) {
            $attributes = [
                ...$attributes,
                'attribution_evidence' => $input->attribution->evidence->value,
                'utm_source' => $input->attribution->source,
                'utm_medium' => $input->attribution->medium,
                'utm_campaign' => $input->attribution->campaign,
                'utm_content' => $input->attribution->content,
                'utm_term' => $input->attribution->term,
                'click_identifier' => $input->attribution->clickIdentifier,
                'referrer' => $input->attribution->referrer,
            ];
        }

        /*
         * The stage, but only where another system owns it.
         *
         * This is the one case where a later arrival overwrites rather than fills a gap, and it is the opposite
         * of the rule above. For a form submission or a phone call a human owns the stage and a re-read must not
         * touch it; for a CRM-sourced lead the CRM owns it, so a re-read is the authoritative answer and leaving
         * the old one would show a deal as open after it was won.
         *
         * `source_detail` moves with it, because it carries the CRM's own stage label — a lead marked won here
         * while still saying "Mudawalat" beside it would be two claims about the same thing.
         */
        if ($input->crmStage !== null) {
            /** @var LeadStage $from */
            $from = $lead->getAttribute('stage');

            if ($from !== $input->crmStage) {
                $attributes['stage'] = $input->crmStage->value;
                $attributes['source_detail'] = $input->sourceDetail;
                /* And its closing time, or its absence — the schema requires the pair to agree. */
                $attributes['closed_at'] = $input->crmStage->isClosed() ? $input->crmClosedAt : null;

                /*
                 * Recorded as an event with no actor, which is what makes the trail readable afterwards: "who
                 * moved this" has the honest answer that nobody here did.
                 */
                $this->recordEvent($lead, 'stage_changed', fromStage: $from, toStage: $input->crmStage);
            }
        }

        if ($attributes !== []) {
            $lead->update($attributes);
        }
    }

    private function recordEvent(
        Lead $lead,
        string $type,
        ?string $actorId = null,
        ?LeadStage $fromStage = null,
        ?LeadStage $toStage = null,
        ?string $note = null,
        ?DateTimeImmutable $occurredAt = null,
    ): void {
        LeadEvent::query()->create([
            'workspace_id' => $lead->getAttribute('workspace_id'),
            'lead_id' => $lead->getKey(),
            'actor_id' => $actorId,
            'type' => $type,
            'from_stage' => $fromStage?->value,
            'to_stage' => $toStage?->value,
            'note' => $note,
            'occurred_at' => $occurredAt ?? $this->clock->now(),
        ]);
    }

    /**
     * Leads this actor may see, applied in SQL.
     *
     * A lead belongs to a client, and a person reaches a client through a project — the same rule the
     * measurement sources and the store analytics use, expressed the same way so the three cannot disagree
     * about who can see what.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return Builder<Lead>
     */
    private function visibleQuery(string $workspaceId, ?array $visibleProjectIds): Builder
    {
        $query = Lead::query()->where('workspace_id', $workspaceId);

        if ($visibleProjectIds === null) {
            return $query;
        }

        if ($visibleProjectIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('client_id', function (QueryBuilder $sub) use ($visibleProjectIds, $workspaceId): void {
            $sub->select('client_id')
                ->from('projects')
                ->where('workspace_id', $workspaceId)
                ->whereIn('id', $visibleProjectIds);
        });
    }

    private function reload(Lead $lead): Lead
    {
        /** @var Lead */
        return Lead::query()->with(self::RELATIONS)->whereKey($lead->getKey())->firstOrFail();
    }

    private function toView(Lead $lead): LeadView
    {
        /** @var LeadSourceKind $sourceKind */
        $sourceKind = $lead->getAttribute('source_kind');
        /** @var LeadStage $stage */
        $stage = $lead->getAttribute('stage');
        /** @var AttributionEvidence $evidence */
        $evidence = $lead->getAttribute('attribution_evidence');
        /** @var DateTimeImmutable $receivedAt */
        $receivedAt = $lead->getAttribute('received_at');

        return new LeadView(
            id: (string) $lead->getKey(),
            clientId: (string) $lead->getAttribute('client_id'),
            clientName: (string) ($lead->client?->getAttribute('name') ?? 'Unknown client'),
            projectId: $lead->getAttribute('project_id') === null ? null : (string) $lead->getAttribute('project_id'),
            sourceKind: $sourceKind,
            sourceDetail: $lead->getAttribute('source_detail'),
            stage: $stage,
            disqualifiedReason: $lead->getAttribute('disqualified_reason'),
            ownerId: $lead->getAttribute('owner_id') === null ? null : (string) $lead->getAttribute('owner_id'),
            ownerName: $lead->owner?->getAttribute('name'),
            name: $lead->getAttribute('name'),
            email: $lead->getAttribute('email'),
            phone: $lead->getAttribute('phone'),
            message: $lead->getAttribute('message'),
            valueMinor: $lead->getAttribute('value_minor'),
            currency: $lead->getAttribute('currency'),
            evidence: $evidence,
            utmSource: $lead->getAttribute('utm_source'),
            utmCampaign: $lead->getAttribute('utm_campaign'),
            receivedAt: $receivedAt,
            firstContactedAt: $lead->getAttribute('first_contacted_at'),
            qualifiedAt: $lead->getAttribute('qualified_at'),
            closedAt: $lead->getAttribute('closed_at'),
        );
    }
}
