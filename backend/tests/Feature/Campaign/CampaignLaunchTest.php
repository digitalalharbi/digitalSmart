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
    /** @var array<string, string> provider → 'throw' | 'fail' */
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
        return $this->sandbox->readBack($request, $campaignId, $adGroupId, $adIds);
    }

    public function find(CreationRequest $request): ?CreateOutcome
    {
        return $this->sandbox->find($request);
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

/*
| Activation: created paused and verified first, then asked for, then approved by somebody who may switch campaigns on, then
| confirmed by the platform. Active only after that confirmation.
*/

/** An activator that fails for the providers listed in the script. */
final class ScriptedActivator implements App\Application\Campaign\Contract\CampaignActivator
{
    /** @var list<string> */
    public static array $failFor = [];

    public function activate(CreationRequest $request, string $campaignId, string $adGroupId, array $adIds): App\Domain\Advertising\Launch\ActivationOutcome
    {
        if (in_array($request->plan->provider->value, self::$failFor, true)) {
            throw new RuntimeException('timeout');
        }

        return (new App\Infrastructure\Campaign\RoutingCampaignActivator)->activate($request, $campaignId, $adGroupId, $adIds);
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
    ScriptedActivator::$failFor = ['google_ads'];
    ['id' => $id, 'owner' => $owner] = verifiedPlan();

    $this->actingAs($owner)->postJson("/api/campaign-plans/{$id}/activation")->assertNoContent();
    $this->actingAs($owner)->postJson("/api/campaign-plans/{$id}/activate")->assertStatus(202);

    $this->actingAs($owner)->getJson("/api/campaign-plans/{$id}")->assertJsonPath('data.status', 'activation_pending')->assertJsonPath('data.status_reason', 'Active on 1 of 2 accounts.');

    ScriptedActivator::$failFor = [];
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
