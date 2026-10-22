<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Notifications;

use App\Application\Notifications\Command\Dismiss;
use App\Application\Notifications\Command\MarkAllRead;
use App\Application\Notifications\Command\MarkClicked;
use App\Application\Notifications\Command\MarkRead;
use App\Application\Notifications\Command\SaveNotificationPreferences;
use App\Application\Notifications\DTO\NotificationPreferences;
use App\Application\Notifications\Query\GetNotificationPreferences;
use App\Application\Notifications\Query\GetNotifications;
use App\Domain\Notifications\DigestChannel;
use App\Domain\Notifications\NotificationCategory;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Resource\Notifications\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * One person's notifications.
 *
 * There is no permission on these routes, and that is correct rather than an omission: every row is scoped to
 * the reader's own user id, and the permission that decided whether they could be told was checked when the
 * notification was written. Gating the read again would mean somebody who lost a permission could no longer see
 * a notification they had already been sent — the record of having been told would vanish, which is worse than
 * never having been told.
 */
final class NotificationController extends Controller
{
    public function index(Request $request, GetNotifications $query): JsonResponse
    {
        return response()->json([
            'data' => NotificationResource::feed($query(
                $request->boolean('unread'),
                $request->integer('limit') ?: null,
                NotificationCategory::tryFrom($request->string('category')->value()),
            )),
        ]);
    }

    public function vocabulary(): JsonResponse
    {
        return response()->json(['data' => NotificationResource::vocabulary()]);
    }

    public function read(string $notification, MarkRead $mark, GetNotifications $query): JsonResponse
    {
        $mark($notification);

        /*
         * The whole feed back, not just the row. Marking one read changes the counts above the list, and a
         * client left to decrement them itself would be running a second implementation of the arithmetic —
         * one that does not know that only some kinds count as waiting.
         */
        return response()->json(['data' => NotificationResource::feed($query())]);
    }

    public function clicked(string $notification, MarkClicked $mark, GetNotifications $query): JsonResponse
    {
        $mark($notification);

        return response()->json(['data' => NotificationResource::feed($query())]);
    }

    public function dismiss(string $notification, Dismiss $dismiss, GetNotifications $query): JsonResponse
    {
        $dismiss($notification);

        return response()->json(['data' => NotificationResource::feed($query())]);
    }

    public function preferences(GetNotificationPreferences $query): JsonResponse
    {
        return response()->json(['data' => NotificationResource::preferences($query())]);
    }

    public function savePreferences(Request $request, SaveNotificationPreferences $save): JsonResponse
    {
        $validated = $request->validate([
            'daily' => ['required', 'boolean'],
            'weekly' => ['required', 'boolean'],
            'monthly' => ['required', 'boolean'],
            'channels' => ['present', 'array'],
            'channels.*' => ['string', Rule::in(DigestChannel::values())],
            'timezone' => ['nullable', 'string', 'max:64'],
            'hour' => ['required', 'integer', 'between:0,23'],
            'client_id' => ['nullable', 'uuid'],
            'project_ids' => ['nullable', 'array'],
            'project_ids.*' => ['uuid'],
            'muted_categories' => ['present', 'array'],
            'muted_categories.*' => ['string', Rule::in(NotificationCategory::values())],
        ]);

        /** @var list<string> $channels */
        $channels = array_values(array_unique($validated['channels']));
        /** @var list<string> $muted */
        $muted = array_values(array_unique($validated['muted_categories']));
        /** @var list<string>|null $projects */
        $projects = isset($validated['project_ids']) ? array_values(array_unique($validated['project_ids'])) : null;

        $saved = $save(new NotificationPreferences(
            daily: (bool) $validated['daily'],
            weekly: (bool) $validated['weekly'],
            monthly: (bool) $validated['monthly'],
            channels: array_map(static fn (string $c): DigestChannel => DigestChannel::from($c), $channels),
            timezone: isset($validated['timezone']) && $validated['timezone'] !== '' ? (string) $validated['timezone'] : null,
            hour: (int) $validated['hour'],
            clientId: isset($validated['client_id']) ? (string) $validated['client_id'] : null,
            projectIds: $projects,
            mutedCategories: array_map(static fn (string $c): NotificationCategory => NotificationCategory::from($c), $muted),
        ));

        return response()->json(['data' => NotificationResource::preferences($saved)]);
    }

    public function readAll(MarkAllRead $mark, GetNotifications $query): JsonResponse
    {
        $cleared = $mark();

        return response()->json([
            'data' => NotificationResource::feed($query()),
            'meta' => ['cleared' => $cleared],
        ]);
    }
}
