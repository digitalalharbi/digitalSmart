<?php

declare(strict_types=1);

namespace App\Application\Notifications\DTO;

use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\NotificationSeverity;

/**
 * What a use case asks to have announced.
 *
 * It names the **audience by rule**, not by a list of people: `aboutProjectId` and `aboutClientId` are what the
 * resolver narrows visibility by, and the kind names the permission a recipient must hold. A caller that handed
 * over a list of user ids would be deciding who may be told, and the decision would then be made in four
 * places — one of which would eventually forget that a notification is a disclosure.
 */
final readonly class NotificationDraft
{
    public function __construct(
        public NotificationKind $kind,
        /** The sentence, as it will be stored. Written once and never re-rendered. */
        public string $title,
        public string $subjectId,
        public ?string $body = null,
        /** Narrow the audience to one person — for a kind with exactly one recipient, like an assignment. */
        public ?string $onlyUserId = null,
        public ?string $aboutProjectId = null,
        public ?string $aboutClientId = null,
        /**
         * A second permission the recipient must also hold.
         *
         * Used for an approval whose kind needs one: telling five people that a spend change is waiting when
         * three of them cannot approve it is how a bell comes to be ignored.
         */
        public ?string $alsoRequires = null,
        /** Decided by a rule — `NotificationSeverity::forAdverseChange` or a state — never chosen by hand. */
        public NotificationSeverity $severity = NotificationSeverity::Info,
        /**
         * The figures a card draws: value, change, currency, the thing's name. Never a sentence, and never
         * anything the recipient could not have read — it is stored as announced and shown as stored.
         *
         * @var array<string, mixed>|null
         */
        public ?array $payload = null,
        /**
         * What makes this a repeat of an earlier announcement — `roas_drop:{campaign}` — so the same thing is not
         * said every hour. Within `cooldownHours` of an earlier announcement with this key, the new one is written
         * only when its severity is worse: a state change is news, a repeat is noise.
         */
        public ?string $dedupeKey = null,
        public int $cooldownHours = 24,
    ) {}
}
