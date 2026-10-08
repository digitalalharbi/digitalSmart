<?php

declare(strict_types=1);

namespace App\Application\Intelligence\DTO;

use App\Application\Reporting\DTO\ReportDefinition;

/**
 * A report the assistant suggests, and the analysis it came from.
 *
 * Both, deliberately. A proposal with no findings behind it asks a reader to trust a shape somebody generated;
 * the analysis beside it is the argument for why those sections and not others.
 */
final readonly class ReportProposal
{
    public function __construct(
        public AnalysisView $analysis,
        /** Validated, and not saved. The reporting module is where a person decides. */
        public ReportDefinition $definition,
    ) {}
}
