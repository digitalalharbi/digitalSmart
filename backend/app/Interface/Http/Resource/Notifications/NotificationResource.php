<?php

declare(strict_types=1);

namespace App\Interface\Http\Resource\Notifications;

use App\Application\Notifications\DTO\NotificationFeed;
use App\Application\Notifications\DTO\NotificationPreferences;
use App\Application\Notifications\DTO\NotificationView;
use App\Domain\Notifications\DigestChannel;
use App\Domain\Notifications\NotificationCategory;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\NotificationSeverity;
use DateTimeImmutable;

final readonly class NotificationResource
{
    /** @return array<string, mixed> */
    public static function feed(NotificationFeed $feed): array
    {
        $digest = $feed->digest;

        return [
            'notifications' => array_map(
                static fn (NotificationView $view): array => self::one($view) + [
                    /* The channels this was carried over and what became of each; absent for the bell alone. */
                    'deliveries' => $feed->deliveries[$view->id] ?? [],
                ],
                $feed->notifications,
            ),
            'digest' => [
                'total' => $digest->total,
                'unread' => $digest->unread,
                /*
                 * The figure a bell should carry. "Twelve unread" measures how long somebody has been away;
                 * "two waiting on you" is a to-do list.
                 */
                'waiting' => $digest->waiting,
                'unread_by_kind' => $digest->unreadByKind,
                'unread_by_category' => $digest->unreadByCategory,
                'unread_by_severity' => $digest->unreadBySeverity,
            ],
            /*
             * Whether this session is being pushed to or is polling. Published rather than assumed: a page that
             * implied it was live while polling is a page where somebody waits for an update that never comes.
             */
            'is_live' => $feed->isLive,
        ];
    }

    /** @return array<string, mixed> */
    public static function one(NotificationView $view): array
    {
        return [
            'id' => $view->id,
            'kind' => $view->kind->value,
            'kind_label' => $view->kind->label(),
            'category' => $view->kind->category()->value,
            'category_label' => $view->kind->category()->label(),
            /* Decided by a rule when it was announced, and stored: a badge that agrees with the list under it. */
            'severity' => $view->severity->value,
            'severity_label' => $view->severity->label(),
            /* The figures a card draws. Null for the kinds that carry a sentence and a link only. */
            'payload' => $view->payload,
            'needs_action' => $view->kind->needsAction(),
            /* The sentence as it was announced. Never re-rendered — see the migration's note. */
            'title' => $view->title,
            'body' => $view->body,
            'subject' => [
                'type' => $view->subject->value,
                'type_label' => $view->subject->label(),
                'id' => $view->subjectId,
                'label' => $view->subjectLabel,
                /* False when the thing has been deleted. There is no foreign key behind it. */
                'exists' => $view->subjectExists,
                'path' => $view->path(),
            ],
            'actor_name' => $view->actorName,
            'is_unread' => $view->isUnread(),
            'is_waiting' => $view->isWaiting(),
            'read_at' => $view->readAt?->format(DateTimeImmutable::ATOM),
            'clicked_at' => $view->clickedAt?->format(DateTimeImmutable::ATOM),
            'dismissed_at' => $view->dismissedAt?->format(DateTimeImmutable::ATOM),
            'created_at' => $view->createdAt->format(DateTimeImmutable::ATOM),
            /*
             * The recipient is deliberately absent. A reader is always the recipient, and a field nobody needs
             * in a payload is a field somebody eventually relies on.
             */
        ];
    }

    /** @return array<string, mixed> */
    public static function preferences(NotificationPreferences $preferences): array
    {
        return [
            'daily' => $preferences->daily,
            'weekly' => $preferences->weekly,
            'monthly' => $preferences->monthly,
            'channels' => array_map(static fn (DigestChannel $c): string => $c->value, $preferences->channels),
            'timezone' => $preferences->timezone,
            'hour' => $preferences->hour,
            'client_id' => $preferences->clientId,
            'project_ids' => $preferences->projectIds,
            'muted_categories' => array_map(static fn (NotificationCategory $c): string => $c->value, $preferences->mutedCategories),
            /* What each channel can do today, in the product's own words: nothing is promised that cannot be sent. */
            'channel_availability' => array_map(static fn (DigestChannel $c): array => [
                'channel' => $c->value,
                'label' => $c->label(),
                'availability' => $c->availability(),
            ], DigestChannel::cases()),
        ];
    }

    /** @return array<string, mixed> */
    public static function vocabulary(): array
    {
        return [
            'kinds' => array_map(static fn (NotificationKind $kind): array => [
                'kind' => $kind->value,
                'label' => $kind->label(),
                'needs_action' => $kind->needsAction(),
                /* The permission a recipient must hold. Published because it explains who gets told what. */
                'requires' => $kind->requires()->value,
                'subject' => $kind->subject()->value,
                'category' => $kind->category()->value,
            ], NotificationKind::cases()),
            'categories' => array_map(static fn (NotificationCategory $category): array => [
                'category' => $category->value,
                'label' => $category->label(),
            ], NotificationCategory::cases()),
            'severities' => array_map(static fn (NotificationSeverity $severity): array => [
                'severity' => $severity->value,
                'label' => $severity->label(),
                'rank' => $severity->rank(),
            ], NotificationSeverity::cases()),
        ];
    }
}
