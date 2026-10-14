<?php

declare(strict_types=1);

namespace App\Application\Advertising\Contract;

use App\Application\Advertising\DTO\CampaignControlFilter;
use App\Application\Advertising\DTO\CampaignVitalsRow;
use DateTimeImmutable;

/**
 * The facts every campaign row is built from, for one window.
 *
 * The read model returns **vitals**, not verdicts. Health is a judgement and it lives in
 * `CampaignHealthPolicy`, where it can be read and tested exhaustively; computing it a second time in SQL
 * so the database could sort by it would be two sets of rules free to disagree, and the disagreement
 * would show up as a row whose colour contradicts its own explanation.
 */
interface CampaignControlReadModel
{
    /**
     * @param  list<string>|null  $visibleProjectIds  Null is every project; `[]` is none, and stays distinct.
     * @return list<CampaignVitalsRow>
     */
    public function vitals(
        string $workspaceId,
        ?array $visibleProjectIds,
        CampaignControlFilter $filter,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $cap,
    ): array;

    /**
     * How many campaigns match the filter before the cap is applied.
     *
     * Published beside a capped page so the interface can say "showing 500 of 2,140" rather than implying
     * the agency has 500 campaigns.
     *
     * @param  list<string>|null  $visibleProjectIds
     */
    public function count(
        string $workspaceId,
        ?array $visibleProjectIds,
        CampaignControlFilter $filter,
    ): int;
}
