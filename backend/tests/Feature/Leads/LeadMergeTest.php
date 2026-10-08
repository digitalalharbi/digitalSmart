<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Audit\AuditAction;
use App\Infrastructure\Persistence\Eloquent\Model\AuditLog;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Lead;
use App\Infrastructure\Persistence\Eloquent\Model\LeadEvent;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Two leads, one person
|--------------------------------------------------------------------------
|
| The operation with no undo. Afterwards both histories are on one record, and a split would have to invent a
| division nobody wrote down — so what is tested here is mostly what the merge refuses to do, and what it
| refuses to lose.
|
*/

/** @return array{scenario: WorkspaceScenario, client: Client, project: Project} */
function mergeScenario(): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->forClient($client)->create(['name' => 'Always-on']);

    return ['scenario' => $scenario, 'client' => $client, 'project' => $project];
}

/**
 * A lead written directly, so a test can state its arrival time, source and stage.
 *
 * The HTTP route deduplicates by contact point inside a day, which is exactly the behaviour a merge test has to
 * get past: these are the leads that route decided were two different people.
 *
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project}  $setup
 * @param  array<string, mixed>  $attributes
 */
function mergeableLead(array $setup, array $attributes = []): Lead
{
    /** @var Lead */
    return Lead::query()->create([
        'workspace_id' => $setup['scenario']->workspace->getKey(),
        'client_id' => $setup['client']->getKey(),
        'project_id' => $setup['project']->getKey(),
        'source_kind' => 'form_submission',
        'source_detail' => 'Contact form',
        'name' => 'Layla Al-Harbi',
        'phone' => '0555001122',
        'phone_key' => '966555001122',
        'stage' => 'new',
        'received_at' => new DateTimeImmutable('2026-09-01 10:00:00'),
        ...$attributes,
    ]);
}

/**
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project}  $setup
 * @param  array<string, mixed>  $body
 * @return Illuminate\Testing\TestResponse<Illuminate\Http\JsonResponse>
 */
function mergeLeads(array $setup, Lead $into, Lead $other, array $body = []): Illuminate\Testing\TestResponse
{
    return test()->actingAs($setup['scenario']->owner)->postJson(
        '/api/leads/'.$into->getKey().'/merge',
        ['lead_id' => (string) $other->getKey(), 'stage' => 'new', ...$body],
    );
}

describe('finding the same person twice', function (): void {
    it('suggests a lead sharing a phone number, and says that is what matched', function (): void {
        $setup = mergeScenario();
        $first = mergeableLead($setup);
        mergeableLead($setup, ['received_at' => new DateTimeImmutable('2026-03-02 10:00:00')]);

        $rows = $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/leads/'.$first->getKey().'/duplicates')
            ->assertOk()
            ->json('data');

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['matched_on'])->toBe('phone')
            ->and($rows[0]['matched_on_label'])->toBe('Same phone number');
    });

    /*
     * Six months apart, and still offered. The deduplication window exists to stop this product deciding on its
     * own; a human asking "are these the same person" wants to see the enquiry from March.
     */
    it('ignores the deduplication window a human is working around', function (): void {
        $setup = mergeScenario();
        $first = mergeableLead($setup, ['received_at' => new DateTimeImmutable('2026-09-01 10:00:00')]);
        mergeableLead($setup, ['received_at' => new DateTimeImmutable('2025-11-02 10:00:00')]);

        expect($this->actingAs($setup['scenario']->owner)
            ->getJson('/api/leads/'.$first->getKey().'/duplicates')
            ->assertOk()
            ->json('data'))->toHaveCount(1);
    });

    /* Nothing matches on a name. Two people called Mohammed Al-Harbi are two people. */
    it('offers nothing for a lead with no contact point', function (): void {
        $setup = mergeScenario();
        $anonymous = mergeableLead($setup, ['phone' => null, 'phone_key' => null]);
        mergeableLead($setup);

        expect($this->actingAs($setup['scenario']->owner)
            ->getJson('/api/leads/'.$anonymous->getKey().'/duplicates')
            ->assertOk()
            ->json('data'))->toBe([]);
    });

    it('does not suggest a lead already merged away', function (): void {
        $setup = mergeScenario();
        /* The earliest, so it is the record the merge keeps — see `LeadMergePlan`. */
        $first = mergeableLead($setup);
        $second = mergeableLead($setup, ['received_at' => new DateTimeImmutable('2026-09-20 10:00:00')]);
        $third = mergeableLead($setup, ['received_at' => new DateTimeImmutable('2026-09-25 10:00:00')]);

        mergeLeads($setup, $first, $second)->assertOk();

        $rows = $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/leads/'.$first->getKey().'/duplicates')
            ->assertOk()
            ->json('data');

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['id'])->toBe((string) $third->getKey());
    });
});

