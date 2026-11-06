<?php

declare(strict_types=1);

namespace App\Application\Influencers\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Influencers\Contract\InfluencerRepository;
use App\Application\Influencers\DTO\InfluencerInput;
use App\Application\Influencers\DTO\InfluencerView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\Tracking\Exception\TrackingRefused;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;

/**
 * Add or change an influencer.
 *
 * An influencer belongs to one client and never moves: the links made for them, and the clicks those links brought, are
 * against that client's offer. Names are unique per client, compared without case, so two spellings of one person cannot
 * split their results across two rows. Archiving keeps the row and its links; nothing here deletes.
 */
final readonly class SaveInfluencer
{
    private const array STATUSES = ['active', 'archived'];

    public function __construct(
        private InfluencerRepository $influencers,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(InfluencerInput $input, ?string $id = null): InfluencerView
    {
        $this->guard->ensure(Permission::TrackingManage);

        $workspaceId = $this->actor->workspaceId()->value;
        $visible = $this->visibility->visibleProjectIds();
        $name = trim($input->name);

        if ($name === '') {
            throw new TrackingRefused('An influencer needs a name.');
        }

        if (! in_array($input->status, self::STATUSES, true)) {
            throw new TrackingRefused('Unknown status.');
        }

        if (! $this->influencers->clientVisible($workspaceId, $visible, $input->clientId)) {
            throw ResourceNotFound::of('Client');
        }

        $clean = new InfluencerInput($input->clientId, $name, $this->blank($input->handle), $this->blank($input->platform), $this->blank($input->notes), $input->status);

        if ($id === null) {
            if ($this->influencers->nameTaken($workspaceId, $clean->clientId, $name, null)) {
                throw new TrackingRefused('This client already has an influencer with that name.');
            }

            $saved = $this->influencers->create($workspaceId, $this->actor->userId()->value, $clean);
        } else {
            $existing = $this->influencers->find($workspaceId, $visible, $id) ?? throw ResourceNotFound::of('Influencer');

            if ($existing->clientId !== $clean->clientId) {
                throw new TrackingRefused('An influencer cannot move to another client: their links and results belong to the client they were made for.');
            }

            if ($this->influencers->nameTaken($workspaceId, $clean->clientId, $name, $id)) {
                throw new TrackingRefused('This client already has an influencer with that name.');
            }

            $saved = $this->influencers->update($workspaceId, $id, $clean);
        }

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::InfluencerSaved,
            auditableType: 'influencer',
            auditableId: $saved->id,
            summary: sprintf('%s influencer "%s"', $id === null ? 'Added' : 'Changed', $saved->name),
            changes: ['client_id' => $saved->clientId, 'platform' => $saved->platform, 'status' => $saved->status],
        ));

        return $saved;
    }

    private function blank(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : $value;
    }
}
