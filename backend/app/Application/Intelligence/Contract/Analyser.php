<?php

declare(strict_types=1);

namespace App\Application\Intelligence\Contract;

use App\Application\Intelligence\DTO\AnalysisContext;
use App\Domain\Access\Permission;
use App\Domain\Intelligence\Finding;

/**
 * One thing the assistant knows how to look for.
 *
 * An analyser is a **pure function over an `AnalysisContext`**: no database, no clock, no HTTP. Everything it
 * needs was fetched once before it ran, which is what lets a rule like "a doubling of cost per click on nine
 * clicks is not a finding" be read and tested rather than inferred from a query.
 *
 * Each declares the permission its inputs require, and `Analyse` runs only the ones the reader may run. An
 * assistant that answered questions a reader could not ask directly would be a route around every other gate in
 * this product.
 */
interface Analyser
{
    /** Stable, so the same analyser's findings are recognisable across runs. */
    public function code(): string;

    public function requires(): Permission;

    /** @return list<Finding> */
    public function run(AnalysisContext $context): array;
}
