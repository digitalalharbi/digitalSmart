<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Intelligence;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Client\Contract\ClientReadModel;
use App\Application\Client\Contract\ClientVisibility;
use App\Application\Intelligence\Query\Analyse;
use App\Application\Intelligence\Query\Ask;
use App\Application\Intelligence\Query\Investigate;
use App\Application\Intelligence\Query\ProposeReport;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Client\ClientId;
use App\Domain\Shared\ValueObject\DateRange;
use App\Domain\Workspace\WorkspaceId;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Resource\Intelligence\AssistantResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The assistant.
 *
 * Thin, like every controller here, and with one decision of its own: **the client is required and is resolved
 * before anything is analysed.** An assistant that answered across every client a reader can see would produce
 * findings nobody could act on — a cost per click averaged over four unrelated accounts is a number with no
 * owner — and it would make the answer depend on the reader's assignments in a way the prose could not express.
 */
final class AssistantController extends Controller
{
    /** The window the assistant reads by default: long enough to show a trend, short enough to be about now. */
    private const int DEFAULT_DAYS = 30;

    private const int MIN_DAYS = 7;

    private const int MAX_DAYS = 180;

    public function __construct(
        private readonly ClientReadModel $clients,
        private readonly ClientVisibility $visibleClients,
        private readonly VisibilityResolver $visibility,
        private readonly ActorContext $actor,
        private readonly Clock $clock,
    ) {}

    public function vocabulary(): JsonResponse
    {
        return response()->json(['data' => AssistantResource::vocabulary()]);
    }

    public function analyse(Request $request, Analyse $analyse): JsonResponse
    {
        [$clientId, $clientName] = $this->client($request);

        return response()->json([
            'data' => AssistantResource::answer($analyse($clientId, $clientName, $this->period($request))),
        ]);
    }

    public function ask(Request $request, Ask $ask): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        [$clientId, $clientName] = $this->client($request);

        return response()->json([
            'data' => AssistantResource::answer($ask(
                $clientId,
                $clientName,
                $this->period($request),
                (string) $validated['question'],
            )),
        ]);
    }

    /**
     * A report definition proposed from the findings.
     *
     * Nothing is saved. The payload is what the reporting module's editor expects, so a reader opens it
     * pre-filled and decides for themselves — which is where that decision belongs.
     */
    public function report(Request $request, ProposeReport $propose): JsonResponse
    {
        [$clientId, $clientName] = $this->client($request);

        return response()->json([
            'data' => AssistantResource::proposal($propose($clientId, $clientName, $this->period($request))),
        ]);
    }

    public function investigate(Request $request, string $code, Investigate $investigate): JsonResponse
    {
        [$clientId, $clientName] = $this->client($request);

        return response()->json([
            'data' => AssistantResource::answer($investigate(
                $clientId,
                $clientName,
                $this->period($request),
                $code,
            )),
        ]);
    }

    /**
     * The client, resolved and named.
     *
     * A client of another workspace, or one that does not exist, is **not found** rather than forbidden: existence
     * across a tenant boundary is itself sensitive, which is the rule every other read in this product follows.
     *
     * @return array{0: string, 1: string}
     */
    private function client(Request $request): array
    {
        $id = $request->string('client_id')->value();

        if ($id === '') {
            throw ResourceNotFound::of('Client');
        }

        $workspaceId = $this->actor->workspaceId()->value;

        /*
         * Visibility first, then the name. Checking the other way round would let a reader learn that a client
         * exists — and what it is called — by asking about one they are not assigned to.
         */
        if (! $this->visibleClients->isVisible($workspaceId, $this->visibility->visibleProjectIds(), $id)) {
            throw ResourceNotFound::of('Client');
        }

        $client = $this->clients->find(new WorkspaceId($workspaceId), new ClientId($id))
            ?? throw ResourceNotFound::of('Client');

        return [$id, $client->name];
    }

    /**
     * The period being analysed.
     *
     * Clamped at both ends, and the floor is the one that matters: a week is the shortest span over which the
     * analysers' denominators mean anything, and a reader asking for two days would get an answer made almost
     * entirely of "not enough data to say".
     */
    private function period(Request $request): DateRange
    {
        $days = $request->integer('days') ?: self::DEFAULT_DAYS;

        return DateRange::lastDays(
            max(self::MIN_DAYS, min($days, self::MAX_DAYS)),
            $this->clock->now(),
        );
    }
}
