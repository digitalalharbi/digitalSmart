<?php

declare(strict_types=1);

namespace App\Interface\Http\Resource\Intelligence;

use App\Application\Intelligence\DTO\AnalysisView;
use App\Application\Intelligence\DTO\ReportProposal;
use App\Application\Reporting\DTO\SectionDefinition;
use App\Domain\Analytics\Metric;
use App\Domain\Intelligence\AssistantMode;
use App\Domain\Intelligence\Confidence;
use App\Domain\Intelligence\DeferredMode;
use App\Domain\Intelligence\Evidence;
use App\Domain\Intelligence\Finding;
use App\Domain\Intelligence\FindingSeverity;
use App\Domain\Intelligence\QuestionIntent;

/**
 * The assistant's answer, with everything a reader needs to disbelieve it.
 *
 * Four things travel with every answer, and each exists because the alternative is an assistant that cannot be
 * checked:
 *
 *  - **`narrative.written_by`** — the product or a named model. Both are legitimate; presenting one as the other
 *    is not.
 *  - **`narrative.model_rejected`** — true when a model answered and its answer was discarded for stating a
 *    figure the evidence does not contain. Published rather than swallowed, because a guard nobody can see
 *    working is a guard nobody will maintain.
 *  - **`evidence` on every finding** — the metric, the figure, the period, the source and the surface it came
 *    from. A reader can go and look.
 *  - **`skipped`** — the analysers this reader's permissions kept out of the answer, named. An answer that
 *    quietly differed by role would leave the reader with fewer permissions unaware a question had gone unasked.
 */
final class AssistantResource
{
    /** @return array<string, mixed> */
    public static function vocabulary(): array
    {
        return [
            'modes' => array_map(
                static fn (AssistantMode $mode): array => [
                    'mode' => $mode->value,
                    'label' => $mode->label(),
                    'description' => $mode->description(),
                    'permission' => $mode->requires()->value,
                    /* False for every mode. The assistant proposes; a person decides, in the owning module. */
                    'enacts_anything' => $mode->enactsAnything(),
                ],
                AssistantMode::cases(),
            ),
            /*
             * What the assistant is expected to do and does not do yet, with the reason. Published rather than
             * omitted and rather than shipped as a tab that produces nothing.
             */
            'deferred' => array_map(
                static fn (DeferredMode $mode): array => [
                    'mode' => $mode->value,
                    'label' => $mode->label(),
                    'because' => $mode->because(),
                ],
                DeferredMode::cases(),
            ),
            /*
             * The questions that are understood, with an example of each in the words somebody would type. A
             * closed list is only usable if it is published.
             */
            'questions' => array_map(
                static fn (QuestionIntent $intent): array => [
                    'intent' => $intent->value,
                    'label' => $intent->label(),
                    'example' => $intent->example(),
                ],
                QuestionIntent::cases(),
            ),
            'severities' => array_map(
                static fn (FindingSeverity $severity): array => [
                    'severity' => $severity->value,
                    'label' => $severity->label(),
                    'needs_attention' => $severity->needsAttention(),
                ],
                FindingSeverity::cases(),
            ),
            'confidence' => array_map(
                static fn (Confidence $confidence): array => [
                    'confidence' => $confidence->value,
                    'label' => $confidence->label(),
                    'supports_recommendation' => $confidence->supportsRecommendation(),
                ],
                Confidence::cases(),
            ),
            /*
             * Stated in the contract, not only in the prose. A reader of this API is entitled to know that no
             * figure here was produced by a language model.
             */
            'denominator_floor' => Confidence::DENOMINATOR_FLOOR,
        ];
    }

    /** @return array<string, mixed> */
    public static function answer(AnalysisView $view): array
    {
        return [
            'mode' => $view->mode->value,
            'client_name' => $view->clientName,
            'period' => [
                'from' => $view->period->startDate(),
                'until' => $view->period->endDate(),
                'days' => $view->period->lengthInDays(),
            ],
            /* Named, because every change figure in the answer is measured against it. */
            'comparison_period' => [
                'from' => $view->before->startDate(),
                'until' => $view->before->endDate(),
            ],
            'question' => $view->question,
            'intent' => $view->intent,
            'source' => $view->source()->value,
            'source_label' => $view->source()->label(),
            'requires_label' => $view->source()->requiresLabel(),
            'needs_attention' => $view->needsAttention(),
            'unanswered' => $view->unanswered(),
            'skipped' => $view->skipped,
            'narrative' => [
                'text' => $view->narrative->text,
                'written_by' => $view->narrative->writtenBy,
                'model' => $view->narrative->model,
                'model_rejected' => $view->narrative->modelRejected,
                'rejection_reason' => $view->narrative->rejectionReason,
            ],
            'findings' => array_map(
                static fn (Finding $finding): array => self::finding($finding),
                $view->findings,
            ),
        ];
    }

    /**
     * A proposed report, beside the analysis it came from.
     *
     * The definition is shaped as the reporting module's own editor expects it, so the surface can open
     * pre-filled rather than translating. Nothing has been saved: `report_id` is deliberately absent, because
     * there is no report yet.
     *
     * @return array<string, mixed>
     */
    public static function proposal(ReportProposal $proposal): array
    {
        $definition = $proposal->definition;

        return [
            'analysis' => self::answer($proposal->analysis),
            'proposal' => [
                'name' => $definition->name,
                'description' => $definition->description,
                'client_id' => $definition->clientId,
                'window' => $definition->window->value,
                'comparison' => $definition->comparison->value,
                /* Internal, always. A client-safe report is one somebody has read as the client would. */
                'audience' => $definition->audience->value,
                'sections' => array_map(
                    static fn (SectionDefinition $section): array => [
                        'kind' => $section->kind->value,
                        'metrics' => array_map(
                            static fn (Metric $metric): string => $metric->value,
                            $section->metrics,
                        ),
                        'dimension' => $section->dimension?->value,
                    ],
                    $definition->sections,
                ),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function finding(Finding $finding): array
    {
        return [
            'code' => $finding->code,
            'severity' => $finding->severity->value,
            'severity_label' => $finding->severity->label(),
            'needs_attention' => $finding->severity->needsAttention(),
            'subject' => $finding->subject,
            'headline' => $finding->headline,
            'detail' => $finding->detail,
            'confidence' => $finding->confidence->value,
            'confidence_label' => $finding->confidence->label(),
            'source' => $finding->source()->value,
            'source_label' => $finding->source()->label(),
            /* Null unless the evidence was firm enough. Most findings carry none, deliberately. */
            'recommendation' => $finding->recommendation,
            'evidence' => array_map(
                static fn (Evidence $evidence): array => [
                    'metric' => $evidence->metric,
                    'label' => $evidence->label,
                    'value' => $evidence->value,
                    'compared_with' => $evidence->comparedWith,
                    'period' => $evidence->period,
                    'source' => $evidence->source->value,
                    'source_label' => $evidence->source->label(),
                    'read_from' => $evidence->readFrom,
                    /* Why a figure is absent. A blank cell becomes a zero in the next calculation. */
                    'reason' => $evidence->reason,
                ],
                $finding->evidence,
            ),
        ];
    }
}
