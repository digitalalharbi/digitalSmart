<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Reporting;

use App\Application\Analytics\DTO\AnalyticsFilters;
use App\Application\Reporting\Command\DeleteReport;
use App\Application\Reporting\Command\RevokeShare;
use App\Application\Reporting\Command\SaveReport;
use App\Application\Reporting\Command\ShareReport;
use App\Application\Reporting\Command\TakeSnapshot;
use App\Application\Reporting\Contract\ReportRepository;
use App\Application\Reporting\Contract\ReportShareStore;
use App\Application\Reporting\Contract\ReportWorkbookWriter;
use App\Application\Reporting\DTO\ReportDefinition;
use App\Application\Reporting\DTO\SectionDefinition;
use App\Application\Reporting\DTO\ShareView;
use App\Application\Reporting\Query\GetReport;
use App\Application\Reporting\Query\ListReports;
use App\Application\Reporting\Query\ListReportShares;
use App\Application\Reporting\Query\ListReportSnapshots;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\Clock;
use App\Domain\Analytics\Dimension;
use App\Domain\Analytics\Metric;
use App\Domain\Commerce\RevenueSource;
use App\Domain\Reporting\ComparisonMode;
use App\Domain\Reporting\CreativeView;
use App\Domain\Reporting\Exception\InvalidReportDefinition;
use App\Domain\Reporting\Exception\InvalidShare;
use App\Domain\Reporting\ReportAudience;
use App\Domain\Reporting\ReportWindow;
use App\Domain\Reporting\SectionKind;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Resource\Reporting\ReportCsv;
use App\Interface\Http\Resource\Reporting\ReportResource;
use DateTimeImmutable;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reports: building them, running them, freezing them and sending them out.
 *
 * Every refusal answers with a code and a sentence a person can act on, because the refusals here are about things
 * nobody could read or trust — a ranking of a metric that does not add up, a comparison between periods of
 * different lengths, a link to a report written for internal eyes.
 */
final class ReportController extends Controller
{
    public function vocabulary(): JsonResponse
    {
        return response()->json(['data' => ReportResource::vocabulary()]);
    }

