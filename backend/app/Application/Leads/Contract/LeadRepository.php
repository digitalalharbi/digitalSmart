<?php

declare(strict_types=1);

namespace App\Application\Leads\Contract;

use App\Application\Leads\DTO\LeadDuplicateRow;
use App\Application\Leads\DTO\LeadHistoryEntry;
use App\Application\Leads\DTO\LeadInput;
use App\Application\Leads\DTO\LeadView;
use App\Domain\Leads\LeadMergePlan;
use App\Domain\Leads\LeadStage;
use DateTimeImmutable;

/**
 * Storing and reading leads.
 *
 * Every read is workspace-scoped and visibility-scoped by contract, as every other repository here is: a
 * lead outside the actor's projects is never in the result set rather than filtered out of it afterwards.
 */
interface LeadRepository
{
    /**
     * Record a lead, recognising one that already exists.
     *
     * Matching is on the provider's own id where there is one, and otherwise on a normalised contact point
     * **within a short window**. The window matters: the same person enquiring twice in a day is almost
     * certainly one lead being resubmitted, and the same person enquiring three months later is a new lead
     * about something else. Merging the second would lose a real enquiry; splitting the first would double a
     * client's lead count and halve their cost per lead.
     */
    public function record(string $workspaceId, LeadInput $input): LeadView;

    /** @param list<string>|null $visibleProjectIds */
    public function find(string $workspaceId, ?array $visibleProjectIds, string $id): ?LeadView;

    /**
     * @param  list<string>|null  $visibleProjectIds
     * @param  list<LeadStage>  $stages
     * @return list<LeadView>
     */
    public function visibleTo(
        string $workspaceId,
        ?array $visibleProjectIds,
        ?string $clientId = null,
        array $stages = [],
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $until = null,
        int $limit = 100,
    ): array;

    /**
     * Move a lead's stage, recording who and when.
     *
     * The timestamps are set here rather than by a caller, because they are facts about the transition: a
     * lead cannot be qualified without a `qualified_at`, and a closed one cannot lack a `closed_at` — the
     * database refuses both.
     */
    public function changeStage(
        string $workspaceId,
        string $id,
        LeadStage $stage,
        string $actorId,
        ?string $reason = null,
        ?string $note = null,
    ): LeadView;

    public function assign(string $workspaceId, string $id, ?string $ownerId, string $actorId): LeadView;

    /** Set what a lead is worth. Never inferred from an average of other leads. */
    public function setValue(
        string $workspaceId,
        string $id,
        ?int $valueMinor,
        ?string $currency,
        string $actorId,
    ): LeadView;

    public function addNote(string $workspaceId, string $id, string $note, string $actorId): LeadView;

    /**
     * What has happened to this lead, newest first.
     *
     * The read half of everything the pipeline writes. Without it a note is a write nobody can see, which is a
     * worse surface than having no notes at all: somebody records what happened on a call and the next person
     * reviewing the pipeline has no idea it is there.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return list<LeadHistoryEntry>
     */
    public function historyOf(
        string $workspaceId,
        ?array $visibleProjectIds,
        string $id,
        int $limit = 50,
    ): array;

    /**
     * Leads that may be the same person as this one.
     *
     * Exact matches on a normalised contact point, within the same client, excluding anything already merged
     * away. Never a fuzzy name match: two people called Mohammed Al-Harbi are two people, and a merge cannot
     * be undone.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return list<LeadDuplicateRow>
     */
    public function duplicatesOf(
        string $workspaceId,
        ?array $visibleProjectIds,
        string $id,
        int $limit = 10,
    ): array;

    /**
     * Put two leads' histories on one record.
     *
     * The plan has already decided which record survives, whether it adopts the other's arrival time and
     * whether it takes the other's attribution; the stage has already been checked to be one of the two. This
     * does the writing, in one transaction: the events move, the blanks fill, the absorbed row is marked rather
     * than deleted, and both records gain a `merged` event so each explains itself afterwards.
     */
    public function merge(
        string $workspaceId,
        LeadMergePlan $plan,
        LeadStage $stage,
        string $actorId,
        ?string $note = null,
    ): LeadView;
}
