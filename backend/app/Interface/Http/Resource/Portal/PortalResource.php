<?php

declare(strict_types=1);

namespace App\Interface\Http\Resource\Portal;

use App\Application\Portal\DTO\ModuleExposure;
use App\Application\Portal\DTO\PortalSummary;
use App\Application\Portal\DTO\PortalView;
use App\Domain\Portal\AlwaysAvailable;
use App\Domain\Portal\PortalModule;
use App\Domain\Portal\WithheldModule;

/**
 * The portal, as the agency's own surface reads it.
 *
 * The vocabulary carries **both lists**: the modules that can be exposed, and the surfaces that never will be with
 * the reason for each. A configuration screen that showed only the first list would make every refusal look like a
 * feature nobody had got round to, and that question would then be asked once a quarter forever.
 */
final class PortalResource
{
    /** @return array<string, mixed> */
    public static function vocabulary(): array
    {
        return [
            'modules' => array_map(
                static fn (PortalModule $module): array => [
                    'module' => $module->value,
                    'label' => $module->label(),
                    'description' => $module->description(),
                    'prerequisite' => $module->prerequisite(),
                ],
                PortalModule::cases(),
            ),
            /*
             * The mirror of the list below: surfaces a client can always reach, so there is no switch for them.
             * Shown for the same reason — the answer should not depend on who is asked.
             */
            'always_available' => array_map(
                static fn (AlwaysAvailable $module): array => [
                    'module' => $module->value,
                    'label' => $module->label(),
                    'because' => $module->because(),
                ],
                AlwaysAvailable::cases(),
            ),
            /* Shown, not hidden. See the note at the top of `WithheldModule`. */
            'withheld' => array_map(
                static fn (WithheldModule $module): array => [
                    'module' => $module->value,
                    'label' => $module->label(),
                    'because' => $module->because(),
                ],
                WithheldModule::cases(),
            ),
        ];
    }

    /** @return array<string, mixed> */
    public static function summary(PortalSummary $summary): array
    {
        return [
            'client_id' => $summary->clientId,
            'client_name' => $summary->clientName,
            'state' => $summary->state->value,
            'state_label' => $summary->state->label(),
            'exposed_count' => $summary->exposedCount,
            /*
             * Exposed with nothing behind it. Its own figure on the list rather than something a reader works out
             * by opening each client, because it is the figure that embarrasses somebody.
             */
            'empty_count' => $summary->emptyCount,
        ];
    }

    /** @return array<string, mixed> */
    public static function detail(PortalView $view): array
    {
        return [
            'client_id' => $view->clientId,
            'client_name' => $view->clientName,
            'state' => $view->state->value,
            'state_label' => $view->state->label(),
            'enabled_at' => $view->enabledAt?->format(DATE_ATOM),
            'enabled_by_name' => $view->enabledByName,
            'suspended_at' => $view->suspendedAt?->format(DATE_ATOM),
            'suspended_by_name' => $view->suspendedByName,
            /*
             * The agency's own note, on the agency's own surface. It is never part of the client's read — see
             * `PortalState::visitorMessage()`, which is what a client is told instead.
             */
            'suspended_reason' => $view->suspendedReason,
            'modules' => array_map(
                static fn (ModuleExposure $exposure): array => [
                    'module' => $exposure->module->value,
                    'label' => $exposure->module->label(),
                    'description' => $exposure->module->description(),
                    'is_exposed' => $exposure->isExposed,
                    'exposed_at' => $exposure->exposedAt?->format(DATE_ATOM),
                    'exposed_by_name' => $exposure->exposedByName,
                    'prerequisite' => $exposure->module->prerequisite(),
                    'is_prerequisite_met' => $exposure->isPrerequisiteMet,
                ],
                $view->modules,
            ),
            'empty_count' => count($view->exposedButEmpty()),
        ];
    }
}
