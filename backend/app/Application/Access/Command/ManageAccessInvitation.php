<?php

declare(strict_types=1);

namespace App\Application\Access\Command;

use App\Application\Access\Contract\AccessInvitationRepository;
use App\Application\Access\Contract\AccessRequestRepository;
use App\Application\Access\DTO\AccessRequestView;
use App\Application\Access\DTO\InvitationView;
use App\Application\Access\Exception\AccessRequestRefused;
use App\Application\Project\Contract\ProjectMemberRepository;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\Workspace\Contract\MemberDirectory;
use App\Application\Workspace\Contract\MembershipRepository;
use App\Domain\Access\AccessEventType;
use App\Domain\Access\AccessRequestStatus;
use App\Domain\Access\Permission;
use App\Domain\Access\RoleId;
use App\Domain\Access\SystemRole;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Identity\Email;
use App\Domain\Identity\UserId;
use App\Domain\Project\ProjectId;
use App\Domain\Project\Repository\ProjectRepository;
use App\Domain\Workspace\WorkspaceId;

/**
 * Turn an approved request into a way in — and an account only when the person takes it.
 *
 * The invitation is a one-time link. Its token is 48 random bytes, stored only as a hash, returned **once** to the person who
 * made it, and good for seven days. Nothing here can send it: there is no mail or messaging credential, so delivery says
 * `AWAITING_CREDENTIALS` and the person who made the link hands it over themselves and records that they did. "Shared" means a
 * person said so; "opened" means the invitation page was actually loaded with the token; neither is claimed without that.
 *
 * Accepting creates the account — with the password the invited person chose, so no administrator ever knows it — adds the
 * membership in the chosen role, and gives access to the chosen projects. Until then no user exists.
 */
