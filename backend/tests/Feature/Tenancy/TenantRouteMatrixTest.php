<?php

declare(strict_types=1);

use App\Infrastructure\Persistence\Eloquent\Model\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAgencySeeder;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as Router;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| The executable tenant matrix
|--------------------------------------------------------------------------
|
| Every authenticated API route that takes an identifier is called by the owner of a fresh workspace — who holds every
| permission there is — with identifiers that belong to other workspaces (the demo and the demo agency). Not one may answer
| 2xx, and not one may answer 5xx: a refusal is the only acceptable answer to "act on something that is not yours".
|
| A route is in the sweep, or it is listed below with the reason it is not and the test that covers it instead. A new route
| that takes an identifier and is neither fails this file, so the matrix cannot quietly fall behind the API.
|
*/

/** Route parameter → the table its identifier comes from. */
const TENANT_PARAM_TABLES = [
    'client' => 'clients',
    'project' => 'projects',
    'lead' => 'leads',
    'report' => 'reports',
    'accessRequest' => 'access_requests',
    'clientRequest' => 'client_requests',
    'blueprint' => 'campaign_blueprints',
    'approval' => 'approval_requests',
    'budget' => 'budgets',
    'creative' => 'creative_requests',
    'account' => 'external_accounts',
    'source' => 'measurement_sources',
    'user' => 'users',
    'task' => 'tasks',
    'link' => 'tracking_links',
    'campaign' => 'campaigns',
    'dashboard' => 'dashboards',
    'tag' => 'tags',
    'connection' => 'provider_connections',
    'notification' => 'notifications',
    'form' => 'forms',
    'rule' => 'automation_rules',
    'definition' => 'event_definitions',
    'funnel' => 'funnels',
    'attachment' => 'client_request_attachments',
    'store' => 'stores',
    'version' => 'creative_versions',
    'digest' => 'digests',
    'export' => 'export_jobs',
    'share' => 'report_shares',
    'dispatch' => 'provider_event_dispatches',
    'mapping' => 'conversion_mappings',
    'workspace' => 'workspaces',
    'schedule' => 'report_schedules',
];

/** Parameters that are a word, not an identifier: a value that lets the request reach the identifier beside it. */
const LITERAL_PARAMS = [
    'provider' => 'meta',
    'kind' => 'weekly_report',
    'surface' => 'campaigns',
    'code' => 'measurement',
    'level' => 'ad_group',
];

/**
 * Routes whose identifier is resolved by a route-specific rule rather than by its name.
 *
 * @return array<string, array<string, string>> "METHOD uri" → param → table
 */
function tenantOverrides(): array
{
    return [
        'POST api/campaigns/entities/{level}/{entity}/status-request' => ['entity' => 'ad_groups'],
        'DELETE api/views/{view}' => ['view' => 'surface_views'],
        'DELETE api/analytics/views/{view}' => ['view' => 'saved_views'],
        'PUT api/analytics/views/{view}' => ['view' => 'saved_views'],
        'POST api/analytics/views/{view}/opened' => ['view' => 'saved_views'],
        'PUT api/shortcuts/pins/{type}/{id}' => ['type' => '=client', 'id' => 'clients'],
        'DELETE api/shortcuts/pins/{type}/{id}' => ['type' => '=client', 'id' => 'clients'],
        'POST api/tags/{tag}/entities/{type}' => ['type' => '=campaign'],
        'PUT api/tags/{tag}/entities/{type}/{id}' => ['type' => '=campaign', 'id' => 'campaigns'],
        'DELETE api/tags/{tag}/entities/{type}/{id}' => ['type' => '=campaign', 'id' => 'campaigns'],
    ];
}

/**
 * Routes outside the sweep, each with why and the test that holds it instead.
 *
 * @return array<string, string>
 */
function tenantCoveredElsewhere(): array
{
    return [
        /* A Sanctum token's id is an integer scoped to its owner, not a workspace row; revoking another's is a 404 there. */
        'DELETE api/mcp/tokens/{token}' => 'tests/Feature/Agents/McpAndToolsTest.php',
        /* A digest belongs to one person; reading another's is refused there, across people and workspaces. */
        'POST api/digests/{digest}/read' => 'tests/Feature/Digest/DigestTest.php',
        /* A dispatch exists only after a tracked conversion is sent; the retry is asserted 404 from another workspace there. */
        'POST api/tracking/conversions/dispatches/{dispatch}/retry' => 'tests/Feature/Tracking/ConversionDispatchTest.php',
        /* An attachment needs a real upload; another client's and another workspace's are asserted 404 there. */
        'GET api/client-request-attachments/{attachment}' => 'tests/Feature/Operations/ClientRequestAttachmentTest.php',
    ];
}

