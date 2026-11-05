<?php

declare(strict_types=1);

use App\Application\Notifications\Mail\MailSendResult;
use App\Application\Notifications\Mail\MailTransport;
use App\Application\Notifications\Mail\RenderedEmail;
use App\Domain\Access\SystemRole;
use App\Domain\Audit\AuditAction;
use App\Infrastructure\Notifications\Mail\LaravelMailTransport;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| A client-safe report emailed to somebody outside the agency
|--------------------------------------------------------------------------
|
| Not a staff digest and not a scheduled export: a link to one client-safe report, sent to one address somebody
| recorded consent for, once per period, through a real mail provider or not at all. What is asserted is what must
| never happen — an email without consent, to an internal report, twice in a period, after access was withdrawn,
| after the recipient said stop, or recorded as sent when nothing left.
|
*/

/** A mail provider that takes messages and keeps them, so a test can read what would have left. */
final class RecordingMailTransport implements MailTransport
{
    /** @var list<array{to: string, email: RenderedEmail, headers: array<string, string>}> */
    public array $sent = [];

    public function __construct(public bool $configured = true, public bool $accepts = true) {}

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function missing(): string
    {
        return $this->configured ? '' : 'Email is not sent from this installation yet.';
    }

    public function send(string $toEmail, ?string $toName, RenderedEmail $email, array $headers = []): MailSendResult
    {
        if (! $this->accepts) {
            return new MailSendResult(false, null, '550 mailbox unavailable');
        }

        $this->sent[] = ['to' => $toEmail, 'email' => $email, 'headers' => $headers];

        return new MailSendResult(true, 'msg-'.count($this->sent));
    }
}

/** @return array{scenario: WorkspaceScenario, report: string, client: Client} */
function emailedReport(string $audience = 'client_safe'): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    Project::factory()->forClient($client)->create();

    $report = test()->actingAs($scenario->owner)->postJson('/api/reports', [
        'name' => 'Monthly performance', 'client_id' => (string) $client->getKey(), 'audience' => $audience,
        'window' => 'last_30_days', 'sections' => [['kind' => 'scorecard', 'metrics' => ['spend', 'clicks']]],
    ])->assertCreated()->json('data.id');

    return ['scenario' => $scenario, 'report' => (string) $report, 'client' => $client];
}

/** @return array<string, mixed> */
function recipientBody(string $email = 'client@customer.example'): array
{
    return [
        'email' => $email, 'name' => 'Noura', 'locale' => 'en', 'cadence' => 'weekly',
        'consent_note' => 'Asked for the weekly link in the kickoff call on 3 November.',
    ];
}

function recordingMail(bool $configured = true, bool $accepts = true): RecordingMailTransport
{
    $transport = new RecordingMailTransport($configured, $accepts);
    app()->instance(MailTransport::class, $transport);

    return $transport;
}

function addRecipient(object $as, string $report, string $email = 'client@customer.example'): string
{
    return (string) test()->actingAs($as)->postJson("/api/reports/{$report}/recipients", recipientBody($email))->assertCreated()->json('data.id');
}

/** @return array<string, mixed> one row as an array, so a missing row fails the assertion rather than the type */
function emailRow(string $table, string $column, string $id): array
{
    return (array) DB::table($table)->where($column, $id)->first();
}

/** The first capture of a pattern in an email's text, or an empty string. */
function emailToken(string $pattern, string $text): string
{
    return preg_match($pattern, $text, $found) === 1 ? $found[1] : '';
}

