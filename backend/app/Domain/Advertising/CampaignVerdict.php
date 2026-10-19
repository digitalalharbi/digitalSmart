<?php

declare(strict_types=1);

namespace App\Domain\Advertising;

/**
 * A health state and the one sentence behind it.
 *
 * The sentence is not decoration. A row that says *Needs attention* and nothing else sends a media buyer
 * to the platform to work out why, which is the trip this product exists to remove — and it is the
 * difference between a judgement somebody can argue with and a colour.
 */
final readonly class CampaignVerdict
{
    public function __construct(
        public CampaignHealth $health,
        /** Business language. No column names, no thresholds, no provider codes. */
        public string $reason,
        /**
         * The same finding as a code an interface can translate — `CAMPAIGN_COST_RISE` with `{pct: 34}` — so an
         * Arabic screen never shows the server's English sentence. The sentence stays for logs and the API's
         * English readers.
         */
        public string $code = 'CAMPAIGN_UNKNOWN',
        /** @var array<string, int|string> */
        public array $params = [],
    ) {}
}