/** @return list<array{method: string, uri: string, params: list<string>}> */
function tenantRoutes(): array
{
    $out = [];

    foreach (Router::getRoutes()->getRoutes() as $route) {
        /** @var Route $route */
        $uri = $route->uri();

        if (! str_starts_with($uri, 'api/') || ! str_contains($uri, '{')) {
            continue;
        }

        $middleware = implode(' ', $route->gatherMiddleware());

        if (! str_contains($middleware, 'auth')) {
            continue;
        }

        preg_match_all('/\{(\w+)\??\}/', $uri, $m);

        foreach ($route->methods() as $method) {
            if ($method === 'HEAD') {
                continue;
            }

            $out[] = ['method' => $method, 'uri' => $uri, 'params' => $m[1]];
        }
    }

    return $out;
}

/**
 * Give the demo workspace one of each thing its seeders do not make, through the API as its own owner, so the sweep has a
 * real foreign identifier for every route rather than a skipped one.
 */
function seedForeignGaps(): void
{
    $owner = User::query()->where('email', 'owner@demo.digitalsmart.test')->firstOrFail();
    $workspace = (string) DB::table('workspaces')->where('slug', 'demo-smart-code')->value('id');
    $project = (string) DB::table('projects')->where('workspace_id', $workspace)->orderBy('id')->value('id');
    $report = (string) DB::table('reports')->where('workspace_id', $workspace)->orderBy('id')->value('id');
    $as = test()->actingAs($owner);

    $as->postJson('/api/dashboards', [
        'name' => 'Foreign dashboard', 'visibility' => 'private', 'filters' => ['preset' => '28d'],
        'widgets' => [['type' => 'bar', 'metrics' => ['spend'], 'dimension' => 'campaign', 'column' => 0, 'row' => 0, 'width' => 6, 'height' => 2]],
    ])->assertCreated();
    $as->postJson('/api/analytics/views', [
        'name' => 'Foreign view', 'visibility' => 'private',
        'query' => ['preset' => '28d', 'metrics' => ['spend'], 'dimension' => 'campaign'],
    ])->assertCreated();
    $as->postJson('/api/views/campaign_control', ['name' => 'Foreign surface view', 'filters' => ['status' => 'active']])->assertCreated();
    $as->postJson('/api/tags', ['name' => 'Foreign tag'])->assertCreated();
    $binding = DB::table('project_integration_bindings')->where('workspace_id', $workspace)->orderBy('id')->first(['project_id', 'external_account_id']);
    $as->postJson('/api/campaign-plans', [
        'project_id' => $binding?->project_id, 'name' => 'Foreign plan', 'objective' => 'traffic', 'budget_type' => 'daily', 'budget_minor' => 10_000,
        'currency' => 'SAR', 'starts_at' => '2027-01-01', 'external_account_ids' => [$binding?->external_account_id], 'landing_url' => 'https://shop.example.com/x',
        'targeting' => ['countries' => ['SA']], 'placement' => ['mode' => 'automatic'], 'ad_copy' => ['headline' => 'Foreign'], 'creative_request_ids' => [],
    ])->assertCreated();
    $as->postJson('/api/tracking/links', ['project_id' => $project, 'name' => 'Foreign link', 'destination_url' => 'https://shop.example.com/x'])->assertCreated();
    $as->postJson('/api/tracking/forms', [
        'project_id' => $project, 'name' => 'Foreign form', 'creates_lead' => true,
        'fields' => [
            ['key' => 'name', 'type' => 'text', 'label_en' => 'Name', 'required' => true, 'maps_to' => 'name'],
            ['key' => 'phone', 'type' => 'phone', 'label_en' => 'Phone', 'required' => true, 'maps_to' => 'phone'],
        ],
    ])->assertCreated();
    $as->postJson('/api/tracking/funnels', [
        'project_id' => $project, 'name' => 'Foreign funnel', 'stages' => [['source' => 'link_click'], ['source' => 'lead_created']],
    ])->assertCreated();
    $as->postJson('/api/tracking/conversions/mappings', [
        'project_id' => $project, 'event' => 'lead_created', 'provider' => 'snapchat', 'provider_event' => 'SIGN_UP',
    ])->assertCreated();
    $safe = (string) DB::table('reports')->where('workspace_id', $workspace)->where('audience', 'client_safe')->orderBy('id')->value('id');
    $as->postJson("/api/reports/{$safe}/shares", ['days' => 7])->assertCreated();
    $as->postJson('/api/exports/schedules', ['report_id' => $report, 'kind' => 'report_csv', 'cadence' => 'daily', 'hour' => 6])->assertCreated();

    config(['digitalsmart.access_requests.workspace_slug' => 'demo-smart-code']);
    test()->postJson('/api/public/access-requests', ['name' => 'Foreign visitor', 'email' => 'foreign@example.test'])->assertAccepted();
    config(['digitalsmart.access_requests.workspace_slug' => null]);
}

