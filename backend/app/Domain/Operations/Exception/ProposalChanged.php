<?php

declare(strict_types=1);

namespace App\Domain\Operations\Exception;

use RuntimeException;

/**
 * A decision about a proposal that has since been amended.
 *
 * Refused, and answered with what the proposal says now. The alternative is a record claiming somebody
 * approved a change they never read — a quiet race that looks exactly like a correct approval afterwards, and
 * the reason `ProposalFingerprint` exists at all.
 */
final class ProposalChanged extends RuntimeException
{
    public function __construct(
        public readonly string $currentFingerprint,
        public readonly ?string $sentFingerprint,
    ) {
        parent::__construct(
            $sentFingerprint === null
                ? 'Say which version of the proposal you are deciding on. Without it there is no way to know '
                    .'whether you read the one that is there now.'
                : 'This proposal was amended after you opened it. Read it again before deciding: the record '
                    .'has to say that whoever approved this saw what they approved.',
        );
    }
}
