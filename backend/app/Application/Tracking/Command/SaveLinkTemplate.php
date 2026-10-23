<?php

declare(strict_types=1);

namespace App\Application\Tracking\Command;

use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Application\Tracking\Contract\TrackingLinkRepository;
use App\Application\Tracking\Exception\TrackingRefused;
use App\Domain\Access\Permission;

final readonly class SaveLinkTemplate
{
    private const array ALLOWED = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];

    public function __construct(
        private TrackingLinkRepository $links,
        private IdentifierGenerator $ids,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
    ) {}

    /** @param array<string, string> $patterns */
    public function __invoke(string $name, array $patterns): void
    {
        $this->guard->ensure(Permission::TrackingManage);

        $clean = [];

        foreach ($patterns as $key => $pattern) {
            if (! in_array($key, self::ALLOWED, true)) {
                throw new TrackingRefused(sprintf('A template can fill %s.', implode(', ', self::ALLOWED)));
            }

            if (trim($pattern) !== '') {
                $clean[$key] = mb_substr(trim($pattern), 0, 190);
            }
        }

        if ($clean === []) {
            throw new TrackingRefused('A template needs at least one pattern.');
        }

        $this->links->saveTemplate($this->actor->workspaceId()->value, $this->ids->generate(), $this->actor->userId()->value, $name, $clean);
    }
}