it('refuses every identifier from another workspace on every authenticated route', function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DemoAgencySeeder::class);
    seedForeignGaps();

    $foreign = DB::table('workspaces')->pluck('id')->all();
    $mine = WorkspaceScenario::create('Tenant A');

    $sample = function (string $table) use ($foreign): ?string {
        if ($table === 'users') {
            $id = DB::table('workspace_members')->whereIn('workspace_id', $foreign)->value('user_id');
        } elseif ($table === 'workspaces') {
            $id = $foreign[0] ?? null;
        } else {
            $id = DB::table($table)->whereIn('workspace_id', $foreign)->value('id');
        }

        return is_string($id) ? $id : null;
    };

    $overrides = tenantOverrides();
    $unclassified = [];
    $noFixture = [];
    $leaks = [];
    $swept = 0;

    foreach (tenantRoutes() as $route) {
        $key = $route['method'].' '.$route['uri'];

        if (array_key_exists($key, tenantCoveredElsewhere())) {
            continue;
        }
        $rules = $overrides[$key] ?? [];
        $url = '/'.$route['uri'];
        $hasIdentifier = false;
        $skip = false;

        foreach ($route['params'] as $param) {
            $rule = $rules[$param] ?? null;

            if ($rule !== null && str_starts_with($rule, '=')) {
                $value = substr($rule, 1);
            } elseif (array_key_exists($param, LITERAL_PARAMS) && $rule === null) {
                $value = LITERAL_PARAMS[$param];
            } else {
                $table = $rule ?? (TENANT_PARAM_TABLES[$param] ?? null);

                if ($table === null) {
                    $unclassified[] = "{$key} ({$param})";
                    $skip = true;

                    break;
                }

                $value = $sample($table);

                if ($value === null) {
                    $noFixture[$table][] = $key;
                    $skip = true;

                    break;
                }

                $hasIdentifier = true;
            }

            $url = preg_replace('/\{'.$param.'\??\}/', $value, $url) ?? $url;
        }

        if ($skip || ! $hasIdentifier) {
            continue;
        }

        $response = $this->actingAs($mine->owner)->json($route['method'], $url, []);
        $status = $response->getStatusCode();
        $swept++;

        if ($status < 300 || $status >= 500) {
            $leaks[] = "{$key} → {$status}";
        }
    }

    file_put_contents(sys_get_temp_dir().'/tenant-matrix.json', json_encode(['swept' => $swept, 'leaks' => $leaks, 'no_fixture' => $noFixture], JSON_PRETTY_PRINT));

    expect($unclassified)->toBe([], 'routes whose identifier this matrix does not know how to source')
        ->and($noFixture)->toBe([], 'routes with no foreign identifier to try — give them a fixture or a reference above')
        ->and($leaks)->toBe([], 'routes that answered another workspace’s identifier with success or a server error');
});

it('never shows another workspace’s records in a list, a search or a filtered read', function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DemoAgencySeeder::class);
    seedForeignGaps();

    $foreign = DB::table('workspaces')->pluck('id')->all();
    $mine = WorkspaceScenario::create('Tenant A');

    /* What would identify a foreign record if it appeared: ids of the things a list could name, and client names. */
    $markers = [];

    foreach (['clients', 'projects', 'campaigns', 'leads', 'reports', 'tasks', 'approval_requests', 'client_requests', 'creative_requests', 'external_accounts', 'tracking_links', 'forms', 'access_requests', 'dashboards', 'tags'] as $table) {
        foreach (DB::table($table)->whereIn('workspace_id', $foreign)->limit(25)->pluck('id') as $id) {
            $markers[] = (string) $id;
        }
    }

    foreach (DB::table('clients')->whereIn('workspace_id', $foreign)->pluck('name') as $name) {
        if (mb_strlen((string) $name) >= 6) {
            $markers[] = (string) $name;
        }
    }

    $first = fn (string $table): string => (string) DB::table($table)->whereIn('workspace_id', $foreign)->value('id');
    $filters = [
        'client_id' => $first('clients'), 'project_id' => $first('projects'), 'campaign_id' => $first('campaigns'),
        'client' => $first('clients'), 'project' => $first('projects'), 'campaign' => $first('campaigns'),
        'report_id' => $first('reports'), 'lead_id' => $first('leads'), 'account_id' => $first('external_accounts'),
        'q' => 'a', 'search' => 'a',
    ];

    $leaks = [];
    $errors = [];
    $read = 0;

    foreach (Router::getRoutes()->getRoutes() as $route) {
        /** @var Route $route */
        $uri = $route->uri();

        if (! in_array('GET', $route->methods(), true) || ! str_starts_with($uri, 'api/') || str_contains($uri, '{')) {
            continue;
        }

        if (! str_contains(implode(' ', $route->gatherMiddleware()), 'auth')) {
            continue;
        }

        $response = $this->actingAs($mine->owner)->getJson('/'.$uri.'?'.http_build_query($filters));
        $read++;

        if ($response->getStatusCode() >= 500) {
            $errors[] = "{$uri} → {$response->getStatusCode()}";

            continue;
        }

        $body = (string) $response->getContent();

        foreach ($markers as $marker) {
            if (str_contains($body, $marker)) {
                $leaks[] = "{$uri} shows {$marker}";

                break;
            }
        }
    }

    expect($read)->toBeGreaterThan(50)
        ->and($errors)->toBe([], 'lists that fail outright when handed another workspace’s ids')
        ->and($leaks)->toBe([], 'lists that show another workspace’s records');
});
