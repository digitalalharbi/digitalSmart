<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Integration\Provider;
use App\Infrastructure\Persistence\Eloquent\Model\Campaign;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyncedWorkspace;

function mcpWorld(): SyncedWorkspace
{
    return SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function mcpCall(object $as, string $tool, array $arguments = []): array
{
    return test()->actingAs($as)->postJson('/api/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments],
    ])->assertOk()->json('result');
}

function activeCampaignId(): string
{
    return (string) Campaign::query()->where('status', 'active')->firstOrFail()->getKey();
}

it('speaks MCP: initialises, lists its tools with schemas, and flags what only reads', function (): void {
    $owner = mcpWorld()->scenario->owner;

    $init = test()->actingAs($owner)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []])->assertOk()->json('result');
    expect($init['serverInfo']['name'])->toBe('digitalsmart')->and($init['capabilities'])->toHaveKey('tools');

    $tools = test()->actingAs($owner)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])->assertOk()->json('result.tools');
    $byName = array_column($tools, null, 'name');

    expect($byName)->toHaveKeys(['list_campaigns', 'get_campaign', 'propose_pause_campaign', 'propose_budget_change', 'create_task'])
        ->and($byName['list_campaigns']['annotations']['readOnlyHint'])->toBeTrue()
        ->and($byName['propose_budget_change']['annotations']['readOnlyHint'])->toBeFalse()
        ->and($byName['propose_budget_change']['inputSchema']['additionalProperties'])->toBeFalse()
        // No tool is a way to send an arbitrary request to a provider, and none deletes.
        ->and(array_keys($byName))->not->toContain('delete_campaign');

    test()->actingAs($owner)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'nope'])->assertOk()->assertJsonPath('error.code', -32601);
});

it('reads campaigns through a tool, in the caller’s own scope', function (): void {
    $owner = mcpWorld()->scenario->owner;

    $result = mcpCall($owner, 'list_campaigns', ['limit' => 5]);
    $data = json_decode($result['content'][0]['text'], true);

    expect($result['isError'])->toBeFalse()->and($data['campaigns'])->not->toBeEmpty()
        ->and($data['campaigns'][0])->toHaveKeys(['id', 'name', 'health', 'spend_minor', 'cost_per_result_minor']);
});

it('refuses unknown input, a missing permission and a tool that does not exist — and logs each', function (): void {
    $w = mcpWorld();
    $owner = $w->scenario->owner;
    $creative = $w->scenario->addMember(SystemRole::Creative);

    $extra = mcpCall($owner, 'list_campaigns', ['limit' => 5, 'sql' => 'drop table']);
    $forbidden = mcpCall($creative, 'list_campaigns');
    $unknown = mcpCall($owner, 'delete_campaign', ['campaign_id' => activeCampaignId()]);

    foreach ([$extra, $forbidden, $unknown] as $result) {
        expect($result['isError'])->toBeTrue();
    }

    expect(json_decode($extra['content'][0]['text'], true)['code'])->toBe('invalid_input')
        ->and(json_decode($forbidden['content'][0]['text'], true)['code'])->toBe('forbidden')
        ->and(json_decode($unknown['content'][0]['text'], true)['code'])->toBe('unknown_tool')
        ->and(DB::table('agent_tool_calls')->where('outcome', 'refused')->count())->toBe(3);
});

it('turns a proposal into a decision for a person and changes nothing on the platform', function (): void {
    $w = mcpWorld();
    $owner = $w->scenario->owner;
    $campaign = activeCampaignId();

    $result = mcpCall($owner, 'propose_pause_campaign', ['campaign_id' => $campaign, 'reason' => 'Cost per result is well over target.']);
    $data = json_decode($result['content'][0]['text'], true);

    expect($result['isError'])->toBeFalse()->and($data['proposal']['state'])->toBe('pending');

    $row = DB::table('approval_requests')->where('id', $data['proposal']['id'])->sole();
    expect($row->origin)->toBe('agent')
        // Recorded under the channel's own name, not one the caller chose.
        ->and($row->agent)->toBe('external_ai')
        ->and($row->state)->toBe('pending')
        ->and($row->expires_at)->not->toBeNull()
        ->and(json_decode((string) $row->evidence, true))->toHaveKeys(['health', 'spend_minor', 'target_roas_source']);

    // A second one for the same campaign is refused rather than stacked.
    $again = mcpCall($owner, 'propose_pause_campaign', ['campaign_id' => $campaign, 'reason' => 'Again.']);
    expect(json_decode($again['content'][0]['text'], true)['code'])->toBe('already_proposed');
});

it('holds a budget proposal to the project’s own limits, in code', function (): void {
    $w = mcpWorld();
    $owner = $w->scenario->owner;
    $campaign = Campaign::query()->where('status', 'active')->where('budget_mode', 'daily')->whereNotNull('budget_minor')->firstOrFail();

    $id = (string) $campaign->getKey();
    $current = (int) $campaign->getAttribute('budget_minor');

    // 150% of today's budget is past the agency default of 20% a change may move.
    $big = mcpCall($owner, 'propose_budget_change', ['campaign_id' => $id, 'daily_budget_minor' => (int) ($current * 1.5), 'reason' => 'Scale it.']);
    expect(json_decode($big['content'][0]['text'], true)['code'])->toBe('change_too_large');

    $same = mcpCall($owner, 'propose_budget_change', ['campaign_id' => $id, 'daily_budget_minor' => $current, 'reason' => 'No change.']);
    expect(json_decode($same['content'][0]['text'], true)['code'])->toBe('budget_unchanged');

    $ok = mcpCall($owner, 'propose_budget_change', ['campaign_id' => $id, 'daily_budget_minor' => (int) ($current * 1.1), 'reason' => 'Return is steady.']);
    $proposal = json_decode($ok['content'][0]['text'], true);
    expect($ok['isError'])->toBeFalse();

    $row = DB::table('approval_requests')->where('id', $proposal['proposal']['id'])->sole();
    expect(json_decode((string) $row->proposal, true))->toHaveKeys(['current', 'requested', 'currency', 'daily_budget_minor', 'external_campaign_id']);

    // And the cooldown: a second change straight after is refused whatever it asks for.
    $next = mcpCall($owner, 'propose_budget_change', ['campaign_id' => $id, 'daily_budget_minor' => (int) ($current * 1.05), 'reason' => 'More.']);
    expect(json_decode($next['content'][0]['text'], true)['code'])->toBeIn(['already_proposed', 'cooling_down']);
});

it('lets an AI client’s token reach the MCP endpoint and nothing else', function (): void {
    $owner = mcpWorld()->scenario->owner;
    $token = $owner->createToken('Claude', ['mcp'])->plainTextToken;

    test()->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertOk();
    test()->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/portfolio')->assertForbidden()->assertJsonPath('code', 'mcp_token_scope');
    test()->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/mcp/tokens', ['name' => 'More'])->assertForbidden();
});

it('never lets one workspace’s campaign be reached through another’s tool call', function (): void {
    $a = mcpWorld();
    $b = mcpWorld();
    $foreign = (string) Campaign::query()->where('workspace_id', $b->scenario->workspace->getKey())->firstOrFail()->getKey();

    $result = mcpCall($a->scenario->owner, 'get_campaign', ['campaign_id' => $foreign]);

    expect($result['isError'])->toBeTrue()
        ->and(json_decode($result['content'][0]['text'], true)['code'])->toBe('not_found');
});
