<?php

declare(strict_types=1);

namespace App\Application\Leads\Command;

use App\Application\Leads\Contract\CrmReadAdapter;
use App\Application\Leads\DTO\CrmDeal;
use App\Application\Leads\DTO\CrmPipeline;
use App\Application\Leads\DTO\LeadInput;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Advertising\MetricSource;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Commerce\OrderAttribution;
use App\Domain\Integration\Provider;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use App\Domain\Leads\CrmStageMeaning;
use App\Domain\Leads\LeadSourceKind;
use App\Domain\Workspace\WorkspaceId;
use DateTimeImmutable;

/**
 * Read a CRM's deals and record them as leads.
 *
 * Four decisions, and each one is a refusal to invent something.
 *
 * **Pipelines first.** A deal's stage is an internal id; what it means lives on the stage, and a stage's meaning
 * comes from its published metadata rather than its name. A sync that read deals alone would hold ids it could
 * only interpret by their labels, which is the mistake the whole bridge exists to avoid.
 *
 * **A stage this product cannot read is left alone.** `CrmStageMeaning::Open` maps to no `LeadStage`, so a lead
 * in a middle stage keeps whatever stage it has here and carries the CRM's own label beside it. Overwriting a
 * stage somebody set with a guess from a label would be worse than leaving it.
 *
 * **A won deal's amount is the lead's value; an open deal's is not.** An open deal's amount is a quote — a hope,
 * and often a round number somebody typed — and a pipeline total built from hopes is the figure that makes a
 * forecast indefensible. Only a closed-won amount is recorded as value.
 *
 * **Nothing is attributed.** A CRM holds no click identifier and no UTM parameters, so every lead from one
 * arrives with `OrderAttribution::none()`. Guessing a campaign from a deal's name is exactly the false
 * assignment this product refuses everywhere else.
 */
