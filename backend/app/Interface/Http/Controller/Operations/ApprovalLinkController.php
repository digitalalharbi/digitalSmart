<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Operations;

use App\Application\Operations\Command\ApprovalLinkRefused;
use App\Application\Operations\Command\IssueApprovalLink;
use App\Application\Operations\Command\UseApprovalLink;
use App\Domain\Operations\ApprovalState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Issuing a one-time link (signed in, for oneself) and using one (no session — the link is the credential).
 *
 * Using a link is a POST only. A GET never decides anything: chat apps and mail scanners open links to preview them,
 * and a preview must not approve somebody's spend.
 */
final class ApprovalLinkController
{
    public function issue(Request $request, string $approval, IssueApprovalLink $issue): JsonResponse
    {
        /** @var array{channel: 'whatsapp'|'email', minutes?: int} $input */
        $input = $request->validate([
            'channel' => ['required', 'in:whatsapp,email'],
            'minutes' => ['sometimes', 'integer', 'min:5', 'max:'.IssueApprovalLink::MAX_MINUTES],
        ]);

        return new JsonResponse(['data' => $issue($approval, $input['channel'], $input['minutes'] ?? IssueApprovalLink::DEFAULT_MINUTES)], 201);
    }

    public function use(Request $request, string $token, UseApprovalLink $use): JsonResponse
    {
        /** @var array{decision: 'approve'|'reject'} $input */
        $input = $request->validate(['decision' => ['required', 'in:approve,reject']]);

        try {
            $decided = $use($token, $input['decision'] === 'approve' ? ApprovalState::Approved : ApprovalState::Declined);
        } catch (ApprovalLinkRefused $refused) {
            return new JsonResponse(['message' => $refused->getMessage()], 410);
        }

        /* Only the outcome: the link page shows no figures a forwarded link could leak. */
        return new JsonResponse(['data' => ['state' => $decided->state->value, 'title' => $decided->title]]);
    }
}
