<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Audit\AuditAction;
use App\Infrastructure\Persistence\Eloquent\Model\AuditLog;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\MeasurementSource;
use App\Infrastructure\Persistence\Eloquent\Model\ObservedEventCount;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\ProjectMember;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| The lead pipeline
|--------------------------------------------------------------------------
|
| A lead is a person who did something. Most of what is tested here is the boundary around that sentence:
| what can become a lead, what counts as contact, what a rate may be divided by, and what a disqualification
| has to say for itself.
|
*/

/** @return array{scenario: WorkspaceScenario, client: Client, project: Project} */
function leadScenario(): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->forClient($client)->create(['name' => 'Always-on']);

    return ['scenario' => $scenario, 'client' => $client, 'project' => $project];
}

/**
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project}  $setup
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function leadPayload(array $setup, array $overrides = []): array
{
    return [
        'client_id' => (string) $setup['client']->getKey(),
        'source_kind' => 'form_submission',
        'source_detail' => 'Contact form',
        'name' => 'Layla Al-Harbi',
        'phone' => '0555001122',
        'message' => 'Interested in the autumn range.',
        ...$overrides,
    ];
}

/**
 * Record a lead and return its id.
 *
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project}  $setup
 * @param  array<string, mixed>  $overrides
 */
function recordLead(array $setup, array $overrides = []): string
{
    /** @var string */
    return test()->actingAs($setup['scenario']->owner)
        ->postJson('/api/leads', leadPayload($setup, $overrides))
        ->assertCreated()
        ->json('data.id');
}

/**
 * Move a lead to a stage.
 *
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project}  $setup
 * @param  array<string, string>  $body
 * @return Illuminate\Testing\TestResponse<Illuminate\Http\JsonResponse>
 */
function moveLead(array $setup, string $leadId, string $stage, array $body = []): Illuminate\Testing\TestResponse
{
    return test()->actingAs($setup['scenario']->owner)
        ->postJson("/api/leads/{$leadId}/stage", ['stage' => $stage, ...$body]);
}

/**
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project}  $setup
 * @return array<string, mixed>
 */
function leadCentre(array $setup): array
{
    /** @var array<string, mixed> */
    return test()->actingAs($setup['scenario']->owner)
        ->getJson('/api/leads/centre')
        ->assertOk()
        ->json('data');
}

describe('what can become a lead', function (): void {
    it('records a form submission', function (): void {
        $setup = leadScenario();

        $data = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/leads', leadPayload($setup))
            ->assertCreated()
            ->json('data');

        expect($data['source_kind'])->toBe('form_submission')
            ->and($data['stage'])->toBe('new')
            ->and($data['is_measured'])->toBeTrue()
            // Nothing carried, so nothing claimed.
            ->and($data['evidence'])->toBe('none')
            ->and($data['confidence'])->toBe(0);
    });

    it('refuses a click as a source kind', function (): void {
        $setup = leadScenario();

        /*
         * The rule the whole phase exists under, and it is enforced by the enum having no such member rather
         * than by a check somebody could remove. A tap is not a conversation.
         */
        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/leads', leadPayload($setup, ['source_kind' => 'whatsapp_click']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['source_kind']);
    });

    it('refuses an import or a CRM source from a person typing', function (): void {
        $setup = leadScenario();

        /*
         * Those arrive from an integration. Letting somebody type one would let a hand-entered row claim to
         * have come from a system, which is the one thing that would make the `is_measured` flag meaningless.
         */
        foreach (['import', 'crm'] as $kind) {
            $this->actingAs($setup['scenario']->owner)
                ->postJson('/api/leads', leadPayload($setup, ['source_kind' => $kind]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['source_kind']);
        }
    });

    it('marks a hand-entered lead as not measured', function (): void {
        $setup = leadScenario();

        $data = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/leads', leadPayload($setup, ['source_kind' => 'manual']))
            ->assertCreated()
            ->json('data');

        // A real lead, and never to be reported as a campaign result.
        expect($data['is_measured'])->toBeFalse();
    });

    it('refuses a lead nobody could identify', function (): void {
        $setup = leadScenario();

        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/leads', [
                'client_id' => (string) $setup['client']->getKey(),
                'source_kind' => 'phone_call',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    });

    it('stores the attribution the lead carried', function (): void {
        $setup = leadScenario();

        $data = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/leads', leadPayload($setup, [
                'utm_source' => 'meta',
                'utm_medium' => 'cpc',
                'utm_campaign' => 'autumn-range',
                'click_identifier' => 'fbclid-123',
            ]))
            ->assertCreated()
            ->json('data');

        /*
         * The same evidence vocabulary the orders use, so a cross-surface report cannot compare two different
         * standards of proof.
         */
        expect($data['evidence'])->toBe('click_identifier')
            ->and($data['confidence'])->toBe(95)
            ->and($data['utm_campaign'])->toBe('autumn-range');
    });

    it('writes down what produced it', function (): void {
        $setup = leadScenario();

        recordLead($setup, ['source_kind' => 'manual']);

        $entry = AuditLog::query()->where('action', AuditAction::LeadRecorded->value)->sole();

        // So "where did these forty leads come from" is answerable from the record.
        expect($entry->getAttribute('changes'))->toMatchArray([
            'source_kind' => 'manual',
            'is_measured' => false,
        ]);
    });
});

