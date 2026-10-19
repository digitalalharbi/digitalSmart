<?php

declare(strict_types=1);

namespace App\Application\Agents\Agent;

use App\Application\Measurement\Query\GetTrackingHealth;

/**
 * The daily tracking review: every measurement source checked, its issues counted by severity, and the run recorded.
 *
 * It opens no cards of its own — tracking issues are already on Today, read live from the same checks — so the
 * routine is the dated record that the review happened and what it found, not a second copy of the same alerts.
 */
final readonly class TrackingReview
{
    public function __construct(private GetTrackingHealth $health) {}

    /** @return array{looked_at: int, issues: int, critical: int, healthy: bool} */
    public function act(): array
    {
        $view = ($this->health)();

        return [
            'looked_at' => count($view->sources),
            'issues' => count($view->issues),
            'critical' => $view->criticalCount(),
            'healthy' => $view->isHealthy(),
        ];
    }
}
