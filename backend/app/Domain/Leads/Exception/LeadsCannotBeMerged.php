<?php

declare(strict_types=1);

namespace App\Domain\Leads\Exception;

use App\Domain\Shared\Exception\DomainException;

/**
 * A merge this product refuses, with the reason in the words of somebody who has to act on it.
 *
 * Each refusal here is a case where merging would produce a record that cannot explain itself afterwards. They
 * are refusals rather than warnings because a merge is not reversible by any control this product offers: the
 * two histories are on one record from then on, and "undo" would have to invent a split nobody recorded.
 */
final class LeadsCannotBeMerged extends DomainException
{
    public function errorCode(): string
    {
        return 'leads_cannot_be_merged';
    }

    public static function theSameLead(): self
    {
        return new self('A lead cannot be merged into itself.');
    }

    /**
     * Across clients, never.
     *
     * The one refusal that is about billing rather than about history. A lead belongs to a client, a client is
     * invoiced for their leads, and a merge across two of them would move one client's enquiry into another
     * client's count — where nothing downstream could tell it had been moved.
     */
    public static function differentClients(): self
    {
        return new self(
            'These leads belong to two different clients. Merging them would move one client\'s enquiry into '
            .'the other\'s count, so if they really are one person, record the second one under the right '
            .'client first.',
        );
    }

    /** A chain of merges would make "how many leads is this really" unanswerable. */
    public static function alreadyMerged(): self
    {
        return new self(
            'One of these leads has already been merged into another. Open the lead it was merged into and '
            .'merge that one instead, so there is a single surviving record rather than a chain.',
        );
    }

    /**
     * Two CRM leads are the CRM's to merge.
     *
     * The same position as `ChangeLeadStage`: the system a lead came from owns it. Merging two of its records
     * here would leave the CRM still holding two, still syncing both, and the merge would have to be repeated
     * after every sync — while the product showed a number the CRM disagrees with.
     */
    public static function bothOwnedByTheCrm(string $sourceDetail): self
    {
        return new self(sprintf(
            'Both of these leads came from %s, which owns them. Merge them there and the result arrives here '
            .'on the next sync; merging them here would leave that system still holding two records.',
            $sourceDetail === '' ? 'a CRM' : $sourceDetail,
        ));
    }

    /**
     * The surviving stage has to be one the two leads are actually in.
     *
     * A merge is not a place to move a lead through a pipeline. Allowing a third stage would let somebody
     * qualify a lead with no qualifying event, and the pipeline review that asks "who decided this, and when"
     * would have no answer.
     */
    public static function stageIsNeitherOfTheirs(string $chosen, string $first, string $second): self
    {
        return new self(sprintf(
            'The merged lead has to stay in a stage one of these two is already in — %s or %s, not %s. Move it '
            .'afterwards if it belongs somewhere else, so the move is recorded as one.',
            $first,
            $second,
            $chosen,
        ));
    }
}