describe('recognising the same person', function (): void {
    it('treats the same phone written differently as one lead', function (): void {
        $setup = leadScenario();

        $first = recordLead($setup, ['phone' => '0555001122']);
        $second = recordLead($setup, ['phone' => '+966 55 500 1122', 'name' => 'Layla A.']);

        /*
         * One person resubmitting a form within a day. Counting them twice would report double the volume at
         * half the cost per lead — and the agency would bid on it.
         */
        expect($second)->toBe($first)
            ->and(DB::table('leads')->count())->toBe(1);
    });

    it('takes the campaign parameters a follow-up carried and the first submission lost', function (): void {
        $setup = leadScenario();

        $first = recordLead($setup);

        $data = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/leads', leadPayload($setup, [
                'utm_source' => 'meta',
                'utm_medium' => 'cpc',
                'utm_campaign' => 'autumn-range',
            ]))
            ->assertCreated()
            ->json('data');

        /*
         * A real pattern: an in-app browser strips the parameters, the customer opens the site properly and
         * submits again. Taking them is strictly better than a lead stuck as unattributed — and only blanks
         * are filled, because the first touch is the one that earned the credit.
         */
        expect($data['id'])->toBe($first)
            ->and($data['evidence'])->toBe('utm_parameters')
            ->and($data['utm_campaign'])->toBe('autumn-range');
    });

    it('does not weaken an attribution it already had', function (): void {
        $setup = leadScenario();

        recordLead($setup, ['click_identifier' => 'fbclid-strong', 'utm_source' => 'meta', 'utm_campaign' => 'autumn']);

        $data = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/leads', leadPayload($setup, ['utm_source' => 'organic']))
            ->assertCreated()
            ->json('data');

        // A later, weaker arrival must not overwrite a stronger link.
        expect($data['evidence'])->toBe('click_identifier')
            ->and($data['utm_campaign'])->toBe('autumn');
    });

    it('treats the same person enquiring months later as a new lead', function (): void {
        $setup = leadScenario();

        recordLead($setup, ['received_at' => '2026-06-01 10:00:00']);
        recordLead($setup, ['received_at' => '2026-10-01 10:00:00']);

        /*
         * Outside the deduplication window. The same phone four months later is a new enquiry about something
         * else, and merging it would lose a real lead.
         */
        expect(DB::table('leads')->count())->toBe(2);
    });

    it('records two anonymous enquiries separately', function (): void {
        $setup = leadScenario();

        recordLead($setup, ['phone' => null, 'email' => null, 'name' => 'Walk-in']);
        recordLead($setup, ['phone' => null, 'email' => null, 'name' => 'Walk-in']);

        /*
         * No way to reach either, so no way to tell them apart. Two enquiries is the honest count — refusing
         * the second would lose one that happened.
         */
        expect(DB::table('leads')->count())->toBe(2);
    });
});

