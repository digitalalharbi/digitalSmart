<?php

declare(strict_types=1);

use App\Application\Campaign\Contract\CampaignCreator;
use App\Application\Campaign\DTO\CreationRequest;
use App\Domain\Access\SystemRole;
use App\Domain\Advertising\Launch\CreateOutcome;
use App\Domain\Advertising\Launch\ReadBack;
use App\Domain\Advertising\MetricSource;
use App\Domain\Integration\Credentials;
use App\Domain\Integration\ProviderConnectionId;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use App\Domain\Workspace\WorkspaceId;
use App\Infrastructure\Campaign\SandboxCampaignCreator;
use App\Infrastructure\Persistence\Eloquent\Model\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CampaignPlanScene;

/*
|--------------------------------------------------------------------------
| Approval and creation
|--------------------------------------------------------------------------
|
| An approval names a fingerprint; a launch re-checks it, queues one creation per account with a stable idempotency reference,
| creates paused, reads back, and only then calls a campaign verified and puts it into the product's own tables. An unknown
| outcome is never retried until reconciled; a partial launch deletes nothing; real credentials with no live adapter are
| recorded as waiting on the platform, with no call made.
|
*/

/** A scriptable creator: the sandbox one, or a failure, an unanswered request, or a refusal, per provider. */
final class ScriptedCreator implements CampaignCreator
{
    /** @var array<string, string> provider → 'throw' | 'fail' | 'readback_throws' | 'find_throws' | 'find_unknown' */
    public static array $script = [];

    public function __construct(private readonly SandboxCampaignCreator $sandbox) {}

    public function create(CreationRequest $request): CreateOutcome
    {
        return match (self::$script[$request->plan->provider->value] ?? 'ok') {
            'throw' => throw new RuntimeException('connection reset by peer'),
            'fail' => CreateOutcome::failed(MetricSource::Sandbox, 'rejected', 'The platform refused the campaign.'),
            default => $this->sandbox->create($request),
        };
    }

    public function readBack(CreationRequest $request, string $campaignId, string $adGroupId, array $adIds): ReadBack
    {
        if ((self::$script[$request->plan->provider->value] ?? '') === 'readback_throws') {
            throw new RuntimeException('read timed out');
        }

        return $this->sandbox->readBack($request, $campaignId, $adGroupId, $adIds);
    }

    public function find(CreationRequest $request): ?CreateOutcome
    {
        return match (self::$script[$request->plan->provider->value.':find'] ?? 'ok') {
            'throws' => throw new RuntimeException('lookup refused'),
            'unknown' => CreateOutcome::unknown(MetricSource::Sandbox, 'The lookup did not answer.'),
            default => $this->sandbox->find($request),
        };
    }
}

/** @return array{scene: array<string, mixed>, id: string, owner: User} */
function approvedPlan(): array
{
    $s = CampaignPlanScene::create();
    DB::table('creative_requests')->where('id', $s['creative'])->update(['state' => 'approved']);
    DB::table('creative_versions')->insert(['id' => (string) Str::uuid7(), 'workspace_id' => $s['w']->workspaceId(), 'creative_request_id' => $s['creative'],
        'number' => 1, 'kind' => 'video', 'created_at' => now(), 'updated_at' => now()]);
    $owner = $s['w']->scenario->owner;
    $id = (string) test()->actingAs($owner)->postJson('/api/campaign-plans', CampaignPlanScene::payload($s))->assertCreated()->json('data.id');
    test()->actingAs($owner)->postJson("/api/campaign-plans/{$id}/submit")->assertOk();
    test()->actingAs($owner)->postJson("/api/campaign-plans/{$id}/approve")->assertOk();

    return ['scene' => $s, 'id' => $id, 'owner' => $owner];
}

beforeEach(function (): void {
    ScriptedCreator::$script = [];
    app()->bind(CampaignCreator::class, ScriptedCreator::class);
});

