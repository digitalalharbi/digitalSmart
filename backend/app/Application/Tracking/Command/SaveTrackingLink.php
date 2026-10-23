<?php

declare(strict_types=1);

namespace App\Application\Tracking\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\Tracking\Contract\OpaqueTokens;
use App\Application\Tracking\Contract\TrackingLinkRepository;
use App\Application\Tracking\DTO\TrackingLinkInput;
use App\Application\Tracking\DTO\TrackingLinkView;
use App\Application\Tracking\Exception\TrackingRefused;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Tracking\DestinationUrl;
use App\Domain\Tracking\Exception\UnsafeDestination;
use App\Domain\Tracking\LinkTemplate;

/**
 * Create or change a tracking link.
 *
 * The destination is parsed and fixed here. The redirect later reads it from the row and never from a request, so a
 * link cannot be turned into an open redirect by whoever holds its address.
 */
final readonly class SaveTrackingLink
{
    private const array CHANNELS = ['paid', 'organic', 'email', 'sms', 'offline_qr', 'referral', 'direct', 'other'];

    /** @param list<string> $ownHosts */
    public function __construct(
        private TrackingLinkRepository $links,
        private OpaqueTokens $tokens,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
        private array $ownHosts = [],
    ) {}

    public function __invoke(TrackingLinkInput $input, ?string $id = null): TrackingLinkView
    {
        $this->guard->ensure(Permission::TrackingManage);

        $workspaceId = $this->actor->workspaceId()->value;
        $visible = $this->visibility->visibleProjectIds();

        $clientId = $this->links->clientOfProject($workspaceId, $visible, $input->projectId)
            ?? throw ResourceNotFound::of('Project');

        if (! in_array($input->channel, self::CHANNELS, true)) {
            throw new TrackingRefused('Unknown channel.');
        }

        try {
            $destination = DestinationUrl::parse($input->destinationUrl, $this->ownHosts);
        } catch (UnsafeDestination $e) {
            throw new TrackingRefused($e->getMessage(), 0, $e);
        }

        $generated = LinkTemplate::render($input->templatePatterns, [
            'platform' => $input->platform,
            'project' => null,
            'campaign' => $input->campaign,
            'ad_group' => $input->adGroup,
            'ad' => $input->ad,
            'creative' => $input->creative,
        ]);
        $utm = [];

        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $key) {
            $typed = trim($input->utm[$key] ?? '');
            $value = $typed !== '' ? $typed : ($generated[$key] ?? null);

            if ($value !== null && $value !== '') {
                $utm[$key] = mb_substr($value, 0, 190);
            }
        }

        $custom = [];

        foreach ($input->customParams as $name => $value) {
            $name = (string) $name;

            if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/', $name) !== 1 || str_starts_with(strtolower($name), 'utm_')) {
                throw new TrackingRefused('A custom parameter name uses letters, numbers and underscores, and is not a utm_ name.');
            }

            $custom[$name] = mb_substr((string) $value, 0, 190);
        }

        $values = [
            'project_id' => $input->projectId,
            'client_id' => $clientId,
            'name' => $input->name,
            'platform' => $input->platform,
            'external_account_id' => $input->externalAccountId,
            'campaign' => $input->campaign,
            'ad_group' => $input->adGroup,
            'ad' => $input->ad,
            'creative' => $input->creative,
            'channel' => $input->channel,
            'destination_url' => $destination->value,
            'utm_source' => $utm['utm_source'] ?? null,
            'utm_medium' => $utm['utm_medium'] ?? null,
            'utm_campaign' => $utm['utm_campaign'] ?? null,
            'utm_content' => $utm['utm_content'] ?? null,
            'utm_term' => $utm['utm_term'] ?? null,
            'custom_params' => $custom,
            'objective' => $input->objective,
            'expires_at' => $input->expiresAt,
            'notes' => $input->notes,
        ];

        if ($id === null) {
            $link = $this->links->create($workspaceId, $clientId, $this->freshToken(), $this->actor->userId()->value, $values);
        } else {
            $existing = $this->links->find($workspaceId, $visible, $id) ?? throw ResourceNotFound::of('Tracking link');

            if ($existing->projectId !== $input->projectId) {
                throw new TrackingRefused('A link cannot move to another project: its history belongs to the project it was made for.');
            }

            $link = $this->links->update($workspaceId, $id, $values);
        }

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::TrackingLinkSaved,
            auditableType: 'tracking_link',
            auditableId: $link->id,
            summary: sprintf('%s tracking link "%s"', $id === null ? 'Created' : 'Changed', $link->name),
            changes: ['project_id' => $link->projectId, 'platform' => $link->platform, 'destination_host' => $destination->host],
        ));

        return $link;
    }

    private function freshToken(): string
    {
        for ($i = 0; $i < 5; $i++) {
            $token = $this->tokens->make(10);

            if (! $this->links->tokenExists($token)) {
                return $token;
            }
        }

        throw new TrackingRefused('Could not make a unique link; try again.');
    }
}
