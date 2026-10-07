<?php

declare(strict_types=1);

namespace App\Domain\Operations\Exception;

use App\Domain\Operations\ClientRequestState;
use RuntimeException;

/** A move the intake queue does not allow. */
final class InvalidRequestTransition extends RuntimeException
{
    /** @param list<string> $allowed */
    private function __construct(
        string $message,
        public readonly string $reasonCode,
        public readonly ClientRequestState $from,
        public readonly array $allowed,
    ) {
        parent::__construct($message);
    }

    public static function between(ClientRequestState $from, ClientRequestState $to): self
    {
        return new self(
            sprintf('A request cannot move from %s to %s.', $from->label(), $to->label()),
            'invalid_transition',
            $from,
            array_map(
                static fn (ClientRequestState $state): string => $state->value,
                $from->allowedNext(),
            ),
        );
    }

    /**
     * Declining, or asking for information, without saying what.
     *
     * Refused. A declined request the client was never told the reason for is the commonest failure of an
     * agency's intake, and "we need something from you" with no statement of what is a message nobody can
     * answer — and the state most likely to sit for weeks.
     */
    public static function reasonRequired(ClientRequestState $from, ClientRequestState $to): self
    {
        return new self(
            $to === ClientRequestState::Declined
                ? 'Say why it is being declined. The client is shown this, and a request that vanished with no '
                    .'reason is the worst thing an intake queue can do.'
                : 'Say what is needed from the client. "We need something from you" is a message nobody can act '
                    .'on, and it is the state most likely to sit for weeks.',
            'reason_required',
            $from,
            [$to->value],
        );
    }

    /**
     * Raising work against a request that is already settled.
     *
     * Refused. Work to answer something already declined or delivered is either a mistake or a sign the request
     * should be reopened — and reopening is a decision with its own record, which is the point of refusing here.
     */
    public static function settledNeedsNoWork(ClientRequestState $from): self
    {
        return new self(
            sprintf(
                'This request is %s, so there is nothing to raise work for. Reopen it first, so the record says '
                .'somebody decided to.',
                mb_strtolower($from->label()),
            ),
            'settled_needs_no_work',
            $from,
            array_map(
                static fn (ClientRequestState $state): string => $state->value,
                $from->allowedNext(),
            ),
        );
    }

    /**
     * Raising work for a question.
     *
     * A question needs an answer, not a card. A queue that spawned a task for every question fills a board with
     * work whose completion is a sentence in a reply.
     */
    public static function questionsNeedAnAnswer(ClientRequestState $from): self
    {
        return new self(
            'A question needs an answer rather than a task. Reply on the request instead.',
            'questions_need_an_answer',
            $from,
            [],
        );
    }

    /**
     * A promise with nobody's name on it.
     *
     * Refused by the use case and by a CHECK. A date an agency committed to is a date somebody committed to, and
     * one that appeared without a person behind it is the date a client quotes back in a meeting nobody can
     * account for.
     */
    public static function promiseNeedsAPerson(ClientRequestState $from): self
    {
        return new self(
            'A promised date needs the person who promised it. A date with nobody behind it is one the client '
            .'will quote and nobody will own.',
            'promise_needs_a_person',
            $from,
            [],
        );
    }
}
