<?php

declare(strict_types=1);

namespace App\Application\Saas\Exception;

use App\Domain\Saas\Feature;
use DomainException;

/** The workspace's plan does not include this. Not a permission problem: nobody in the workspace can do it. */
final class FeatureNotEntitled extends DomainException
{
    public function __construct(public readonly Feature $feature, public readonly string $planCode)
    {
        parent::__construct(sprintf('This workspace’s plan does not include %s.', str_replace('_', ' ', $feature->value)));
    }
}