final readonly class ManageAccessInvitation
{
    public const int TOKEN_BYTES = 48;

    private const int LIFETIME_DAYS = 7;

    public function __construct(
        private AccessRequestRepository $requests,
        private AccessInvitationRepository $invitations,
        private MembershipRepository $memberships,
        private MemberDirectory $directory,
        private ProjectRepository $projects,
        private ProjectMemberRepository $projectMembers,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private AuditRecorder $audit,
        private Clock $clock,
        private IdentifierGenerator $ids,
    ) {}

    /**
     * @param  list<string>  $projectIds
     * @return array{token: string, invitation: InvitationView}
     */
    public function create(string $requestId, string $roleSlug, array $projectIds): array
    {
        $this->guard->ensure(Permission::UsersInvite);

        $workspace = $this->actor->workspaceId();
        $request = $this->requests->find($workspace->value, $requestId) ?? throw ResourceNotFound::of('Request');

        if (! $request->kind->canBeApproved()) {
            throw AccessRequestRefused::notAnAccountRequest();
        }

        if ($request->status !== AccessRequestStatus::Approved) {
            throw AccessRequestRefused::notApproved();
        }

        if (SystemRole::tryFrom($roleSlug) === null || SystemRole::tryFrom($roleSlug) === SystemRole::Owner) {
            throw AccessRequestRefused::roleNotOffered();
        }

        $roleId = $this->memberships->findRoleIdBySlug($workspace, $roleSlug) ?? throw ResourceNotFound::of('Role');

        $existing = $this->directory->findByEmail(new Email($request->email));

        if ($existing !== null && $this->memberships->find($workspace, $existing) !== null) {
            throw AccessRequestRefused::alreadyAMember();
        }

        $projectIds = array_values(array_unique($projectIds));

        foreach ($projectIds as $projectId) {
            $this->projects->findInWorkspace($workspace, new ProjectId($projectId)) ?? throw ResourceNotFound::of('Project');
        }

        $now = $this->clock->now();
        $this->invitations->revokeOpen($workspace->value, $requestId, $now);

        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $this->invitations->create(
            $this->ids->generate(),
            $workspace->value,
            $requestId,
            hash('sha256', $token),
            substr($token, 0, 12),
            $request->email,
            $request->name,
            $roleId->value,
            $projectIds,
            $this->actor->userId()->value,
            $now->modify(sprintf('+%d days', self::LIFETIME_DAYS)),
            $now,
        );

        $this->requests->update($workspace->value, $requestId, [
            'invited_at' => $now->format('Y-m-d H:i:sP'),
            'invitation_shared_at' => null,
            'invitation_opened_at' => null,
        ], $now);
        $this->requests->addEvent($this->ids->generate(), $workspace->value, $requestId, AccessEventType::InvitationCreated, $this->actor->userId()->value, null, ['role' => $roleSlug, 'projects' => count($projectIds)], $now);

        $this->audit->record(new AuditEntry(
            workspaceId: $workspace,
            actorId: $this->actor->userId(),
            action: AuditAction::AccessInvitationCreated,
            auditableType: 'access_request',
            auditableId: $requestId,
            summary: sprintf('Made an invitation as %s', $roleSlug),
            /* The role and how many projects; never the address, and never the token. */
            changes: ['role' => $roleSlug, 'projects' => count($projectIds), 'existing_account' => $existing !== null],
        ));

        $invitation = $this->invitations->latestFor($workspace->value, $requestId) ?? throw ResourceNotFound::of('Invitation');

        return ['token' => $token, 'invitation' => $invitation];
    }

    /** Somebody says they handed the link over. This is the only thing that can set it: nothing here sends. */
    public function markShared(string $requestId): AccessRequestView
    {
        $this->guard->ensure(Permission::UsersInvite);

        $workspace = $this->actor->workspaceId()->value;
        $request = $this->requests->find($workspace, $requestId) ?? throw ResourceNotFound::of('Request');
        $invitation = $this->invitations->latestFor($workspace, $requestId);

        if ($invitation === null || ! $invitation->isUsable($this->clock->now())) {
            throw AccessRequestRefused::invitationUnusable();
        }

        $now = $this->clock->now();
        $this->requests->update($workspace, $requestId, ['invitation_shared_at' => $now->format('Y-m-d H:i:sP')], $now);
        $this->requests->addEvent($this->ids->generate(), $workspace, $requestId, AccessEventType::InvitationShared, $this->actor->userId()->value, null, ['by' => 'hand'], $now);

        return $this->requests->find($workspace, $request->id) ?? throw ResourceNotFound::of('Request');
    }

    /**
     * What the invitation page needs, and the evidence that it was opened.
     *
     * Answers null for every token that is not a live invitation — unknown, expired, used, revoked all look the same.
     *
     * @return array{name: string, email: string, needs_password: bool}|null
     */
    public function open(string $token): ?array
    {
        $invitation = $this->invitations->findByTokenHash(hash('sha256', $token));
        $now = $this->clock->now();

        if ($invitation === null || ! $invitation->isUsable($now)) {
            return null;
        }

        $request = $this->requests->find($invitation->workspaceId, $invitation->accessRequestId);

        if ($request !== null && $request->invitationOpenedAt === null) {
            $this->requests->update($invitation->workspaceId, $request->id, ['invitation_opened_at' => $now->format('Y-m-d H:i:sP')], $now);
            $this->requests->addEvent($this->ids->generate(), $invitation->workspaceId, $request->id, AccessEventType::InvitationOpened, null, null, null, $now);
        }

        return [
            'name' => $invitation->name,
            'email' => $invitation->email,
            'needs_password' => $this->directory->findByEmail(new Email($invitation->email)) === null,
        ];
    }

    /** Create the account (or join an existing one) and the access that was granted. False when the token is not a live invitation. */
    public function accept(string $token, ?string $password): bool
    {
        $invitation = $this->invitations->findByTokenHash(hash('sha256', $token));
        $now = $this->clock->now();

        if ($invitation === null || ! $invitation->isUsable($now)) {
            return false;
        }

        $workspace = new WorkspaceId($invitation->workspaceId);
        $email = new Email($invitation->email);
        $existing = $this->directory->findByEmail($email);

        if ($existing === null && ($password === null || $password === '')) {
            return false;
        }

        if ($existing !== null && $this->memberships->find($workspace, $existing) !== null) {
            return false;
        }

        $member = $this->directory->add(
            $workspace,
            $invitation->name,
            $email,
            new RoleId($invitation->roleId),
            $existing,
            $existing === null ? $password : null,
        );

        $userId = new UserId($member->userId);

        foreach ($invitation->projectIds as $projectId) {
            $this->projectMembers->assign(
                new ProjectId($projectId),
                $userId,
                new RoleId($invitation->roleId),
                $invitation->createdBy !== null ? new UserId($invitation->createdBy) : $userId,
            );
        }

        $this->invitations->markAccepted($invitation->id, $now);
        $this->requests->update($invitation->workspaceId, $invitation->accessRequestId, [
            'accepted_at' => $now->format('Y-m-d H:i:sP'),
            'account_user_id' => $member->userId,
        ], $now);
        $this->requests->addEvent($this->ids->generate(), $invitation->workspaceId, $invitation->accessRequestId, AccessEventType::InvitationAccepted, null, null, null, $now);

        $this->audit->record(new AuditEntry(
            workspaceId: $workspace,
            actorId: $userId,
            action: AuditAction::AccessInvitationAccepted,
            auditableType: 'access_request',
            auditableId: $invitation->accessRequestId,
            summary: 'An invitation was accepted',
            changes: ['role' => $invitation->roleSlug, 'existing_account' => $existing !== null],
        ));

        return true;
    }
}
