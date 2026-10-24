<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Platform;

use App\Application\Platform\Command\ChangeSubscriptionStatus;
use App\Application\Platform\Command\CreateWorkspace;
use App\Application\Platform\Contract\PlatformReadModel;
use App\Application\Platform\DTO\NewWorkspace;
use App\Application\Platform\DTO\PlatformWorkspaceRow;
use App\Application\Platform\PlatformAccess;
use App\Application\Platform\Query\GetPlatformWorkspace;
use App\Application\Platform\Query\ListPlatformWorkspaces;
use App\Application\Saas\Command\ChangeWorkspacePlan;
use App\Application\Saas\Command\OverrideEntitlement;
use App\Application\Saas\DTO\OverrideRecord;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Identity\UserId;
use App\Domain\Saas\SubscriptionStatus;
use App\Domain\Workspace\WorkspaceType;
use App\Interface\Http\Controller\Controller;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The platform console's API. Every action authorises the caller as a platform operator in the application layer and names
 * them, by address, in the workspace's own audit trail. Nothing here reads a client's data.
 */
final class PlatformController extends Controller
{
    public function index(Request $request, ListPlatformWorkspaces $list): JsonResponse
    {
        $result = $list(
            $this->caller($request),
            $request->string('search')->toString() ?: null,
            $request->string('status')->toString() ?: null,
            $request->integer('page', 1),
        );

        return response()->json([
            'data' => array_map($this->row(...), $result['rows']),
            'meta' => ['total' => $result['total']],
        ]);
    }

    public function show(Request $request, string $workspace, GetPlatformWorkspace $get): JsonResponse
    {
        $view = $get($this->caller($request), $workspace);
        $plan = $view['plan'];

        return response()->json(['data' => [
            ...$this->row($view['row']),
            'plan_detail' => [
                'name' => $plan->planName,
                'features' => $plan->features,
                'limits' => array_map(static fn (array $l): array => [...$l, 'resets_at' => $l['resets_at']?->format(DateTimeInterface::ATOM)], $plan->limits),
                'overrides' => array_map(static fn (OverrideRecord $o): array => [
                    'kind' => $o->kind, 'key' => $o->key, 'value' => $o->value, 'previous_value' => $o->previousValue,
                    'reason' => $o->reason, 'changed_by' => $o->changedBy, 'at' => $o->at->format(DateTimeInterface::ATOM),
                ], $plan->overrides),
            ],
            'history' => $view['history'],
        ]]);
    }

