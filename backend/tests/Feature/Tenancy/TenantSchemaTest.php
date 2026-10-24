<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Who owns every table
|--------------------------------------------------------------------------
|
| Every table is one of three things: it carries `workspace_id`; it hangs off a table that does, through a foreign key
| that deletes with it; or it is global by nature (identity, the permission catalogue, the framework's own plumbing).
| A table that is none of these fails here, so a new table cannot arrive without somebody deciding whose it is.
|
| And every unique constraint on a tenant table either names the tenant or names something that is itself tenant-owned
| (or is a random token, unique everywhere on purpose). One that does neither lets one workspace's value block another's.
|
*/

/** Scoped through a parent: table → the column and the tenant-owned table it cascades from. */
const TRANSITIVE_TABLES = [
    'automation_rule_streaks' => ['rule_id', 'automation_rules'],
    'campaign_blueprint_creatives' => ['blueprint_id', 'campaign_blueprints'],
    'campaign_blueprint_targets' => ['blueprint_id', 'campaign_blueprints'],
    'creative_assets' => ['creative_id', 'creatives'],
    'dashboard_widgets' => ['dashboard_id', 'dashboards'],
    'form_fields' => ['form_id', 'forms'],
    'lead_round_robin' => ['project_id', 'projects'],
    'project_members' => ['project_id', 'projects'],
    'tracking_daily_rollups' => ['project_id', 'projects'],
];

/** Global by nature, each for a stated reason. */
const GLOBAL_TABLES = [
    'users' => 'one identity per person across every workspace; membership is per workspace',
    'permissions' => 'the permission catalogue, the same for every tenant',
    'permission_role' => 'joins a tenant role (which carries workspace_id) to the global catalogue',
    'creative_specs' => 'platform specifications, the same for every tenant and read-only through the API',
    'plans' => 'the plan catalogue, the same for every tenant; a workspace points at one through workspace_subscriptions',
    'plan_features' => 'which features a catalogue plan includes',
    'plan_limits' => 'the limits of a catalogue plan',
    'personal_access_tokens' => 'Sanctum tokens, scoped to the user who owns them and to the mcp ability',
    'sessions' => 'framework sessions; the active workspace is re-checked against membership on every request',
    'password_reset_tokens' => 'framework table; unused (there is no reset flow)',
    'cache' => 'framework cache; tenant-dependent keys carry the workspace id',
    'cache_locks' => 'framework cache locks',
    'jobs' => 'queue; each job carries its own workspace scope and revalidates it',
    'job_batches' => 'queue batches',
    'failed_jobs' => 'queue failures, read only by operators',
    'migrations' => 'schema history',
    'workspaces' => 'the tenant itself',
];

/**
 * Unique constraints on tenant tables that do not include `workspace_id`, and why each is still tenant-safe.
 *
 * @return array<string, string> "table(columns)" → reason
 */
function tenantSafeUniques(): array
{
    $ownedBy = static fn (string $parent): string => "bounded by {$parent}, which is tenant-owned";
    $token = 'a random token, unique across the installation on purpose';

    return [
        'access_invitations(token_hash)' => $token,
        'plans(code)' => 'a catalogue plan code, global by design',
        'campaign_blueprint_targets(blueprint_id,external_account_id)' => $ownedBy('campaign_blueprints'),
        'campaign_blueprint_creatives(blueprint_id,creative_request_id)' => $ownedBy('campaign_blueprints'),
        'approval_links(token_hash)' => $token,
        'report_shares(token_hash)' => $token,
        'forms(public_token)' => $token,
        'tracking_links(public_token)' => $token,
        'client_request_attachments(stored_name)' => 'a generated storage name, unique across the installation',
        'ad_groups(external_account_id,external_id)' => $ownedBy('external_accounts'),
        'ads(external_account_id,external_id)' => $ownedBy('external_accounts'),
        'campaigns(external_account_id,external_id)' => $ownedBy('external_accounts'),
        'creatives(external_account_id,external_id)' => $ownedBy('external_accounts'),
        'sync_cursors(external_account_id,kind)' => $ownedBy('external_accounts'),
        'external_accounts(provider_connection_id,external_id)' => $ownedBy('provider_connections').' — two workspaces connecting one real account get two rows',
        'client_portal_modules(client_portal_id,module)' => $ownedBy('client_portals'),
        'client_portals(client_id)' => $ownedBy('clients'),
        'conversion_mappings(project_id,event,provider)' => $ownedBy('projects'),
        'funnels(project_id,name)' => $ownedBy('projects'),
        'form_submissions(project_id,event_id)' => $ownedBy('projects'),
        'tracking_events(project_id,event_id)' => $ownedBy('projects'),
        'tracking_sessions(touchpoint_id)' => 'a server-generated touchpoint id',
        'project_integration_bindings(project_id,external_account_id)' => $ownedBy('projects'),
        'conversion_metrics(entity_type,entity_id,date,raw_action_type,attribution_window)' => 'bounded by a tenant-owned campaign, ad group or ad id',
        'daily_metrics(entity_type,entity_id,date)' => 'bounded by a tenant-owned campaign, ad group or ad id',
        'observed_event_counts(measurement_source_id,raw_event_name,date)' => $ownedBy('measurement_sources'),
        'creative_versions(creative_request_id,number)' => $ownedBy('creative_requests'),
        'notification_deliveries(notification_id,channel)' => $ownedBy('notifications'),
        'provider_event_dispatches(mapping_id,event_id)' => $ownedBy('conversion_mappings'),
        'report_sections(report_id,position)' => $ownedBy('reports'),
        'store_customers(store_id,external_id)' => $ownedBy('stores'),
        'store_orders(store_id,external_id)' => $ownedBy('stores'),
        'store_products(store_id,external_id)' => $ownedBy('stores'),
        'store_product_variants(store_product_id,external_id)' => $ownedBy('store_products'),
        'taggings(tag_id,entity_type,entity_id)' => $ownedBy('tags'),
        'project_members(project_id,user_id)' => $ownedBy('projects'),
        'form_fields(form_id,field_key)' => $ownedBy('forms'),
        'tracking_daily_rollups(project_id,day,event,traffic_class,tracking_link_id)' => $ownedBy('projects'),
    ];
}

/** @return list<string> */
function publicTables(): array
{
    /** @var list<string> $tables */
    $tables = DB::table('information_schema.tables')
        ->where('table_schema', 'public')->where('table_type', 'BASE TABLE')
        ->orderBy('table_name')->pluck('table_name')->map(static fn (mixed $t): string => (string) $t)->values()->all();

    return $tables;
}

function hasWorkspaceColumn(string $table): bool
{
    return DB::selectOne(
        "select count(*) as c from information_schema.columns where table_schema = 'public' and table_name = ? and column_name = 'workspace_id'",
        [$table],
    )->c > 0;
}

it('knows whose every table is', function (): void {
    $unclassified = [];

    foreach (publicTables() as $table) {
        $scoped = hasWorkspaceColumn($table);
        $listed = array_key_exists($table, TRANSITIVE_TABLES) || array_key_exists($table, GLOBAL_TABLES);

        if ($scoped && $listed) {
            $unclassified[] = "{$table} carries workspace_id and is also listed as transitive or global — remove it from the list";
        } elseif (! $scoped && ! $listed) {
            $unclassified[] = "{$table} has no workspace_id and is not classified";
        }
    }

    expect($unclassified)->toBe([]);
});

it('scopes every transitive table through a cascading foreign key to a tenant-owned table', function (): void {
    foreach (TRANSITIVE_TABLES as $table => [$column, $parent]) {
        $fk = DB::selectOne(<<<'SQL'
            select rc.delete_rule as rule
            from information_schema.table_constraints tc
            join information_schema.key_column_usage kcu on tc.constraint_name = kcu.constraint_name
            join information_schema.constraint_column_usage ccu on ccu.constraint_name = tc.constraint_name
            join information_schema.referential_constraints rc on rc.constraint_name = tc.constraint_name
            where tc.constraint_type = 'FOREIGN KEY' and tc.table_name = ? and kcu.column_name = ? and ccu.table_name = ?
            SQL, [$table, $column, $parent]);

        expect($fk)->not->toBeNull("{$table}.{$column} must reference {$parent}");
        expect($fk->rule ?? null)->toBe('CASCADE', "{$table} must go when its {$parent} row goes");
        expect(hasWorkspaceColumn($parent))->toBeTrue("{$parent} must itself carry workspace_id");
    }
});

it('names the tenant, or a tenant-owned parent, in every unique constraint on a tenant table', function (): void {
    $rows = DB::select(<<<'SQL'
        select t.relname as tbl, pg_get_indexdef(ix.indexrelid) as def
        from pg_index ix
        join pg_class t on t.oid = ix.indrelid
        join pg_namespace n on n.oid = t.relnamespace
        where n.nspname = 'public' and ix.indisunique and not ix.indisprimary
        SQL);

    $allowed = tenantSafeUniques();
    $unexplained = [];

    foreach ($rows as $row) {
        $table = (string) $row->tbl;

        /* A global table's uniques are global on purpose (an email, a slug, a catalogue key). */
        if (array_key_exists($table, GLOBAL_TABLES) || str_contains((string) $row->def, 'workspace_id')) {
            continue;
        }

        preg_match('/\(([^)]*)\)/', (string) $row->def, $m);
        $columns = str_replace([' ', '"'], '', $m[1] ?? '');
        $key = "{$table}({$columns})";

        if (! array_key_exists($key, $allowed)) {
            $unexplained[] = $key;
        }
    }

    expect($unexplained)->toBe([], 'unique constraints that can let one workspace block another’s value');
});
