<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Shared\Contract\ApplicationInfo;
use App\Application\Shared\Contract\HealthProbe;
use App\Application\System\Query\GetSystemHealth;
use App\Infrastructure\System\CacheHealthProbe;
use App\Infrastructure\System\DatabaseHealthProbe;
use App\Infrastructure\System\LaravelApplicationInfo;
use App\Infrastructure\System\QueueHealthProbe;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

/**
 * Composition root.
 *
 * This is the only place allowed to know about both an Application interface and
 * an Infrastructure implementation.
 */
final class AppServiceProvider extends ServiceProvider
{
    /** Probes composing the system health report, in display order. */
    private const HEALTH_PROBES = [
        DatabaseHealthProbe::class,
        CacheHealthProbe::class,
        QueueHealthProbe::class,
    ];

    public function register(): void
    {
        $this->app->bind(ApplicationInfo::class, LaravelApplicationInfo::class);

        foreach (self::HEALTH_PROBES as $probe) {
            $this->app->tag($probe, HealthProbe::class);
        }

        $this->app->singleton(GetSystemHealth::class, fn ($app): GetSystemHealth => new GetSystemHealth(
            $app->make(ApplicationInfo::class),
            $app->tagged(HealthProbe::class),
        ));
    }

    public function boot(): void
    {
        $this->enforceModelDiscipline();
        $this->enforcePasswordPolicy();
        $this->guardAgainstSlowQueries();
    }

    /**
     * Fail loudly in development instead of silently shipping N+1 queries or
     * mass-assignment holes.
     */
    private function enforceModelDiscipline(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        Model::preventAccessingMissingAttributes(! $this->app->isProduction());
        Model::unguard(false);
    }

    private function enforcePasswordPolicy(): void
    {
        Password::defaults(fn (): Password => $this->app->isProduction()
            ? Password::min(12)->letters()->mixedCase()->numbers()->symbols()->uncompromised()
            : Password::min(8)->letters()->numbers());
    }

    /** Query profiling: surface slow queries rather than discovering them in production. */
    private function guardAgainstSlowQueries(): void
    {
        if ($this->app->runningUnitTests()) {
            return;
        }

        $threshold = (int) config('digitalsmart.slow_query_threshold_ms', 500);

        DB::whenQueryingForLongerThan($threshold, function ($connection) use ($threshold): void {
            Log::warning('Database query budget exceeded.', [
                'connection' => $connection->getName(),
                'threshold_ms' => $threshold,
            ]);
        });
    }
}
