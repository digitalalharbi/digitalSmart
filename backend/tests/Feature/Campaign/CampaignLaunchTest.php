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