describe('working the pipeline', function (): void {
    it('records when somebody first got through, not when they tried', function (): void {
        $setup = leadScenario();
        $lead = recordLead($setup);

        $contacted = moveLead($setup, $lead, 'contacted')->assertOk()->json('data');

        /*
         * `contacted` is an attempt. Three voicemails are not contact, and counting them as such is how a
         * client comes to believe their leads were worked.
         */
        expect($contacted['first_contacted_at'])->toBeNull()
            ->and($contacted['hours_to_first_contact'])->toBeNull();

        $qualified = moveLead($setup, $lead, 'qualified')->assertOk()->json('data');

        expect($qualified['first_contacted_at'])->not->toBeNull()
            ->and($qualified['qualified_at'])->not->toBeNull()
            ->and($qualified['hours_to_first_contact'])->not->toBeNull();
    });

    it('insists a disqualification says why', function (): void {
        $setup = leadScenario();
        $lead = recordLead($setup);

        /*
         * "Not a real enquiry", "out of area" and "price" lead to three different conversations with a client
         * paying for leads. A disqualified pile with no reasons is the least useful thing an agency can hand
         * back.
         */
        moveLead($setup, $lead, 'disqualified')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_lead_transition');

        $data = moveLead($setup, $lead, 'disqualified', ['reason' => 'Outside the delivery area'])
            ->assertOk()
            ->json('data');

        expect($data['disqualified_reason'])->toBe('Outside the delivery area')
            ->and($data['is_closed'])->toBeTrue()
            ->and($data['closed_at'])->not->toBeNull();
    });

    it('clears the reason when a lead moves on', function (): void {
        $setup = leadScenario();
        $lead = recordLead($setup);

        moveLead($setup, $lead, 'disqualified', ['reason' => 'Wrong product'])->assertOk();
        $data = moveLead($setup, $lead, 'qualified')->assertOk()->json('data');

        /*
         * Leaving "wrong product" attached to a lead that was later qualified would make the record say
         * something nobody believes.
         */
        expect($data['disqualified_reason'])->toBeNull()
            ->and($data['closed_at'])->toBeNull();
    });

    it('drops the closing time when a lead is reopened', function (): void {
        $setup = leadScenario();
        $lead = recordLead($setup);

        moveLead($setup, $lead, 'won')->assertOk();
        $data = moveLead($setup, $lead, 'qualified')->assertOk()->json('data');

        // A lead that is open has not closed, and a stale closing time makes every cycle time wrong.
        expect($data['closed_at'])->toBeNull()
            ->and($data['is_closed'])->toBeFalse();
    });

    it('keeps the history of every move', function (): void {
        $setup = leadScenario();
        $lead = recordLead($setup);

        moveLead($setup, $lead, 'contacted')->assertOk();
        moveLead($setup, $lead, 'qualified')->assertOk();
        moveLead($setup, $lead, 'won')->assertOk();

        $events = DB::table('lead_events')->where('lead_id', $lead)->orderBy('occurred_at')->get();

        /*
         * "Who qualified this, and when" is a question about events. A table holding only the current stage
         * makes a pipeline report possible and a pipeline review impossible — and the review is where an
         * agency finds out half its leads sat untouched for a week.
         */
        expect($events)->toHaveCount(4)
            ->and($events->pluck('type')->all())->toBe(['received', 'stage_changed', 'stage_changed', 'stage_changed']);
    });

    it('audits every move with both stages', function (): void {
        $setup = leadScenario();
        $lead = recordLead($setup);

        moveLead($setup, $lead, 'won')->assertOk();

        $entry = AuditLog::query()->where('action', AuditAction::LeadStageChanged->value)->sole();

        expect($entry->getAttribute('changes'))->toMatchArray(['from' => 'new', 'to' => 'won']);
    });

    it('refuses a stage that does not exist', function (): void {
        $setup = leadScenario();
        $lead = recordLead($setup);

        moveLead($setup, $lead, 'maybe')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_stage');
    });
});

