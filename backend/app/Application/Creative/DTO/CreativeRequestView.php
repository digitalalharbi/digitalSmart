<?php

declare(strict_types=1);

namespace App\Application\Creative\DTO;

use App\Domain\Creative\AssetKind;
use App\Domain\Creative\CreativeState;
use DateTimeImmutable;

final readonly class CreativeRequestView
{
    /**
     * @param  list<CreativeVersionView>  $versions
     * @param  list<CreativeCommentView>  $comments
     */
    public function __construct(
        public string $id,
        public string $clientId,
        public string $clientName,
        public string $title,
        public CreativeState $state,
        public AssetKind $kind,
        public DateTimeImmutable $createdAt,
        public ?string $projectId = null,
        public ?string $projectName = null,
        public ?string $brief = null,
        public ?string $provider = null,
        public ?string $placement = null,
        public ?DateTimeImmutable $dueAt = null,
        /*
         * The id as well as the name, because a picker has to know which option is selected. The board showed an
         * owner that could only ever be set when the request was opened and never afterwards.
         */
        public ?string $ownerId = null,
        public ?string $ownerName = null,
        public ?string $cancelledReason = null,
        public ?DateTimeImmutable $stateChangedAt = null,
        public array $versions = [],
        public array $comments = [],
    ) {}

    public function latestVersion(): ?CreativeVersionView
    {
        $latest = null;

        foreach ($this->versions as $version) {
            if ($latest === null || $version->number > $latest->number) {
                $latest = $version;
            }
        }

        return $latest;
    }

    /**
     * Whether the newest version is the one somebody approved.
     *
     * The question an approval trail exists to answer, and the reason it is computed from the versions
     * rather than read off the request: a request in the approved state with a newer unapproved version is
     * a request whose state is stale, and this is how the interface notices.
     */
    public function newestIsApproved(): bool
    {
        return $this->latestVersion()?->isApproved() ?? false;
    }

    /** How many days until it is due, negative when overdue, null when nobody set a date. */
    public function daysUntilDue(DateTimeImmutable $now): ?int
    {
        if ($this->dueAt === null) {
            return null;
        }

        $diff = $now->setTime(0, 0)->diff($this->dueAt->setTime(0, 0));

        return (int) $diff->days * ($diff->invert === 1 ? -1 : 1);
    }

    /** Overdue only while the work is still open: a finished request is not late. */
    public function isOverdue(DateTimeImmutable $now): bool
    {
        $days = $this->daysUntilDue($now);

        return $days !== null && $days < 0 && $this->state->isOpen();
    }
}