it('creates an approved plan paused on every account, reads it back, and reconciles it into the product’s own campaigns', function (): void {
    ['scene' => $s, 'id' => $id, 'owner' => $owner] = approvedPlan();

    $this->actingAs($owner)->postJson("/api/campaign-plans/{$id}/launch")->assertStatus(202)->assertJsonPath('data.queued', 2);

    $executions = $this->actingAs($owner)->getJson("/api/campaign-plans/{$id}/launches")->assertOk()->json('data');
    expect(array_column($executions, 'status'))->toBe(['verified', 'verified'])
        ->and(array_unique(array_column($executions, 'source')))->toBe(['sandbox'])
        ->and($executions[0]['readback']['found'])->toBeTrue()
        ->and($executions[0]['attempt'])->toBe(1);
    $this->actingAs($owner)->getJson("/api/campaign-plans/{$id}")->assertJsonPath('data.status', 'verified');

    $campaigns = DB::table('campaigns')->where('external_id', 'like', 'SANDBOX-CAMPAIGN-%')->get();
    expect($campaigns)->toHaveCount(2)
        ->and($campaigns->pluck('status')->unique()->all())->toBe(['paused'])
        ->and($campaigns->pluck('project_id')->unique()->all())->toBe([$s['project']->getKey()])
        ->and(json_decode((string) DB::table('campaigns')->where('external_id', 'like', 'SANDBOX-CAMPAIGN-%')->value('metadata'), true)['source'])->toBe('sandbox')
        ->and(DB::table('ads')->where('external_id', 'like', 'SANDBOX-AD-%')->count())->toBe(2);

    expect(DB::table('audit_logs')->where('action', 'campaign_launch.executed')->count())->toBe(2)
        ->and(DB::table('campaign_launches')->count())->toBe(1);
});

it('refuses to launch what nobody approved, and sends a plan whose writes changed after approval back to review', function (): void {
    ['scene' => $s, 'id' => $id, 'owner' => $owner] = approvedPlan();
    DB::table('external_accounts')->where('id', $s['google'])->update(['name' => 'Renamed after approval']);

    $this->actingAs($owner)->postJson("/api/campaign-plans/{$id}/launch")->assertUnprocessable()->assertJsonPath('code', 'approval_stale');
    $this->actingAs($owner)->getJson("/api/campaign-plans/{$id}")->assertJsonPath('data.status', 'ready_for_review')->assertJsonPath('data.approved_by', null);
    $this->actingAs($owner)->postJson("/api/campaign-plans/{$id}/launch")->assertUnprocessable()->assertJsonPath('code', 'not_approved');
    expect(DB::table('campaign_launches')->count())->toBe(0);
});