    public function store(Request $request, CreateWorkspace $create, PlatformAccess $access): JsonResponse
    {
        $caller = $this->caller($request);
        $access->ensure($caller);

        $input = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'type' => ['required', Rule::in(array_map(static fn (WorkspaceType $t): string => $t->value, WorkspaceType::cases()))],
            'locale' => ['required', Rule::in(['en', 'ar'])],
            'timezone' => ['required', 'timezone:all'],
            'currency' => ['required', 'string', 'size:3'],
            'plan' => ['required', 'string', 'max:40'],
            'status' => ['required', Rule::in([SubscriptionStatus::Trial->value, SubscriptionStatus::Active->value])],
            'trial_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'email:rfc', 'max:255'],
        ]);

        $status = SubscriptionStatus::from((string) $input['status']);
        $trialDays = isset($input['trial_days']) ? (int) $input['trial_days'] : 14;
        $created = $create(new NewWorkspace(
            name: (string) $input['name'],
            slug: isset($input['slug']) && $input['slug'] !== '' ? (string) $input['slug'] : Str::slug((string) $input['name']).'-'.Str::lower(Str::random(4)),
            type: WorkspaceType::from((string) $input['type']),
            locale: (string) $input['locale'],
            timezone: (string) $input['timezone'],
            currency: strtoupper((string) $input['currency']),
            planCode: (string) $input['plan'],
            status: $status,
            trialEndsAt: $status === SubscriptionStatus::Trial ? new DateTimeImmutable("+{$trialDays} days") : null,
            ownerName: (string) $input['owner_name'],
            ownerEmail: (string) $input['owner_email'],
        ), $this->operator($request));

        return response()->json(['data' => [
            'workspace_id' => $created->workspaceId,
            'owner_email' => $created->ownerEmail,
            /* Shown once. Null when the owner already had an account and keeps their own password. */
            'one_time_password' => $created->oneTimePassword,
        ]], 201);
    }

    public function plan(Request $request, string $workspace, ChangeWorkspacePlan $change, PlatformAccess $access, PlatformReadModel $platform): JsonResponse
    {
        $this->target($request, $workspace, $access, $platform);
        $input = $request->validate(['plan' => ['required', 'string', 'max:40'], 'reason' => ['required', 'string', 'max:500']]);
        $change($workspace, (string) $input['plan'], $this->operator($request), (string) $input['reason']);

        return response()->json(null, 204);
    }

    public function override(Request $request, string $workspace, OverrideEntitlement $override, PlatformAccess $access, PlatformReadModel $platform): JsonResponse
    {
        $this->target($request, $workspace, $access, $platform);
        $input = $request->validate([
            'kind' => ['required', Rule::in(['feature', 'limit'])],
            'key' => ['required', 'string', 'max:60'],
            'value' => ['required', 'string', 'max:20'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $previous = $override($workspace, (string) $input['kind'], (string) $input['key'], (string) $input['value'], $this->operator($request), (string) $input['reason']);

        return response()->json(['data' => ['previous_value' => $previous]]);
    }

    public function status(Request $request, string $workspace, ChangeSubscriptionStatus $change, PlatformAccess $access, PlatformReadModel $platform): JsonResponse
    {
        $this->target($request, $workspace, $access, $platform);
        $input = $request->validate([
            'status' => ['required', Rule::in(SubscriptionStatus::values())],
            'reason' => ['required', 'string', 'max:500'],
            'trial_ends_at' => ['nullable', 'date', 'after:now'],
            'grace_ends_at' => ['nullable', 'date', 'after:now'],
        ]);
        $change(
            $workspace,
            SubscriptionStatus::from((string) $input['status']),
            isset($input['trial_ends_at']) ? new DateTimeImmutable((string) $input['trial_ends_at']) : null,
            isset($input['grace_ends_at']) ? new DateTimeImmutable((string) $input['grace_ends_at']) : null,
            (string) $input['reason'],
            $this->operator($request),
        );

        return response()->json(null, 204);
    }

    private function target(Request $request, string $workspace, PlatformAccess $access, PlatformReadModel $platform): void
    {
        $access->ensure($this->caller($request));
        $platform->find($workspace) ?? throw ResourceNotFound::of('Workspace');
    }

    private function caller(Request $request): UserId
    {
        return new UserId((string) $request->user()?->getAuthIdentifier());
    }

    private function operator(Request $request): string
    {
        $email = $request->user()?->getAttribute('email');

        return is_string($email) ? $email : 'unknown operator';
    }

    /** @return array<string, mixed> */
    private function row(PlatformWorkspaceRow $row): array
    {
        return [
            'id' => $row->id,
            'name' => $row->name,
            'slug' => $row->slug,
            'type' => $row->type,
            'created_at' => $row->createdAt->format(DateTimeInterface::ATOM),
            'plan' => $row->planCode,
            'status' => $row->status,
            'trial_ends_at' => $row->trialEndsAt?->format(DateTimeInterface::ATOM),
            'grace_ends_at' => $row->graceEndsAt?->format(DateTimeInterface::ATOM),
            'status_reason' => $row->statusReason,
            'owners' => $row->owners,
            'seats' => ['internal' => $row->internalSeats, 'client' => $row->clientSeats],
            'clients' => $row->clients,
            'projects' => $row->projects,
            'connections' => (object) $row->connections,
            'overrides' => $row->overrides,
            'provisioning' => $row->missing === [] ? 'complete' : 'incomplete',
            'missing' => $row->missing,
        ];
    }
}
