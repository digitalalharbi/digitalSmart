<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Reporting\Subscription\ReportSubscription;
use App\Application\Reporting\Subscription\ReportSubscriptionStore;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Reporting\EmailCadence;
use App\Domain\Reporting\EmailDeliveryState;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

final readonly class EloquentReportSubscriptionStore implements ReportSubscriptionStore
{
    public function __construct(private IdentifierGenerator $identifiers) {}

    public function exists(string $workspaceId, string $reportId, string $email): bool
    {
        return DB::table('report_email_subscriptions')
            ->where('workspace_id', $workspaceId)->where('report_id', $reportId)->where('recipient_email', $email)->exists();
    }

    public function create(
        string $workspaceId,
        string $reportId,
        string $email,
        ?string $name,
        string $locale,
        EmailCadence $cadence,
        string $consentNote,
        string $unsubscribeTokenHash,
        string $createdBy,
        DateTimeImmutable $at,
    ): ReportSubscription {
        $id = $this->identifiers->generate();

        DB::table('report_email_subscriptions')->insert([
            'id' => $id, 'workspace_id' => $workspaceId, 'report_id' => $reportId,
            'recipient_email' => $email, 'recipient_name' => $name, 'locale' => $locale,
            'cadence' => $cadence->value, 'status' => 'active', 'consent_note' => $consentNote,
            'consented_at' => $at, 'unsubscribe_token_hash' => $unsubscribeTokenHash,
            'created_by' => $createdBy, 'created_at' => $at, 'updated_at' => $at,
        ]);

        return new ReportSubscription($id, $workspaceId, $reportId, $email, $name, $locale, $cadence, 'active', $consentNote, $at, null, $createdBy);
    }

    public function find(string $workspaceId, string $id): ?ReportSubscription
    {
        $row = $this->query()->where('s.workspace_id', $workspaceId)->where('s.id', $id)->first();

        return $row instanceof stdClass ? $this->map($row) : null;
    }

    public function forReport(string $workspaceId, string $reportId): array
    {
        /** @var list<stdClass> $rows */
        $rows = $this->query()->where('s.workspace_id', $workspaceId)->where('s.report_id', $reportId)
            ->orderBy('s.created_at')->orderBy('s.id')->get()->all();

        return array_map($this->map(...), $rows);
    }

    public function setStatus(string $id, string $status, ?string $haltedReason = null): void
    {
        DB::table('report_email_subscriptions')->where('id', $id)
            ->update(['status' => $status, 'halted_reason' => $haltedReason, 'updated_at' => now()]);
    }

    public function remove(string $workspaceId, string $id): void
    {
        DB::table('report_email_subscriptions')->where('workspace_id', $workspaceId)->where('id', $id)->delete();
    }

    public function rotateUnsubscribeToken(string $id, string $tokenHash): void
    {
        DB::table('report_email_subscriptions')->where('id', $id)
            ->update(['unsubscribe_token_hash' => $tokenHash, 'updated_at' => now()]);
    }

    public function active(int $limit): array
    {
        /** @var list<stdClass> $rows */
        $rows = $this->query()->where('s.status', 'active')->orderBy('s.created_at')->orderBy('s.id')->limit($limit)->get()->all();

        return array_map($this->map(...), $rows);
    }

    public function claim(string $workspaceId, string $subscriptionId, string $periodKey, int $maxAttempts, DateTimeImmutable $at): ?string
    {
        $id = $this->identifiers->generate();

        /*
         * Insert-or-nothing, in one statement, so two runs cannot both create the period. The unique key decides;
         * `ON CONFLICT DO NOTHING` rather than a caught violation, which would abort a surrounding transaction.
         */
        $inserted = DB::affectingStatement(
            'insert into report_email_deliveries (id, workspace_id, subscription_id, period_key, state, attempts, attempted_at, created_at, updated_at)
             values (?, ?, ?, ?, ?, 1, ?, ?, ?) on conflict (subscription_id, period_key) do nothing',
            [$id, $workspaceId, $subscriptionId, $periodKey, EmailDeliveryState::Pending->value, $at, $at, $at],
        );

        if ($inserted === 1) {
            return $id;
        }

        $retryable = array_map(
            static fn (EmailDeliveryState $state): string => $state->value,
            array_values(array_filter(EmailDeliveryState::cases(), static fn (EmailDeliveryState $state): bool => $state->mayRetry())),
        );

        /*
         * A retry is claimed by the update itself: the row is taken only if it is still retryable and has attempts
         * left at the moment of writing, so a concurrent run that got there first leaves nothing to take.
         */
        $existing = DB::table('report_email_deliveries')
            ->where('subscription_id', $subscriptionId)->where('period_key', $periodKey)
            ->whereIn('state', $retryable)->where('attempts', '<', $maxAttempts)
            // Not the run that is still sending it: a pending row is retried only once it has been left for a while.
            ->where(static function ($query) use ($at): void {
                $query->where('state', '!=', EmailDeliveryState::Pending->value)
                    ->orWhere('attempted_at', '<', $at->modify('-30 minutes'));
            })
            ->value('id');

        if (! is_string($existing)) {
            return null;
        }

        $taken = DB::table('report_email_deliveries')->where('id', $existing)->where('attempts', '<', $maxAttempts)
            ->update(['state' => EmailDeliveryState::Pending->value, 'attempts' => DB::raw('attempts + 1'), 'attempted_at' => $at, 'updated_at' => $at]);

        return $taken === 1 ? $existing : null;
    }

    public function settle(string $deliveryId, EmailDeliveryState $state, ?string $detail, ?string $providerMessageId, ?string $shareId): void
    {
        DB::table('report_email_deliveries')->where('id', $deliveryId)->update([
            'state' => $state->value, 'detail' => $detail, 'provider_message_id' => $providerMessageId,
            'share_id' => $shareId, 'updated_at' => now(),
        ]);
    }

    public function deliveries(string $workspaceId, string $subscriptionId, int $limit): array
    {
        /** @var list<stdClass> $rows */
        $rows = DB::table('report_email_deliveries')->where('workspace_id', $workspaceId)->where('subscription_id', $subscriptionId)
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()->all();

        return array_map(static fn (stdClass $row): array => [
            'id' => (string) $row->id,
            'period_key' => (string) $row->period_key,
            'state' => EmailDeliveryState::from((string) $row->state),
            'detail' => is_string($row->detail) ? $row->detail : null,
            'attempts' => (int) $row->attempts,
            'attempted_at' => is_string($row->attempted_at) ? new DateTimeImmutable($row->attempted_at) : null,
        ], $rows);
    }

    public function unsubscribeByTokenHash(string $tokenHash): ?ReportSubscription
    {
        $row = $this->query()->where('s.unsubscribe_token_hash', $tokenHash)->whereIn('s.status', ['active', 'paused', 'halted'])->first();

        if (! $row instanceof stdClass) {
            return null;
        }

        DB::table('report_email_subscriptions')->where('id', $row->id)
            ->update(['status' => 'unsubscribed', 'halted_reason' => null, 'updated_at' => now()]);

        return $this->map($row);
    }

    private function query(): \Illuminate\Database\Query\Builder
    {
        return DB::table('report_email_subscriptions as s')->leftJoin('users as u', 'u.id', '=', 's.created_by')
            ->select(['s.*', 'u.name as created_by_name']);
    }

    private function map(stdClass $row): ReportSubscription
    {
        return new ReportSubscription(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            reportId: (string) $row->report_id,
            recipientEmail: (string) $row->recipient_email,
            recipientName: is_string($row->recipient_name) ? $row->recipient_name : null,
            locale: (string) $row->locale,
            cadence: EmailCadence::from((string) $row->cadence),
            status: (string) $row->status,
            consentNote: (string) $row->consent_note,
            consentedAt: new DateTimeImmutable((string) $row->consented_at),
            haltedReason: is_string($row->halted_reason) ? $row->halted_reason : null,
            createdBy: is_string($row->created_by) ? $row->created_by : null,
            createdByName: is_string($row->created_by_name ?? null) ? $row->created_by_name : null,
        );
    }
}
