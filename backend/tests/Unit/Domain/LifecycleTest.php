<?php

declare(strict_types=1);

use App\Domain\Client\Client;
use App\Domain\Client\ClientId;
use App\Domain\Client\ClientProfile;
use App\Domain\Client\ClientStatus;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectId;
use App\Domain\Project\ProjectStatus;
use App\Domain\Shared\Exception\InvalidDateRange;
use App\Domain\Shared\Exception\InvalidStatusTransition;
use App\Domain\Shared\ValueObject\Slug;
use App\Domain\Workspace\MembershipStatus;
use App\Domain\Workspace\WorkspaceId;

function newClient(): Client
{
    return Client::register(
        new ClientId('0193f0f4-2b7a-7cc0-9a1b-4a5f6d7e8c90'),
        new WorkspaceId('0193f0f4-2b7a-7cc0-9a1b-4a5f6d7e8c91'),
        'Acme Retail',
        new Slug('acme-retail'),
        ClientProfile::empty(),
    );
}

function newProject(?DateTimeImmutable $starts = null, ?DateTimeImmutable $ends = null): Project
{
    return Project::open(
        new ProjectId('0193f0f4-2b7a-7cc0-9a1b-4a5f6d7e8c92'),
        new WorkspaceId('0193f0f4-2b7a-7cc0-9a1b-4a5f6d7e8c91'),
        new ClientId('0193f0f4-2b7a-7cc0-9a1b-4a5f6d7e8c90'),
        'Ramadan Campaign',
        new Slug('ramadan-campaign'),
        'sar',
        startsOn: $starts,
        endsOn: $ends,
    );
}

describe('Client lifecycle', function (): void {
    it('starts as a prospect', function (): void {
        expect(newClient()->status())->toBe(ClientStatus::Prospect);
    });

    it('moves through its lifecycle', function (): void {
        $client = newClient();

        $client->changeStatus(ClientStatus::Active);
        expect($client->status())->toBe(ClientStatus::Active);

        $client->changeStatus(ClientStatus::Paused);
        expect($client->status())->toBe(ClientStatus::Paused);

        $client->changeStatus(ClientStatus::Active);
        expect($client->status())->toBe(ClientStatus::Active);
    });

    it('refuses to resurrect an archived client', function (): void {
        $client = newClient();
        $client->changeStatus(ClientStatus::Archived);

        expect(fn () => $client->changeStatus(ClientStatus::Active))
            ->toThrow(InvalidStatusTransition::class);
    });

    it('cannot skip from prospect straight to paused', function (): void {
        expect(fn () => newClient()->changeStatus(ClientStatus::Paused))
            ->toThrow(InvalidStatusTransition::class);
    });

    it('treats setting the same status as a no-op', function (): void {
        $client = newClient();
        $client->changeStatus(ClientStatus::Prospect);

        expect($client->status())->toBe(ClientStatus::Prospect);
    });

    it('trims the name it is given', function (): void {
        $client = newClient();
        $client->rename('  Acme Group  ');

        expect($client->name())->toBe('Acme Group');
    });

    it('knows which workspace owns it', function (): void {
        $client = newClient();

        expect($client->belongsToWorkspace(new WorkspaceId('0193f0f4-2b7a-7cc0-9a1b-4a5f6d7e8c91')))->toBeTrue()
            ->and($client->belongsToWorkspace(new WorkspaceId('0193f0f4-2b7a-7cc0-9a1b-4a5f6d7e8c99')))->toBeFalse();
    });
});

describe('Project lifecycle', function (): void {
    it('starts in planning and normalises its currency', function (): void {
        $project = newProject();

        expect($project->status())->toBe(ProjectStatus::Planning)
            ->and($project->currency())->toBe('SAR');
    });

    it('only allows spend while active', function (): void {
        expect(ProjectStatus::Active->allowsSpend())->toBeTrue()
            ->and(ProjectStatus::Paused->allowsSpend())->toBeFalse()
            ->and(ProjectStatus::Planning->allowsSpend())->toBeFalse()
            ->and(ProjectStatus::Completed->allowsSpend())->toBeFalse();
    });

    it('can reopen a completed project when work resumes', function (): void {
        $project = newProject();
        $project->changeStatus(ProjectStatus::Active);
        $project->changeStatus(ProjectStatus::Completed);
        $project->changeStatus(ProjectStatus::Active);

        expect($project->status())->toBe(ProjectStatus::Active);
    });

    it('refuses to leave the archived state', function (): void {
        $project = newProject();
        $project->changeStatus(ProjectStatus::Archived);

        expect(fn () => $project->changeStatus(ProjectStatus::Active))
            ->toThrow(InvalidStatusTransition::class);
    });

    it('rejects an end date before the start date', function (): void {
        expect(fn (): Project => newProject(
            new DateTimeImmutable('2026-03-01'),
            new DateTimeImmutable('2026-02-01'),
        ))->toThrow(InvalidDateRange::class);
    });

    it('rejects an invalid reschedule too', function (): void {
        $project = newProject();

        expect(fn () => $project->reschedule(
            new DateTimeImmutable('2026-03-01'),
            new DateTimeImmutable('2026-02-01'),
        ))->toThrow(InvalidDateRange::class);
    });

    it('accepts an open-ended schedule', function (): void {
        $project = newProject(new DateTimeImmutable('2026-03-01'), null);

        expect($project->endsOn())->toBeNull()
            ->and($project->startsOn()?->format('Y-m-d'))->toBe('2026-03-01');
    });
});

describe('Membership', function (): void {
    it('grants access only while active', function (): void {
        expect(MembershipStatus::Active->grantsAccess())->toBeTrue()
            ->and(MembershipStatus::Invited->grantsAccess())->toBeFalse()
            ->and(MembershipStatus::Suspended->grantsAccess())->toBeFalse();
    });
});
