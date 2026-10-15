<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Integration;

use App\Application\Integration\Command\ConnectClientProvider;
use App\Application\Integration\Command\ManageConnection;
use App\Application\Integration\Command\RequestBackfill;
use App\Application\Integration\Command\TriageAccount;
use App\Application\Integration\DTO\AccountRow;
use App\Application\Integration\DTO\ConnectionView;
use App\Application\Integration\Query\ListAccountQueue;
use App\Application\Integration\Query\ListConnections;
use App\Domain\Integration\Provider;
use App\Domain\Integration\Sync\SyncClass;
use App\Interface\Http\Controller\Controller;
use App\Interface\Queue\SyncBoundAccountJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Connections as first-class things, and the review queue of the accounts they discovered.
 *
 * The provider-keyed routes answer "the agency's connection to Meta". These answer "this connection" —
 * which is what an operator needs the moment a workspace holds the agency's and three clients' own.
 */
final class ConnectionController extends Controller
{
    public function index(ListConnections $list): JsonResponse
    {
        return response()->json(['data' => array_map(
            static fn (ConnectionView $c): array => self::connection($c),
            $list(),
        )]);
    }

    public function store(Request $request, ConnectClientProvider $connect): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string'],
            'client_id' => ['required', 'uuid'],
            'name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'credentials' => ['required', 'array'],
            'credentials.*' => ['required', 'string', 'max:4096'],
        ]);

        $provider = Provider::tryFrom((string) $validated['provider']);

        if ($provider === null) {
            return response()->json([
                'message' => 'Unknown provider.',
                'code' => 'invalid_provider',
                'allowed' => array_map(static fn (Provider $p): string => $p->value, Provider::cases()),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $unknownKeys = array_diff(array_keys((array) $validated['credentials']), $provider->requiredCredentialKeys());

        if ($unknownKeys !== []) {
            return response()->json([
                'message' => sprintf('Expected: %s.', implode(', ', $provider->requiredCredentialKeys())),
                'errors' => ['credentials' => ['Unexpected credential key.']],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $connection = $connect(
            $provider,
            (string) $validated['client_id'],
            $this->strings((array) $validated['credentials']),
            isset($validated['name']) ? (string) $validated['name'] : null,
        );

        return response()->json(['data' => [
            'id' => $connection->id->value,
            'provider' => $connection->provider->value,
            'status' => $connection->status()->value,
            'owner' => 'client',
            // Key names only — values never leave the server.
            'credential_keys' => $connection->credentials()->describe(),
        ]], Response::HTTP_CREATED);
    }

    public function discover(string $connection, ManageConnection $manage): JsonResponse
    {
        $accounts = $manage->discover($connection);

        return response()->json(['data' => ['discovered' => count($accounts)]]);
    }

    public function reconnect(Request $request, string $connection, ManageConnection $manage): JsonResponse
    {
        $validated = $request->validate([
            'credentials' => ['required', 'array'],
            'credentials.*' => ['required', 'string', 'max:4096'],
        ]);

        $updated = $manage->reconnect($connection, $this->strings((array) $validated['credentials']));

        return response()->json(['data' => [
            'id' => $updated->id->value,
            'status' => $updated->status()->value,
            'credential_keys' => $updated->credentials()->describe(),
        ]]);
    }

    public function destroy(string $connection, ManageConnection $manage): JsonResponse
    {
        $manage->disconnect($connection);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function accounts(Request $request, ListAccountQueue $queue): JsonResponse
    {
        $states = array_values(array_filter((array) $request->query('state', []), 'is_string'));
        $allowed = ['unassigned', 'bound', 'shared', 'ignored'];

        if (array_diff($states, $allowed) !== []) {
            return response()->json([
                'message' => 'Unknown state.',
                'code' => 'invalid_state',
                'allowed' => $allowed,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $connection = $request->query('connection_id');
        $limit = $request->query('limit');
        $offset = $request->query('offset');

        $result = $queue(
            $states,
            is_string($connection) && $connection !== '' ? $connection : null,
            is_numeric($limit) ? (int) $limit : 50,
            is_numeric($offset) ? (int) $offset : 0,
        );

        return response()->json([
            'data' => array_map(static fn (AccountRow $a): array => [
                'id' => $a->id,
                'name' => $a->name,
                'provider' => $a->provider,
                'external_id' => $a->externalId,
                'connection' => ['id' => $a->connectionId, 'label' => $a->connectionLabel],
                'owner_client' => $a->ownerClientId === null
                    ? null
                    : ['id' => $a->ownerClientId, 'name' => $a->ownerClientName],
                'currency' => $a->currency,
                'status' => $a->status,
                'state' => $a->state,
                'projects' => $a->projects,
                'campaigns' => $a->campaigns,
            ], $result['accounts']),
            'meta' => ['counts' => $result['counts']],
        ]);
    }

    public function ignore(string $account, TriageAccount $triage): JsonResponse
    {
        $triage->ignore($account);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /** Read an account's history, at the lowest priority, once every six hours at most. */
    public function backfill(Request $request, string $account, RequestBackfill $backfill): JsonResponse
    {
        $validated = $request->validate(['days' => ['required', 'integer', 'in:'.implode(',', RequestBackfill::WINDOWS)]]);

        $window = $backfill($account, (int) $validated['days']);

        $key = 'backfill:'.$account;

        if (! RateLimiter::attempt($key, 1, static fn (): bool => true, 6 * 3600)) {
            return response()->json([
                'message' => 'This account was already asked for history in the last six hours. Wait for that read to finish.',
                'code' => 'backfill_recently_requested',
                'retry_after_seconds' => RateLimiter::availableIn($key),
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        SyncBoundAccountJob::dispatch(
            $window['workspace_id'],
            $window['account_id'],
            $window['since']->format('Y-m-d'),
            $window['until']->format('Y-m-d'),
            SyncClass::Backfill->queue(),
            $window['provider']->value,
        );

        return response()->json(['data' => [
            'account_id' => $account,
            'days' => $window['days'],
            'from' => $window['since']->format('Y-m-d'),
            'until' => $window['until']->format('Y-m-d'),
            'queue' => SyncClass::Backfill->queue(),
        ]], Response::HTTP_ACCEPTED);
    }

    public function restore(string $account, TriageAccount $triage): JsonResponse
    {
        $triage->restore($account);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /** @return array<string, mixed> */
    private static function connection(ConnectionView $c): array
    {
        return [
            'id' => $c->id,
            'provider' => $c->provider,
            'provider_label' => $c->providerLabel,
            'label' => $c->label,
            'owner' => $c->ownerType->value,
            'owner_client' => $c->ownerClientId === null ? null : ['id' => $c->ownerClientId, 'name' => $c->ownerClientName],
            'status' => $c->status->value,
            'state' => $c->state->value,
            'state_label' => $c->state->label(),
            'needs_action' => $c->state->needsAction(),
            'accounts' => [
                'discovered' => $c->accountsDiscovered,
                'bound' => $c->accountsBound,
                'unassigned' => $c->accountsUnassigned,
            ],
            'last_checked_at' => $c->lastCheckedAt?->format(DATE_ATOM),
            'expires_at' => $c->expiresAt?->format(DATE_ATOM),
            /* Advanced: present only for somebody who may act on it. */
            'advanced' => $c->technicalMessage === null ? null : ['message' => $c->technicalMessage],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array<string, string>
     */
    private function strings(array $raw): array
    {
        $out = [];

        foreach ($raw as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
