<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Access\Contract\AccessSynchroniser;
use App\Application\Advertising\Contract\AdvertisingStore;
use App\Application\Advertising\Contract\SyncAdapterRegistry as SyncAdapterRegistryContract;
use App\Application\Advertising\Contract\SyncJournal;
use App\Application\Analytics\Contract\AnalyticsReadModel;
use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Audit\Contract\AuditReadModel;
use App\Application\Budget\Contract\BenchmarkReadModel;
use App\Application\Budget\Contract\BudgetRepository;
use App\Application\Budget\Contract\BudgetSpendReadModel;
use App\Application\Client\Contract\ClientReadModel;
use App\Application\Commerce\Contract\CommerceStore;
use App\Application\Commerce\Contract\WebhookEventJournal;
use App\Application\Dashboard\Contract\DashboardRepository;
use App\Application\Dashboard\Contract\SavedViewRepository;
use App\Application\Identity\Contract\Authenticator;
use App\Application\Identity\Contract\IdentityReadModel;
use App\Application\Integration\Contract\CredentialCipher;
use App\Application\Integration\Contract\IntegrationReadModel;
use App\Application\Integration\Contract\ProviderAdapterRegistry as ProviderAdapterRegistryContract;
use App\Application\Measurement\Contract\EventDefinitionRepository;
use App\Application\Measurement\Contract\MeasurementSourceRepository;
use App\Application\Measurement\Contract\MeasurementSyncAdapterRegistry as MeasurementSyncAdapterRegistryContract;
use App\Application\Measurement\Contract\ObservedEventReadModel;
use App\Application\Measurement\Contract\ObservedEventWriter;
use App\Application\Measurement\Contract\TagContainerReadAdapter;
use App\Application\Project\Contract\ProjectMemberRepository;
use App\Application\Project\Contract\ProjectReadModel;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\ActorContextBinder;
use App\Application\Shared\Contract\ApplicationInfo;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Contract\HealthProbe;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Application\System\Query\GetSystemHealth;
use App\Application\Workspace\Contract\MemberReadModel;
use App\Application\Workspace\Contract\MembershipRepository;
use App\Domain\Client\Repository\ClientRepository;
use App\Domain\Integration\Repository\ExternalAccountRepository;
use App\Domain\Integration\Repository\IntegrationBindingRepository;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use App\Domain\Project\Repository\ProjectRepository;
use App\Infrastructure\Access\ActorVisibilityResolver;
use App\Infrastructure\Access\PermissionAuthorizationGuard;
use App\Infrastructure\Access\RequestActorContext;
use App\Infrastructure\Access\WorkspaceAccessSynchroniser;
use App\Infrastructure\Advertising\Sandbox\SandboxFixtures;
use App\Infrastructure\Advertising\SyncAdapterRegistry;
use App\Infrastructure\Audit\DatabaseAuditRecorder;
use App\Infrastructure\Identity\EloquentIdentityReadModel;
use App\Infrastructure\Identity\SessionAuthenticator;
use App\Infrastructure\Integration\LaravelCredentialCipher;
use App\Infrastructure\Integration\ProviderAdapterRegistry;
use App\Infrastructure\Measurement\Adapter\GoogleTagManagerReadAdapter;
use App\Infrastructure\Measurement\MeasurementSyncAdapterRegistry;
use App\Infrastructure\Measurement\Sandbox\MeasurementFixtures;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentAnalyticsReadModel;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentAuditReadModel;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentBenchmarkReadModel;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentBudgetSpendReadModel;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentClientReadModel;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentIntegrationReadModel;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentMemberReadModel;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentObservedEventReadModel;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentProjectReadModel;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentAdvertisingStore;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentBudgetRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentClientRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentCommerceStore;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentDashboardRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentEventDefinitionRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentExternalAccountRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentIntegrationBindingRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentMeasurementSourceRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentMembershipRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentObservedEventWriter;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentProjectMemberRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentProjectRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentProviderConnectionRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentSavedViewRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentSyncJournal;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentWebhookEventJournal;
use App\Infrastructure\Support\SystemClock;
use App\Infrastructure\Support\Uuid7Generator;
use App\Infrastructure\System\CacheHealthProbe;
use App\Infrastructure\System\DatabaseHealthProbe;
use App\Infrastructure\System\LaravelApplicationInfo;
use App\Infrastructure\System\QueueHealthProbe;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
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

    /**
     * Application interface => Infrastructure implementation.
     *
     * @var array<class-string, class-string>
     */
    private const BINDINGS = [
        ApplicationInfo::class => LaravelApplicationInfo::class,
        Clock::class => SystemClock::class,
        IdentifierGenerator::class => Uuid7Generator::class,
        AuditRecorder::class => DatabaseAuditRecorder::class,
        AuthorizationGuard::class => PermissionAuthorizationGuard::class,
        IdentityReadModel::class => EloquentIdentityReadModel::class,
        Authenticator::class => SessionAuthenticator::class,
        ClientRepository::class => EloquentClientRepository::class,
        ClientReadModel::class => EloquentClientReadModel::class,
        ProjectRepository::class => EloquentProjectRepository::class,
        ProjectReadModel::class => EloquentProjectReadModel::class,
        ProjectMemberRepository::class => EloquentProjectMemberRepository::class,
        MemberReadModel::class => EloquentMemberReadModel::class,
        MembershipRepository::class => EloquentMembershipRepository::class,
        AuditReadModel::class => EloquentAuditReadModel::class,

        // Integrations
        CredentialCipher::class => LaravelCredentialCipher::class,
        ProviderAdapterRegistryContract::class => ProviderAdapterRegistry::class,
        ProviderConnectionRepository::class => EloquentProviderConnectionRepository::class,
        ExternalAccountRepository::class => EloquentExternalAccountRepository::class,
        IntegrationBindingRepository::class => EloquentIntegrationBindingRepository::class,
        IntegrationReadModel::class => EloquentIntegrationReadModel::class,

        // Advertising data (Phase 3)
        SyncAdapterRegistryContract::class => SyncAdapterRegistry::class,
        AdvertisingStore::class => EloquentAdvertisingStore::class,
        SyncJournal::class => EloquentSyncJournal::class,

        // Analytics (Phase 4)
        AnalyticsReadModel::class => EloquentAnalyticsReadModel::class,
        VisibilityResolver::class => ActorVisibilityResolver::class,
        AccessSynchroniser::class => WorkspaceAccessSynchroniser::class,
        SavedViewRepository::class => EloquentSavedViewRepository::class,
        DashboardRepository::class => EloquentDashboardRepository::class,
        BudgetRepository::class => EloquentBudgetRepository::class,
        BudgetSpendReadModel::class => EloquentBudgetSpendReadModel::class,
        BenchmarkReadModel::class => EloquentBenchmarkReadModel::class,

        // Measurement (Phase 7)
        MeasurementSyncAdapterRegistryContract::class => MeasurementSyncAdapterRegistry::class,
        MeasurementSourceRepository::class => EloquentMeasurementSourceRepository::class,
        ObservedEventWriter::class => EloquentObservedEventWriter::class,
        /*
         * One implementation, bound directly rather than through a registry. Tag Manager is
         * the only container provider this product reads, and a registry of one is a
         * ceremony that hides that fact rather than a seam anybody needs.
         */
        TagContainerReadAdapter::class => GoogleTagManagerReadAdapter::class,

        // Commerce (Phase 11)
        CommerceStore::class => EloquentCommerceStore::class,
        WebhookEventJournal::class => EloquentWebhookEventJournal::class,
        EventDefinitionRepository::class => EloquentEventDefinitionRepository::class,
        ObservedEventReadModel::class => EloquentObservedEventReadModel::class,
    ];

    public function register(): void
    {
        foreach (self::BINDINGS as $abstract => $concrete) {
            $this->app->bind($abstract, $concrete);
        }

        $this->registerActorContext();

        // Sanctum's SPA guard is stateful; the authenticator needs that
        // contract rather than the generic Guard.
        $this->app->bind(StatefulGuard::class, static fn (): StatefulGuard => Auth::guard('web'));

        foreach (self::HEALTH_PROBES as $probe) {
            $this->app->tag($probe, HealthProbe::class);
        }

        $this->app->singleton(GetSystemHealth::class, fn ($app): GetSystemHealth => new GetSystemHealth(
            $app->make(ApplicationInfo::class),
            $app->tagged(HealthProbe::class),
        ));

        /*
         * Sandbox fixtures resolve their relative dates against injected time,
         * so a test can freeze the clock and get a predictable window.
         */
        $this->app->singleton(SandboxFixtures::class, fn ($app): SandboxFixtures => new SandboxFixtures(
            database_path('fixtures/providers'),
            $app->make(Clock::class)->now(),
        ));

        /*
         * Measurement fixtures shift their dates against the same injected time, so a sandbox
         * tracking page shows a property that reported yesterday rather than one that has been
         * silent since the fixture was written.
         */
        $this->app->singleton(MeasurementFixtures::class, fn ($app): MeasurementFixtures => new MeasurementFixtures(
            database_path('fixtures/providers'),
            $app->make(Clock::class)->now(),
        ));
    }

    public function boot(): void
    {
        $this->enforceModelDiscipline();
        $this->enforcePasswordPolicy();
        $this->guardAgainstSlowQueries();
    }

    /**
     * One actor context per request, shared by the binder, the guard and every
     * use case, so permissions are resolved once rather than per check.
     */
    private function registerActorContext(): void
    {
        $this->app->scoped(RequestActorContext::class);
        $this->app->bind(ActorContext::class, RequestActorContext::class);
        $this->app->bind(ActorContextBinder::class, RequestActorContext::class);
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
