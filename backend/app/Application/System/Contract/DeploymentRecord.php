<?php

declare(strict_types=1);

namespace App\Application\System\Contract;

/** The commit this installation was deployed from, as the deploy script recorded it — null when none ever was. */
interface DeploymentRecord
{
    public function deployedSha(): ?string;
}