final readonly class SyncCrmDeals
{
    /**
     * How far back a sync with no high-water mark reaches.
     *
     * Thirty days rather than everything: a first sync that pulled a CRM's entire history would import deals
     * closed years ago as fresh leads, and every figure measured "since the lead arrived" would be wrong for all
     * of them. A longer history is an import, which is a different operation with somebody's attention on it.
     */
    public const int FIRST_SYNC_DAYS = 30;

    public function __construct(
        private CrmReadAdapter $crm,
        private ProviderConnectionRepository $connections,
        private RecordLead $record,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    /**
     * @return array{read: int, recorded: int, left_alone: int, source: string, source_label: string}
     */
    public function __invoke(string $clientId, ?DateTimeImmutable $since = null): array
    {
        /*
         * `leads.manage` rather than a sync permission of its own: this writes leads, and the permission that
         * governs a lead is the permission that governs a lead however it arrived.
         */
        $this->guard->ensure(Permission::LeadsManage);

        $workspaceId = $this->actor->workspaceId()->value;

        $connection = $this->connections->agencyConnection(new WorkspaceId($workspaceId), Provider::HubSpot)
            ?? throw ResourceNotFound::of('CRM connection');

        $credentials = $connection->credentials();
        /*
         * What kind of figures these are, decided before any of them is read.
         *
         * Every other sync in this product returns its source state, and this one did not — so a reader was told
         * "12 deals read" with nothing saying they came from fixtures. A sandbox figure presented without its
         * label is the single thing this product's honesty rules exist to prevent, and a count is as capable of
         * being quoted to a client as a chart is.
         */
        $source = $credentials->isSandbox(Provider::HubSpot->requiredCredentialKeys())
            ? MetricSource::Sandbox
            : MetricSource::Synced;

        $pipelines = $this->crm->pipelines($credentials);

        $from = $since ?? $this->clock->now()->modify('-'.self::FIRST_SYNC_DAYS.' days');
        $deals = $this->crm->deals($credentials, $from);

        $recorded = 0;
        $leftAlone = 0;

        foreach ($deals as $deal) {
            $meaning = $this->meaningOf($deal, $pipelines);

            if ($meaning === CrmStageMeaning::Open) {
                /*
                 * Counted rather than hidden. "How many deals did we read and decline to interpret" is the figure
                 * that tells somebody the bridge is working as designed — a sync reporting only the stages it
                 * understood would look like a sync that read almost nothing.
                 */
                $leftAlone++;
            }

            ($this->record)($this->leadFrom($clientId, $deal, $pipelines, $meaning));
            $recorded++;
        }

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::LeadRecorded,
            auditableType: 'client',
            auditableId: $clientId,
            summary: sprintf('Read %d deal(s) from %s', count($deals), Provider::HubSpot->label()),
            changes: [
                'provider' => Provider::HubSpot->value,
                'since' => $from->format(DateTimeImmutable::ATOM),
                'read' => count($deals),
                'recorded' => $recorded,
                /* Deals whose stage this product declined to interpret. See `CrmStageMeaning`. */
                'left_at_their_stage' => $leftAlone,
                /* In the trail for ever, because "was that real?" is asked months later. */
                'source' => $source->value,
            ],
        ));

        return [
            'read' => count($deals),
            'recorded' => $recorded,
            'left_alone' => $leftAlone,
            'source' => $source->value,
            'source_label' => $source->label(),
        ];
    }

    /**
     * What this deal's stage means, from the pipeline it is in.
     *
     * A deal naming a pipeline or a stage this product did not read is **open**, not an error: a pipeline added
     * between two requests is ordinary, and the honest answer about a stage nobody has the metadata for is that
     * nothing is known about it.
     *
     * @param  list<CrmPipeline>  $pipelines
     */
    private function meaningOf(CrmDeal $deal, array $pipelines): CrmStageMeaning
    {
        foreach ($pipelines as $pipeline) {
            if ($pipeline->id !== $deal->pipelineId) {
                continue;
            }

            return $pipeline->stage($deal->stageId)->meaning ?? CrmStageMeaning::Open;
        }

        return CrmStageMeaning::Open;
    }

    /**
     * One deal as a lead.
     *
     * @param  list<CrmPipeline>  $pipelines
     */
    private function leadFrom(
        string $clientId,
        CrmDeal $deal,
        array $pipelines,
        CrmStageMeaning $meaning,
    ): LeadInput {
        $contact = $deal->contacts[0] ?? null;
        $stageLabel = $this->stageLabel($deal, $pipelines);

        return new LeadInput(
            /*
             * Supplied by the caller, and that is a limitation stated rather than hidden: a HubSpot portal is one
             * company's CRM, so mapping its deals onto an agency's several clients needs a binding somebody made
             * deliberately. Nothing here infers a client from a company name.
             */
            clientId: $clientId,
            sourceKind: LeadSourceKind::Crm,
            receivedAt: $deal->createdAt,
            /*
             * Never attributed. A CRM holds no click identifier and no UTM parameters, and reading a campaign out
             * of a deal's name would be the false assignment this product refuses everywhere else.
             */
            attribution: OrderAttribution::none(),
            /*
             * The CRM's own stage label, carried as the source detail so a reader can see where the lead stands
             * in the system that owns it — beside this product's own stage rather than instead of it.
             */
            sourceDetail: sprintf('%s · %s', Provider::HubSpot->label(), $stageLabel),
            externalId: $deal->id,
            name: $contact->name ?? $deal->name,
            email: $contact?->email,
            phone: $contact?->phone,
            /*
             * Only a closed-won amount. An open deal's amount is a quote, and a pipeline total built from quotes
             * is the figure that makes a forecast indefensible.
             */
            valueMinor: $meaning === CrmStageMeaning::Won ? $deal->amountMinor : null,
            currency: $meaning === CrmStageMeaning::Won ? $deal->currency : null,
            /*
             * Null for an open deal, which leaves the lead where it is. `CrmStageMeaning::Open` maps to no stage
             * of this product's own, and overwriting one somebody set with a guess from a label would be worse
             * than leaving it alone.
             */
            crmStage: $meaning->stage(),
            /*
             * The CRM's own close date where it published one, and the deal's last modification where it did not:
             * a deal that closed without a date was still closed when somebody last touched it, which is the
             * nearest true statement available. Never the sync's clock.
             */
            crmClosedAt: $meaning === CrmStageMeaning::Open ? null : ($deal->closeDate ?? $deal->updatedAt),
        );
    }

    /** @param list<CrmPipeline> $pipelines */
    private function stageLabel(CrmDeal $deal, array $pipelines): string
    {
        foreach ($pipelines as $pipeline) {
            if ($pipeline->id === $deal->pipelineId) {
                return $pipeline->stage($deal->stageId)->label ?? $deal->stageId;
            }
        }

        /* The id, rather than a guess at a name. An unrecognised stage is better shown than described. */
        return $deal->stageId;
    }
}
