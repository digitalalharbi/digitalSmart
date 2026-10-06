<?php

declare(strict_types=1);

namespace App\Application\Leads\DTO;

use App\Domain\Leads\LeadFunnel;

/**
 * The Lead Center's answer.
 *
 * `signals` sits beside the funnel deliberately and is **not** part of it. Those are counted clicks and form
 * starts from the measurement layer — WhatsApp taps, phone taps, forms begun — and they are shown next to
 * the leads precisely so nobody has to guess the relationship. The one thing this product will not do is
 * add them together: a tap is not a conversation, and the gap between the two columns is information rather
 * than a rounding error.
 */
final readonly class LeadCentreView
{
    /**
     * @param  list<LeadSourceRow>  $bySource
     * @param  list<LeadBreakdownRow>  $byCampaign
     * @param  array<string, int>  $signals  raw event name to count over the same window
     */
    public function __construct(
        public LeadFunnel $funnel,
        public array $bySource,
        public array $byCampaign,
        public array $signals,
        public int $windowDays,
        public ?string $currency = null,
    ) {}
}
