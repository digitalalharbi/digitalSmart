<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Reporting;

use App\Application\Reporting\Subscription\ChangeReportSubscription;
use App\Application\Reporting\Subscription\InvalidSubscription;
use App\Application\Reporting\Subscription\ListReportSubscriptions;
use App\Application\Reporting\Subscription\ReportSubscription;
use App\Application\Reporting\Subscription\SubscribeReportRecipient;
use App\Application\Reporting\Subscription\UnsubscribeReportRecipient;
use App\Domain\Reporting\EmailCadence;
use App\Interface\Http\Controller\Controller;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * The people outside the agency who are emailed a report's link, and what happened to each email.
 */
final class ReportRecipientController extends Controller
{
    public function index(string $report, ListReportSubscriptions $list): JsonResponse
    {
        $result = $list($report);

        return response()->json([
            'data' => array_map(fn (array $row): array => [
                ...$this->resource($row['subscription']),
                'deliveries' => array_map(static fn (array $delivery): array => [
                    'id' => $delivery['id'],
                    'period' => $delivery['period_key'],
                    'state' => $delivery['state']->value,
                    'detail' => $delivery['detail'],
                    'attempts' => $delivery['attempts'],
                    'attempted_at' => $delivery['attempted_at']?->format(DateTimeImmutable::ATOM),
                ], $row['deliveries']),
            ], $result['recipients']),
            'meta' => [
                // Said on the surface, not discovered from a row that never turns green.
                'mail_configured' => $result['mail_configured'],
                'mail_missing' => $result['mail_missing'],
                'cadences' => EmailCadence::values(),
            ],
        ]);
    }

    public function store(string $report, Request $request, SubscribeReportRecipient $subscribe): JsonResponse
    {
        $valid = $request->validate([
            'email' => ['required', 'string', 'max:254'],
            'name' => ['nullable', 'string', 'max:120'],
            'locale' => ['nullable', Rule::in(['ar', 'en'])],
            'cadence' => ['required', Rule::enum(EmailCadence::class)],
            // A sentence somebody wrote, not a ticked box: it has to be readable a year later.
            'consent_note' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        try {
            $subscription = $subscribe(
                $report,
                $valid['email'],
                $valid['name'] ?? null,
                $valid['locale'] ?? 'ar',
                EmailCadence::from($valid['cadence']),
                $valid['consent_note'],
            );
        } catch (InvalidSubscription $refusal) {
            return response()->json(['message' => $refusal->getMessage(), 'code' => $refusal->reason], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['data' => [...$this->resource($subscription), 'deliveries' => []]], Response::HTTP_CREATED);
    }

    public function pause(string $recipient, ChangeReportSubscription $change): Response
    {
        $change->pause($recipient);

        return response()->noContent();
    }

    public function resume(string $recipient, ChangeReportSubscription $change): Response
    {
        $change->resume($recipient);

        return response()->noContent();
    }

    public function destroy(string $recipient, ChangeReportSubscription $change): Response
    {
        $change->remove($recipient);

        return response()->noContent();
    }

    /**
     * The recipient's own stop, with no session. One answer whatever the token was.
     *
     * POST, so a mail scanner or a link preview that fetches the address in the email unsubscribes nobody.
     */
    public function unsubscribe(string $token, UnsubscribeReportRecipient $unsubscribe): JsonResponse
    {
        $unsubscribe($token);

        return response()->json(['data' => ['state' => 'unsubscribed']]);
    }

    /** @return array<string, mixed> */
    private function resource(ReportSubscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'report_id' => $subscription->reportId,
            'email' => $subscription->recipientEmail,
            'name' => $subscription->recipientName,
            'locale' => $subscription->locale,
            'cadence' => $subscription->cadence->value,
            'status' => $subscription->status,
            'halted_reason' => $subscription->haltedReason,
            'consent_note' => $subscription->consentNote,
            'consented_at' => $subscription->consentedAt->format(DateTimeImmutable::ATOM),
            'added_by_name' => $subscription->createdByName,
        ];
    }
}