describe('the Lead Center', function (): void {
    it('divides wins by closed leads, not by every lead', function (): void {
        $setup = leadScenario();

        $won = recordLead($setup, ['phone' => '0555000001']);
        $lost = recordLead($setup, ['phone' => '0555000002']);
        recordLead($setup, ['phone' => '0555000003']);
        recordLead($setup, ['phone' => '0555000004']);

        moveLead($setup, $won, 'won')->assertOk();
        moveLead($setup, $lost, 'lost')->assertOk();

        $data = leadCentre($setup);

        /*
         * One win out of two closed, not one out of four. The second figure would call a young pipeline a
         * disaster, and most of a month's pipeline is young.
         */
        expect($data['funnel']['total'])->toBe(4)
            ->and($data['funnel']['closed'])->toBe(2)
            ->and($data['funnel']['open'])->toBe(2)
            // JSON renders a whole percentage without its decimal, so the comparison is on value.
            ->and((float) $data['funnel']['win_rate'])->toBe(50.0);
    });

    it('gives no win rate when nothing has closed', function (): void {
        $setup = leadScenario();

        recordLead($setup, ['phone' => '0555000001']);
        recordLead($setup, ['phone' => '0555000002']);

        $data = leadCentre($setup);

        // Two leads, none finished. A zero would read as "we win nothing".
        expect($data['funnel']['total'])->toBe(2)
            ->and($data['funnel']['win_rate'])->toBeNull()
            ->and($data['funnel']['qualification_rate'])->toBeNull();
    });

    it('shows counted signals beside the leads and never inside them', function (): void {
        $setup = leadScenario();

        /** @var MeasurementSource $source */
        $source = MeasurementSource::query()->create([
            'workspace_id' => $setup['scenario']->workspace->getKey(),
            'client_id' => $setup['client']->getKey(),
            'kind' => 'analytics',
            'provider' => 'google_analytics',
            'external_id' => 'properties/1',
            'name' => 'GA4',
            'last_synced_at' => now()->subHour(),
            'last_event_at' => now()->subHours(2),
        ]);

        foreach (['whatsapp_click' => 400, 'phone_click' => 120, 'form_start' => 90, 'purchase' => 7] as $name => $count) {
            ObservedEventCount::query()->create([
                'workspace_id' => $setup['scenario']->workspace->getKey(),
                'measurement_source_id' => $source->getKey(),
                'raw_event_name' => $name,
                'date' => now()->subDay()->format('Y-m-d'),
                'event_count' => $count,
            ]);
        }

        recordLead($setup, ['source_kind' => 'whatsapp_message', 'phone' => '0555000009']);

        $data = leadCentre($setup);

        /*
         * Four hundred taps and one conversation. Shown side by side so an agency can see the gap, and never
         * summed: a product that added them would report a cost per lead a fraction of the real one.
         *
         * `purchase` is not a signal and does not belong in this column — only the events the vocabulary marks
         * as intent-only appear.
         */
        expect($data['funnel']['total'])->toBe(1)
            ->and($data['signals'])->toHaveKey('whatsapp_click')
            ->and($data['signals']['whatsapp_click'])->toBe(400)
            ->and($data['signals'])->toHaveKey('form_start')
            ->and($data['signals'])->not->toHaveKey('purchase');
    });

    it('publishes no ratio between signals and leads', function (): void {
        $setup = leadScenario();

        $data = leadCentre($setup);

        /*
         * A tap and a lead do not share a denominator: the same person taps three times, or taps and then
         * phones instead, or taps on a device the form never loaded on. The gap is information; a percentage
         * would be a claim — so the response carries a note instead of a rate.
         */
        expect($data)->not->toHaveKey('signal_conversion_rate')
            ->and($data['signals_note'])->toContain('not comparable as a ratio');
    });

    it('groups leads by campaign and keeps the unattributed ones visible', function (): void {
        $setup = leadScenario();

        recordLead($setup, [
            'phone' => '0555000001',
            'utm_source' => 'meta',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'autumn-range',
        ]);
        recordLead($setup, ['phone' => '0555000002']);
        recordLead($setup, ['phone' => '0555000003', 'source_kind' => 'phone_call']);

        $data = leadCentre($setup);
        $labels = array_column($data['by_campaign'], 'label');

        /*
         * For leads the unattributed bucket is usually enormous — most enquiries carry no parameters, and a
         * great many arrive by phone. Hiding it would make every named campaign look responsible for a far
         * larger share of the pipeline than it is.
         */
        expect($labels)->toContain('autumn-range')
            ->and($labels)->toContain('Unattributed');

        $unattributed = jsonRow($data['by_campaign'], 'label', 'Unattributed');

        expect($unattributed['total'])->toBe(2)
            ->and($unattributed['is_unattributed'])->toBeTrue();
    });

    it('groups leads by the route they arrived through', function (): void {
        $setup = leadScenario();

        recordLead($setup, ['phone' => '0555000001', 'source_kind' => 'form_submission']);
        recordLead($setup, ['phone' => '0555000002', 'source_kind' => 'phone_call']);
        recordLead($setup, ['phone' => '0555000003', 'source_kind' => 'manual']);

        $data = leadCentre($setup);
        $kinds = array_column($data['by_source'], 'source_kind');

        expect($kinds)->toContain('form_submission')
            ->and($kinds)->toContain('phone_call')
            // The hand-entered route is marked, so a figure built from it can say so.
            ->and(jsonRow($data['by_source'], 'source_kind', 'manual')['is_measured'])->toBeFalse();
    });
});

