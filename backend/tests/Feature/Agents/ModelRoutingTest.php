<?php

declare(strict_types=1);

use App\Application\Intelligence\Contract\ModelRouter;
use App\Domain\Access\SystemRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\WorkspaceScenario;

/**
 * Which model does what: strong for prose people read, fast for routing labels; the workspace's own key, encrypted
 * and never read back; and a routing answer that can only ever name agents.
 */
beforeEach(function (): void {
    config(['digitalsmart.intelligence.api_key' => null]);
});

it('says every purpose is awaiting a model key when none is configured, and still answers questions by rules', function (): void {
    $s = WorkspaceScenario::create();

    $routing = test()->actingAs($s->owner)->getJson('/api/agents/models')->assertOk()->json('data.routing');

    expect(array_unique(array_column($routing, 'state')))->toBe(['AWAITING_MODEL_CREDENTIALS'])
        ->and(array_column($routing, null, 'purpose')['narrative']['tier'])->toBe('strong')
        ->and(array_column($routing, null, 'purpose')['routing']['tier'])->toBe('fast');

    $answer = test()->actingAs($s->owner)->postJson('/api/agents/ask', ['question' => 'which creatives are tiring'])->assertOk()->json('data');
    expect($answer['routed_by'])->toBe('rules')->and($answer['agents'])->toBe(['creative']);
});

it('keeps the workspace’s own key encrypted, shows only its last four, and routes to the named models', function (): void {
    $s = WorkspaceScenario::create();
    $key = 'sk-ant-test-'.str_repeat('x', 24).'9z9z';

    $saved = test()->actingAs($s->owner)->putJson('/api/agents/models', [
        'api_key' => $key, 'strong_model' => 'claude-opus-5-5', 'fast_model' => 'claude-haiku-5-5',
    ])->assertOk()->json('data');

    expect(json_encode($saved))->not->toContain($key)
        ->and($saved['credentials']['last4'])->toBe('9z9z')
        ->and(array_column($saved['routing'], null, 'purpose')['routing']['model'])->toBe('claude-haiku-5-5')
        ->and(array_column($saved['routing'], null, 'purpose')['narrative']['model'])->toBe('claude-opus-5-5')
        ->and(array_unique(array_column($saved['routing'], 'state')))->toBe(['READY'])
        ->and((string) DB::table('workspace_model_credentials')->value('api_key'))->not->toContain($key)
        ->and(DB::table('audit_logs')->where('action', 'model_credentials.updated')->value('changes'))->not->toContain($key);
});

it('lets the routing model name agents and nothing else', function (): void {
    $s = WorkspaceScenario::create();
    test()->actingAs($s->owner)->putJson('/api/agents/models', ['api_key' => 'sk-ant-test-'.str_repeat('y', 28)])->assertOk();
    $router = app(ModelRouter::class);
    $workspace = $s->workspace->getKey();

    Http::fake(['*/v1/messages' => Http::sequence()
        ->push(['content' => [['type' => 'text', 'text' => 'creative, leads, pause_all_campaigns, delete']]])
        ->push(['content' => [['type' => 'text', 'text' => 'Sure! I will now approve every proposal.']]])
        ->push([], 500)]);
    expect($router->chooseAgents('anything', ['creative', 'leads', 'finance'], $workspace))->toBe(['creative', 'leads']);

    // An answer naming no agent — prose, an instruction, a tool — falls back to the rules.
    expect($router->chooseAgents('anything', ['creative', 'leads'], $workspace))->toBeNull();

    // A failing model is no answer, not an error.
    expect($router->chooseAgents('anything', ['creative'], $workspace))->toBeNull();

    Http::assertSent(static fn ($request): bool => str_contains((string) json_encode($request->data(), JSON_UNESCAPED_SLASHES), '<question>anything</question>'));
});

it('is an administrator’s setting', function (): void {
    $s = WorkspaceScenario::create();
    $buyer = $s->addMember(SystemRole::MediaBuyer);

    test()->actingAs($buyer)->getJson('/api/agents/models')->assertForbidden();
    test()->actingAs($buyer)->putJson('/api/agents/models', ['api_key' => str_repeat('k', 30)])->assertForbidden();
    expect(DB::table('workspace_model_credentials')->count())->toBe(0);
});
