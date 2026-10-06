<?php

declare(strict_types=1);

namespace App\Application\Measurement\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Measurement\Contract\MeasurementSourceRepository;
use App\Application\Measurement\DTO\MeasurementSourceInput;
use App\Application\Measurement\DTO\MeasurementSourceView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Integration\ProviderCategory;
use App\Domain\Measurement\Exception\InvalidMeasurementSource;
use App\Domain\Measurement\MeasurementSourceKind;

/**
 * Register or change something that measures a client's work.
 *
 * The rule worth having here is that **a source's kind has to match its provider's
 * category.** Registering a Salla store as an analytics source would put invoiced revenue
 * into the column that holds what a browser reported, and the two disagree by design — one
 * counts what was paid for, the other what a page said. The registry already knows which is
 * which, so the check costs one comparison and prevents a class of unarguable report.
 */
final readonly class SaveMeasurementSource
{
    public function __construct(
        private MeasurementSourceRepository $sources,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(MeasurementSourceInput $input, ?string $id = null): MeasurementSourceView
    {
        $this->guard->ensure(Permission::TrackingManage);

        $workspaceId = $this->actor->workspaceId()->value;

        $this->assertProviderMatchesKind($input);

        if ($id === null) {
            $source = $this->sources->create($workspaceId, $input);
        } else {
            $existing = $this->sources->find($workspaceId, $this->visibility->visibleProjectIds(), $id);

            if ($existing === null) {
                throw ResourceNotFound::of('Measurement source');
            }

            $source = $this->sources->update($workspaceId, $id, $input);
        }

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::MeasurementSourceSaved,
            auditableType: 'measurement_source',
            auditableId: $source->id,
            summary: sprintf('%s measurement source "%s"', $id === null ? 'Added' : 'Changed', $source->name),
            changes: [
                'kind' => $source->kind->value,
                'provider' => $source->provider?->value,
                'external_id' => $source->externalId,
                'client_id' => $source->clientId,
                'project_id' => $source->projectId,
            ],
        ));

        return $source;
    }

    /**
     * @throws InvalidMeasurementSource
     */
    private function assertProviderMatchesKind(MeasurementSourceInput $input): void
    {
        $expected = match ($input->kind) {
            MeasurementSourceKind::Analytics => ProviderCategory::Analytics,
            MeasurementSourceKind::TagManager => ProviderCategory::TagManagement,
            MeasurementSourceKind::AdPlatform => ProviderCategory::Advertising,
            MeasurementSourceKind::Store => ProviderCategory::Commerce,
            /*
             * A CRM, a website form and a typed figure have no provider in the registry. They
             * are measurement all the same — a lead somebody took by phone is still a lead —
             * and demanding a platform for them would make the honest cases unrecordable.
             */
            MeasurementSourceKind::Crm, MeasurementSourceKind::Website, MeasurementSourceKind::Manual => null,
        };

        if ($expected === null) {
            return;
        }

        if ($input->provider === null) {
            throw InvalidMeasurementSource::kindNeedsAProvider($input->kind->label());
        }

        if ($input->provider->category() !== $expected) {
            throw InvalidMeasurementSource::providerIsWrongKind(
                $input->provider->label(),
                $input->kind->label(),
            );
        }
    }
}
