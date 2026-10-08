<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Audit\AuditAction;
use App\Domain\Workspace\MembershipStatus;
use App\Infrastructure\Persistence\Eloquent\Model\AuditLog;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Lead;
use App\Infrastructure\Persistence\Eloquent\Model\LeadEvent;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\ProjectMember;
use App\Infrastructure\Persistence\Eloquent\Model\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Working a pipeline: ownership, value and notes
|--------------------------------------------------------------------------
|
| The three things the pipeline list already had columns for and nothing could set. Most of what is tested here
| is who may be given a lead and what a value is allowed to be, because both of those end up in front of a
| client: the owner column decides who answers an enquiry, and the value feeds the won-value total.
|
*/

/** @return array{scenario: WorkspaceScenario, client: Client, project: Project} */
function ownershipScenario(): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->forClient($client)->create(['name' => 'Always-on']);

    return ['scenario' => $scenario, 'client' => $client, 'project' => $project];
}

/**
 * A member of the workspace, optionally assigned to its project.
 *
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project}  $setup
 */
function member(
    array $setup,
    SystemRole $role,
    bool $onTheProject = true,
    MembershipStatus $status = MembershipStatus::Active,
): User {
    $user = $setup['scenario']->addMember($role, $status);

    if ($onTheProject) {
        ProjectMember::query()->create([
            'project_id' => $setup['project']->getKey(),
            'user_id' => $user->getKey(),
            'role_id' => $setup['scenario']->role($role)->getKey(),
        ]);
    }

    return $user;
}

/**
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project}  $setup
 * @param  array<string, mixed>  $attributes
 */
function ownableLead(array $setup, array $attributes = []): Lead
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
        'received_at' => new DateTimeImmutable('-3 days'),
        ...$attributes,
    ]);
}

describe('who a lead can be put in the name of', function (): void {
    it('offers a colleague assigned to the client’s project', function (): void {
        $setup = ownershipScenario();
        $lead = ownableLead($setup);
        $buyer = member($setup, SystemRole::MediaBuyer);

        $people = $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/leads/'.$lead->getKey().'/owners')
            ->assertOk()
            ->json('data');

        expect(array_column($people, 'id'))->toContain((string) $buyer->getKey());
    });

    /*
     * The whole reason this list is served rather than assembled from the member list. A lead carries a
     * prospect's name and phone number: an owner whose projects do not reach this client would be a name in a
     * column attached to a record they can never open.
     */
    it('leaves out a colleague who cannot reach the client', function (): void {
        $setup = ownershipScenario();
        $lead = ownableLead($setup);
        $elsewhere = member($setup, SystemRole::MediaBuyer, onTheProject: false);

        $people = $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/leads/'.$lead->getKey().'/owners')
            ->assertOk()
            ->json('data');

        expect(array_column($people, 'id'))->not->toContain((string) $elsewhere->getKey());
    });

    it('leaves out a colleague who cannot read leads at all', function (): void {
        $setup = ownershipScenario();
        $lead = ownableLead($setup);
        $creative = member($setup, SystemRole::Creative);

        $people = $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/leads/'.$lead->getKey().'/owners')
            ->assertOk()
            ->json('data');

        expect(array_column($people, 'id'))->not->toContain((string) $creative->getKey());
    });

    it('leaves out a suspended member, who keeps their row and loses everything else', function (): void {
        $setup = ownershipScenario();
        $lead = ownableLead($setup);
        $suspended = member($setup, SystemRole::MediaBuyer, status: MembershipStatus::Suspended);

        $people = $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/leads/'.$lead->getKey().'/owners')
            ->assertOk()
            ->json('data');

        expect(array_column($people, 'id'))->not->toContain((string) $suspended->getKey());
    });

    /* Names and ids. A picker does not need email addresses, and nobody in it agreed to have theirs listed. */
    it('carries no contact details for the people in it', function (): void {
        $setup = ownershipScenario();
        $lead = ownableLead($setup);
        member($setup, SystemRole::MediaBuyer);

        $people = $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/leads/'.$lead->getKey().'/owners')
            ->assertOk()
            ->json('data');

        expect(array_keys($people[0]))->toEqualCanonicalizing(['id', 'name']);
    });
});

