<?php

declare(strict_types=1);

namespace App\Infrastructure\System;

use App\Application\System\Contract\DeploymentRecord;

/** Reads `shared/DEPLOYED_SHA`, which the deploy script writes after a healthy switch (docs/DEPLOYMENT.md). */
final readonly class FileDeploymentRecord implements DeploymentRecord
{
    public function __construct(private ?string $path) {}

    public function deployedSha(): ?string
    {
        if ($this->path === null || $this->path === '' || ! is_readable($this->path)) {
            return null;
        }

        $sha = trim((string) file_get_contents($this->path));

        return preg_match('/^[0-9a-f]{7,40}$/', $sha) === 1 ? substr($sha, 0, 7) : null;
    }
}
