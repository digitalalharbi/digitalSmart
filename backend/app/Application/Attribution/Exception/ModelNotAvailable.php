<?php

declare(strict_types=1);

namespace App\Application\Attribution\Exception;

use App\Domain\Attribution\AttributionModel;
use RuntimeException;

/**
 * A model was asked for that cannot be computed from the data this product holds.
 *
 * Refusing is the point. The alternative — computing something shaped like a time-decay model from a
 * single recorded touch — produces a number that looks like the thing it is named after and is not
 * it, and nobody downstream can tell. The message carries what is missing and what would unblock it,
 * because the person asking is usually about to decide whether it is worth paying for.
 */
final class ModelNotAvailable extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly AttributionModel $model,
        public readonly ?string $missing,
        public readonly ?string $wouldUnblock,
    ) {
        parent::__construct($message);
    }

    public static function of(AttributionModel $model): self
    {
        $requirement = $model->requires();

        return new self(
            sprintf(
                '%s attribution cannot be computed. %s',
                $model->label(),
                $requirement->missing() ?? 'The data it needs is not available.',
            ),
            $model,
            $requirement->missing(),
            $requirement->wouldUnblock(),
        );
    }
}