describe('access', function (): void {
    it('requires authentication', function (): void {
        $this->getJson('/api/leads')->assertUnauthorized();
    });

    it('refuses a creative', function (): void {
        $setup = leadScenario();

        $this->actingAs($setup['scenario']->addMember(SystemRole::Creative))
            ->getJson('/api/leads')
            ->assertForbidden();
    });

    it('lets a media buyer read the pipeline but not qualify a lead', function (): void {
        $setup = leadScenario();
        $lead = recordLead($setup);

        $buyer = $setup['scenario']->addMember(SystemRole::MediaBuyer);
        ProjectMember::query()->create([
            'project_id' => $setup['project']->getKey(),
            'user_id' => $buyer->getKey(),
            'role_id' => $setup['scenario']->role(SystemRole::MediaBuyer)->getKey(),
        ]);

        /*
         * A buyer reads the pipeline, because a cost per *qualified* lead is the only honest measure of their
         * work. Judging the quality of what you bought is not a judgement to hand to the buyer.
         */
        $this->actingAs($buyer)->getJson('/api/leads')->assertOk();

        $this->actingAs($buyer)
            ->postJson("/api/leads/{$lead}/stage", ['stage' => 'qualified'])
            ->assertForbidden();
    });

    it('hides leads for a client the actor cannot reach', function (): void {
        $setup = leadScenario();
        recordLead($setup);

        $stranger = $setup['scenario']->addMember(SystemRole::MediaBuyer);

        expect($this->actingAs($stranger)->getJson('/api/leads')->assertOk()->json('data'))->toBe([]);
    });

    it('does not reach into another workspace', function (): void {
        $setup = leadScenario();
        $other = leadScenario();
        $lead = recordLead($other);

        $this->actingAs($setup['scenario']->owner)
            ->postJson("/api/leads/{$lead}/stage", ['stage' => 'won'])
            ->assertNotFound();
    });
});
