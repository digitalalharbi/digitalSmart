<?php

declare(strict_types=1);

namespace App\Application\Saas\Exception;

use App\Domain\Saas\Limit;
use DateTimeImmutable;
use DomainException;

/** The workspace has reached a limit of its plan. Nothing already there is touched; this one more is refused. */
final class QuotaExceeded extends DomainException
{
    public function __construct(
        public readonly Limit $limit,
        public readonly int $used,
        public readonly int $allowed,
        public readonly ?DateTimeImmutable $resetsAt,
    ) {
        parent::__construct(sprintf(
            'This workspace has used %d of %d %s allowed by its plan.',
            $used,
            $allowed,
            str_replace('_', ' ', $limit->value),
        ));
    }
}