describe('putting a lead in somebody’s name', function (): void {
    it('assigns, audits it, and tells the new owner', function (): void {
        $setup = ownershipScenario();
        $lead = ownableLead($setup);
        $buyer = member($setup, SystemRole::MediaBuyer);

        $data = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/leads/'.$lead->getKey().'/owner', ['owner_id' => (string) $buyer->getKey()])
            ->assertOk()
            ->json('data');

        expect($data['owner_id'])->toBe((string) $buyer->getKey())
            ->and(AuditLog::query()->where('action', AuditAction::LeadAssigned->value)->count())->toBe(1);

        /*
         * The agreement that matters: somebody the directory offered is somebody the notification reaches. If
         * these two rules ever disagreed, the owner column would name a person who was never told and cannot
         * open the record.
         */
        expect(
            DB::table('notifications')
                ->where('user_id', $buyer->getKey())
                ->where('kind', 'lead_assigned')
                ->count(),
        )->toBe(1);
    });

    /* A pipeline that can only ever add an owner accumulates leads belonging to whoever touched them first. */
    it('takes a lead back out of somebody’s name', function (): void {
        $setup = ownershipScenario();
        $buyer = member($setup, SystemRole::MediaBuyer);
        $lead = ownableLead($setup, ['owner_id' => $buyer->getKey()]);

        $data = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/leads/'.$lead->getKey().'/owner', ['owner_id' => ''])
            ->assertOk()
            ->json('data');

        expect($data['owner_id'])->toBeNull()
            // Nobody is told. There is no new owner, and telling the last one would be a complaint.
            ->and(DB::table('notifications')->where('kind', 'lead_assigned')->count())->toBe(0);
    });

    it('refuses an owner who could not open the lead, and says what to do instead', function (): void {
        $setup = ownershipScenario();
        $lead = ownableLead($setup);
        $elsewhere = member($setup, SystemRole::MediaBuyer, onTheProject: false);

        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/leads/'.$lead->getKey().'/owner', ['owner_id' => (string) $elsewhere->getKey()])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'lead_cannot_be_owned')
            /* The message names the fix, because the person assigning cannot grant access themselves. */
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'projects first'));
    });

    /* Ownership is not a stage: the CRM owns where a deal stands, not who here is chasing it. */
    it('assigns a CRM-sourced lead, because ownership is this product’s business', function (): void {
        $setup = ownershipScenario();
        $lead = ownableLead($setup, ['source_kind' => 'crm', 'external_id' => 'deal-9']);
        $buyer = member($setup, SystemRole::MediaBuyer);

        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/leads/'.$lead->getKey().'/owner', ['owner_id' => (string) $buyer->getKey()])
            ->assertOk();
    });

    it('does not let a media buyer reassign a lead', function (): void {
        $setup = ownershipScenario();
        $lead = ownableLead($setup);
        $buyer = member($setup, SystemRole::MediaBuyer);

        $this->actingAs($buyer)
            ->postJson('/api/leads/'.$lead->getKey().'/owner', ['owner_id' => (string) $buyer->getKey()])
            ->assertForbidden();
    });
});

describe('what somebody says a lead is worth', function (): void {
    it('records the figure and who said it', function (): void {
        $setup = ownershipScenario();
        $lead = ownableLead($setup);

        $data = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/leads/'.$lead->getKey().'/value', [
                'value_minor' => 250_000,
                'currency' => 'sar',
            ])
            ->assertOk()
            ->json('data');

        expect($data['value_minor'])->toBe(250_000)
            // Upper-cased on the way in, because a currency is a code and not a word.
            ->and($data['currency'])->toBe('SAR')
            ->and(AuditLog::query()->where('action', AuditAction::LeadValued->value)->count())->toBe(1);
    });

    /* Null means nobody has said; zero would be a claim that the lead is worth nothing. */
    it('clears a value without claiming it is zero', function (): void {
        $setup = ownershipScenario();
        $lead = ownableLead($setup, ['value_minor' => 250_000, 'currency' => 'SAR']);

        $data = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/leads/'.$lead->getKey().'/value', ['value_minor' => null])
            ->assertOk()
            ->json('data');

        expect($data['value_minor'])->toBeNull()->and($data['currency'])->toBeNull();
    });

    it('accepts a deliberate zero, which is a judgement somebody made', function (): void {
        $setup = ownershipScenario();
        $lead = ownableLead($setup);

        expect($this->actingAs($setup['scenario']->owner)
            ->postJson('/api/leads/'.$lead->getKey().'/value', ['value_minor' => 0, 'currency' => 'SAR'])
            ->assertOk()
            ->json('data.value_minor'))->toBe(0);
    });

    it('refuses a value with no currency, which is a number with no unit', function (): void {
        $setup = ownershipScenario();
        $lead = ownableLead($setup);

        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/leads/'.$lead->getKey().'/value', ['value_minor' => 250_000])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_lead_value');
    });

    it('refuses a negative value', function (): void {
        $setup = ownershipScenario();
        $lead = ownableLead($setup);

        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/leads/'.$lead->getKey().'/value', [
                'value_minor' => -100,
                'currency' => 'SAR',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_lead_value');
    });

    /*
     * The CRM holds the deal's amount. A figure typed here would be overwritten by the next sync without
     * anybody being told — and in between, two numbers would have been quoted to the same client.
     */
    it('refuses to value a CRM-sourced lead, and says where to do it', function (): void {
        $setup = ownershipScenario();
        $lead = ownableLead($setup, [
            'source_kind' => 'crm',
            'source_detail' => 'HubSpot · Proposal sent',
            'external_id' => 'deal-11',
        ]);

        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/leads/'.$lead->getKey().'/value', [
                'value_minor' => 250_000,
                'currency' => 'SAR',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_lead_value');
    });
});

