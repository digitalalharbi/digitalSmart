<?php

declare(strict_types=1);

namespace App\Infrastructure\System;

use App\Application\Shared\Contract\HealthProbe;
use App\Application\Shared\DTO\ServiceHealth;
use Illuminate\Database\DatabaseManager;
use PDO;
use Throwable;

/** Verifies the primary PostgreSQL connection answers a trivial query. */
final readonly class DatabaseHealthProbe implements HealthProbe
{
    public function __construct(private DatabaseManager $database) {}

    public function probe(): ServiceHealth
    {
        try {
            $connection = $this->database->connection();
            $connection->select('select 1');

            $driver = $connection->getDriverName();
            $version = $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);

            return ServiceHealth::ok('database', sprintf('%s %s', $driver, is_scalar($version) ? (string) $version : 'unknown'));
        } catch (Throwable $exception) {
            // The database is not optional: without it nothing can be served.
            return ServiceHealth::down('database', $exception->getMessage());
        }
    }
}