describe('what a merge keeps', function (): void {
    it('moves both histories onto the surviving record', function (): void {
        $setup = mergeScenario();
        $early = mergeableLead($setup);
        $late = mergeableLead($setup, ['received_at' => new DateTimeImmutable('2026-09-20 10:00:00')]);

        /* Something happened to the later lead, through the surface that records it. */
        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/leads/'.$late->getKey().'/stage', [
                'stage' => 'contacted',
                'note' => 'Called, no answer.',
            ])
            ->assertOk();

        mergeLeads($setup, $early, $late, ['stage' => 'contacted'])->assertOk();

        /*
         * Nothing is deleted. The absorbed record is kept and marked, because a client may already have been
         * told a count — and because its provider id has to stay where the next sync will look for it.
         */
        $moved = LeadEvent::query()
            ->where('lead_id', $early->getKey())
            ->pluck('type')
            ->all();

        expect($moved)->toContain('stage_changed')
            ->and($moved)->toContain('merged')
            ->and(Lead::query()->whereKey($late->getKey())->value('merged_into_id'))
            ->toBe((string) $early->getKey())
            /* And the absorbed row keeps one event of its own, saying where its history continues. */
            ->and(LeadEvent::query()->where('lead_id', $late->getKey())->count())->toBe(1);
    });

    it('counts the person once afterwards, not twice', function (): void {
        $setup = mergeScenario();
        $early = mergeableLead($setup);
        $late = mergeableLead($setup, ['received_at' => new DateTimeImmutable('2026-09-20 10:00:00')]);

        expect($this->actingAs($setup['scenario']->owner)->getJson('/api/leads')->json('data'))
            ->toHaveCount(2);

        mergeLeads($setup, $early, $late)->assertOk();

        $remaining = $this->actingAs($setup['scenario']->owner)->getJson('/api/leads')->json('data');

        expect($remaining)->toHaveCount(1)
            ->and($remaining[0]['id'])->toBe((string) $early->getKey())
            // Published, so a history longer than one arrival explains itself.
            ->and($remaining[0]['absorbed_count'])->toBe(1);
    });

    /*
     * The figure this protects is time-to-first-contact. If a merge could reset the arrival time, every merge
     * would shorten every speed-of-response number and flatter whoever did the merging.
     */
    it('keeps the earlier arrival time when the CRM record is the one that survives', function (): void {
        $setup = mergeScenario();
        $form = mergeableLead($setup, ['received_at' => new DateTimeImmutable('2026-09-01 10:00:00')]);
        $deal = mergeableLead($setup, [
            'source_kind' => 'crm',
            'source_detail' => 'HubSpot · Proposal sent',
            'external_id' => 'deal-77',
            'received_at' => new DateTimeImmutable('2026-09-20 10:00:00'),
        ]);

        $data = mergeLeads($setup, $form, $deal)->assertOk()->json('data');

        expect($data['id'])->toBe((string) $deal->getKey())
            ->and($data['source_kind'])->toBe('crm')
            ->and($data['received_at'])->toStartWith('2026-09-01');
    });

    it('fills a blank contact detail from the absorbed lead', function (): void {
        $setup = mergeScenario();
        $early = mergeableLead($setup, ['name' => null]);
        $late = mergeableLead($setup, [
            'name' => 'Layla Al-Harbi',
            'email' => 'layla@example.com',
            'email_key' => 'layla@example.com',
            'received_at' => new DateTimeImmutable('2026-09-20 10:00:00'),
        ]);

        $data = mergeLeads($setup, $early, $late)->assertOk()->json('data');

        expect($data['name'])->toBe('Layla Al-Harbi')
            ->and($data['email'])->toBe('layla@example.com');
    });

    /*
     * Wholly or not at all — the same rule the repository applies to a re-arrival. A half-merged attribution
     * (this lead's click id beside the other's campaign) is a link to a campaign nobody can verify.
     */
    it('takes a stronger attribution whole, never field by field', function (): void {
        $setup = mergeScenario();
        $early = mergeableLead($setup, [
            'attribution_evidence' => 'utm_parameters',
            'utm_source' => 'google',
            'utm_campaign' => 'always-on',
        ]);
        $late = mergeableLead($setup, [
            'attribution_evidence' => 'click_identifier',
            'utm_source' => 'meta',
            'click_identifier' => 'fbclid-99',
            'received_at' => new DateTimeImmutable('2026-09-20 10:00:00'),
        ]);

        $data = mergeLeads($setup, $early, $late)->assertOk()->json('data');

        expect($data['evidence'])->toBe('click_identifier')
            ->and($data['utm_source'])->toBe('meta')
            // Not left behind from the weaker block, which is what "whole" means.
            ->and($data['utm_campaign'])->toBeNull();
    });

    /*
     * Never the clock. A closed lead needs a closing time and inventing "now" would date every closure to the
     * day somebody tidied up two records.
     */
    it('takes the closing time from the lead that is in the chosen stage', function (): void {
        $setup = mergeScenario();
        $early = mergeableLead($setup);
        $won = mergeableLead($setup, [
            'stage' => 'won',
            'closed_at' => new DateTimeImmutable('2026-09-15 09:00:00'),
            'received_at' => new DateTimeImmutable('2026-09-20 10:00:00'),
        ]);

        $data = mergeLeads($setup, $early, $won, ['stage' => 'won'])->assertOk()->json('data');

        expect($data['stage'])->toBe('won')
            ->and($data['closed_at'])->toStartWith('2026-09-15');
    });

    it('writes the absorbed lead’s stated value into the trail rather than dropping it', function (): void {
        $setup = mergeScenario();
        $early = mergeableLead($setup, ['value_minor' => 500_000, 'currency' => 'SAR']);
        $late = mergeableLead($setup, [
            'value_minor' => 900_000,
            'currency' => 'SAR',
            'received_at' => new DateTimeImmutable('2026-09-20 10:00:00'),
        ]);

        mergeLeads($setup, $early, $late)->assertOk();

        $note = LeadEvent::query()
            ->where('lead_id', $early->getKey())
            ->where('type', 'merged')
            ->value('note');

        expect($note)->toContain('9,000.00 SAR');
    });

    it('records the merge in the audit log, naming both leads', function (): void {
        $setup = mergeScenario();
        $early = mergeableLead($setup);
        $late = mergeableLead($setup, ['received_at' => new DateTimeImmutable('2026-09-20 10:00:00')]);

        mergeLeads($setup, $early, $late)->assertOk();

        $entry = AuditLog::query()->where('action', AuditAction::LeadsMerged->value)->firstOrFail();

        expect($entry->getAttribute('changes')['survivor'])->toBe((string) $early->getKey())
            ->and($entry->getAttribute('changes')['absorbed'])->toBe((string) $late->getKey());
    });
});

