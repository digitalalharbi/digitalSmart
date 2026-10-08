<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Presentation;

use App\Application\Presentation\Contract\DeckWriter;
use App\Application\Presentation\DTO\Deck;
use App\Application\Presentation\Query\ComposeDeck;
use App\Application\Presentation\Query\ComposeMediaPlanDeck;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Presentation\DeckKind;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Resource\Presentation\DeckResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use RuntimeException;

/**
 * Decks: previewed as JSON, downloaded as PowerPoint.
 *
 * **There is no PDF endpoint, and that is a decision rather than an omission.** A PDF is produced by printing the
 * preview from the browser, where the Arabic shaping is the browser's own, the fonts are the product's own
 * self-hosted faces, and the charts are the same ones on screen. The alternative — a server-side HTML-to-PDF
 * converter — is a second rendering engine, with weaker Arabic support, that would eventually disagree with the
 * page it was made from. See `docs/PRESENTATIONS.md`.
 */
final class PresentationController extends Controller
{
    public function vocabulary(): JsonResponse
    {
        return response()->json(['data' => DeckResource::vocabulary()]);
    }

    public function show(
        Request $request,
        string $kind,
        ComposeDeck $compose,
        ComposeMediaPlanDeck $plan,
    ): JsonResponse {
        return response()->json(['data' => DeckResource::deck($this->deck($request, $kind, $compose, $plan))]);
    }

    /**
     * The deck as a file.
     *
     * Read into the response with an explicit length, rather than streamed from disk and deleted afterwards.
     * That is not the obvious choice and it was not the first one: `download()` with `deleteFileAfterSend()`
     * worked through the test kernel and returned 500 through a real server, because PHP's development server
     * closes a binary file response early and the proxy in front of it reports a reset connection. A deck is tens
     * of kilobytes, so holding one in memory costs nothing measurable — and a download that works only under
     * some servers is a download somebody will hit on the day they need it.
     *
     * The temporary file is removed before the response leaves, so nothing depends on a callback firing.
     */
    public function download(
        Request $request,
        string $kind,
        ComposeDeck $compose,
        ComposeMediaPlanDeck $plan,
        DeckWriter $writer,
    ): HttpResponse {
        $deck = $this->deck($request, $kind, $compose, $plan);
        $path = $writer->write($deck);

        try {
            $contents = file_get_contents($path);
        } finally {
            @unlink($path);
        }

        if ($contents === false) {
            throw new RuntimeException('The deck was written but could not be read back.');
        }

        return response($contents, 200, [
            'Content-Type' => $writer->contentType(),
            'Content-Length' => (string) strlen($contents),
            'Content-Disposition' => sprintf(
                'attachment; filename="%s"',
                $deck->filename($writer->extension()),
            ),
        ]);
    }

    /**
     * The deck, from whichever composer owns this kind.
     *
     * A media plan is built from a budget and the rest from a client's delivery, so the two take different
     * parameters — and asking for one without the other is **not found** rather than a deck composed from
     * whatever was supplied. A media plan deck built from a client id would be a performance deck wearing the
     * wrong title.
     */
    private function deck(
        Request $request,
        string $kind,
        ComposeDeck $compose,
        ComposeMediaPlanDeck $plan,
    ): Deck {
        $resolved = DeckKind::tryFrom($kind) ?? throw ResourceNotFound::of('Deck');

        if ($resolved->isBuiltFromBudget()) {
            $budgetId = $request->string('budget_id')->value();

            if ($budgetId === '') {
                throw ResourceNotFound::of('Budget');
            }

            return $plan($budgetId);
        }

        $clientId = $request->string('client_id')->value();

        if ($clientId === '') {
            throw ResourceNotFound::of('Client');
        }

        return $compose($resolved, $clientId);
    }
}