describe('adding a recipient', function (): void {
    it('records who agreed and how, and sends nothing by itself', function (): void {
        $setup = emailedReport();
        $mail = recordingMail();

        $id = addRecipient($setup['scenario']->owner, $setup['report']);

        expect($mail->sent)->toBe([])
            ->and(DB::table('report_email_subscriptions')->where('id', $id)->value('consent_note'))->toContain('kickoff call')
            ->and(DB::table('audit_logs')->where('action', AuditAction::ReportRecipientAdded->value)->where('auditable_id', $id)->exists())->toBeTrue();
    });

    it('refuses an internal report, a missing consent note, a bad address and a second copy of the same address', function (): void {
        $internal = emailedReport('internal');
        test()->actingAs($internal['scenario']->owner)->postJson("/api/reports/{$internal['report']}/recipients", recipientBody())
            ->assertStatus(422)->assertJsonPath('code', 'report_is_internal');

        $setup = emailedReport();
        $owner = $setup['scenario']->owner;
        $url = "/api/reports/{$setup['report']}/recipients";

        test()->actingAs($owner)->postJson($url, [...recipientBody(), 'consent_note' => ''])->assertStatus(422);
        test()->actingAs($owner)->postJson($url, [...recipientBody(), 'email' => 'not-an-address'])->assertStatus(422)->assertJsonPath('code', 'email_invalid');
        test()->actingAs($owner)->postJson($url, recipientBody())->assertCreated();
        test()->actingAs($owner)->postJson($url, recipientBody('CLIENT@customer.example'))->assertStatus(422)->assertJsonPath('code', 'already_subscribed');
    });

    it('is for people who may share: a media buyer may build a report and not email it out', function (): void {
        $setup = emailedReport();
        $buyer = $setup['scenario']->addMember(SystemRole::MediaBuyer);

        test()->actingAs($buyer)->postJson("/api/reports/{$setup['report']}/recipients", recipientBody())->assertForbidden();
        test()->actingAs($buyer)->getJson("/api/reports/{$setup['report']}/recipients")->assertForbidden();
    });

    it('does not let another workspace add to, list or remove this report’s recipients', function (): void {
        $setup = emailedReport();
        $id = addRecipient($setup['scenario']->owner, $setup['report']);
        $other = WorkspaceScenario::create();

        test()->actingAs($other->owner)->postJson("/api/reports/{$setup['report']}/recipients", recipientBody('x@customer.example'))->assertNotFound();
        test()->actingAs($other->owner)->getJson("/api/reports/{$setup['report']}/recipients")->assertNotFound();
        test()->actingAs($other->owner)->deleteJson("/api/report-recipients/{$id}")->assertNotFound();

        expect(DB::table('report_email_subscriptions')->where('id', $id)->exists())->toBeTrue();
    });
});

