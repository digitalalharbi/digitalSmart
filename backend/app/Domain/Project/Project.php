<?php

declare(strict_types=1);

namespace App\Domain\Project;

use App\Domain\Client\ClientId;
use App\Domain\Shared\Exception\InvalidDateRange;
use App\Domain\Shared\Exception\InvalidStatusTransition;
use App\Domain\Shared\ValueObject\Slug;
use App\Domain\Workspace\WorkspaceId;
use DateTimeImmutable;

/**
 * A unit of work delivered for one client.
 *
 * The project is the isolation unit of the whole platform: integration
 * bindings, campaigns, budgets, creative and reports all hang off it, and
 * membership of it is what most people's access is actually scoped by.
 */
final class Project
{
    private function __construct(
        public readonly ProjectId $id,
        public readonly WorkspaceId $workspaceId,
        public readonly ClientId $clientId,
        private string $name,
        private Slug $slug,
        private ProjectStatus $status,
        private string $currency,
        private ?string $description,
        private ?DateTimeImmutable $startsOn,
        private ?DateTimeImmutable $endsOn,
        public readonly bool $isDemo,
    ) {
        $this->assertDateRange($startsOn, $endsOn);
    }

    public static function open(
        ProjectId $id,
        WorkspaceId $workspaceId,
        ClientId $clientId,
        string $name,
        Slug $slug,
        string $currency,
        ?string $description = null,
        ?DateTimeImmutable $startsOn = null,
        ?DateTimeImmutable $endsOn = null,
        bool $isDemo = false,
    ): self {
        return new self(
            $id,
            $workspaceId,
            $clientId,
            trim($name),
            $slug,
            ProjectStatus::Planning,
            strtoupper($currency),
            $description,
            $startsOn,
            $endsOn,
            $isDemo,
        );
    }

    /** Rehydrate from storage without replaying business rules. */
    public static function reconstitute(
        ProjectId $id,
        WorkspaceId $workspaceId,
        ClientId $clientId,
        string $name,
        Slug $slug,
        ProjectStatus $status,
        string $currency,
        ?string $description,
        ?DateTimeImmutable $startsOn,
        ?DateTimeImmutable $endsOn,
        bool $isDemo,
    ): self {
        return new self(
            $id, $workspaceId, $clientId, $name, $slug, $status,
            $currency, $description, $startsOn, $endsOn, $isDemo,
        );
    }

    public function rename(string $name): void
    {
        $this->name = trim($name);
    }

    public function changeSlug(Slug $slug): void
    {
        $this->slug = $slug;
    }

    public function describe(?string $description): void
    {
        $this->description = $description;
    }

    public function reschedule(?DateTimeImmutable $startsOn, ?DateTimeImmutable $endsOn): void
    {
        $this->assertDateRange($startsOn, $endsOn);

        $this->startsOn = $startsOn;
        $this->endsOn = $endsOn;
    }

    public function changeStatus(ProjectStatus $status): void
    {
        if (! $this->status->canTransitionTo($status)) {
            throw InvalidStatusTransition::between('project', $this->status->value, $status->value);
        }

        $this->status = $status;
    }

    public function belongsToWorkspace(WorkspaceId $workspaceId): bool
    {
        return $this->workspaceId->equals($workspaceId);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): Slug
    {
        return $this->slug;
    }

    public function status(): ProjectStatus
    {
        return $this->status;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function startsOn(): ?DateTimeImmutable
    {
        return $this->startsOn;
    }

    public function endsOn(): ?DateTimeImmutable
    {
        return $this->endsOn;
    }

    private function assertDateRange(?DateTimeImmutable $startsOn, ?DateTimeImmutable $endsOn): void
    {
        if ($startsOn !== null && $endsOn !== null && $endsOn < $startsOn) {
            throw InvalidDateRange::endsBeforeStart();
        }
    }
}
