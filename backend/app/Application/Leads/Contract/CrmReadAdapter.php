<?php

declare(strict_types=1);

namespace App\Application\Leads\Contract;

use App\Application\Leads\DTO\CrmDeal;
use App\Application\Leads\DTO\CrmPipeline;
use App\Domain\Integration\Credentials;
use DateTimeImmutable;

/**
 * Reading a CRM. Reading only.
 *
 * **There is no write side and there will not be one.** The CRM owns its own pipeline: two systems both claiming
 * to own a deal's stage is how a sales team loses a day's work to a sync, and a reporting tool that moved
 * somebody's deals would be a hazard rather than a feature. `ChangeLeadStage` refuses a CRM-owned lead for the
 * same reason, and says so.
 *
 * Pipelines are fetched separately from deals and before them, because a deal's stage is an internal id whose
 * meaning lives on the stage. A deal read without its pipeline is an id with no meaning attached, and the
 * temptation then is to read the meaning out of a label — which is the one thing this bridge exists not to do.
 */
interface CrmReadAdapter
{
    /**
     * Every deal pipeline, with its stages and their published metadata.
     *
     * @return list<CrmPipeline>
     */
    public function pipelines(Credentials $credentials): array;

    /**
     * Deals changed since a moment, with the contacts associated with each.
     *
     * `$since` is the high-water mark rather than a page number: a CRM is edited continuously, and a sync that
     * paged through everything would re-read a year of deals to find yesterday's.
     *
     * @return list<CrmDeal>
     */
    public function deals(Credentials $credentials, DateTimeImmutable $since): array;
}