describe('the scheduled send', function (): void {
    it('emails a fresh link once, records the provider’s acceptance, and never repeats a period', function (): void {
        $setup = emailedReport();
        $mail = recordingMail();
        $id = addRecipient($setup['scenario']->owner, $setup['report']);

        test()->artisan('reports:email-due')->assertSuccessful();
        test()->artisan('reports:email-due')->assertSuccessful();

        expect($mail->sent)->toHaveCount(1)
            ->and($mail->sent[0]['to'])->toBe('client@customer.example')
            ->and($mail->sent[0]['email']->text)->toContain('/shared-reports/')
            ->and($mail->sent[0]['headers'])->toHaveKey('List-Unsubscribe');

        $delivery = emailRow('report_email_deliveries', 'subscription_id', $id);

        expect(DB::table('report_email_deliveries')->where('subscription_id', $id)->count())->toBe(1)
            ->and($delivery['state'])->toBe('provider_accepted')
            ->and($delivery['provider_message_id'])->toBe('msg-1')
            ->and($delivery['share_id'])->not->toBeNull();
    });

    it('sends a link a stranger can open, and nothing from the report itself', function (): void {
        $setup = emailedReport();
        $mail = recordingMail();
        addRecipient($setup['scenario']->owner, $setup['report']);

        test()->artisan('reports:email-due')->assertSuccessful();

        $token = emailToken('#/shared-reports/([A-Fa-f0-9]{96})#', $mail->sent[0]['email']->text);

        auth()->forgetGuards();
        test()->getJson('/api/shared-reports/'.$token)->assertOk()->assertJsonPath('data.state', 'active');

        // The title is the only thing about the report in the message: no figure, no client name.
        expect($mail->sent[0]['email']->text)->toContain('Monthly performance')
            ->and($mail->sent[0]['email']->text)->not->toContain($setup['client']->name);
    });

    it('records awaiting credentials, sends nothing and mints no link when no provider is configured', function (): void {
        $setup = emailedReport();
        $mail = recordingMail(configured: false);
        $id = addRecipient($setup['scenario']->owner, $setup['report']);

        test()->artisan('reports:email-due')->assertSuccessful();

        expect($mail->sent)->toBe([])
            ->and(DB::table('report_email_deliveries')->where('subscription_id', $id)->value('state'))->toBe('awaiting_credentials')
            ->and(DB::table('report_shares')->where('report_id', $setup['report'])->count())->toBe(0);
    });

    it('never records a refused message as accepted, and stops retrying after three attempts', function (): void {
        $setup = emailedReport();
        $mail = recordingMail(accepts: false);
        $id = addRecipient($setup['scenario']->owner, $setup['report']);

        foreach (range(1, 5) as $run) {
            test()->artisan('reports:email-due')->assertSuccessful();
        }

        $delivery = emailRow('report_email_deliveries', 'subscription_id', $id);

        expect($mail->sent)->toBe([])
            ->and($delivery['state'])->toBe('failed')
            ->and($delivery['detail'])->toContain('550')
            ->and($delivery['attempts'])->toBe(3);
    });

    it('halts instead of sending once the member who added the recipient may no longer share', function (): void {
        $setup = emailedReport();
        $mail = recordingMail();
        $manager = $setup['scenario']->addMember(SystemRole::ProjectManager);
        $id = addRecipient($manager, $setup['report']);

        test()->actingAs($setup['scenario']->owner)
            ->patchJson('/api/members/'.$manager->getKey().'/role', ['role' => SystemRole::MediaBuyer->value])->assertSuccessful();
        auth()->forgetGuards();

        test()->artisan('reports:email-due')->assertSuccessful();

        $row = emailRow('report_email_subscriptions', 'id', $id);

        expect($mail->sent)->toBe([])
            ->and($row['status'])->toBe('halted')
            ->and($row['halted_reason'])->toContain('can no longer share')
            ->and(DB::table('report_email_deliveries')->where('subscription_id', $id)->value('state'))->toBe('skipped');
    });

    it('halts once the report stops being client-safe', function (): void {
        $setup = emailedReport();
        $mail = recordingMail();
        $id = addRecipient($setup['scenario']->owner, $setup['report']);

        DB::table('reports')->where('id', $setup['report'])->update(['audience' => 'internal']);

        test()->artisan('reports:email-due')->assertSuccessful();

        expect($mail->sent)->toBe([])
            ->and(DB::table('report_email_subscriptions')->where('id', $id)->value('status'))->toBe('halted');
    });

    it('does not write to a paused recipient, and does once resumed', function (): void {
        $setup = emailedReport();
        $mail = recordingMail();
        $owner = $setup['scenario']->owner;
        $id = addRecipient($owner, $setup['report']);

        test()->actingAs($owner)->postJson("/api/report-recipients/{$id}/pause")->assertNoContent();
        test()->artisan('reports:email-due')->assertSuccessful();
        expect($mail->sent)->toBe([]);

        test()->actingAs($owner)->postJson("/api/report-recipients/{$id}/resume")->assertNoContent();
        test()->artisan('reports:email-due')->assertSuccessful();
        expect($mail->sent)->toHaveCount(1);
    });
});

