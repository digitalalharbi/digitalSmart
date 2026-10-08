<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Workspace\MembershipStatus;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| The realtime channel
|--------------------------------------------------------------------------
|
| The channel authorisation **is** the access control on the realtime layer. A notification that reached the
| wrong browser would have bypassed every permission check in the module, so this tests the two things the
| callback checks: that the subscriber is who they claim to be, and that their membership is still active.
|
| A workspace-wide channel would have been one line shorter and would mean every member's browser receiving
| every notification and filtering it client-side — which is not filtering, it is delivery followed by
| politeness. That is why the channel is per person, and why these tests exist.
|
*/

/*
 * A broadcaster that actually consults the channel rules, with its channels re-registered against it.
 *
 * Two things have to be arranged, and the first one is a trap worth recording.
 *
 * Neither the `null` nor the `log` driver consults the channel callbacks — both have an empty `auth()` — so with
 * the suite's default connection every subscription is simply answered, and a test asserting a refusal would
 * pass without the rule existing at all. Reverb speaks the Pusher protocol, whose broadcaster runs the
 * registered callback and answers 403 when it returns false, which is the path that has to be exercised. The
 * credentials are placeholders: authorising a subscription is signing, not calling, so nothing leaves the
 * process.
 *
 * **And `Broadcast::channel()` registers on whichever driver was default when `routes/channels.php` was
 * loaded** — the null one, at boot. Switching the connection here builds a fresh broadcaster with no channels
 * on it, and *every* request then falls through to the same 403 that a denial produces. That is how a set of
 * refusal tests passes while asserting nothing, so the file is re-required against the new driver and the first
 * test below — the one that expects a subscription to *succeed* — is what proves the rule is wired at all.
 */
beforeEach(function (): void {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);

    require base_path('routes/channels.php');
});

/** The channel name, built the way `NotificationAnnounced` builds it. */
function channelFor(string $workspaceId, string $userId): string
{
    return sprintf('private-workspace.%s.user.%s', $workspaceId, $userId);
}

it('lets a member subscribe to their own channel', function (): void {
    $scenario = WorkspaceScenario::create();

    test()->actingAs($scenario->owner)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => channelFor(
                (string) $scenario->workspace->getKey(),
                (string) $scenario->owner->getKey(),
            ),
        ])
        ->assertOk();
});

it('refuses a member subscribing to somebody else\'s channel', function (): void {
    $scenario = WorkspaceScenario::create();
    $other = $scenario->addMember(SystemRole::ProjectManager);

    /*
     * The case the whole callback exists for. Without this check, a notification's contents — a client, a
     * campaign, an intention — would be readable by anybody who could guess a user id.
     */
    test()->actingAs($other)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => channelFor(
                (string) $scenario->workspace->getKey(),
                (string) $scenario->owner->getKey(),
            ),
        ])
        ->assertForbidden();
});

it('refuses a suspended member on their own channel', function (): void {
    $scenario = WorkspaceScenario::create();
    $member = $scenario->addMember(SystemRole::ProjectManager);

    DB::table('workspace_members')
        ->where('workspace_id', $scenario->workspace->getKey())
        ->where('user_id', $member->getKey())
        ->update(['status' => MembershipStatus::Suspended->value]);

    /*
     * Re-checked on every subscription rather than taken from the session. Somebody suspended between opening
     * the page and the next notification must stop receiving them; a socket authorised once would otherwise keep
     * delivering for as long as the tab stayed open.
     */
    test()->actingAs($member)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => channelFor(
                (string) $scenario->workspace->getKey(),
                (string) $member->getKey(),
            ),
        ])
        ->assertForbidden();
});

it('refuses a member of one workspace on a channel of another', function (): void {
    $scenario = WorkspaceScenario::create();
    $other = WorkspaceScenario::create();

    test()->actingAs($scenario->owner)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => channelFor(
                (string) $other->workspace->getKey(),
                (string) $scenario->owner->getKey(),
            ),
        ])
        ->assertForbidden();
});

it('refuses somebody who is not signed in', function (): void {
    $scenario = WorkspaceScenario::create();

    test()->postJson('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => channelFor(
            (string) $scenario->workspace->getKey(),
            (string) $scenario->owner->getKey(),
        ),
    ])->assertUnauthorized();
});

/** A board channel, built the way `BoardChanged` builds it. */
function boardChannelFor(string $workspaceId, string $board): string
{
    return sprintf('private-workspace.%s.board.%s', $workspaceId, $board);
}

it('lets somebody who may read a board watch it', function (): void {
    $scenario = WorkspaceScenario::create();

    /*
     * Workspace-wide rather than per person, which is safe only because the payload carries no rows. The question
     * this rule answers is "may this person read this board at all"; which rows they get is settled by the
     * authenticated endpoint on every refetch.
     */
    test()->actingAs($scenario->owner)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => boardChannelFor((string) $scenario->workspace->getKey(), 'tasks'),
        ])
        ->assertOk();
});

it('refuses a client viewer on the agency\'s boards and allows them their own queue', function (): void {
    $scenario = WorkspaceScenario::create();
    $client = $scenario->addMember(SystemRole::ClientViewer);

    /*
     * The case the permission on the channel exists for. A client viewer holds none of `tasks.view` or
     * `approvals.view`, so they cannot subscribe to the agency's boards at all — and the nudge they *can* have is
     * for the one queue they are party to, through the permission that let them raise a request.
     */
    foreach (['tasks', 'approvals'] as $board) {
        test()->actingAs($client)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => boardChannelFor((string) $scenario->workspace->getKey(), $board),
            ])
            ->assertForbidden();
    }

    test()->actingAs($client)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => boardChannelFor((string) $scenario->workspace->getKey(), 'client_requests'),
        ])
        ->assertOk();
});

it('refuses a board nobody publishes', function (): void {
    $scenario = WorkspaceScenario::create();

    /*
     * An unknown board name is refused rather than treated as a board with no permission. The channel name is
     * attacker-supplied text, and a rule that fell through on a value it did not recognise would eventually fall
     * through on one that mattered.
     */
    test()->actingAs($scenario->owner)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => boardChannelFor((string) $scenario->workspace->getKey(), 'everything'),
        ])
        ->assertForbidden();
});

it('refuses a suspended member on a board they could otherwise read', function (): void {
    $scenario = WorkspaceScenario::create();
    $member = $scenario->addMember(SystemRole::ProjectManager);

    DB::table('workspace_members')
        ->where('workspace_id', $scenario->workspace->getKey())
        ->where('user_id', $member->getKey())
        ->update(['status' => MembershipStatus::Suspended->value]);

    // Re-checked per subscription, exactly as on the notification channel.
    test()->actingAs($member)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => boardChannelFor((string) $scenario->workspace->getKey(), 'tasks'),
        ])
        ->assertForbidden();
});

it('refuses a member of one workspace on another workspace\'s board', function (): void {
    $scenario = WorkspaceScenario::create();
    $other = WorkspaceScenario::create();

    test()->actingAs($scenario->owner)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => boardChannelFor((string) $other->workspace->getKey(), 'tasks'),
        ])
        ->assertForbidden();
});