describe('merges the surface refuses', function (): void {
    it('refuses a stage neither lead is in', function (): void {
        $setup = mergeScenario();
        $early = mergeableLead($setup);
        $late = mergeableLead($setup, ['received_at' => new DateTimeImmutable('2026-09-20 10:00:00')]);

        mergeLeads($setup, $early, $late, ['stage' => 'qualified'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'leads_cannot_be_merged');
    });

    it('refuses two leads belonging to different clients', function (): void {
        $setup = mergeScenario();
        $other = Client::factory()->create(['workspace_id' => $setup['scenario']->workspace->getKey()]);
        Project::factory()->forClient($other)->create(['name' => 'Second client']);

        $mine = mergeableLead($setup);
        $theirs = mergeableLead($setup, ['client_id' => $other->getKey(), 'project_id' => null]);

        mergeLeads($setup, $mine, $theirs)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'leads_cannot_be_merged');
    });

    it('refuses two CRM leads, which the CRM owns', function (): void {
        $setup = mergeScenario();
        $first = mergeableLead($setup, ['source_kind' => 'crm', 'external_id' => 'deal-1']);
        $second = mergeableLead($setup, [
            'source_kind' => 'crm',
            'external_id' => 'deal-2',
            'received_at' => new DateTimeImmutable('2026-09-20 10:00:00'),
        ]);

        mergeLeads($setup, $first, $second)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'leads_cannot_be_merged');
    });

    it('refuses to chain a merge onto a lead already merged away', function (): void {
        $setup = mergeScenario();
        $early = mergeableLead($setup);
        $late = mergeableLead($setup, ['received_at' => new DateTimeImmutable('2026-09-20 10:00:00')]);
        $third = mergeableLead($setup, ['received_at' => new DateTimeImmutable('2026-09-25 10:00:00')]);

        mergeLeads($setup, $early, $late)->assertOk();

        mergeLeads($setup, $late, $third)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'leads_cannot_be_merged');
    });

    it('does not let a creative merge leads', function (): void {
        $setup = mergeScenario();
        $early = mergeableLead($setup);
        $late = mergeableLead($setup, ['received_at' => new DateTimeImmutable('2026-09-20 10:00:00')]);

        $this->actingAs($setup['scenario']->addMember(SystemRole::Creative))->postJson(
            '/api/leads/'.$early->getKey().'/merge',
            ['lead_id' => (string) $late->getKey(), 'stage' => 'new'],
        )->assertForbidden();
    });
});