describe('stopping', function (): void {
    it('lets the recipient stop with the link in the email, with no account, and is not undone by the agency', function (): void {
        $setup = emailedReport();
        $mail = recordingMail();
        $owner = $setup['scenario']->owner;
        $id = addRecipient($owner, $setup['report']);
        test()->artisan('reports:email-due')->assertSuccessful();

        $token = emailToken('#/report-emails/unsubscribe/([a-f0-9]{64})#', $mail->sent[0]['email']->text);
        auth()->forgetGuards();

        test()->postJson('/api/report-emails/unsubscribe/'.$token)->assertOk()->assertJsonPath('data.state', 'unsubscribed');
        expect(DB::table('report_email_subscriptions')->where('id', $id)->value('status'))->toBe('unsubscribed')
            ->and(DB::table('audit_logs')->where('action', AuditAction::ReportRecipientUnsubscribed->value)->where('auditable_id', $id)->whereNull('actor_id')->exists())->toBeTrue();

        // The agency cannot resume somebody who said stop.
        test()->actingAs($owner)->postJson("/api/report-recipients/{$id}/resume")->assertNoContent();
        expect(DB::table('report_email_subscriptions')->where('id', $id)->value('status'))->toBe('unsubscribed');
    });

    it('answers an unknown token exactly as it answers a real one, and a GET changes nothing', function (): void {
        test()->postJson('/api/report-emails/unsubscribe/'.str_repeat('a', 64))->assertOk()->assertJsonPath('data.state', 'unsubscribed');
        test()->getJson('/api/report-emails/unsubscribe/'.str_repeat('a', 64))->assertStatus(405);
    });

    it('removes the address and its history when the recipient is removed', function (): void {
        $setup = emailedReport();
        recordingMail();
        $owner = $setup['scenario']->owner;
        $id = addRecipient($owner, $setup['report']);
        test()->artisan('reports:email-due')->assertSuccessful();

        test()->actingAs($owner)->deleteJson("/api/report-recipients/{$id}")->assertNoContent();

        expect(DB::table('report_email_subscriptions')->where('id', $id)->exists())->toBeFalse()
            ->and(DB::table('report_email_deliveries')->where('subscription_id', $id)->exists())->toBeFalse();
    });
});

describe('the surface', function (): void {
    it('says whether email can be sent at all, and shows each period’s outcome', function (): void {
        $setup = emailedReport();
        recordingMail(configured: false);
        $owner = $setup['scenario']->owner;
        addRecipient($owner, $setup['report']);
        test()->artisan('reports:email-due')->assertSuccessful();

        test()->actingAs($owner)->getJson("/api/reports/{$setup['report']}/recipients")->assertOk()
            ->assertJsonPath('meta.mail_configured', false)
            ->assertJsonPath('data.0.email', 'client@customer.example')
            ->assertJsonPath('data.0.deliveries.0.state', 'awaiting_credentials');
    });
});

describe('the mail transport', function (): void {
    it('is not configured by default, and says exactly what is missing', function (): void {
        $transport = new LaravelMailTransport(app(MailFactory::class), 'log', 'no-reply@digitalsmart.local', 'digitalSmart', false);

        expect($transport->isConfigured())->toBeFalse()
            ->and($transport->missing())->toContain('MAIL_MAILER')->toContain('MAIL_FROM_ADDRESS')->toContain('MAIL_DELIVERY_ENABLED')
            ->and($transport->send('a@customer.example', null, new RenderedEmail('s', '<p>h</p>', 't'))->accepted)->toBeFalse();
    });

    it('needs all three: a sending mailer and a real sender are not enough without the switch', function (): void {
        $transport = new LaravelMailTransport(app(MailFactory::class), 'smtp', 'reports@agency.sa', 'Agency', false);

        expect($transport->isConfigured())->toBeFalse()
            ->and($transport->missing())->toContain('MAIL_DELIVERY_ENABLED')
            ->and(str_contains($transport->missing(), 'MAIL_MAILER'))->toBeFalse();
    });

    it('hands a message to the configured mailer with its subject, both bodies and the unsubscribe header', function (): void {
        // A named mailer over the in-memory transport: everything the real path does, with nothing leaving the process.
        config(['mail.mailers.outbox' => ['transport' => 'array']]);
        $transport = new LaravelMailTransport(app(MailFactory::class), 'outbox', 'reports@agency.sa', 'Agency', true);

        $result = $transport->send('client@customer.example', 'Noura', new RenderedEmail('Your report', '<p>Open it</p>', 'Open it'), ['List-Unsubscribe' => '<https://app.example.com/u>']);

        $message = app(MailFactory::class)->mailer('outbox')->getSymfonyTransport()->messages()->first()->getOriginalMessage();

        expect($result->accepted)->toBeTrue()
            ->and($message->getSubject())->toBe('Your report')
            ->and($message->getTextBody())->toBe('Open it')
            ->and($message->getHtmlBody())->toContain('Open it')
            ->and($message->getHeaders()->get('List-Unsubscribe')->getBodyAsString())->toBe('<https://app.example.com/u>');
    });
});
