<?php

declare(strict_types=1);

namespace App\Application\Leads\DTO;

use DateTimeImmutable;

/**
 * One deal, as the CRM published it.
 *
 * `stageId` is the CRM's **internal** stage id, not its label: HubSpot's documentation is explicit that the
 * internal id is what a deal's `dealstage` property contains, and the label is a thing people rename. What that
 * id means is answered by the pipeline, which is why a deal is never read without one.
 *
 * `amountMinor` is null wherever the CRM published no amount, and the currency with it. A deal with no amount is
 * ordinary — most are, until somebody quotes — and a zero would be a figure this product invented.
 */
final readonly class CrmDeal
{
    /** @param list<CrmContact> $contacts */
    public function __construct(
        public string $id,
        public string $name,
        public string $pipelineId,
        public string $stageId,
        public ?int $amountMinor,
        public ?string $currency,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $closeDate,
        public array $contacts = [],
    ) {}
}
