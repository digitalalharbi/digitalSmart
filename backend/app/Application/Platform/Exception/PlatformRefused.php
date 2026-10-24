<?php

declare(strict_types=1);

namespace App\Application\Platform\Exception;

use DomainException;

/** A platform request that cannot be carried out, with what to change. */
final class PlatformRefused extends DomainException
{
    public static function reasonRequired(): self
    {
        return new self('Give a reason. The people in the workspace are shown it when their workspace stops, and the trail keeps it.');
    }

    public static function slugTaken(string $slug): self
    {
        return new self(sprintf('The address "%s" is already used by another workspace.', $slug));
    }

    public static function unknownPlan(string $code): self
    {
        return new self(sprintf('There is no plan "%s".', $code));
    }

    public static function newWorkspaceStatus(): self
    {
        return new self('A new workspace starts on a trial or active.');
    }

    public static function trialNeedsEnd(): self
    {
        return new self('A trial needs an end date in the future.');
    }
}
