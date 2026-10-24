<?php

declare(strict_types=1);

namespace App\Application\Attention\Source;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Attention\Contract\AttentionSource;
use App\Application\Attention\DTO\AttentionItem;
use App\Application\Campaign\Contract\LaunchRepository;
use App\Application\Shared\Contract\ActorContext;
use App\Domain\Attention\AttentionSeverity;
use App\Domain\Attention\AttentionStream;
use App\Domain\Integration\Provider;
use DateTimeImmutable;

/**
 * The launch path's own findings, each from a record: a plan waiting for approval or activation, a launch that is partial, a
 * creation whose outcome is unknown or that differs from the plan when read back, an activation that failed. An unknown outcome is
 * critical — something may exist on a client's account that nobody has looked at.
 */
final readonly class LaunchAttentionSource implements AttentionSource
{
    private const int CAP = 50;

    public function __construct(
        private LaunchRepository $launches,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    public function stream(): AttentionStream
    {
        return AttentionStream::Launches;
    }

    public function items(): array
    {
        $items = [];

        foreach ($this->launches->issues($this->actor->workspaceId()->value, $this->visibility->visibleProjectIds(), self::CAP) as $issue) {
            $platform = $issue['provider'] === null ? '' : ' on '.(Provider::tryFrom($issue['provider'])?->label() ?? $issue['provider']);
            [$severity, $message, $code] = match ($issue['kind']) {
                'unknown' => [AttentionSeverity::Critical, sprintf('Creation outcome unknown%s: check the platform before anything is retried — %s', $platform, $issue['name']), 'LAUNCH_UNKNOWN'],
                'readback_mismatch' => [AttentionSeverity::Critical, sprintf('Created%s, and it differs from the plan — %s', $platform, $issue['name']), 'LAUNCH_READBACK_MISMATCH'],
                'activation_unknown' => [AttentionSeverity::Critical, sprintf('Switch-on outcome unknown%s: it may be delivering and spending — check the platform before anything is repeated — %s', $platform, $issue['name']), 'LAUNCH_ACTIVATION_UNKNOWN'],
                'activation_failed' => [AttentionSeverity::NeedsAttention, sprintf('Not switched on%s — %s', $platform, $issue['name']), 'LAUNCH_ACTIVATION_FAILED'],
                'partial' => [AttentionSeverity::NeedsAttention, sprintf('Partly created; the rest needs a decision — %s', $issue['name']), 'LAUNCH_PARTIAL'],
                'activation_pending' => [AttentionSeverity::Watch, sprintf('Waiting for approval to switch on — %s', $issue['name']), 'LAUNCH_ACTIVATION_PENDING'],
                default => [AttentionSeverity::Watch, sprintf('Waiting for approval — %s', $issue['name']), 'LAUNCH_APPROVAL_PENDING'],
            };

            $items[] = new AttentionItem(
                id: sprintf('launch:%s:%s:%s', $issue['kind'], $issue['blueprint_id'], $issue['provider'] ?? 'plan'),
                stream: AttentionStream::Launches,
                severity: $severity,
                message: $message,
                entityKind: 'Campaign plan',
                entityLabel: $issue['name'],
                owner: false,
                since: new DateTimeImmutable($issue['since']),
                clientId: $issue['client_id'],
                clientName: $issue['client_name'],
                projectId: $issue['project_id'],
                projectName: $issue['project_name'],
                reasonCode: $code,
                reasonParams: ['name' => $issue['name'], 'platform' => $issue['provider'] === null ? '' : (Provider::tryFrom($issue['provider'])?->label() ?? $issue['provider'])],
            );
        }

        return $items;
    }
}