describe('writing down what happened', function (): void {
    it('puts the note in the lead’s own history, with its author', function (): void {
        $setup = ownershipScenario();
        $lead = ownableLead($setup);

        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/leads/'.$lead->getKey().'/notes', ['note' => '  Rang twice, no answer.  '])
            ->assertOk();

        $event = LeadEvent::query()
            ->where('lead_id', $lead->getKey())
            ->where('type', 'note_added')
            ->firstOrFail();

        expect($event->getAttribute('note'))->toBe('Rang twice, no answer.')
            ->and($event->getAttribute('actor_id'))->toBe((string) $setup['scenario']->owner->getKey());
    });

    /* An empty note reads as something that was lost, which is worse than no note. */
    it('refuses an empty note', function (): void {
        $setup = ownershipScenario();
        $lead = ownableLead($setup);

        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/leads/'.$lead->getKey().'/notes', ['note' => '   '])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_lead_note');
    });

    it('does not reach a lead the actor cannot see', function (): void {
        $setup = ownershipScenario();
        $other = Client::factory()->create(['workspace_id' => $setup['scenario']->workspace->getKey()]);
        $hidden = ownableLead($setup, ['client_id' => $other->getKey(), 'project_id' => null]);
        $manager = member($setup, SystemRole::ProjectManager);

        /* A project manager holds `projects.view_all`, so this is reachable for them... */
        $this->actingAs($manager)
            ->postJson('/api/leads/'.$hidden->getKey().'/notes', ['note' => 'Reachable.'])
            ->assertOk();

        /* ...and a media buyer assigned to one project is not allowed to manage leads at all. */
        $this->actingAs(member($setup, SystemRole::MediaBuyer))
            ->postJson('/api/leads/'.$hidden->getKey().'/notes', ['note' => 'Not reachable.'])
            ->assertForbidden();
    });
});

describe('reading what happened', function (): void {
    it('shows the history newest first, naming who did each thing', function (): void {
        $setup = ownershipScenario();
        $lead = ownableLead($setup);

        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/leads/'.$lead->getKey().'/notes', ['note' => 'Rang twice, no answer.'])
            ->assertOk();
        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/leads/'.$lead->getKey().'/stage', ['stage' => 'contacted'])
            ->assertOk();

        $history = $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/leads/'.$lead->getKey().'/history')
            ->assertOk()
            ->json('data');

        expect($history[0]['type'])->toBe('stage_changed')
            ->and($history[0]['to_stage_label'])->toBe('Contacted')
            ->and($history[1]['type'])->toBe('note_added')
            ->and($history[1]['note'])->toBe('Rang twice, no answer.')
            ->and($history[1]['actor_name'])->toBe($setup['scenario']->owner->getAttribute('name'));
    });

    /*
     * The honest answer to "who moved this". A CRM sync has no actor, and filling the column in with whoever
     * happened to trigger the sync would make the trail say something untrue.
     */
    it('leaves the actor null where the system did it rather than a person', function (): void {
        $setup = ownershipScenario();
        $lead = ownableLead($setup);

        LeadEvent::query()->create([
            'workspace_id' => $setup['scenario']->workspace->getKey(),
            'lead_id' => $lead->getKey(),
            'actor_id' => null,
            'type' => 'stage_changed',
            'from_stage' => 'new',
            'to_stage' => 'qualified',
            'occurred_at' => new DateTimeImmutable('-1 hour'),
        ]);

        $history = $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/leads/'.$lead->getKey().'/history')
            ->assertOk()
            ->json('data');

        expect($history[0]['actor_name'])->toBeNull();
    });

    /* Reading a pipeline is how it gets reviewed, and the readers are not only the writers. */
    it('is readable by a media buyer, who may not write to it', function (): void {
        $setup = ownershipScenario();
        $lead = ownableLead($setup);
        $buyer = member($setup, SystemRole::MediaBuyer);

        $this->actingAs($buyer)->getJson('/api/leads/'.$lead->getKey().'/history')->assertOk();

        $this->actingAs($buyer)
            ->postJson('/api/leads/'.$lead->getKey().'/notes', ['note' => 'Not mine to write.'])
            ->assertForbidden();
    });

    it('does not let a creative read it at all', function (): void {
        $setup = ownershipScenario();
        $lead = ownableLead($setup);

        $this->actingAs(member($setup, SystemRole::Creative))
            ->getJson('/api/leads/'.$lead->getKey().'/history')
            ->assertForbidden();
    });
});
