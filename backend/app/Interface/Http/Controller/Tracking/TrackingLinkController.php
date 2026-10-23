<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Tracking;

use App\Application\Tracking\Command\SaveLinkTemplate;
use App\Application\Tracking\Command\SaveTrackingLink;
use App\Application\Tracking\Command\SetTrackingLinkStatus;
use App\Application\Tracking\DTO\LinkCounts;
use App\Application\Tracking\DTO\TrackingLinkInput;
use App\Application\Tracking\DTO\TrackingLinkView;
use App\Application\Tracking\Exception\TrackingRefused;
use App\Application\Tracking\Query\ListTrackingLinks;
use App\Domain\Tracking\LinkStatus;
use App\Interface\Http\Controller\Controller;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The agency's side of tracking links: build, read, pause. */
final class TrackingLinkController extends Controller
{
    public function index(Request $request, ListTrackingLinks $list): JsonResponse
    {
        $status = $request->query('status');

        return response()->json(['data' => array_map(
            $this->resource(...),
            $list(
                $this->id($request, 'project_id'),
                $this->id($request, 'client_id'),
                is_string($status) ? LinkStatus::tryFrom($status) : null,
            ),
        )]);
    }

    public function show(string $link, ListTrackingLinks $list): JsonResponse
    {
        return response()->json(['data' => $this->resource($list->one($link))]);
    }

    public function store(Request $request, SaveTrackingLink $save): JsonResponse
    {
        return response()->json(['data' => $this->resource($this->save($request, $save, null))], Response::HTTP_CREATED);
    }

    public function update(Request $request, string $link, SaveTrackingLink $save): JsonResponse
    {
        return response()->json(['data' => $this->resource($this->save($request, $save, $link))]);
    }

    public function status(Request $request, string $link, SetTrackingLinkStatus $set): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'string', 'in:'.implode(',', LinkStatus::values())]]);
        $set($link, LinkStatus::from((string) $data['status']));

        return response()->json(['data' => ['status' => $data['status']]]);
    }

    public function templates(ListTrackingLinks $list): JsonResponse
    {
        return response()->json(['data' => $list->templates()]);
    }

    public function saveTemplate(Request $request, SaveLinkTemplate $save): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'patterns' => ['required', 'array', 'min:1', 'max:5'],
            'patterns.*' => ['nullable', 'string', 'max:190'],
        ]);

        try {
            $save((string) $data['name'], array_map('strval', array_filter($data['patterns'], 'is_string')));
        } catch (TrackingRefused $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['data' => ['saved' => true]], Response::HTTP_CREATED);
    }

    private function save(Request $request, SaveTrackingLink $save, ?string $id): TrackingLinkView
    {
        $data = $request->validate([
            'project_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'destination_url' => ['required', 'string', 'max:2048'],
            'platform' => ['nullable', 'string', 'max:32'],
            'external_account_id' => ['nullable', 'uuid'],
            'campaign' => ['nullable', 'string', 'max:190'],
            'ad_group' => ['nullable', 'string', 'max:190'],
            'ad' => ['nullable', 'string', 'max:190'],
            'creative' => ['nullable', 'string', 'max:190'],
            'channel' => ['nullable', 'string', 'max:24'],
            'utm' => ['nullable', 'array'],
            'utm.*' => ['nullable', 'string', 'max:190'],
            'template_patterns' => ['nullable', 'array'],
            'template_patterns.*' => ['nullable', 'string', 'max:190'],
            'custom_params' => ['nullable', 'array', 'max:10'],
            'custom_params.*' => ['nullable', 'string', 'max:190'],
            'objective' => ['nullable', 'string', 'max:24'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $strings = static fn (mixed $v): array => is_array($v)
            ? array_map('strval', array_filter($v, static fn (mixed $x): bool => is_string($x) && $x !== ''))
            : [];
        $text = static fn (string $k): ?string => isset($data[$k]) && is_string($data[$k]) && $data[$k] !== '' ? $data[$k] : null;

        try {
            return $save(new TrackingLinkInput(
                projectId: (string) $data['project_id'],
                name: (string) $data['name'],
                destinationUrl: (string) $data['destination_url'],
                platform: $text('platform'),
                externalAccountId: $text('external_account_id'),
                campaign: $text('campaign'),
                adGroup: $text('ad_group'),
                ad: $text('ad'),
                creative: $text('creative'),
                channel: $text('channel') ?? 'paid',
                utm: $strings($data['utm'] ?? null),
                templatePatterns: $strings($data['template_patterns'] ?? null),
                customParams: $strings($data['custom_params'] ?? null),
                objective: $text('objective'),
                expiresAt: isset($data['expires_at']) ? new DateTimeImmutable((string) $data['expires_at']) : null,
                notes: $text('notes'),
            ), $id);
        } catch (TrackingRefused $e) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, $e->getMessage());
        }
    }

    private function id(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && preg_match('/^[0-9a-f-]{36}$/i', $value) === 1 ? $value : null;
    }

    /** @return array<string, mixed> */
    private function resource(TrackingLinkView $l): array
    {
        $base = rtrim((string) config('digitalsmart.tracking.public_base_url'), '/');

        return [
            'id' => $l->id,
            'client' => ['id' => $l->clientId, 'name' => $l->clientName],
            'project' => ['id' => $l->projectId, 'name' => $l->projectName],
            'name' => $l->name,
            'short_url' => $base.'/r/'.$l->publicToken,
            'platform' => $l->platform,
            'campaign' => $l->campaign,
            'ad_group' => $l->adGroup,
            'ad' => $l->ad,
            'creative' => $l->creative,
            'channel' => $l->channel,
            'destination_url' => $l->destinationUrl,
            'utm' => (object) $l->utm,
            'custom_params' => (object) $l->customParams,
            'objective' => $l->objective,
            'status' => $l->status->value,
            'expires_at' => $l->expiresAt?->format(DATE_ATOM),
            'notes' => $l->notes,
            'created_at' => $l->createdAt->format(DATE_ATOM),
            'counts' => $l->counts === null ? null : $this->counts($l->counts),
        ];
    }

    /** @return array<string, mixed> */
    private function counts(LinkCounts $c): array
    {
        return [
            'clicks' => ['value' => $c->clicks, 'state' => 'live'],
            'visitors' => ['value' => $c->visitors, 'state' => 'live'],
            'sessions' => ['value' => $c->sessions, 'state' => 'live'],
            'landing_views' => [
                'value' => $c->landingViews,
                'state' => $c->landingViews === null ? 'partial' : 'live',
                'reason' => $c->landingViewsNote,
            ],
            'click_id_coverage' => $c->clickIdCoverage,
            'excluded' => $c->excluded,
            'last_click_at' => $c->lastClickAt?->format(DATE_ATOM),
        ];
    }
}
