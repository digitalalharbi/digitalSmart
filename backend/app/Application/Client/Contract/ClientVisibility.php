<?php

declare(strict_types=1);

namespace App\Application\Client\Contract;

/**
 * Whether a reader may see a particular client at all.
 *
 * One question, asked by several modules, and therefore worth one implementation. Reporting asks it before
 * listing a client's reports, the portal asks it before showing a client's configuration, and the assistant asks
 * it before analysing a client's performance. The rule is the same in all three — a client is visible when it owns
 * a project the reader can see — and three expressions of it would eventually be three different rules, with the
 * loosest one deciding.
 *
 * `$visibleProjectIds` carries the usual meaning: `null` is the whole workspace, `[]` is nothing, and a list
 * narrows. A client with no projects at all is visible only to a reader with workspace-wide visibility, which is
 * the correct answer rather than an edge case: there is nothing to have been assigned to.
 */
interface ClientVisibility
{
    /**
     * @param  list<string>|null  $visibleProjectIds
     */
    public function isVisible(string $workspaceId, ?array $visibleProjectIds, string $clientId): bool;

    /**
     * The visible clients, keyed by id and ordered by name.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return array<string, string>
     */
    public function visible(string $workspaceId, ?array $visibleProjectIds): array;
}
