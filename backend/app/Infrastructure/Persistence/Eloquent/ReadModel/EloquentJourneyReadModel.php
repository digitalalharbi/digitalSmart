<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\ReadModel;

use App\Application\Journeys\Contract\JourneyReadModel;
use App\Domain\Commerce\AttributionEvidence;
use App\Domain\Journeys\IdentityEvidence;
use App\Domain\Journeys\Journey;
use App\Domain\Journeys\Touchpoint;
use App\Domain\Journeys\TouchpointKind;
use App\Domain\Shared\ValueObject\ContactPoint;
use DateTimeImmutable;
use Exception;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Assembles one person's history from the records that exist.
 *
 * Resolved on read, never stored. An identity graph in a table goes stale the moment the next order lands and
 * nobody dares rebuild it; resolving on read means the answer is always made of rows a person can go and look
 * at for themselves.
 *
 * The matching is **exact on normalised contact details and nothing else.** No fuzzy names, no "same city and
 * similar spelling": merging two people's histories produces one record nobody can disentangle afterwards, and
 * the cost of missing a link is only an unlinked journey.
 */
final readonly class EloquentJourneyReadModel implements JourneyReadModel
{
    public function forContact(
        string $workspaceId,
        ?array $visibleProjectIds,
        string $clientId,
        ContactPoint $contact,
    ): Journey {
        if (! $contact->isReachable()) {
            /*
             * Nothing to match on. An empty journey with `None` evidence rather than a guess — an anonymous
             * enquiry is a real enquiry and genuinely cannot be joined to anything.
             */
            return new Journey([], IdentityEvidence::None);
        }

        if (! $this->canSeeClient($workspaceId, $visibleProjectIds, $clientId)) {
            // Outside the actor's reach. Indistinguishable from a client with no records, deliberately.
            return new Journey([], IdentityEvidence::None);
        }

        $leads = $this->leadTouchpoints($workspaceId, $clientId, $contact);
        $orders = $this->orderTouchpoints($workspaceId, $clientId, $contact);

        $touchpoints = [...$leads['touchpoints'], ...$orders['touchpoints']];

        usort(
            $touchpoints,
            static fn (Touchpoint $a, Touchpoint $b): int => $a->occurredAt <=> $b->occurredAt,
        );

        return new Journey(
            touchpoints: $touchpoints,
            identityEvidence: $this->strongestEvidence($leads['evidence'], $orders['evidence']),
            name: $leads['name'] ?? $orders['name'],
        );
    }

    public function forLead(string $workspaceId, ?array $visibleProjectIds, string $leadId): ?Journey
    {
        $lead = DB::table('leads')
            ->where('workspace_id', $workspaceId)
            ->where('id', $leadId)
            ->first(['client_id', 'email', 'phone']);

        if ($lead === null) {
            return null;
        }

        /*
         * Checked here as well as inside `forContact`, so a lead the actor cannot reach is **not addressable**
         * rather than addressable-but-empty. The rest of the product answers 404 for an out-of-reach lead, and
         * an empty journey would confirm that the id exists — which is itself across the boundary.
         */
        if (! $this->canSeeClient($workspaceId, $visibleProjectIds, (string) $lead->client_id)) {
            return null;
        }

        return $this->forContact(
            $workspaceId,
            $visibleProjectIds,
            (string) $lead->client_id,
            ContactPoint::of(
                is_string($lead->email) ? $lead->email : null,
                is_string($lead->phone) ? $lead->phone : null,
            ),
        );
    }

    /**
     * Enquiries from this person.
     *
     * @return array{touchpoints: list<Touchpoint>, evidence: IdentityEvidence, name: string|null}
     */
    private function leadTouchpoints(string $workspaceId, string $clientId, ContactPoint $contact): array
    {
        $rows = DB::table('leads')
            ->where('workspace_id', $workspaceId)
            ->where('client_id', $clientId)
            ->where(fn (Builder $scope) => $this->matchContact($scope, $contact))
            ->orderBy('received_at')
            ->get();

        $touchpoints = [];
        $evidence = IdentityEvidence::None;
        $name = null;

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;

            $touchpoints[] = new Touchpoint(
                kind: TouchpointKind::Lead,
                id: (string) ($values['id'] ?? ''),
                occurredAt: $this->date($values['received_at'] ?? null),
                summary: $this->leadSummary($values),
                attributionEvidence: AttributionEvidence::tryFrom((string) ($values['attribution_evidence'] ?? ''))
                    ?? AttributionEvidence::None,
                campaign: $this->string($values, 'utm_campaign'),
                source: $this->string($values, 'utm_source'),
                valueMinor: isset($values['value_minor']) && is_numeric($values['value_minor'])
                    ? (int) $values['value_minor']
                    : null,
                currency: $this->string($values, 'currency'),
                state: $this->string($values, 'stage'),
            );

            $evidence = $this->strongestEvidence($evidence, $this->evidenceFor($values, $contact));
            $name ??= $this->string($values, 'name');
        }

        return ['touchpoints' => $touchpoints, 'evidence' => $evidence, 'name' => $name];
    }

    /**
     * Orders from this person.
     *
     * @return array{touchpoints: list<Touchpoint>, evidence: IdentityEvidence, name: string|null}
     */
    private function orderTouchpoints(string $workspaceId, string $clientId, ContactPoint $contact): array
    {
        $rows = DB::table('store_orders as o')
            ->join('stores as s', 's.id', '=', 'o.store_id')
            ->join('store_customers as c', 'c.id', '=', 'o.store_customer_id')
            ->where('o.workspace_id', $workspaceId)
            ->where('s.client_id', $clientId)
            ->where(fn (Builder $scope) => $this->matchContact($scope, $contact, 'c'))
            ->orderBy('o.placed_at')
            ->get([
                'o.id', 'o.placed_at', 'o.reference', 'o.total_minor', 'o.currency', 'o.payment_state',
                'o.attribution_evidence', 'o.utm_campaign', 'o.utm_source',
                'c.name as customer_name', 'c.email_key', 'c.phone_key',
            ]);

        $touchpoints = [];
        $evidence = IdentityEvidence::None;
        $name = null;

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;

            $touchpoints[] = new Touchpoint(
                kind: TouchpointKind::Order,
                id: (string) ($values['id'] ?? ''),
                occurredAt: $this->date($values['placed_at'] ?? null),
                summary: 'Order '.($this->string($values, 'reference') ?? (string) ($values['id'] ?? '')),
                attributionEvidence: AttributionEvidence::tryFrom((string) ($values['attribution_evidence'] ?? ''))
                    ?? AttributionEvidence::None,
                campaign: $this->string($values, 'utm_campaign'),
                source: $this->string($values, 'utm_source'),
                valueMinor: isset($values['total_minor']) && is_numeric($values['total_minor'])
                    ? (int) $values['total_minor']
                    : null,
                currency: $this->string($values, 'currency'),
                state: $this->string($values, 'payment_state'),
            );

            /*
             * An order reached through its store customer record is the platform's own link, which is stronger
             * than anything this product infers: the customer signed in, or the shop matched them itself.
             */
            $evidence = $this->strongestEvidence($evidence, IdentityEvidence::PlatformCustomer);
            $name ??= $this->string($values, 'customer_name');
        }

        return ['touchpoints' => $touchpoints, 'evidence' => $evidence, 'name' => $name];
    }

    /**
     * Match on normalised contact details only.
     *
     * Exact, and on nothing else. A fuzzy name match would merge two people called Mohammed Al-Harbi into one
     * record that nobody can disentangle afterwards; the cost of missing a link is only an unlinked journey.
     */
    private function matchContact(Builder $scope, ContactPoint $contact, string $prefix = ''): Builder
    {
        $column = static fn (string $name): string => $prefix === '' ? $name : $prefix.'.'.$name;

        if ($contact->phone !== null) {
            $scope->orWhere($column('phone_key'), $contact->phone);
        }

        if ($contact->email !== null) {
            $scope->orWhere($column('email_key'), $contact->email);
        }

        return $scope;
    }

    /**
     * Which contact detail actually matched this row.
     *
     * The phone outranks the e-mail, because in this market it is the identifier people give consistently.
     *
     * @param  array<string, mixed>  $values
     */
    private function evidenceFor(array $values, ContactPoint $contact): IdentityEvidence
    {
        if ($contact->phone !== null && ($values['phone_key'] ?? null) === $contact->phone) {
            return IdentityEvidence::ExactPhone;
        }

        if ($contact->email !== null && ($values['email_key'] ?? null) === $contact->email) {
            return IdentityEvidence::ExactEmail;
        }

        return IdentityEvidence::None;
    }

    private function strongestEvidence(IdentityEvidence $a, IdentityEvidence $b): IdentityEvidence
    {
        return $a->confidence() >= $b->confidence() ? $a : $b;
    }

    /** @param array<string, mixed> $values */
    private function leadSummary(array $values): string
    {
        $kind = $this->string($values, 'source_kind') ?? 'lead';
        $detail = $this->string($values, 'source_detail');

        return $detail === null ? $kind : $kind.' — '.$detail;
    }

    /** @param list<string>|null $visibleProjectIds */
    private function canSeeClient(string $workspaceId, ?array $visibleProjectIds, string $clientId): bool
    {
        if ($visibleProjectIds === null) {
            return true;
        }

        if ($visibleProjectIds === []) {
            return false;
        }

        return DB::table('projects')
            ->where('workspace_id', $workspaceId)
            ->where('client_id', $clientId)
            ->whereIn('id', $visibleProjectIds)
            ->exists();
    }

    private function date(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            try {
                return new DateTimeImmutable($value);
            } catch (Exception) {
                // Falls through to the epoch, which is obviously wrong rather than plausibly wrong.
            }
        }

        return new DateTimeImmutable('@0');
    }

    /** @param array<string, mixed> $values */
    private function string(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
