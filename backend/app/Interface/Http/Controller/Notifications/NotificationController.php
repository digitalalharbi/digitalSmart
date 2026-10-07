<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Notifications;

use App\Application\Notifications\Command\MarkAllRead;
use App\Application\Notifications\Command\MarkRead;
use App\Application\Notifications\Query\GetNotifications;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Resource\Notifications\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function readAll(MarkAllRead $mark, GetNotifications $query): JsonResponse
    {
        $cleared = $mark();

        return response()->json([
            'data' => NotificationResource::feed($query()),
            'meta' => ['cleared' => $cleared],
        ]);
    }
}
