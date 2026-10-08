<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Workspace\Contract\MemberDirectory;
use App\Application\Workspace\DTO\InvitedMember;
use App\Domain\Access\RoleId;
use App\Domain\Identity\Email;
use App\Domain\Identity\UserId;
use App\Domain\Workspace\MembershipStatus;
use App\Domain\Workspace\WorkspaceId;
use App\Infrastructure\Persistence\Eloquent\Model\Role;
use App\Infrastructure\Persistence\Eloquent\Model\User;
use App\Infrastructure\Persistence\Eloquent\Model\WorkspaceMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class EloquentMemberDirectory implements MemberDirectory
{
    /**
     * The length of a one-time password.
     *
     * Thirty-two characters from `Str::random`, which is base62 over a cryptographically secure source — about
     * 190 bits. Long because nobody has to remember it: it is read out once and replaced, and the only cost of
     * length is the reading.
     */
    private const int ONE_TIME_LENGTH = 32;

    public function findByEmail(Email $email): ?UserId
    {
        $id = User::query()->where('email', $email->value)->value('id');

        return $id === null ? null : new UserId((string) $id);
    }

    public function add(
        WorkspaceId $workspaceId,
        string $name,
        Email $email,
        RoleId $roleId,
        ?UserId $existing,
    ): InvitedMember {
        $oneTime = Str::random(self::ONE_TIME_LENGTH);

        return DB::transaction(function () use (
            $workspaceId,
            $name,
            $email,
            $roleId,
            $existing,
            $oneTime,
        ): InvitedMember {
            if ($existing === null) {
                /** @var User $user */
                $user = User::query()->create([
                    'name' => $name,
                    'email' => $email->value,
                    /* Hashed by the model's cast. The plaintext exists in this method and in the response. */
                    'password' => $oneTime,
                ]);

                /*
                 * Must be replaced before anything else can be done. Without this the flow would be "an
                 * administrator knows your password indefinitely", which is not a flow worth shipping.
                 */
                $user->forceFill(['must_change_password' => true])->saveQuietly();
            } else {
                /** @var User $user */
                $user = User::query()->whereKey($existing->value)->firstOrFail();

                /*
                 * An existing account keeps its password. Somebody who already works on this installation has
                 * one they chose, and resetting it to join a second workspace would lock them out of the first.
                 */
            }

            WorkspaceMember::query()->create([
                'workspace_id' => $workspaceId->value,
                'user_id' => $user->getKey(),
                'role_id' => $roleId->value,
                /*
                 * `Active`, not `Invited`. A member in that state cannot sign in at all — `grantsAccess()` is
                 * false and they resolve to no workspace — so without an email-based activation route it would
                 * be an account nobody could ever open. See `InviteMember`.
                 */
                'status' => MembershipStatus::Active->value,
                'joined_at' => now(),
            ]);

            /** @var string $roleSlug */
            $roleSlug = Role::query()->whereKey($roleId->value)->value('slug') ?? '';

            return new InvitedMember(
                userId: (string) $user->getKey(),
                name: (string) $user->getAttribute('name'),
                email: $email->value,
                roleSlug: $roleSlug,
                /*
                 * The existing-account case returns no password, because none was created: the person already
                 * has one, and inventing a string to show the administrator would be a lie about what happened.
                 */
                oneTimePassword: $existing === null ? $oneTime : '',
            );
        });
    }
}
