<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Access;

use App\Application\Access\Command\DecideAccessRequest;
use App\Application\Access\Command\SaveAccessSettings;
use App\Application\Access\Command\SubmitAccessRequest;
use App\Application\Access\Command\WorkAccessRequest;
use App\Application\Access\Contract\AccessRequestRepository;
use App\Application\Access\DTO\AccessEventView;
use App\Application\Access\DTO\AccessRequestView;
use App\Application\Access\DTO\AccessSettings;
use App\Application\Access\DTO\InvitationView;
use App\Application\Access\Exception\AccessRequestRefused;
use App\Application\Access\Query\ReadAccessRequests;
use App\Domain\Access\AccessEventType;
use App\Domain\Access\AccessRequestKind;
use App\Domain\Access\AccessRequestNeed;
use App\Domain\Access\AccessRequestStatus;
use App\Domain\Access\PreferredContact;
use App\Interface\Http\Controller\Controller;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asking for an account or sending an enquiry, and answering it.
 *
 * The first two actions are public and say as little as they can. `store` answers 202 whether the request was kept, was a
 * repeat or was dropped because this installation takes none; `open` says only whether the form should be offered.
 */
final class AccessRequestController extends Controller
{
    /** The largest body the public form is read from. The form has a thousand characters of message and little else. */
    private const int MAX_BODY_BYTES = 16384;

    public function open(AccessRequestRepository $requests): JsonResponse
    {
        return response()->json(['data' => ['open' => $requests->intakeWorkspaceId() !== null]]);
    }

