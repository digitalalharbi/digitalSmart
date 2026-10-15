<?php

declare(strict_types=1);

use App\Infrastructure\Persistence\Eloquent\Model\User;
use Database\Seeders\DemoAgencySeeder;
use Database\Seeders\DemoWorkspaceSeeder;
use Illuminate\Support\Facades\DB;

/*
| The demo agency, from each seat.
|
| One test, because building the agency costs seconds and what is being asserted is a *world*: that each role
| lands on its own home, that the data has the variety the experience depends on (health states, budget risks,
| every lead stage), and that a client sees only their own client.
*/

it('builds an agency each role can use, and keeps a client inside their own', function (): void {
    $this->seed(DemoWorkspaceSeeder::class);
    $this->seed(DemoAgencySeeder::class);

    $as = fn (string $email): User => User::query()->where('email', $email.'@demo.digitalsmart.test')->firstOrFail();
    $get = function (string $email, string $url) use ($as) {
        // A new seat, a clean session: the previous person's workspace must not carry across.
        $this->flushSession();
        app('auth')->forgetGuards();

        return $this->actingAs($as($email))->getJson($url);
    };

    // Every demo identity lands on a home that is theirs.
    $primary = fn (string $email): ?string => $get($email, '/api/home')->assertOk()->json('data.primary');

    expect($primary('owner'))->toBe('executive')
        ->and($primary('manager'))->toBe('project_manager')
        ->and($primary('buyer'))->toBe('media_buyer')
        ->and($primary('finance'))->toBe('finance')
        ->and($primary('creative'))->toBe('creative');

    // Agency scale, populated: a book of clients, not three.
    expect(DB::table('clients')->where('slug', 'like', DemoAgencySeeder::TAG.'%')->count())->toBeGreaterThanOrEqual(50)
        ->and(DB::table('campaigns')->where('external_id', 'like', DemoAgencySeeder::TAG.'%')->count())->toBeGreaterThan(300);

    // The day has variety: more than one health state, budget risks, and leads in every stage.
    $control = $get('owner', '/api/campaigns/control?limit=1')->assertOk()->json('meta.health_counts');
    expect(count(array_filter($control, static fn (int $n): bool => $n > 0)))->toBeGreaterThanOrEqual(3);

    $budget = $get('finance', '/api/budgets/control')->assertOk();
    expect($budget->json('meta.count'))->toBeGreaterThan(20);
    $withRisk = count(array_filter($budget->json('data'), static fn (array $r): bool => $r['risks'] !== []));
    expect($withRisk)->toBeGreaterThan(5);

    $stages = DB::table('leads')->where('workspace_id', DB::table('workspaces')->where('slug', 'demo-smart-code')->value('id'))
        ->distinct()->pluck('stage')->all();
    expect($stages)->toContain('new', 'contacted', 'qualified', 'won', 'lost');

    // Objective-aware: an awareness campaign carries no revenue figure, so there is no ROAS to show.
    $rows = $get('owner', '/api/campaigns/control?objective[]=awareness&limit=50')->assertOk()->json('data');
    expect($rows)->not->toBeEmpty();
    foreach ($rows as $row) {
        expect($row['roas'])->toBeNull();
    }

    // The client is inside their own client: no portfolio, no benchmarks, no agency tags, no other clients.
    $get('client', '/api/portfolio')->assertForbidden();
    $get('client', '/api/benchmarks')->assertForbidden();
    $get('client', '/api/tags')->assertForbidden();
    $clients = $get('client', '/api/clients')->json('data');
    expect(count($clients ?? []))->toBeLessThanOrEqual(1);
});
