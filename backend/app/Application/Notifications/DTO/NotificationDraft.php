<?php

declare(strict_types=1);

namespace App\Application\Notifications\DTO;

use App\Domain\Notifications\NotificationKind;

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
    ) {}
}
