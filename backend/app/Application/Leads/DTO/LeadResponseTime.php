<?php

declare(strict_types=1);

namespace App\Application\Leads\DTO;

/**
 * How quickly a client's leads were first answered.
 *
 * Only leads with a recorded first contact are in it, and `measured` says how many of the period's leads that is. A
 * lead nobody has answered has no response time — it is not a slow one, and it is not a zero — so it is left out of
 * the buckets and the median and counted in `unanswered` instead.
 */
final readonly class LeadResponseTime
{
    /** The bucket edges in seconds, in order. The last bucket is open-ended. */
    public const array EDGES = [300, 900, 3600, 14400];

    /**
     * @param  list<int>  $buckets  leads per bucket: under 5 min, 5–15, 15–60, 1–4 h, 4 h and over
     */
    public function __construct(
        public int $measured,
        public int $unanswered,
        public ?int $medianSeconds,
        public array $buckets,
    ) {}
}
