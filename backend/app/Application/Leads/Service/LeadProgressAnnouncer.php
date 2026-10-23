<?php

declare(strict_types=1);

namespace App\Application\Leads\Service;

use App\Application\Leads\DTO\LeadView;
use App\Application\Notifications\Command\Announce;
use App\Application\Notifications\DTO\NotificationDraft;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\NotificationSeverity;
use Throwable;

/**
 * Tells the person who owns a lead that it moved forward.
 *
 * Only the owner, and never the person who moved it (`Announce` drops the actor): this is news for somebody who is not the
 * one who just did it. A lead nobody owns tells nobody — the pipeline is where it is read. The sentence names the event and
 * the source, and carries nothing about the prospect.
 */
final readonly class LeadProgressAnnouncer
{
    public function __construct(private Announce $announce) {}

    public function announce(LeadView $lead, NotificationKind $kind): void
    {
        if ($lead->ownerId === null || $lead->projectId === null) {
            return;
        }

        try {
            ($this->announce)(new NotificationDraft(
                kind: $kind,
                title: $kind->label(),
                subjectId: $lead->id,
                body: $lead->sourceDetail,
                onlyUserId: $lead->ownerId,
                aboutProjectId: $lead->projectId,
                aboutClientId: $lead->clientId,
                severity: $kind === NotificationKind::LeadSaleCompleted ? NotificationSeverity::Success : NotificationSeverity::Info,
                dedupeKey: $kind->value.':'.$lead->id,
                cooldownHours: 24,
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
