<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Leads\Contract\LeadRepository;
use App\Application\Leads\DTO\LeadDuplicateRow;
use App\Application\Leads\DTO\LeadFilter;
use App\Application\Leads\DTO\LeadHistoryEntry;
use App\Application\Leads\DTO\LeadInput;
use App\Application\Leads\DTO\LeadView;
use App\Application\Shared\Contract\Clock;
use App\Domain\Commerce\AttributionEvidence;
use App\Domain\Leads\LeadMergePlan;
use App\Domain\Leads\LeadScope;
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
                'custom' => $input->custom,
            ]);

            $this->recordEvent($lead, 'received', occurredAt: $input->receivedAt);

            return $this->toView($this->reload($lead));
        });
    }

    public function find(string $workspaceId, ?array $visibleProjectIds, string $id): ?LeadView
    {
        $lead = $this->visibleQuery($workspaceId, $visibleProjectIds, includeMergedAway: true)
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
        ?LeadFilter $filter = null,
    ): array {
        $query = $this->visibleQuery($workspaceId, $visibleProjectIds)->with(self::RELATIONS);

        if ($filter !== null) {
            $this->narrow($query, $workspaceId, $filter);
        }

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

        /*
         * One grouped count for the page, not one per row. `toView` counts a single lead's absorbed records
         * itself, which is right for a single lead and a statement per card on a list of fifty.
         */
        $absorbed = $this->absorbedCounts($workspaceId, array_values($leads->map(static fn (Lead $l): string => (string) $l->getKey())->all()));

        return array_values($leads->map(
            fn (Lead $lead): LeadView => $this->toView($lead, $absorbed[(string) $lead->getKey()] ?? 0),
        )->all());
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

    public function recordMilestone(string $workspaceId, string $id, string $type, string $actorId, ?string $note, ?DateTimeImmutable $at): void
    {
        /** @var Lead $lead */
        $lead = Lead::query()->where('workspace_id', $workspaceId)->whereKey($id)->firstOrFail();

        $this->recordEvent($lead, $type, actorId: $actorId, note: $note, occurredAt: $at);
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

    /** @return list<LeadHistoryEntry> */
    public function historyOf(
        string $workspaceId,
        ?array $visibleProjectIds,
        string $id,
        int $limit = 50,
    ): array {
        $lead = $this->visibleQuery($workspaceId, $visibleProjectIds, includeMergedAway: true)
            ->whereKey($id)
            ->first();

        if (! $lead instanceof Lead) {
            return [];
        }

        $rows = DB::table('lead_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.actor_id')
            ->where('e.workspace_id', $workspaceId)
            ->where('e.lead_id', $id)
            /*
             * Newest first, and by id after the time: a merge writes two events at the same instant, and a
             * list whose order changes between two reads of the same data is a list nobody can cite.
             */
            ->orderByDesc('e.occurred_at')
            ->orderByDesc('e.id')
            ->limit(max(1, min($limit, 200)))
            ->get(['e.id', 'e.type', 'e.from_stage', 'e.to_stage', 'e.note', 'e.occurred_at', 'u.name']);

        return array_values($rows->map(static function (object $row): LeadHistoryEntry {
            $type = (string) $row->type;

            return new LeadHistoryEntry(
                id: (string) $row->id,
                type: $type,
                typeLabel: match ($type) {
                    'received' => 'Arrived',
                    'contacted' => 'Contacted',
                    'stage_changed' => 'Moved',
                    'note_added' => 'Note',
                    'assigned' => 'Owner changed',
                    'value_set' => 'Value set',
                    'merged' => 'Merged',
                    /*
                     * A type the database allows and this list has not been taught. Shown as itself rather than
                     * dropped: an event missing from a history is worse than one with an ugly name.
                     */
                    default => $type,
                },
                /* Null means the system did it. "Who moved this" has the honest answer that nobody here did. */
                actorName: is_string($row->name) ? $row->name : null,
                fromStage: is_string($row->from_stage) ? LeadStage::tryFrom($row->from_stage) : null,
                toStage: is_string($row->to_stage) ? LeadStage::tryFrom($row->to_stage) : null,
                note: is_string($row->note) ? $row->note : null,
                occurredAt: new DateTimeImmutable((string) $row->occurred_at),
            );
        })->all());
    }

    /** @return list<LeadDuplicateRow> */
    public function duplicatesOf(
        string $workspaceId,
        ?array $visibleProjectIds,
        string $id,
        int $limit = 10,
    ): array {
        $lead = $this->visibleQuery($workspaceId, $visibleProjectIds, includeMergedAway: true)
            ->whereKey($id)
            ->first();

        if (! $lead instanceof Lead) {
            return [];
        }

        $emailKey = $lead->getAttribute('email_key');
        $phoneKey = $lead->getAttribute('phone_key');

        if ($emailKey === null && $phoneKey === null) {
            /*
             * No contact point, so nothing this product is willing to match on. An empty list rather than a
             * list of leads that merely arrived nearby: a suggestion with no evidence is one somebody accepts
             * without thinking, and a merge cannot be undone.
             */
            return [];
        }

        /*
         * Same client, deliberately. A merge across clients is refused outright — it would move one client's
         * enquiry into another's count — so offering candidates from elsewhere would only produce a refusal the
         * reader could not have predicted.
         */
        $candidates = $this->visibleQuery($workspaceId, $visibleProjectIds)
            ->where('client_id', $lead->getAttribute('client_id'))
            ->whereKeyNot($id)
            ->where(function (Builder $scope) use ($emailKey, $phoneKey): void {
                if ($emailKey !== null) {
                    $scope->orWhere('email_key', $emailKey);
                }

                if ($phoneKey !== null) {
                    $scope->orWhere('phone_key', $phoneKey);
                }
            })
            ->orderByDesc('received_at')
            ->limit(max(1, min($limit, 50)))
            ->get();

        return array_values($candidates->map(
            function (Lead $candidate) use ($emailKey, $phoneKey): LeadDuplicateRow {
                $byEmail = $emailKey !== null && $candidate->getAttribute('email_key') === $emailKey;
                $byPhone = $phoneKey !== null && $candidate->getAttribute('phone_key') === $phoneKey;

                /** @var LeadSourceKind $sourceKind */
                $sourceKind = $candidate->getAttribute('source_kind');
                /** @var LeadStage $stage */
                $stage = $candidate->getAttribute('stage');
                /** @var DateTimeImmutable $receivedAt */
                $receivedAt = $candidate->getAttribute('received_at');

                /*
                 * Which contact point matched, named exactly. "Both" is a stronger claim than either and is
                 * worth distinguishing, because it is the case where a reader can accept without checking.
                 */
                [$matchedOn, $label] = match (true) {
                    $byEmail && $byPhone => ['email_and_phone', 'Same email address and phone number'],
                    $byEmail => ['email', 'Same email address'],
                    default => ['phone', 'Same phone number'],
                };

                return new LeadDuplicateRow(
                    id: (string) $candidate->getKey(),
                    sourceKind: $sourceKind,
                    sourceDetail: $candidate->getAttribute('source_detail'),
                    stage: $stage,
                    name: $candidate->getAttribute('name'),
                    email: $candidate->getAttribute('email'),
                    phone: $candidate->getAttribute('phone'),
                    receivedAt: $receivedAt,
                    matchedOn: $matchedOn,
                    matchedOnLabel: $label,
                );
            },
        )->all());
    }

    public function merge(
        string $workspaceId,
        LeadMergePlan $plan,
        LeadStage $stage,
        string $actorId,
        ?string $note = null,
    ): LeadView {
        return DB::transaction(function () use ($workspaceId, $plan, $stage, $actorId, $note): LeadView {
            /** @var Lead $survivor */
            $survivor = Lead::query()->where('workspace_id', $workspaceId)
                ->whereKey($plan->survivor->id)->lockForUpdate()->firstOrFail();
            /** @var Lead $absorbed */
            $absorbed = Lead::query()->where('workspace_id', $workspaceId)
                ->whereKey($plan->absorbed->id)->lockForUpdate()->firstOrFail();

            $now = $this->clock->now();
            $attributes = ['stage' => $stage->value];

            /*
             * Contact details and the message fill blanks only, field by field — and that is not the
             * half-merge the attribution rule forbids. Each of these is an independent fact about one person:
             * knowing their phone from one enquiry and their email from another leaves both true. An
             * attribution is a single claim about where they came from, which is why it moves whole or not at
             * all.
             */
            foreach (['name', 'email', 'phone', 'email_key', 'phone_key', 'message', 'landing_page'] as $column) {
                $value = $absorbed->getAttribute($column);

                if ($value !== null && $value !== '' && $survivor->getAttribute($column) === null) {
                    $attributes[$column] = $value;
                }
            }

            /* An unowned lead takes the other's owner; an owned one keeps its own. */
            if ($survivor->getAttribute('owner_id') === null && $absorbed->getAttribute('owner_id') !== null) {
                $attributes['owner_id'] = $absorbed->getAttribute('owner_id');
            }

            /*
             * The value of the lead, only where the survivor has none and the currencies cannot disagree. Two
             * stated values for one person are two people's judgements and this is not the place to pick
             * between them — the merge note carries the absorbed figure so it is not lost. Nothing is
             * converted: a value in another currency is a different number, not the same one.
             */
            if ($survivor->getAttribute('value_minor') === null && $absorbed->getAttribute('value_minor') !== null) {
                $attributes['value_minor'] = $absorbed->getAttribute('value_minor');
                $attributes['currency'] = $absorbed->getAttribute('currency');
            }

            if ($plan->inheritsAttribution) {
                /* Wholly. See `LeadMergePlan`. */
                foreach ([
                    'attribution_evidence', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content',
                    'utm_term', 'click_identifier', 'referrer',
                ] as $column) {
                    $attributes[$column] = $absorbed->getAttribute($column);
                }
            }

            if ($plan->inheritsReceivedAt) {
                /* The age of the enquiry outlives the record that carried it. */
                $attributes['received_at'] = $absorbed->getAttribute('received_at');
            }

            /*
             * The earliest of each milestone, because each is a "did this happen, and when" fact about one
             * person: if somebody was contacted on the Tuesday, they were contacted on the Tuesday, whichever
             * record recorded it.
             */
            foreach (['first_contacted_at', 'qualified_at'] as $column) {
                $earliest = $this->earlier($survivor->getAttribute($column), $absorbed->getAttribute($column));

                if ($earliest !== null) {
                    $attributes[$column] = $earliest;
                }
            }

            /*
             * The closing time comes from whichever lead is in the chosen stage, never from the clock. The
             * schema requires a closed lead to have one and an open lead not to, and inventing "now" would
             * turn every merge into a closure dated the day of the merge.
             */
            $holder = $plan->stageIsHeldBy($stage)->id === (string) $survivor->getKey() ? $survivor : $absorbed;
            $attributes['closed_at'] = $stage->isClosed() ? $holder->getAttribute('closed_at') : null;
            $attributes['disqualified_reason'] = $stage === LeadStage::Disqualified
                ? $holder->getAttribute('disqualified_reason')
                : null;

            $survivor->update($attributes);

            /*
             * The history moves rather than being copied: a lead's events are the record of what happened to a
             * person, and that person now has one record. Both `merged` events are written afterwards so they
             * are last in the trail.
             */
            LeadEvent::query()
                ->where('workspace_id', $workspaceId)
                ->where('lead_id', $absorbed->getKey())
                ->update(['lead_id' => $survivor->getKey()]);

            $absorbed->update([
                'merged_into_id' => $survivor->getKey(),
                'merged_at' => $now,
                'merged_by_id' => $actorId,
            ]);

            $summary = sprintf(
                'Merged with the lead received %s%s',
                $plan->absorbed->receivedAt->format('Y-m-d'),
                $note === null || trim($note) === '' ? '' : '. '.trim($note),
            );

            if ($absorbed->getAttribute('value_minor') !== null
                && $survivor->getAttribute('value_minor') !== $absorbed->getAttribute('value_minor')) {
                /*
                 * Written down rather than dropped. A figure somebody stated about this person is part of the
                 * history even when it is not the one the merged record carries.
                 */
                $summary .= sprintf(
                    ' The absorbed lead was valued at %s %s.',
                    number_format(((int) $absorbed->getAttribute('value_minor')) / 100, 2),
                    (string) ($absorbed->getAttribute('currency') ?? ''),
                );
            }

            $this->recordEvent($survivor, 'merged', actorId: $actorId, note: $summary, occurredAt: $now);
            $this->recordEvent(
                $absorbed,
                'merged',
                actorId: $actorId,
                note: 'Merged into the lead received '.$plan->survivor->receivedAt->format('Y-m-d')
                    .', which is where this person\'s history continues.',
                occurredAt: $now,
            );

            return $this->toView($this->reload($survivor));
        });
    }

    /** The earlier of two moments, where either exists. */
    private function earlier(?DateTimeImmutable $first, ?DateTimeImmutable $second): ?DateTimeImmutable
    {
        if ($first === null) {
            return $second;
        }

        if ($second === null) {
            return $first;
        }

        return $first <= $second ? $first : $second;
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

        /* Answers a follow-up adds are kept; one already given is never overwritten by a later arrival. */
        $custom = is_array($lead->getAttribute('custom')) ? $lead->getAttribute('custom') : [];
        $merged = [...$input->custom, ...$custom];

        if ($merged !== $custom) {
            $attributes['custom'] = $merged;
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
    private function visibleQuery(
        string $workspaceId,
        ?array $visibleProjectIds,
        bool $includeMergedAway = false,
    ): Builder {
        $query = Lead::query()->where('workspace_id', $workspaceId);

        /*
         * A lead merged into another counts nowhere. It is kept for its history and its provider id — see the
         * merge migration — and leaving it in a list would show the same person twice in the pipeline the merge
         * existed to fix. `find()` is the exception: a reader following an old link needs the record itself, so
         * the surface can send them to the survivor rather than a 404.
         */
        if (! $includeMergedAway) {
            $query->whereNull('merged_into_id');
        }

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

    /** @param  Builder<Lead>  $query */
    private function narrow(Builder $query, string $workspaceId, LeadFilter $filter): void
    {
        $actor = $filter->actorId;

        match ($filter->scope) {
            LeadScope::All => null,
            /* Without an actor there is no "my" — an empty result, never a silent fall back to everything. */
            LeadScope::Mine => $actor === null ? $query->whereRaw('1 = 0') : $query->where('owner_id', $actor),
            LeadScope::MyProjects => $actor === null ? $query->whereRaw('1 = 0') : $query->whereIn(
                'project_id',
                static fn (QueryBuilder $sub) => $sub->select('project_id')->from('project_members')->where('user_id', $actor),
            ),
            LeadScope::MyClients => $actor === null ? $query->whereRaw('1 = 0') : $query->whereIn(
                'client_id',
                static fn (QueryBuilder $sub) => $sub->select('p.client_id')->from('projects as p')
                    ->join('project_members as m', 'm.project_id', '=', 'p.id')
                    ->where('p.workspace_id', $workspaceId)->where('m.user_id', $actor),
            ),
        };

        if ($filter->projectId !== null) {
            $query->where('project_id', $filter->projectId);
        }

        if ($filter->ownerId !== null) {
            $query->where('owner_id', $filter->ownerId);
        }

        if ($filter->sourceKind !== null) {
            $query->where('source_kind', $filter->sourceKind);
        }

        if ($filter->campaign !== null) {
            $query->where('utm_campaign', $filter->campaign);
        }
    }

    private function reload(Lead $lead): Lead
    {
        /** @var Lead */
        return Lead::query()->with(self::RELATIONS)->whereKey($lead->getKey())->firstOrFail();
    }

    /**
     * @param  list<string>  $leadIds
     * @return array<string, int>
     */
    private function absorbedCounts(string $workspaceId, array $leadIds): array
    {
        if ($leadIds === []) {
            return [];
        }

        $out = [];

        foreach (Lead::query()->where('workspace_id', $workspaceId)->whereIn('merged_into_id', $leadIds)
            ->selectRaw('merged_into_id, count(*) as absorbed')->groupBy('merged_into_id')->get() as $row) {
            $out[(string) $row->getAttribute('merged_into_id')] = (int) $row->getAttribute('absorbed');
        }

        return $out;
    }

    private function toView(Lead $lead, ?int $absorbed = null): LeadView
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
            mergedIntoId: $lead->getAttribute('merged_into_id') === null
                ? null
                : (string) $lead->getAttribute('merged_into_id'),
            mergedAt: $lead->getAttribute('merged_at'),
            custom: $this->customOf($lead),
            /*
             * Counted rather than loaded. A surface needs to say "two other enquiries are on this record" so a
             * reader is not surprised by a history longer than one arrival; it does not need the rows.
             */
            absorbedCount: $absorbed ?? (int) Lead::query()
                ->where('workspace_id', $lead->getAttribute('workspace_id'))
                ->where('merged_into_id', $lead->getKey())
                ->count(),
        );
    }

    /** @return array<string, string> */
    private function customOf(Lead $lead): array
    {
        $raw = $lead->getAttribute('custom');
        $out = [];

        foreach (is_array($raw) ? $raw : [] as $key => $value) {
            if (is_scalar($value)) {
                $out[(string) $key] = (string) $value;
            }
        }

        return $out;
    }
}
