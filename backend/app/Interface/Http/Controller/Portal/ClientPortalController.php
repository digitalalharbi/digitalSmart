<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Portal;

use App\Application\Portal\DTO\PortalEntry;
use App\Application\Portal\Query\GetPortalIndex;
use App\Application\Portal\Query\GetPortalPerformance;
use App\Application\Portal\Query\GetPortalReport;
use App\Application\Portal\Query\GetPortalReports;
use App\Application\Portal\Query\GetPortalRevenue;
use App\Application\Portal\Query\GetPortalTracking;
use App\Application\Reporting\DTO\ReportSummary;
use App\Application\Shared\Contract\Clock;
use App\Domain\Commerce\RevenueSource;
use App\Domain\Portal\Exception\RevenueSourceRequired;
use App\Domain\Shared\Exception\InvalidDateRange;
use App\Domain\Shared\ValueObject\DateRange;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Resource\Commerce\StoreAnalyticsResource;
use App\Interface\Http\Resource\Measurement\MeasurementResource;
use App\Interface\Http\Resource\Portal\ClientPortalResource;
use App\Interface\Http\Resource\Reporting\ReportResource;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The client's own side of the portal.
 *
 * Every method here takes a client id in the path and nothing that widens what is returned. There is no metric
 * parameter on the performance read, no dimension, no project filter — a client reading a surface they can reshape
 * will eventually build a figure nobody from the agency is present to explain, and the whole argument for this
 * module is that an agency can stand behind what is on it without commentary.
 *
 * Authorization is `PortalAccess`, in the application layer, for every one of them. A module that is not exposed
 * answers **404** rather than 403: a client told that a surface about them exists and is being withheld has been
 * told something about the agency's intentions rather than about the product.
 */
final class ClientPortalController extends Controller
{
    /** The window a client reads by default. Long enough to show a trend, short enough to be about now. */
    private const int DEFAULT_DAYS = 30;

    /** Beyond this, a client is doing analysis rather than reading a portal. */
    private const int MAX_DAYS = 365;

    /*
     * The clock, injected rather than read from `now()`. "Yesterday" is a decision this class makes on every
     * request, and a controller that asked the global helper could not be tested across a midnight boundary.
     */
    public function __construct(private readonly Clock $clock) {}

    public function index(GetPortalIndex $index): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                static fn (PortalEntry $entry): array => ClientPortalResource::entry($entry),
                $index(),
            ),
        ]);
    }

    public function performance(Request $request, string $client, GetPortalPerformance $read): JsonResponse
    {
        return response()->json([
            'data' => ClientPortalResource::performance($read($client, $this->period($request))),
        ]);
    }

    public function revenue(Request $request, string $client, GetPortalRevenue $read): JsonResponse
    {
        $source = RevenueSource::tryFrom((string) $request->query('revenue_source', ''));

        try {
            return response()->json([
                'data' => StoreAnalyticsResource::view($read($client, $source, $this->period($request))),
            ]);
        } catch (RevenueSourceRequired $refusal) {
            /*
             * Raised by the use case *after* the access check, so a reader who may not open this surface is told
             * it is not there rather than told which parameter they left out.
             *
             * Required, with no default, on the client's surface as much as the agency's. A Salla total counts
             * invoices and a GA4 total counts what a browser reported; choosing for the reader is how a portal
             * comes to show one labelled as the other.
             */
            return response()->json([
                'message' => $refusal->getMessage(),
                'code' => 'revenue_source_required',
                /*
                 * What this surface will accept, which is not every source the product knows: attributed revenue
                 * is a model and is never a portal figure — see `GetPortalRevenue::available()`.
                 */
                'available' => array_map(
                    static fn (RevenueSource $value): array => [
                        'revenue_source' => $value->value,
                        'label' => $value->label(),
                        'is_invoiced' => $value->isInvoiced(),
                    ],
                    GetPortalRevenue::available(),
                ),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    public function reports(string $client, GetPortalReports $read): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                static fn (ReportSummary $summary): array => ReportResource::summary($summary),
                $read($client),
            ),
        ]);
    }

    public function report(string $client, string $report, GetPortalReport $read): JsonResponse
    {
        return response()->json(['data' => ReportResource::rendered($read($client, $report))]);
    }

    public function tracking(string $client, GetPortalTracking $read): JsonResponse
    {
        return response()->json(['data' => MeasurementResource::health($read($client))]);
    }

    /**
     * The period being read.
     *
     * Defaults to the last thirty days **ending yesterday**, which is the same rule every relative window in this
     * product follows: today is still accumulating, and a partial last day reads as a drop in performance.
     */
    private function period(Request $request): DateRange
    {
        $from = $request->query('from');
        $until = $request->query('until');

        if (is_string($from) && is_string($until) && $from !== '' && $until !== '') {
            try {
                $range = new DateRange(new DateTimeImmutable($from), new DateTimeImmutable($until));
            } catch (InvalidDateRange) {
                return DateRange::lastDays(self::DEFAULT_DAYS, $this->clock->now());
            }

            return $range->lengthInDays() > self::MAX_DAYS
                ? DateRange::lastDays(self::DEFAULT_DAYS, $this->clock->now())
                : $range;
        }

        return DateRange::lastDays(self::DEFAULT_DAYS, $this->clock->now());
    }
}