describe('what the figures do afterwards', function (): void {
    /*
     * The counting side of the merge. A funnel that still showed two enquiries would make the merge cosmetic:
     * the pipeline list would say one person and the figure beneath it would say two.
     */
    it('counts the merged person once in the funnel', function (): void {
        $setup = mergeScenario();
        /* Inside the Lead Center's own window, which is counted back from today. */
        $early = mergeableLead($setup, ['received_at' => new DateTimeImmutable('-5 days')]);
        $late = mergeableLead($setup, ['received_at' => new DateTimeImmutable('-2 days')]);

        $before = $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/leads/centre')->assertOk()->json('data.funnel.total');

        mergeLeads($setup, $early, $late)->assertOk();

        $after = $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/leads/centre')->assertOk()->json('data.funnel.total');

        expect($before)->toBe(2)->and($after)->toBe(1);
    });

    /*
     * The journey is the opposite case, and deliberately so. It is a timeline of what happened to a person, and
     * both enquiries did happen — the merge decided they were one person, not that one of them never arrived.
     * Leaving them both is what makes "they came back in September" readable.
     */
    it('still shows both enquiries on the person’s journey', function (): void {
        $setup = mergeScenario();
        $early = mergeableLead($setup);
        $late = mergeableLead($setup, ['received_at' => new DateTimeImmutable('2026-09-20 10:00:00')]);

        mergeLeads($setup, $early, $late)->assertOk();

        $touchpoints = $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/leads/'.$early->getKey().'/journey')
            ->assertOk()
            ->json('data.touchpoints');

        expect($touchpoints)->toHaveCount(2);
    });
});
