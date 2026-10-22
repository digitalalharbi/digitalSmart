<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Access;

use App\Application\Access\Command\ReviewAccessRequest;
use App\Application\Access\Command\SubmitAccessRequest;
use App\Application\Access\Contract\AccessRequestRepository;
use App\Application\Access\DTO\AccessRequestView;
use App\Application\Access\Exception\AccessRequestRefused;
use App\Application\Access\Query\ListAccessRequests;
use App\Domain\Access\AccessRequestNeed;
use App\Domain\Access\AccessRequestStatus;
use App\Interface\Http\Controller\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asking for an account, and answering the ask.
 *
 * The first two actions are public and say as little as they can. `store` answers 202 whether the request was kept,
 * was a repeat or was dropped because this installation takes none; `open` says only whether the form should be offered.
 */
final class AccessRequestController extends Controller
{
    public function open(AccessRequestRepository $requests): JsonResponse
    {
        return response()->json(['data' => ['open' => $requests->intakeWorkspaceId() !== null]]);
    }

    public function store(Request $request, SubmitAccessRequest $submit): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
            'company' => ['nullable', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'need' => ['required', 'string', 'in:'.implode(',', AccessRequestNeed::values())],
            'message' => ['nullable', 'string', 'max:1000'],
            'locale' => ['nullable', 'string', 'in:ar,en'],
            /* A field no person sees. Anything in it is a script filling every box it finds. */
            'website' => ['nullable', 'string', 'max:200'],
        ]);

        if (($data['website'] ?? '') === '') {
            $submit(
                (string) $data['name'],
                (string) $data['email'],
                isset($data['company']) ? (string) $data['company'] : null,
                isset($data['phone']) ? (string) $data['phone'] : null,
                AccessRequestNeed::from((string) $data['need']),
                isset($data['message']) ? (string) $data['message'] : null,
                (string) ($data['locale'] ?? 'ar'),
            );
        }

        return response()->json(['data' => ['received' => true]], Response::HTTP_ACCEPTED);
    }

    public function index(Request $request, ListAccessRequests $list): JsonResponse
    {
        $status = AccessRequestStatus::tryFrom((string) $request->query('status', ''));
        $result = $list($status);

        return response()->json([
            'data' => array_map(self::row(...), $result['rows']),
            'meta' => ['counts' => $result['counts']],
        ]);
    }

    public function review(string $accessRequest, Request $request, ReviewAccessRequest $review): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:contacted,approved,declined'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $view = $review(
                $accessRequest,
                AccessRequestStatus::from((string) $data['status']),
                isset($data['note']) ? (string) $data['note'] : null,
            );
        } catch (AccessRequestRefused $refusal) {
            return response()->json(
                ['message' => $refusal->getMessage(), 'code' => $refusal->reasonCode],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return response()->json(['data' => self::row($view)]);
    }

    /** @return array<string, mixed> */
    private static function row(AccessRequestView $view): array
    {
        return [
            'id' => $view->id,
            'name' => $view->name,
            'email' => $view->email,
            'company' => $view->company,
            'phone' => $view->phone,
            'need' => $view->need->value,
            'need_label' => $view->need->label(),
            'message' => $view->message,
            'locale' => $view->locale,
            'status' => $view->status->value,
            'created_at' => $view->createdAt->format(DATE_ATOM),
            'reviewed_at' => $view->reviewedAt?->format(DATE_ATOM),
            'reviewed_by_name' => $view->reviewedByName,
            'review_note' => $view->reviewNote,
        ];
    }
}