    public function index(Request $request, ListReports $reports): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                static fn (\App\Application\Reporting\DTO\ReportSummary $summary): array => ReportResource::summary($summary),
                $reports($this->scopeId($request, 'client_id'), $request->integer('limit') ?: null),
            ),
        ]);
    }

    /** The report, rendered against today's data. */
    public function show(string $report, GetReport $get): JsonResponse
    {
        return response()->json(['data' => ReportResource::rendered($get($report))]);
    }

    /** The definition, for an editor. Separate from the render, which costs an aggregation per section. */
    public function definition(
        string $report,
        ReportRepository $reports,
        ActorContext $actor,
        \App\Application\Analytics\Contract\VisibilityResolver $visibility,
    ): JsonResponse {
        $saved = $reports->find(
            $actor->workspaceId()->value,
            $visibility->visibleProjectIds(),
            $report,
        );

        if ($saved === null) {
            return response()->json(['message' => 'Report not found.'], Response::HTTP_NOT_FOUND);
        }

        return response()->json(['data' => ReportResource::definition($saved)]);
    }

    public function store(Request $request, SaveReport $save): JsonResponse
    {
        try {
            $definition = $this->definitionFrom($request);
        } catch (InvalidReportDefinition $refusal) {
            return $this->refused($refusal);
        }

        if ($definition instanceof JsonResponse) {
            return $definition;
        }

        try {
            $saved = $save($definition);
        } catch (InvalidReportDefinition $refusal) {
            return $this->refused($refusal);
        }

        return response()->json(
            ['data' => ReportResource::definition($saved)],
            Response::HTTP_CREATED,
        );
    }

    public function update(string $report, Request $request, SaveReport $save): JsonResponse
    {
        $definition = $this->definitionFrom($request);

        if ($definition instanceof JsonResponse) {
            return $definition;
        }

        try {
            $saved = $save($definition, $report);
        } catch (InvalidReportDefinition $refusal) {
            return $this->refused($refusal);
        }

        return response()->json(['data' => ReportResource::definition($saved)]);
    }

    public function destroy(string $report, DeleteReport $delete): JsonResponse
    {
        $delete($report);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /** Freeze the report as it is now. See `RenderMode` for why this exists. */
    public function snapshot(string $report, Request $request, TakeSnapshot $take, Clock $clock): JsonResponse
    {
        $id = $take($report, $this->text($request, 'label'));

        return response()->json(
            ['data' => ['id' => $id, 'taken_at' => $clock->now()->format(DateTimeImmutable::ATOM)]],
            Response::HTTP_CREATED,
        );
    }

    public function snapshots(
        string $report,
        ReportRepository $reports,
        ActorContext $actor,
    ): JsonResponse {
        $snapshots = $reports->snapshots($actor->workspaceId()->value, $report, 50);

        return response()->json([
            'data' => array_map(static fn (array $snapshot): array => [
                'id' => $snapshot['id'],
                'taken_at' => $snapshot['taken_at']->format(DateTimeImmutable::ATOM),
                'period_from' => $snapshot['period_from']->format('Y-m-d'),
                'period_until' => $snapshot['period_until']->format('Y-m-d'),
                'source' => $snapshot['source']->value,
                'source_label' => $snapshot['source']->label(),
                'label' => $snapshot['label'],
                'taken_by_name' => $snapshot['taken_by_name'],
            ], $snapshots),
        ]);
    }

    /** Every link out of the agency, across the reports the reader may see. */
    public function allShares(ListReportShares $list, Clock $clock): JsonResponse
    {
        $now = $clock->now();

        return response()->json([
            'data' => array_map(
                static fn (array $share): array => ReportResource::acrossShare($share, $now),
                $list(),
            ),
        ]);
    }

    /** Every frozen record across the reports the reader may see. */
    public function allSnapshots(ListReportSnapshots $list): JsonResponse
    {
        return response()->json([
            'data' => array_map(ReportResource::acrossSnapshot(...), $list()),
        ]);
    }

    public function shares(
        string $report,
        ReportShareStore $shares,
        ActorContext $actor,
        Clock $clock,
    ): JsonResponse {
        $now = $clock->now();

        return response()->json([
            'data' => array_map(
                static fn (ShareView $share): array => ReportResource::share($share, $now),
                $shares->forReport($actor->workspaceId()->value, $report),
            ),
        ]);
    }

    /**
     * Create a link.
     *
     * The response carries the raw token **once**. Nothing reads it back, which is deliberate: a link somebody lost
     * is a link they should replace rather than recover.
     */
    public function share(string $report, Request $request, ShareReport $share, Clock $clock): JsonResponse
    {
        try {
            $created = $share(
                $report,
                $request->integer('days') ?: 7,
                $this->scopeId($request, 'snapshot_id'),
                $this->text($request, 'recipient_label'),
            );
        } catch (InvalidShare $refusal) {
            return response()->json([
                'message' => $refusal->getMessage(),
                'code' => $refusal->reasonCode,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'data' => ReportResource::share($created['share'], $clock->now()),
            'meta' => [
                /*
                 * Returned once, here. There is no endpoint that returns it again — see `ReportShareStore`.
                 */
                'token' => $created['token'],
            ],
        ], Response::HTTP_CREATED);
    }

    public function revokeShare(string $share, Request $request, RevokeShare $revoke): JsonResponse
    {
        try {
            $revoke($share, $this->text($request, 'reason'));
        } catch (InvalidShare $refusal) {
            return response()->json([
                'message' => $refusal->getMessage(),
                'code' => $refusal->reasonCode,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /** The report as CSV, with its caveats in the file. See `ReportCsv`. */
    public function csv(string $report, GetReport $get): HttpResponse
    {
        $rendered = $get($report);

        return response(ReportCsv::render($rendered), Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => sprintf(
                'attachment; filename="%s.csv"',
                /*
                 * The period is in the filename. A folder of files called "Monthly report.csv" is a folder nobody
                 * can tell apart, and the one somebody opens is whichever sorted first.
                 */
                $this->filename($rendered->name.' '.$rendered->period->start->format('Y-m-d')),
            ),
        ]);
    }

    /**
     * The report as a spreadsheet, with the caveats on every sheet. See `XlsxWriter`.
     *
     * Read into memory and returned with an explicit length rather than streamed from disk: the deck download
     * taught this stack that `download()` with `deleteFileAfterSend()` passes through the test kernel and
     * returns 500 through a real server, because PHP's development server closes a binary response early. A
     * workbook is tens of kilobytes, and a download that works only under some servers is one somebody will
     * hit on the day they need it.
     */
    public function xlsx(string $report, GetReport $get, ReportWorkbookWriter $writer): HttpResponse
    {
        $rendered = $get($report);
        $path = $writer->write($rendered);

        try {
            $contents = file_get_contents($path);
        } finally {
            @unlink($path);
        }

        if ($contents === false) {
            throw new RuntimeException('The workbook was written but could not be read back.');
        }

        return response($contents, Response::HTTP_OK, [
            'Content-Type' => $writer->contentType(),
            'Content-Length' => (string) strlen($contents),
            'Content-Disposition' => sprintf(
                'attachment; filename="%s.%s"',
                $this->filename($rendered->name.' '.$rendered->period->start->format('Y-m-d')),
                $writer->extension(),
            ),
        ]);
    }

    private function definitionFrom(Request $request): ReportDefinition|JsonResponse
    {
        $window = ReportWindow::tryFrom($request->string('window')->value());

        if ($request->string('window')->value() !== '' && $window === null) {
            return $this->unknown('window', ReportWindow::values());
        }

        $comparison = ComparisonMode::tryFrom($request->string('comparison')->value());

        if ($request->string('comparison')->value() !== '' && $comparison === null) {
            return $this->unknown('comparison', ComparisonMode::values());
        }

        $audience = ReportAudience::tryFrom($request->string('audience')->value());

        if ($request->string('audience')->value() !== '' && $audience === null) {
            return $this->unknown('audience', ReportAudience::values());
        }

        /** @var list<SectionDefinition> $sections */
        $sections = [];

        /** @var array<array-key, mixed> $raw */
        $raw = is_array($request->input('sections')) ? $request->input('sections') : [];

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $kind = SectionKind::tryFrom(is_string($entry['kind'] ?? null) ? $entry['kind'] : '');

            if ($kind === null) {
                return $this->unknown('section kind', SectionKind::values());
            }

            /** @var list<Metric> $metrics */
            $metrics = [];

            foreach (is_array($entry['metrics'] ?? null) ? $entry['metrics'] : [] as $value) {
                $metric = is_string($value) ? Metric::tryFrom($value) : null;

                if ($metric === null) {
                    return $this->unknown('metric', Metric::values());
                }

                $metrics[] = $metric;
            }

            $dimensionValue = is_string($entry['dimension'] ?? null) ? $entry['dimension'] : '';
            $dimension = Dimension::tryFrom($dimensionValue);

            if ($dimensionValue !== '' && $dimension === null) {
                return $this->unknown('dimension', Dimension::values());
            }

            $sourceValue = is_string($entry['revenue_source'] ?? null) ? $entry['revenue_source'] : '';
            $revenueSource = RevenueSource::tryFrom($sourceValue);

            if ($sourceValue !== '' && $revenueSource === null) {
                return $this->unknown(
                    'revenue source',
                    array_map(static fn (RevenueSource $case): string => $case->value, RevenueSource::cases()),
                );
            }

            $viewValue = is_string($entry['creative_view'] ?? null) ? $entry['creative_view'] : '';
            $creativeView = CreativeView::tryFrom($viewValue);

            if ($viewValue !== '' && $creativeView === null) {
                return $this->unknown('creative view', CreativeView::values());
            }

            $sections[] = new SectionDefinition(
                kind: $kind,
                metrics: $metrics,
                title: is_string($entry['title'] ?? null) && trim($entry['title']) !== ''
                    ? trim($entry['title'])
                    : null,
                dimension: $dimension,
                rowLimit: isset($entry['row_limit']) && is_numeric($entry['row_limit'])
                    ? (int) $entry['row_limit']
                    : null,
                revenueSource: $revenueSource,
                creativeView: $creativeView,
            );
        }

        return new ReportDefinition(
            name: trim($request->string('name')->value()),
            window: $window ?? ReportWindow::Last30Days,
            comparison: $comparison ?? ComparisonMode::None,
            /*
             * Internal unless somebody says otherwise. A default of client-safe would mean a report written in
             * shorthand becoming shareable by omission.
             */
            audience: $audience ?? ReportAudience::Internal,
            filters: new AnalyticsFilters(
                providers: $this->strings($request, 'providers'),
                campaignIds: $this->strings($request, 'campaign_ids'),
                projectIds: $this->strings($request, 'project_ids'),
                clientId: $this->scopeId($request, 'client_id'),
            ),
            sections: $sections,
            description: $this->text($request, 'description'),
            clientId: $this->scopeId($request, 'client_id'),
            customFrom: $this->date($request, 'custom_from'),
            customUntil: $this->date($request, 'custom_until'),
            internalNote: $this->text($request, 'internal_note'),
        );
    }

    /** @return list<string> */
    private function strings(Request $request, string $key): array
    {
        $value = $request->input($key);

        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    /** @param list<string> $allowed */
    private function unknown(string $field, array $allowed): JsonResponse
    {
        return response()->json([
            'message' => sprintf('Unknown %s.', $field),
            'code' => 'invalid_'.str_replace(' ', '_', $field),
            'allowed' => $allowed,
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function refused(InvalidReportDefinition $refusal): JsonResponse
    {
        return response()->json([
            'message' => $refusal->getMessage(),
            'code' => $refusal->reasonCode,
            'allowed' => $refusal->allowed,
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function filename(string $name): string
    {
        $safe = preg_replace('/[^A-Za-z0-9 \-_]/', '', $name) ?? 'report';

        return trim($safe) === '' ? 'report' : trim($safe);
    }

    private function text(Request $request, string $key): ?string
    {
        $value = trim($request->string($key)->value());

        return $value === '' ? null : $value;
    }

    private function date(Request $request, string $key): ?DateTimeImmutable
    {
        $value = trim($request->string($key)->value());

        if ($value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->setTime(0, 0);
        } catch (Exception) {
            return null;
        }
    }

    private function scopeId(Request $request, string $key): ?string
    {
        return $request->string($key)->value() ?: null;
    }
}