it('keeps approving, creating and planning as separate permissions', function (): void {
    $s = CampaignPlanScene::create();
    DB::table('creative_versions')->insert(['id' => (string) Str::uuid7(), 'workspace_id' => $s['w']->workspaceId(), 'creative_request_id' => $s['creative'],
        'number' => 1, 'kind' => 'video', 'created_at' => now(), 'updated_at' => now()]);
    $owner = $s['w']->scenario->owner;
    $id = (string) $this->actingAs($owner)->postJson('/api/campaign-plans', CampaignPlanScene::payload($s))->json('data.id');
    $this->actingAs($owner)->postJson("/api/campaign-plans/{$id}/submit")->assertOk();

    $member = static function (SystemRole $role) use ($s): User {
        Auth::forgetGuards();
        $user = $s['w']->scenario->addMember($role);
        DB::table('project_members')->insert(['id' => (string) Str::uuid7(), 'project_id' => $s['project']->getKey(), 'user_id' => $user->getKey(),
            'role_id' => $s['w']->scenario->role($role)->getKey(), 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    };

    $this->actingAs($member(SystemRole::MediaBuyer))->postJson("/api/campaign-plans/{$id}/approve")->assertForbidden();
    $pm = $member(SystemRole::ProjectManager);
    $this->actingAs($pm)->postJson("/api/campaign-plans/{$id}/approve")->assertOk();
    $this->actingAs($pm)->postJson("/api/campaign-plans/{$id}/launch")->assertForbidden();
});

it('never retries an unknown outcome until it is reconciled, and retries only what was proven not to exist', function (): void {
    ['id' => $id, 'owner' => $owner] = approvedPlan();
    ScriptedCreator::$script = ['google_ads' => 'throw'];

    $this->actingAs($owner)->postJson("/api/campaign-plans/{$id}/launch")->assertStatus(202);
    $google = collect((array) $this->actingAs($owner)->getJson("/api/campaign-plans/{$id}/launches")->json('data'))->firstWhere('provider', 'google_ads');

    expect($google['status'])->toBe('unknown')->and($google['may_retry'])->toBeFalse()->and($google['may_reconcile'])->toBeTrue();
    $this->actingAs($owner)->getJson("/api/campaign-plans/{$id}")->assertJsonPath('data.status', 'partial');
    $this->actingAs($owner)->postJson("/api/launch-executions/{$google['id']}/retry")->assertUnprocessable()->assertJsonPath('code', 'not_retryable');

    // Reconcile: nothing exists on the platform for that reference, so it becomes a proven failure — and only then retryable.
    $this->actingAs($owner)->postJson("/api/launch-executions/{$google['id']}/reconcile")->assertOk()
        ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.failure_classification', 'not_created');

    ScriptedCreator::$script = [];
    $this->actingAs($owner)->postJson("/api/launch-executions/{$google['id']}/retry")->assertStatus(202);
    $google = collect((array) $this->actingAs($owner)->getJson("/api/campaign-plans/{$id}/launches")->json('data'))->firstWhere('provider', 'google_ads');
    expect($google['status'])->toBe('verified')->and($google['attempt'])->toBe(2);
    $this->actingAs($owner)->getJson("/api/campaign-plans/{$id}")->assertJsonPath('data.status', 'verified');

    // The same reference throughout, so a platform that de-duplicates sees one request.
    expect(DB::table('campaigns')->where('external_id', 'like', 'SANDBOX-CAMPAIGN-%')->count())->toBe(2);
});

it('keeps what was created when another account fails, and calls the launch partial', function (): void {
    ['id' => $id, 'owner' => $owner] = approvedPlan();
    ScriptedCreator::$script = ['google_ads' => 'fail'];

    $this->actingAs($owner)->postJson("/api/campaign-plans/{$id}/launch")->assertStatus(202);

    $by = array_column((array) $this->actingAs($owner)->getJson("/api/campaign-plans/{$id}/launches")->json('data'), null, 'provider');
    expect($by['meta']['status'])->toBe('verified')
        ->and($by['google_ads']['status'])->toBe('failed')
        ->and($by['google_ads']['may_retry'])->toBeTrue();
    $this->actingAs($owner)->getJson("/api/campaign-plans/{$id}")->assertJsonPath('data.status', 'partial');
    expect(DB::table('campaigns')->where('external_id', 'like', 'SANDBOX-CAMPAIGN-%')->count())->toBe(1);
});

it('records real credentials as waiting on the platform, and calls nothing', function (): void {
    $s = CampaignPlanScene::create();
    $repository = app(ProviderConnectionRepository::class);

    foreach ([$s['meta'], $s['google']] as $accountId) {
        $connectionId = (string) DB::table('external_accounts')->where('id', $accountId)->value('provider_connection_id');
        $connection = $repository->findInWorkspace(new WorkspaceId($s['w']->workspaceId()), new ProviderConnectionId($connectionId));
        $keys = $connection?->provider->requiredCredentialKeys() ?? [];
        $connection?->replaceCredentials(new Credentials(array_fill_keys($keys, 'a-real-looking-value')));
        $connection?->markConnected(null, null, new DateTimeImmutable);

        if ($connection !== null) {
            $repository->save($connection);
        }
    }

    DB::table('creative_versions')->insert(['id' => (string) Str::uuid7(), 'workspace_id' => $s['w']->workspaceId(), 'creative_request_id' => $s['creative'],
        'number' => 1, 'kind' => 'video', 'created_at' => now(), 'updated_at' => now()]);
    $owner = $s['w']->scenario->owner;
    $id = (string) $this->actingAs($owner)->postJson('/api/campaign-plans', CampaignPlanScene::payload($s))->json('data.id');
    $this->actingAs($owner)->postJson("/api/campaign-plans/{$id}/submit")->assertOk();
    $this->actingAs($owner)->postJson("/api/campaign-plans/{$id}/approve")->assertOk();

    $this->actingAs($owner)->postJson("/api/campaign-plans/{$id}/launch")->assertStatus(202)
        ->assertJsonPath('data.queued', 0)->assertJsonPath('data.blocked_external', 2);
    $this->actingAs($owner)->getJson("/api/campaign-plans/{$id}")->assertJsonPath('data.status', 'failed');
    expect(DB::table('campaigns')->where('external_id', 'like', 'SANDBOX-%')->count())->toBe(0);
});

it('keeps the ids of a campaign whose read-back failed, never retries it, and does not leave the plan creating', function (): void {
    ['id' => $id, 'owner' => $owner] = approvedPlan();
    ScriptedCreator::$script = ['google_ads' => 'readback_throws'];

    $this->actingAs($owner)->postJson("/api/campaign-plans/{$id}/launch")->assertStatus(202);

    $by = array_column((array) $this->actingAs($owner)->getJson("/api/campaign-plans/{$id}/launches")->json('data'), null, 'provider');
    expect($by['google_ads']['status'])->toBe('readback_mismatch')
        ->and($by['google_ads']['failure_classification'])->toBe('readback_failed')
        ->and($by['google_ads']['provider_ids']['campaign'])->toStartWith('SANDBOX-CAMPAIGN-')
        ->and($by['google_ads']['may_retry'])->toBeFalse()
        ->and($by['meta']['status'])->toBe('verified');
    // Not verified, so it is not put into the product's own tables; the other account's is.
    expect(DB::table('campaigns')->where('external_id', 'like', 'SANDBOX-CAMPAIGN-%')->count())->toBe(1);
    $this->actingAs($owner)->getJson("/api/campaign-plans/{$id}")->assertJsonPath('data.status', 'partial');
    $this->actingAs($owner)->postJson("/api/launch-executions/{$by['google_ads']['id']}/retry")->assertUnprocessable();
});

it('treats a lookup that could not be made as still unknown, never as proof that nothing was created', function (string $mode): void {
    ['id' => $id, 'owner' => $owner] = approvedPlan();
    ScriptedCreator::$script = ['google_ads' => 'throw'];
    $this->actingAs($owner)->postJson("/api/campaign-plans/{$id}/launch")->assertStatus(202);
    $google = collect((array) $this->actingAs($owner)->getJson("/api/campaign-plans/{$id}/launches")->json('data'))->firstWhere('provider', 'google_ads');

    ScriptedCreator::$script = ['google_ads:find' => $mode];
    $this->actingAs($owner)->postJson("/api/launch-executions/{$google['id']}/reconcile")->assertOk()
        ->assertJsonPath('data.status', 'unknown')->assertJsonPath('data.may_retry', false)->assertJsonPath('data.may_reconcile', true);
    $this->actingAs($owner)->postJson("/api/launch-executions/{$google['id']}/retry")->assertUnprocessable();

    // And once the platform can be asked, and holds nothing, it is a proven failure.
    ScriptedCreator::$script = [];
    $this->actingAs($owner)->postJson("/api/launch-executions/{$google['id']}/reconcile")->assertOk()->assertJsonPath('data.status', 'failed');
})->with(['lookup throws' => ['throws'], 'lookup answers unknown' => ['unknown']]);

it('lets exactly one caller take an execution, and offers reconcile for a worker that died mid-request', function (): void {
    ['id' => $id, 'owner' => $owner] = approvedPlan();
    ScriptedCreator::$script = ['google_ads' => 'throw'];
    $this->actingAs($owner)->postJson("/api/campaign-plans/{$id}/launch")->assertStatus(202);
    $google = collect((array) $this->actingAs($owner)->getJson("/api/campaign-plans/{$id}/launches")->json('data'))->firstWhere('provider', 'google_ads');
    $workspace = (string) DB::table('campaign_launch_executions')->where('id', $google['id'])->value('workspace_id');
    $repository = app(App\Application\Campaign\Contract\LaunchRepository::class);
    $unknown = App\Domain\Advertising\Launch\ExecutionStatus::Unknown;
    $creating = App\Domain\Advertising\Launch\ExecutionStatus::Creating;

    // Two workers (or two presses) holding the same record: the second finds it already taken.
    expect($repository->transition($workspace, $google['id'], $unknown, ['status' => 'creating']))->toBeTrue()
        ->and($repository->transition($workspace, $google['id'], $unknown, ['status' => 'creating']))->toBeFalse();

    // A worker that dies leaves `creating`: not reconcilable while fresh, reconcilable once it is plainly stale.
    DB::table('campaign_launch_executions')->where('id', $google['id'])->update(['request_started_at' => now()->subMinute()]);
    $this->actingAs($owner)->postJson("/api/launch-executions/{$google['id']}/reconcile")->assertUnprocessable();
    DB::table('campaign_launch_executions')->where('id', $google['id'])->update(['request_started_at' => now()->subHour()]);
    ScriptedCreator::$script = [];
    $this->actingAs($owner)->postJson("/api/launch-executions/{$google['id']}/reconcile")->assertOk()->assertJsonPath('data.status', 'failed');
    expect($creating->isTerminal())->toBeFalse();
});

/*
| Activation: created paused and verified first, then asked for, then approved by somebody who may switch campaigns on, then
| confirmed by the platform. Active only after that confirmation.
*/

/** An activator scripted per provider: no answer (throws), a refusal, or — for the lookup — a failure to ask. */
final class ScriptedActivator implements App\Application\Campaign\Contract\CampaignActivator
{
    /** @var list<string> providers whose activation request gets no answer */
    public static array $failFor = [];

    /** @var list<string> providers whose activation request is refused */
    public static array $refuseFor = [];

    /** @var list<string> providers whose platform cannot be read */
    public static array $unreadableFor = [];

    /** @var int how many activation requests actually left */
    public static int $sent = 0;

    public function activate(CreationRequest $request, string $campaignId, string $adGroupId, array $adIds): App\Domain\Advertising\Launch\ActivationOutcome
    {
        self::$sent++;

        if (in_array($request->plan->provider->value, self::$failFor, true)) {
            throw new RuntimeException('timeout');
        }

        if (in_array($request->plan->provider->value, self::$refuseFor, true)) {
            return new App\Domain\Advertising\Launch\ActivationOutcome(App\Domain\Advertising\Launch\ActivationStatus::Failed, MetricSource::Sandbox, [], 'The platform refused to switch it on.');
        }

        return (new App\Infrastructure\Campaign\RoutingCampaignActivator)->activate($request, $campaignId, $adGroupId, $adIds);
    }

    public function read(CreationRequest $request, string $campaignId, string $adGroupId, array $adIds): App\Domain\Advertising\Launch\ActivationOutcome
    {
        if (in_array($request->plan->provider->value, self::$unreadableFor, true)) {
            throw new RuntimeException('lookup refused');
        }

        return (new App\Infrastructure\Campaign\RoutingCampaignActivator)->read($request, $campaignId, $adGroupId, $adIds);
    }
}

/** @return array{scene: array<string, mixed>, id: string, owner: User} */
function verifiedPlan(): array
{
    $plan = approvedPlan();
    test()->actingAs($plan['owner'])->postJson("/api/campaign-plans/{$plan['id']}/launch")->assertStatus(202);

    return $plan;
}

it('switches a verified launch on in two acts, and calls it active only once the platform confirms', function (): void {
    ['id' => $id, 'owner' => $owner] = verifiedPlan();

    $this->actingAs($owner)->postJson("/api/campaign-plans/{$id}/activation")->assertNoContent();
    $this->actingAs($owner)->getJson("/api/campaign-plans/{$id}")->assertJsonPath('data.status', 'activation_pending');
    expect(DB::table('campaigns')->where('external_id', 'like', 'SANDBOX-CAMPAIGN-%')->pluck('status')->unique()->all())->toBe(['paused']);

    $this->actingAs($owner)->postJson("/api/campaign-plans/{$id}/activate")->assertStatus(202)->assertJsonPath('data.queued', 2);

    $this->actingAs($owner)->getJson("/api/campaign-plans/{$id}")->assertJsonPath('data.status', 'active');
    $executions = $this->actingAs($owner)->getJson("/api/campaign-plans/{$id}/launches")->json('data');
    expect(array_column($executions, 'activation_status'))->toBe(['active', 'active'])
        ->and(DB::table('campaigns')->where('external_id', 'like', 'SANDBOX-CAMPAIGN-%')->pluck('status')->unique()->all())->toBe(['active'])
        ->and(DB::table('audit_logs')->where('action', 'campaign_activation.executed')->count())->toBe(2);
});

it('refuses to switch on what was not created and verified, and keeps activation its own permission', function (): void {
    ['scene' => $s, 'id' => $id, 'owner' => $owner] = approvedPlan();

    $this->actingAs($owner)->postJson("/api/campaign-plans/{$id}/activation")->assertUnprocessable()->assertJsonPath('code', 'invalid_transition');
    $this->actingAs($owner)->postJson("/api/campaign-plans/{$id}/launch")->assertStatus(202);

    Auth::forgetGuards();
    $pm = $s['w']->scenario->addMember(SystemRole::ProjectManager);
    DB::table('project_members')->insert(['id' => (string) Str::uuid7(), 'project_id' => $s['project']->getKey(), 'user_id' => $pm->getKey(),
        'role_id' => $s['w']->scenario->role(SystemRole::ProjectManager)->getKey(), 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($pm)->postJson("/api/campaign-plans/{$id}/activation")->assertForbidden();

    Auth::forgetGuards();
    $this->actingAs($owner)->postJson("/api/campaign-plans/{$id}/activation")->assertNoContent();
    Auth::forgetGuards();
    $this->actingAs($pm)->postJson("/api/campaign-plans/{$id}/activate")->assertForbidden();
});

it('does not call a plan active while part of it is not, and lets the failed part be approved again', function (): void {
    app()->bind(App\Application\Campaign\Contract\CampaignActivator::class, ScriptedActivator::class);
    ScriptedActivator::$refuseFor = ['google_ads'];
    ['id' => $id, 'owner' => $owner] = verifiedPlan();

    $this->actingAs($owner)->postJson("/api/campaign-plans/{$id}/activation")->assertNoContent();
    $this->actingAs($owner)->postJson("/api/campaign-plans/{$id}/activate")->assertStatus(202);

    $this->actingAs($owner)->getJson("/api/campaign-plans/{$id}")->assertJsonPath('data.status', 'activation_pending')->assertJsonPath('data.status_reason', 'Active on 1 of 2 accounts.');

    ScriptedActivator::$refuseFor = [];
    $this->actingAs($owner)->postJson("/api/campaign-plans/{$id}/activate")->assertStatus(202)->assertJsonPath('data.queued', 1);
    $this->actingAs($owner)->getJson("/api/campaign-plans/{$id}")->assertJsonPath('data.status', 'active');
});

it('raises launch problems in the attention queue, with an unknown outcome as critical', function (): void {
    ScriptedCreator::$script = ['google_ads' => 'throw'];
    ['id' => $id, 'owner' => $owner] = approvedPlan();
    $this->actingAs($owner)->postJson("/api/campaign-plans/{$id}/launch")->assertStatus(202);

    $items = collect((array) $this->actingAs($owner)->getJson('/api/attention')->assertOk()->json('data'))->where('stream', 'launches');

    expect($items->pluck('severity')->all())->toContain('critical')
        ->and($items->pluck('reason.code')->all())->toContain('LAUNCH_UNKNOWN', 'LAUNCH_PARTIAL');
});

it('filters plans by platform and status, and counts each state', function (): void {
    ['id' => $id, 'owner' => $owner] = approvedPlan();

    $this->actingAs($owner)->getJson('/api/campaign-plans?provider=meta')->assertOk()->assertJsonPath('meta.counts.approved', 1)->assertJsonCount(1, 'data');
    $this->actingAs($owner)->getJson('/api/campaign-plans?provider=tiktok')->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($owner)->getJson('/api/campaign-plans?status=draft')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.counts.approved', 1);
});

it('takes a seat type on an invitation that fits the role, and refuses one that does not', function (): void {
    ['owner' => $owner] = approvedPlan();

    $this->actingAs($owner)->postJson('/api/members', ['name' => 'Client', 'email' => 'c@example.test', 'role' => 'client-viewer', 'seat_type' => 'full'])
        ->assertUnprocessable()->assertJsonPath('code', 'member_not_added');
    $this->actingAs($owner)->postJson('/api/members', ['name' => 'Part-time buyer', 'email' => 'p@example.test', 'role' => 'media-buyer', 'seat_type' => 'limited'])
        ->assertSuccessful();

    expect(DB::table('workspace_members as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('u.email', 'p@example.test')->value('m.seat_type'))->toBe('limited');
});

it('treats an activation nobody answered as unknown, never repeats it blindly, and settles it only by reading the platform', function (): void {
    app()->bind(App\Application\Campaign\Contract\CampaignActivator::class, ScriptedActivator::class);
    ScriptedActivator::$failFor = ['google_ads'];
    ScriptedActivator::$refuseFor = ScriptedActivator::$unreadableFor = [];
    ScriptedActivator::$sent = 0;
    ['id' => $id, 'owner' => $owner] = verifiedPlan();

    $this->actingAs($owner)->postJson("/api/campaign-plans/{$id}/activation")->assertNoContent();
    $this->actingAs($owner)->postJson("/api/campaign-plans/{$id}/activate")->assertStatus(202);

    $by = array_column((array) $this->actingAs($owner)->getJson("/api/campaign-plans/{$id}/launches")->json('data'), null, 'provider');
    expect($by['google_ads']['activation_status'])->toBe('unknown')->and($by['google_ads']['may_reconcile'])->toBeTrue()
        ->and($by['meta']['activation_status'])->toBe('active')
        // The unanswered account is not marked active in the product's own tables.
        ->and(DB::table('campaigns')->where('external_id', $by['google_ads']['provider_ids']['campaign'])->value('status'))->toBe('paused');
    $this->actingAs($owner)->getJson("/api/campaign-plans/{$id}")->assertJsonPath('data.status', 'activation_pending');
    expect((string) $this->actingAs($owner)->getJson("/api/campaign-plans/{$id}")->json('data.status_reason'))->toContain('unknown');

    $items = collect((array) $this->actingAs($owner)->getJson('/api/attention')->json('data'))->where('stream', 'launches');
    expect($items->pluck('reason.code')->all())->toContain('LAUNCH_ACTIVATION_UNKNOWN');

    // Approving again sends nothing for an account whose outcome is unknown.
    $sentBefore = ScriptedActivator::$sent;
    $this->actingAs($owner)->postJson("/api/campaign-plans/{$id}/activate")->assertStatus(202)->assertJsonPath('data.queued', 0);
    expect(ScriptedActivator::$sent)->toBe($sentBefore);

    // A platform that cannot be read leaves it unknown.
    ScriptedActivator::$unreadableFor = ['google_ads'];
    $this->actingAs($owner)->postJson("/api/launch-executions/{$by['google_ads']['id']}/reconcile")->assertOk()->assertJsonPath('data.activation_status', 'unknown');

    // Read, and reported still paused: a proven failure that may be approved again — and then it is switched on and confirmed.
    ScriptedActivator::$unreadableFor = [];
    ScriptedActivator::$failFor = [];
    $this->actingAs($owner)->postJson("/api/launch-executions/{$by['google_ads']['id']}/reconcile")->assertOk()->assertJsonPath('data.activation_status', 'failed');
    expect(ScriptedActivator::$sent)->toBe($sentBefore);
    $this->actingAs($owner)->postJson("/api/campaign-plans/{$id}/activate")->assertStatus(202)->assertJsonPath('data.queued', 1);
    $this->actingAs($owner)->getJson("/api/campaign-plans/{$id}")->assertJsonPath('data.status', 'active');
});

it('lets exactly one worker send an activation, and refuses to call an activation without a read-back active', function (): void {
    ['id' => $id, 'owner' => $owner] = verifiedPlan();
    $execution = (array) $this->actingAs($owner)->getJson("/api/campaign-plans/{$id}/launches")->json('data.0');
    $workspace = (string) DB::table('campaign_launch_executions')->where('id', $execution['id'])->value('workspace_id');
    $repository = app(App\Application\Campaign\Contract\LaunchRepository::class);
    DB::table('campaign_launch_executions')->where('id', $execution['id'])->update(['activation_status' => 'activating']);

    expect($repository->transitionActivation($workspace, $execution['id'], App\Domain\Advertising\Launch\ActivationStatus::Activating, ['activation_status' => 'sending']))->toBeTrue()
        ->and($repository->transitionActivation($workspace, $execution['id'], App\Domain\Advertising\Launch\ActivationStatus::Activating, ['activation_status' => 'sending']))->toBeFalse();

    // An "active" answer that observed nothing is not a confirmation.
    $record = $repository->execution($workspace, $execution['id']);
    expect($record)->not->toBeNull();
    assert($record !== null);
    app(App\Application\Campaign\Launch\ExecuteActivation::class)->settle(
        $record,
        new App\Domain\Advertising\Launch\ActivationOutcome(App\Domain\Advertising\Launch\ActivationStatus::Active, MetricSource::Sandbox, [], null),
        'meta', 'Plan', (string) $owner->getKey(),
    );
    expect(DB::table('campaign_launch_executions')->where('id', $execution['id'])->value('activation_status'))->toBe('unknown');
});
