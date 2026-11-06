<?php

declare(strict_types=1);

namespace App\Application\Platform\Query;

use App\Application\Platform\PlatformAccess;
use App\Application\Shared\Contract\Clock;
use App\Application\System\Contract\ProductionReadiness;
use App\Application\System\Readiness;
use App\Domain\Identity\UserId;

/**
 * Is this installation's configuration fit to serve real users — the same report `ops:production-check` prints, on the console.
 *
 * Operators only. The server's configuration belongs to whoever runs the installation, not to any one workspace, so no
 * workspace role reaches it. It is read-only, names settings and never their values, and a pass is configuration only: it is not
 * a deployment, and it says nothing about a live provider.
 */
final readonly class GetServerReadiness
{
    public function __construct(
        private PlatformAccess $access,
        private ProductionReadiness $readiness,
        private Clock $clock,
    ) {}

    /** @return array{status: string, expected_environment: string, checked_at: string, checks: list<array{key: string, status: string, detail: string}>} */
    public function __invoke(UserId $caller, string $expectedEnvironment = 'production'): array
    {
        $this->access->ensure($caller);

        $expected = in_array($expectedEnvironment, ['production', 'staging'], true) ? $expectedEnvironment : 'production';
        $checks = $this->readiness->check($expected);

        return [
            'status' => Readiness::overall($checks),
            'expected_environment' => $expected,
            'checked_at' => $this->clock->now()->format(DATE_ATOM),
            'checks' => $checks,
        ];
    }
}
