<?php

declare(strict_types=1);

namespace App\Application\Tracking\Command;

use App\Application\Shared\Contract\Clock;
use App\Application\Tracking\Contract\OpaqueTokens;
use App\Application\Tracking\Contract\TrackingEventStore;
use App\Application\Tracking\Contract\TrackingLinkRepository;
use App\Domain\Tracking\BotHeuristic;
use App\Domain\Tracking\ClickIdCatalogue;
use App\Domain\Tracking\ConsentState;
use App\Domain\Tracking\DestinationUrl;
use App\Domain\Tracking\Exception\UnsafeDestination;
use Throwable;

/**
 * Somebody followed a tracking link.
 *
 * Public and unauthenticated, so it is built around what it will not do: it never reads a destination from the request,
 * never says why a link did not work (an expired link, a paused one and one that never existed all answer the same, so
 * the response discloses nothing about the tenant), and never fails the visitor over a recording problem — the click is
 * lost, the person still arrives.
 */
final readonly class FollowTrackingLink
{
    /** @param list<string> $ownHosts */
    public function __construct(
        private TrackingLinkRepository $links,
        private TrackingEventStore $events,
        private OpaqueTokens $tokens,
        private Clock $clock,
        private array $ownHosts = [],
    ) {}

    /**
     * @param  array<string, mixed>  $query  the query string of the tracking link as followed
     * @return array{url: string, visitor_id: string}|null null = nothing to redirect to
     */
    public function __invoke(string $token, array $query, ?string $userAgent, ?string $referer, ?string $visitorCookie): ?array
    {
        $link = $this->links->findTarget($token);
        $now = $this->clock->now();

        if ($link === null || ! $link->status->redirects($link->expiresAt, $now)) {
            return null;
        }

        try {
            $destination = DestinationUrl::parse($link->destinationUrl, $this->ownHosts);
        } catch (UnsafeDestination) {
            return null;
        }

        $visitor = $visitorCookie !== null && preg_match('/^[A-Za-z0-9]{20}$/', $visitorCookie) === 1
            ? $visitorCookie
            : $this->tokens->make(20);
        $session = $this->tokens->make(20);
        $touchpoint = $this->tokens->make(20);
        $clickIds = ClickIdCatalogue::from($query);

        try {
            $this->events->recordClick(
                $link, $visitor, $session, $touchpoint,
                $this->tokens->make(24), $this->tokens->make(24),
                BotHeuristic::classify($userAgent),
                ConsentState::Unknown,
                $this->hostOf($referer),
                BotHeuristic::deviceClass($userAgent),
                BotHeuristic::browserClass($userAgent),
                (string) parse_url($destination->value, PHP_URL_PATH),
                $clickIds,
                $now,
            );
        } catch (Throwable $e) {
            report($e);
        }

        $params = [...$link->utm, ...$link->customParams, ...$clickIds, 'ds_tp' => $touchpoint];

        return ['url' => $destination->with($params), 'visitor_id' => $visitor];
    }

    private function hostOf(?string $referer): ?string
    {
        $host = $referer === null ? null : parse_url($referer, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? mb_substr(strtolower($host), 0, 190) : null;
    }
}
