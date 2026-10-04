<?php

declare(strict_types=1);

namespace App\Domain\Client;

use App\Domain\Shared\Exception\InvalidStatusTransition;
use App\Domain\Shared\ValueObject\Slug;
use App\Domain\Workspace\WorkspaceId;

/**
 * A client of the agency.
 *
 * A client always belongs to exactly one workspace. That ownership is set at
 * construction and can never change: moving a client between workspaces would
 * move its projects, spend history and reports across an isolation boundary.
 */
final class Client
{
    private function __construct(
        public readonly ClientId $id,
        public readonly WorkspaceId $workspaceId,
        private string $name,
        private Slug $slug,
        private ClientStatus $status,
        private ClientProfile $profile,
        public readonly bool $isDemo,
    ) {}

    public static function register(
        ClientId $id,
        WorkspaceId $workspaceId,
        string $name,
        Slug $slug,
        ClientProfile $profile,
        bool $isDemo = false,
    ): self {
        return new self($id, $workspaceId, trim($name), $slug, ClientStatus::Prospect, $profile, $isDemo);
    }

    /** Rehydrate from storage without replaying business rules. */
    public static function reconstitute(
        ClientId $id,
        WorkspaceId $workspaceId,
        string $name,
        Slug $slug,
        ClientStatus $status,
        ClientProfile $profile,
        bool $isDemo,
    ): self {
        return new self($id, $workspaceId, $name, $slug, $status, $profile, $isDemo);
    }

    public function rename(string $name): void
    {
        $this->name = trim($name);
    }

    public function changeSlug(Slug $slug): void
    {
        $this->slug = $slug;
    }

    public function updateProfile(ClientProfile $profile): void
    {
        $this->profile = $profile;
    }

    public function changeStatus(ClientStatus $status): void
    {
        if (! $this->status->canTransitionTo($status)) {
            throw InvalidStatusTransition::between('client', $this->status->value, $status->value);
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

    public function status(): ClientStatus
    {
        return $this->status;
    }

    public function profile(): ClientProfile
    {
        return $this->profile;
    }
}
