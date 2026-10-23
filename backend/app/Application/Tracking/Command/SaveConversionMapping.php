<?php

declare(strict_types=1);

namespace App\Application\Tracking\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\Tracking\Contract\ConversionMappingRepository;
use App\Application\Tracking\Contract\TrackingLinkRepository;
use App\Application\Tracking\Exception\TrackingRefused;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Integration\Provider;
use App\Domain\Tracking\Dispatch\ProviderConversionCatalogue;

/**
 * Say which provider event a digitalSmart event becomes.
 *
 * The provider's own documentation decides what is allowed (`ProviderConversionCatalogue`). A provider whose path has not
 * been read, or has none, cannot be mapped at all — the refusal says which — and a name outside what that provider documents
 * is refused with the names it does document.
 */
final readonly class SaveConversionMapping
{
    /** Events that mean something to an advertiser: the submission, the lead, and the later stages of its life. */
    public const array EVENTS = ['form_submit', 'lead_created', 'lead_qualified', 'appointment_booked', 'opportunity_created', 'sale_completed'];

    public function __construct(
        private ConversionMappingRepository $mappings,
        private TrackingLinkRepository $links,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    /** @return array{id: string, event: string, provider: string, provider_event: string, enabled: bool} */
    public function __invoke(string $projectId, string $event, string $provider, string $providerEvent, bool $enabled): array
    {
        $this->guard->ensure(Permission::TrackingManage);

        $workspaceId = $this->actor->workspaceId()->value;

        if ($this->links->clientOfProject($workspaceId, $this->visibility->visibleProjectIds(), $projectId) === null) {
            throw ResourceNotFound::of('Project');
        }

        if (! in_array($event, self::EVENTS, true)) {
            throw new TrackingRefused('Choose one of: '.implode(', ', self::EVENTS).'.');
        }

        $p = Provider::tryFrom($provider) ?? throw new TrackingRefused('Unknown provider.');
        $refusal = ProviderConversionCatalogue::refusal($p, trim($providerEvent));

        if ($refusal !== null) {
            throw new TrackingRefused($refusal);
        }

        $saved = $this->mappings->save($workspaceId, $projectId, $event, $provider, trim($providerEvent), $enabled, $this->actor->userId()->value);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::TrackingSettingsSaved,
            auditableType: 'conversion_mapping',
            auditableId: $saved['id'],
            summary: sprintf('Mapped %s to %s %s', $event, $p->label(), trim($providerEvent)),
            changes: ['project_id' => $projectId, 'event' => $event, 'provider' => $provider, 'enabled' => $enabled],
        ));

        return $saved;
    }
}
