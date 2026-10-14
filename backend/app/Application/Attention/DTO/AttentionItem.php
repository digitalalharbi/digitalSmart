<?php

declare(strict_types=1);

namespace App\Application\Attention\DTO;

use App\Domain\Attention\AttentionSeverity;
use App\Domain\Attention\AttentionStream;
use DateTimeImmutable;

/**
 * One thing waiting on a person.
 *
 * **The message is a sentence, not a diagnostic.** "Ramadan Push has spent for four weeks with nothing
 * recorded against it" belongs here; "GTM-XXXX returned 404 on /checkout" belongs in the detail view the
 * action opens. A card that reads like a log line gets skipped by the person who most needs to act on it.
 *
 * **One action.** This list exists to remove decisions, and a card with three buttons is a decision.
 */
final readonly class AttentionItem
{
    public function __construct(
        /** Stable across reads, so an interface can key a list and a reader can dismiss one thing twice. */
        public string $id,
        public AttentionStream $stream,
        public AttentionSeverity $severity,
        public string $message,
        /** What it is about, named the way the product names it elsewhere. */
        public string $entityKind,
        public string $entityLabel,
        /**
         * Who holds it.
         *
         * Three states and they are three different facts: a name; `null` for "nobody holds this", which
         * is printed because an unowned item is the one most likely to rot; and `false` for a kind that
         * has no owner at all — a budget belongs to a client, not to a person, and printing *Unassigned*
         * against one would invent a gap.
         */
        public string|null|false $owner,
        /** When it started being true. Null where the source cannot say. */
        public ?DateTimeImmutable $since,
        public ?string $clientId,
        public ?string $clientName,
        public ?string $projectId,
        public ?string $projectName,
    ) {}
}