    public function store(Request $request, SubmitAccessRequest $submit): JsonResponse
    {
        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            return response()->json(['message' => 'Too large.'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $data = $request->validate([
            'kind' => ['nullable', 'string', 'in:'.implode(',', AccessRequestKind::values())],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
            'company' => ['nullable', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'preferred_contact' => ['nullable', 'string', 'in:'.implode(',', PreferredContact::values())],
            'need' => ['nullable', 'string', 'in:'.implode(',', AccessRequestNeed::values())],
            'message' => ['nullable', 'string', 'max:1000'],
            'locale' => ['nullable', 'string', 'in:ar,en'],
            /* A field no person sees. Anything in it is a script filling every box it finds. */
            'website' => ['nullable', 'string', 'max:200'],
        ]);

        if (($data['website'] ?? '') === '') {
            $submit(
                AccessRequestKind::tryFrom((string) ($data['kind'] ?? '')) ?? AccessRequestKind::AccountAccess,
                (string) $data['name'],
                (string) $data['email'],
                isset($data['company']) ? (string) $data['company'] : null,
                isset($data['phone']) ? (string) $data['phone'] : null,
                isset($data['job_title']) ? (string) $data['job_title'] : null,
                PreferredContact::tryFrom((string) ($data['preferred_contact'] ?? '')) ?? PreferredContact::Email,
                AccessRequestNeed::tryFrom((string) ($data['need'] ?? '')),
                isset($data['message']) ? (string) $data['message'] : null,
                (string) ($data['locale'] ?? 'ar'),
            );
        }

        return response()->json(['data' => ['received' => true]], Response::HTTP_ACCEPTED);
    }

    public function index(Request $request, ReadAccessRequests $read): JsonResponse
    {
        $statuses = array_values(array_filter(array_map(
            static fn (string $s): ?AccessRequestStatus => AccessRequestStatus::tryFrom($s),
            array_filter(explode(',', (string) $request->query('status', '')), static fn (string $s): bool => $s !== ''),
        )));
        $owner = (string) $request->query('owner', '');
        $result = $read->inbox($statuses === [] ? null : $statuses, $owner === '' ? null : $owner);

        return response()->json([
            'data' => array_map(static fn (array $r): array => self::row($r['view'], $r['sla']), $result['rows']),
            'meta' => [
                'counts' => $result['counts'],
                'settings' => self::settings($result['settings']),
                'owners' => $result['owners'],
                'delivery' => self::delivery(),
            ],
        ]);
    }

    public function show(string $accessRequest, ReadAccessRequests $read): JsonResponse
    {
        $one = $read->one($accessRequest);

        return response()->json([
            'data' => [
                ...self::row($one['view'], $one['sla']),
                'events' => array_map(self::event(...), $one['events']),
                'linked' => array_map(static fn (AccessRequestView $v): array => [
                    'id' => $v->id, 'name' => $v->name, 'kind' => $v->kind->value, 'status' => $v->status->value,
                    'created_at' => $v->createdAt->format(DATE_ATOM),
                ], $one['linked']),
                'invitation' => $one['invitation'] === null ? null : self::invitation($one['invitation']),
            ],
            'meta' => ['delivery' => self::delivery()],
        ]);
    }

    public function figures(Request $request, ReadAccessRequests $read): JsonResponse
    {
        return response()->json(['data' => $read->figures((int) $request->query('days', 30))]);
    }

    public function assign(string $accessRequest, Request $request, WorkAccessRequest $work): JsonResponse
    {
        $data = $request->validate(['owner_id' => ['nullable', 'uuid']]);

        return $this->respond(fn () => $work->assign($accessRequest, isset($data['owner_id']) ? (string) $data['owner_id'] : null));
    }

    public function contact(string $accessRequest, Request $request, WorkAccessRequest $work): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'in:'.implode(',', array_map(static fn (AccessEventType $t): string => $t->value, AccessEventType::contactActions()))],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->respond(fn () => $work->contact($accessRequest, AccessEventType::from((string) $data['action']), isset($data['note']) ? (string) $data['note'] : null));
    }

    public function note(string $accessRequest, Request $request, WorkAccessRequest $work): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'min:1', 'max:2000']]);

        return $this->respond(fn () => $work->note($accessRequest, (string) $data['body']));
    }

    public function followUp(string $accessRequest, Request $request, WorkAccessRequest $work): JsonResponse
    {
        $data = $request->validate(['at' => ['nullable', 'date']]);

        return $this->respond(fn () => $work->followUp($accessRequest, isset($data['at']) ? new DateTimeImmutable((string) $data['at']) : null));
    }

    public function status(string $accessRequest, Request $request, WorkAccessRequest $work): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'string', 'in:contacted,waiting_for_reply,qualified,closed']]);

        return $this->respond(fn () => $work->move($accessRequest, AccessRequestStatus::from((string) $data['status'])));
    }

    public function approve(string $accessRequest, Request $request, DecideAccessRequest $decide): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        return $this->respond(fn () => $decide->approve($accessRequest, isset($data['note']) ? (string) $data['note'] : null));
    }

    public function reject(string $accessRequest, Request $request, DecideAccessRequest $decide): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:1', 'max:1000'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->respond(fn () => $decide->reject($accessRequest, (string) $data['reason'], isset($data['message']) ? (string) $data['message'] : null));
    }

    public function saveSettings(Request $request, SaveAccessSettings $save): JsonResponse
    {
        $data = $request->validate([
            'response_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
            'default_owner_id' => ['nullable', 'uuid'],
        ]);

        try {
            $settings = $save((int) $data['response_minutes'], isset($data['default_owner_id']) ? (string) $data['default_owner_id'] : null);
        } catch (AccessRequestRefused $refusal) {
            return self::refused($refusal);
        }

        return response()->json(['data' => self::settings($settings)]);
    }

    public static function refused(AccessRequestRefused $refusal): JsonResponse
    {
        return response()->json(['message' => $refusal->getMessage(), 'code' => $refusal->reasonCode], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param  array{state: string, due_at: DateTimeImmutable, met: bool|null}|null  $sla
     * @return array<string, mixed>
     */
    public static function row(AccessRequestView $view, ?array $sla): array
    {
        return [
            'id' => $view->id,
            'kind' => $view->kind->value,
            'name' => $view->name,
            'email' => $view->email,
            'company' => $view->company,
            'phone' => $view->phone,
            'job_title' => $view->jobTitle,
            'preferred_contact' => $view->preferredContact->value,
            'need' => $view->need?->value,
            'message' => $view->message,
            'locale' => $view->locale,
            'status' => $view->status->value,
            'created_at' => $view->createdAt->format(DATE_ATOM),
            'owner_id' => $view->ownerId,
            'owner_name' => $view->ownerName,
            'first_attempt_at' => $view->firstAttemptAt?->format(DATE_ATOM),
            'first_contacted_at' => $view->firstContactedAt?->format(DATE_ATOM),
            'last_contact_at' => $view->lastContactAt?->format(DATE_ATOM),
            'next_follow_up_at' => $view->nextFollowUpAt?->format(DATE_ATOM),
            'possible_duplicate' => $view->possibleDuplicateOf !== null,
            'possible_duplicate_of' => $view->possibleDuplicateOf,
            'reviewed_at' => $view->reviewedAt?->format(DATE_ATOM),
            'reviewed_by_name' => $view->reviewedByName,
            'review_note' => $view->reviewNote,
            'client_message' => $view->clientMessage,
            'invited_at' => $view->invitedAt?->format(DATE_ATOM),
            'invitation_shared_at' => $view->invitationSharedAt?->format(DATE_ATOM),
            'invitation_opened_at' => $view->invitationOpenedAt?->format(DATE_ATOM),
            'accepted_at' => $view->acceptedAt?->format(DATE_ATOM),
            'account_created' => $view->accountUserId !== null,
            'first_login_at' => $view->firstLoginAt?->format(DATE_ATOM),
            'sla' => $sla === null ? null : [
                'state' => $sla['state'],
                'due_at' => $sla['due_at']->format(DATE_ATOM),
                'met' => $sla['met'],
            ],
        ];
    }

    /** @param callable(): AccessRequestView $action */
    private function respond(callable $action): JsonResponse
    {
        try {
            $view = $action();
        } catch (AccessRequestRefused $refusal) {
            return self::refused($refusal);
        }

        return response()->json(['data' => self::row($view, null)]);
    }

    /**
     * What delivery exists for the contact actions. Nothing in this repository can send a message to a stranger, so both
     * channels say so; a person records what they did by hand, and the record says "marked", never "sent".
     *
     * @return array<string, array{state: string}>
     */
    private static function delivery(): array
    {
        return ['email' => ['state' => 'awaiting_credentials'], 'whatsapp' => ['state' => 'awaiting_credentials']];
    }

    /** @return array<string, mixed> */
    private static function settings(AccessSettings $settings): array
    {
        return [
            'response_minutes' => $settings->responseMinutes,
            'default_owner_id' => $settings->defaultOwnerId,
            'default_owner_name' => $settings->defaultOwnerName,
        ];
    }

    /** @return array<string, mixed> */
    private static function event(AccessEventView $event): array
    {
        return [
            'id' => $event->id,
            'type' => $event->type->value,
            'actor_name' => $event->actorName,
            'body' => $event->body,
            'meta' => $event->meta,
            'occurred_at' => $event->occurredAt->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private static function invitation(InvitationView $invitation): array
    {
        return [
            'hint' => $invitation->tokenHint,
            'role' => $invitation->roleSlug,
            'project_ids' => $invitation->projectIds,
            'expires_at' => $invitation->expiresAt->format(DATE_ATOM),
            'accepted_at' => $invitation->acceptedAt?->format(DATE_ATOM),
            'revoked_at' => $invitation->revokedAt?->format(DATE_ATOM),
            'created_at' => $invitation->createdAt->format(DATE_ATOM),
            /* Honest about delivery: nothing here can send the link, so it is shared by hand. */
            'delivery' => ['email' => 'awaiting_credentials'],
        ];
    }
}
