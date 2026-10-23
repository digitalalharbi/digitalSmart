<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Dispatch;

use App\Domain\Tracking\ConsentState;
use App\Domain\Tracking\TrafficClass;

/** Why a conversion was not sent. Every skip is recorded; a silent drop looks identical to a lost event. */
enum SkipReason: string
{
    case Consent = 'consent';
    case TestTraffic = 'test_traffic';
    case SuspectedBot = 'suspected_bot';
    case Duplicate = 'duplicate_traffic';
    case NoClickId = 'no_click_id';
    case NotConnected = 'not_connected';
    case LiveTransportBlocked = 'live_transport_blocked';
    case NoTrackedVisit = 'no_tracked_visit';

    /**
     * The gates that need no provider: traffic class, consent and the identifier a provider requires.
     *
     * @param  list<string>  $needsClickId  parameter names of which at least one must be present
     * @param  array<string, string>  $clickIds
     */
    public static function gate(TrafficClass $class, ConsentState $consent, ConsentPolicy $policy, array $needsClickId, array $clickIds): ?self
    {
        return match (true) {
            $class === TrafficClass::Test => self::TestTraffic,
            $class === TrafficClass::SuspectedBot => self::SuspectedBot,
            $class === TrafficClass::Duplicate => self::Duplicate,
            ! $policy->allows($consent) => self::Consent,
            $needsClickId !== [] && array_intersect_key($clickIds, array_flip($needsClickId)) === [] => self::NoClickId,
            default => null,
        };
    }
}
