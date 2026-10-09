<?php

declare(strict_types=1);

namespace App\Interface\Http\Resource\Operations;

use App\Application\Operations\DTO\AttachmentView;
use App\Application\Operations\DTO\ClientRequestMessageView;
use App\Application\Operations\DTO\ClientRequestView;
use App\Application\Operations\DTO\IntakeQueueView;
use App\Application\Operations\DTO\TaskView;
use App\Domain\Operations\ClientRequestKind;
use App\Domain\Operations\ClientRequestState;
use App\Domain\Operations\IntakeLoad;
use App\Domain\Operations\MessageAudience;
use App\Domain\Operations\StatedUrgency;
use DateTimeImmutable;

final readonly class ClientRequestResource
{
    /** @return array<string, mixed> */
    public static function vocabulary(): array
    {
        return [
            'states' => array_map(static fn (ClientRequestState $state): array => [
                'state' => $state->value,
                'label' => $state->label(),
                'position' => $state->boardPosition(),
                'is_open' => $state->isOpen(),
                /*
                 * Both published. A queue that could only say "open" would let a conversation stalled on the
                 * client hide behind the same column as work the agency has not started.
                 */
                'is_on_the_agency' => $state->isOnTheAgency(),
                'is_on_the_client' => $state->isOnTheClient(),
                'needs_reason' => $state->needsReason(),
                'allowed_next' => array_map(
                    static fn (ClientRequestState $next): string => $next->value,
                    $state->allowedNext(),
                ),
            ], ClientRequestState::board()),
            'kinds' => array_map(static fn (ClientRequestKind $kind): array => [
                'kind' => $kind->value,
                'label' => $kind->label(),
                'produces_work' => $kind->producesWork(),
            ], ClientRequestKind::cases()),
            /*
             * Published as the client's own scale, with its own name. Nothing in this product turns it into a
             * priority — see `StatedUrgency` — and a front end that reused the task priority labels here would be
             * presenting a client's claim as the agency's judgement.
             */
            'urgencies' => array_map(static fn (StatedUrgency $urgency): array => [
                'urgency' => $urgency->value,
                'label' => $urgency->label(),
                'rank' => $urgency->rank(),
            ], StatedUrgency::ascending()),
            'audiences' => array_map(static fn (MessageAudience $audience): array => [
                'audience' => $audience->value,
                'label' => $audience->label(),
                'is_visible_to_client' => $audience->isVisibleToClient(),
            ], MessageAudience::cases()),
            'patience_days' => IntakeLoad::PATIENCE_DAYS,
        ];
    }

    /** @return array<string, mixed> */
    public static function queue(IntakeQueueView $queue): array
    {
        $load = $queue->load;

        return [
            'columns' => array_map(static fn (ClientRequestState $state): array => [
                'state' => $state->value,
                'label' => $state->label(),
                'total' => $queue->inState($state),
                'requests' => array_map(
                    static fn (ClientRequestView $view): array => self::card($view, $queue->now),
                    $queue->column($state),
                ),
            ], ClientRequestState::board()),
            'load' => [
                'total' => $load->total,
                'open' => $load->open,
                /* The pair that stops a stalled conversation hiding behind a reassuring column. */
                'on_the_agency' => $load->onTheAgency,
                'on_the_client' => $load->onTheClient,
                'delivered' => $load->delivered,
                'declined' => $load->declined,
                'withdrawn' => $load->withdrawn(),
                'promised' => $load->promised,
                /* Accepted with no agreed date. "We are on it" is the whole of what those clients were told. */
                'unpromised' => $load->unpromised,
                'overdue' => $load->overdue,
                /* What clients called urgent. Their word, never this product's priority. */
                'called_urgent' => $load->calledUrgent,
                /* Null, not zero, when nothing is unanswered. */
                'oldest_unanswered_days' => $load->oldestUnansweredDays,
                'by_state' => $load->byState,
                'by_kind' => $load->byKind,
                'by_urgency' => $load->byUrgency,
                'delivery_rate' => $load->deliveryRate(),
                'promised_share' => $load->promisedShare(),
                'needs_attention' => $load->needsAttention(),
                'patience_days' => $load::PATIENCE_DAYS,
            ],
            /*
             * Which side is reading. A page that did not know it was showing the client's view would offer an
             * "internal note" control that silently produced a client-visible message.
             */
            'is_agency_view' => $queue->isAgencyView,
            'can_triage' => $queue->canTriage,
            'as_of' => $queue->now->format(DateTimeImmutable::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public static function request(ClientRequestView $view, DateTimeImmutable $now): array
    {
        return self::card($view, $now) + [
            'detail' => $view->detail,
            'declined_reason' => $view->declinedReason,
            'needed_information' => $view->neededInformation,
            'submitted_by_name' => $view->submittedByName,
            /*
             * The id as well as the name, because a picker has to know which option is selected. Added when the
             * queue finally got a control for the owner: the name alone made "who has this" readable and
             * "give it to somebody else" unbuildable.
             */
            'owner_id' => $view->ownerId,
            'owner_name' => $view->ownerName,
            'first_answered_at' => $view->firstAnsweredAt?->format(DateTimeImmutable::ATOM),
            'delivered_at' => $view->deliveredAt?->format(DateTimeImmutable::ATOM),
            'state_changed_at' => $view->stateChangedAt?->format(DateTimeImmutable::ATOM),
            /* Null while undelivered. Measured from arrival, which is when the client started waiting. */
            'turnaround_days' => $view->turnaroundDays(),
            'messages' => array_map(static fn (ClientRequestMessageView $message): array => [
                'id' => $message->id,
                'audience' => $message->audience->value,
                'is_visible_to_client' => $message->audience->isVisibleToClient(),
                'body' => $message->body,
                'author_name' => $message->authorName,
                'is_from_client' => $message->isFromClient,
                'created_at' => $message->createdAt->format(DateTimeImmutable::ATOM),
            ], $view->messages),
            /*
             * Files on the thread, already narrowed by the same audience rule the messages are — in SQL, so an
             * internal attachment is not selected for a client at all.
             *
             * `stored_name` and the checksum are deliberately **not** published. A generated name is not a secret,
             * but publishing one turns it into a name somebody can guess at, and a checksum is for an audit of
             * what was actually sent rather than for a page. The id is what the download endpoint addresses.
             */
            'attachments' => array_map(static fn (AttachmentView $file): array => [
                'id' => $file->id,
                'audience' => $file->audience->value,
                'is_visible_to_client' => $file->audience->isVisibleToClient(),
                'name' => $file->originalName,
                'type' => $file->type->value,
                'type_label' => $file->type->label(),
                'is_image' => $file->type->isImage(),
                'byte_size' => $file->byteSize,
                'uploaded_by_name' => $file->uploadedByName,
                'is_from_client' => $file->isFromClient,
                'uploaded_at' => $file->uploadedAt->format(DateTimeImmutable::ATOM),
            ], $view->attachments),
            /*
             * Empty on the client's side. The tasks raised from a request are the agency's own plan, with its
             * assignees and its estimates, and showing them to a client is the same leak an internal note is.
             */
            'tasks' => array_map(static fn (TaskView $task): array => [
                'id' => $task->id,
                'title' => $task->title,
                'state' => $task->state->value,
                'state_label' => $task->state->label(),
                'assignee_name' => $task->assigneeName,
            ], $view->tasks),
        ];
    }

    /** @return array<string, mixed> */
    private static function card(ClientRequestView $view, DateTimeImmutable $now): array
    {
        return [
            'id' => $view->id,
            'client_id' => $view->clientId,
            'client_name' => $view->clientName,
            'kind' => $view->kind->value,
            'kind_label' => $view->kind->label(),
            'title' => $view->title,
            'state' => $view->state->value,
            'state_label' => $view->state->label(),
            'is_on_the_client' => $view->state->isOnTheClient(),
            /* The client's word, labelled as such everywhere it appears. */
            'stated_urgency' => $view->urgency->value,
            'stated_urgency_label' => $view->urgency->label(),
            'project_id' => $view->projectId,
            'project_name' => $view->projectName,
            /* What the client asked for. A wish. */
            'wanted_by' => $view->wantedBy?->format('Y-m-d'),
            /* What the agency agreed, with the person who agreed it. Null means nothing was promised. */
            'promised_at' => $view->promisedAt?->format('Y-m-d'),
            'promised_by_name' => $view->promisedByName,
            'is_overdue' => $view->isOverdue($now),
            'is_unanswered' => $view->isUnanswered(),
            /* Still running while unanswered, which is what makes the queue's oldest figure honest. */
            'waiting_for_answer_days' => $view->waitingForAnswerDays($now),
            'created_at' => $view->createdAt->format(DateTimeImmutable::ATOM),
        ];
    }
}
