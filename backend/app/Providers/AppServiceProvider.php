<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Access\Contract\AccessSynchroniser;
use App\Application\Activity\Contract\ActivityReadModel;
use App\Application\Advertising\Command\RequestCampaignStatusChange as RequestStatusChangeUseCase;
use App\Application\Advertising\Contract\AdvertisingStore;
use App\Application\Advertising\Contract\CampaignAssignmentRepository;
use App\Application\Advertising\Contract\CampaignControlReadModel;
use App\Application\Advertising\Contract\CampaignDraftRepository;
use App\Application\Advertising\Contract\CampaignHierarchyReadModel;
use App\Application\Advertising\Contract\SyncAdapterRegistry as SyncAdapterRegistryContract;
use App\Application\Advertising\Contract\SyncJournal;
use App\Application\Agents\Contract\AgentActors;
use App\Application\Agents\Contract\AgentReadModel;
use App\Application\Agents\Contract\AgentRunLog;
use App\Application\Agents\Contract\FindingLog;
use App\Application\Agents\Contract\OperatingRuleRepository;
use App\Application\Agents\Contract\ProposalHistory;
use App\Application\Agents\Contract\ToolCallLog;
use App\Application\Agents\Tool\CreateTaskTool;
use App\Application\Agents\Tool\GetAttentionItemsTool;
use App\Application\Agents\Tool\GetBudgetStatusTool;
use App\Application\Agents\Tool\GetCampaignTool;
use App\Application\Agents\Tool\GetLeadQualityTool;
use App\Application\Agents\Tool\GetOperatingRulesTool;
use App\Application\Agents\Tool\ListAdSignalsTool;
use App\Application\Agents\Tool\ListCampaignsTool;
use App\Application\Agents\Tool\ListClientsTool;
use App\Application\Agents\Tool\ProposeBudgetChangeTool;
use App\Application\Agents\Tool\ProposeStatusChangeTool;
use App\Application\Agents\Tool\StatusEvidence;
use App\Application\Agents\Tool\ToolCaller;
use App\Application\Agents\Tool\ToolGateway;
use App\Application\Analytics\Contract\AnalyticsReadModel;
use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Attention\Contract\ActorMemberships;
use App\Application\Attention\Contract\AttentionSource;
use App\Application\Attention\Query\GetAttentionQueue;
use App\Application\Attention\Source\AgentAttentionSource;
use App\Application\Attention\Source\ApprovalAttentionSource;
use App\Application\Attention\Source\AutomationAttentionSource;
use App\Application\Attention\Source\BudgetAttentionSource;
use App\Application\Attention\Source\CampaignAttentionSource;
use App\Application\Attention\Source\RequestAttentionSource;
use App\Application\Attention\Source\TaskAttentionSource;
use App\Application\Attention\Source\TrackingAttentionSource;
use App\Application\Attribution\Contract\AttributionReadModel;
use App\Application\Audit\Contract\AuditReadModel;
use App\Application\Automation\Contract\AutomationRuleRepository;
use App\Application\Automation\Contract\RuleExecutionLog;
use App\Application\Benchmark\Contract\ClientIndustryDirectory;
use App\Application\Budget\Contract\BenchmarkReadModel;
use App\Application\Budget\Contract\BudgetControlReadModel;
use App\Application\Budget\Contract\BudgetRepository;
use App\Application\Budget\Contract\BudgetSpendReadModel;
use App\Application\Client\Contract\ClientOffboarding;
use App\Application\Client\Contract\ClientReadModel;
use App\Application\Client\Contract\ClientVisibility;
use App\Application\Commerce\Contract\CommerceStore;
use App\Application\Commerce\Contract\CommerceSyncAdapterRegistry as CommerceSyncAdapterRegistryContract;
use App\Application\Commerce\Contract\StoreAnalyticsReadModel;
use App\Application\Commerce\Contract\StoreDirectory;
use App\Application\Commerce\Contract\StoreLocator;
use App\Application\Commerce\Contract\StoreOrderMapperRegistry as StoreOrderMapperRegistryContract;
use App\Application\Commerce\Contract\WebhookEventJournal;
use App\Application\Commerce\Contract\WebhookEventReader;
use App\Application\Commerce\Contract\WebhookSignatureVerifier;
use App\Application\Creative\Contract\CreativeRepository;
use App\Application\Creative\Contract\CreativeSpecRegistry;
use App\Application\Dashboard\Contract\DashboardRepository;
use App\Application\Dashboard\Contract\SavedViewRepository;
use App\Application\Digest\Contract\DigestStore;
use App\Application\Export\Contract\ExportFileStorage;
use App\Application\Export\Contract\ExportJobStore;
use App\Application\Export\Contract\ExportRenderer;
use App\Application\Export\Contract\ScheduleStore;
use App\Application\Identity\Contract\Authenticator;
use App\Application\Identity\Contract\IdentityReadModel;
use App\Application\Integration\Contract\ConnectionDirectory;
use App\Application\Integration\Contract\CredentialCipher;
use App\Application\Integration\Contract\IntegrationReadModel;
use App\Application\Integration\Contract\OperationsReadModel;
use App\Application\Integration\Contract\ProviderAdapterRegistry as ProviderAdapterRegistryContract;
use App\Application\Integration\Contract\ProviderWriterRegistry;
use App\Application\Integration\Contract\SyncCandidateSource;
use App\Application\Intelligence\Analyser\DeliveryStoppedAnalyser;
use App\Application\Intelligence\Analyser\EfficiencyDriftAnalyser;
use App\Application\Intelligence\Analyser\PeriodShapeAnalyser;
use App\Application\Intelligence\Analyser\SpendConcentrationAnalyser;
use App\Application\Intelligence\Analyser\TrackingBlindSpotAnalyser;
use App\Application\Intelligence\Contract\Analyser;
use App\Application\Intelligence\Contract\LanguageModel;
use App\Application\Intelligence\Contract\ModelCredentialStore;
use App\Application\Intelligence\Contract\ModelRouter;
use App\Application\Intelligence\Query\Analyse;
use App\Application\Intelligence\Service\AnalysisAssembler;
use App\Application\Intelligence\Service\FigureScanner;
use App\Application\Intelligence\Service\Narrator;
use App\Application\Journeys\Contract\JourneyReadModel;
use App\Application\Leads\Contract\CrmReadAdapter;
use App\Application\Leads\Contract\LeadReadModel;
use App\Application\Leads\Contract\LeadRepository;
use App\Application\Measurement\Contract\EventDefinitionRepository;
use App\Application\Measurement\Contract\MeasurementSourceRepository;
use App\Application\Measurement\Contract\MeasurementSyncAdapterRegistry as MeasurementSyncAdapterRegistryContract;
use App\Application\Measurement\Contract\ObservedEventReadModel;
use App\Application\Measurement\Contract\ObservedEventWriter;
use App\Application\Measurement\Contract\TagContainerReadAdapter;
use App\Application\Notifications\Contract\NotificationBroadcaster;
use App\Application\Notifications\Contract\NotificationStore;
use App\Application\Notifications\Contract\RecipientResolver;
use App\Application\Operations\Command\IssueApprovalLink;
use App\Application\Operations\Command\UseApprovalLink;
use App\Application\Operations\Contract\ApprovalLinkDelivery;
use App\Application\Operations\Contract\ApprovalLinkRepository;
use App\Application\Operations\Contract\ApprovalRepository;
use App\Application\Operations\Contract\AttachmentStore;
use App\Application\Operations\Contract\BoardSignaller;
use App\Application\Operations\Contract\ClientRequestRepository;
use App\Application\Operations\Contract\TaskRepository;
use App\Application\Portal\Contract\PortalRepository;
use App\Application\Portfolio\Contract\PortfolioReadModel;
use App\Application\Presentation\Contract\DeckWriter;
use App\Application\Project\Contract\ProjectMemberRepository;
use App\Application\Project\Contract\ProjectReadModel;
use App\Application\Project\Contract\ReadinessFacts;
use App\Application\Reporting\Contract\ReportRepository;
use App\Application\Reporting\Contract\ReportShareStore;
use App\Application\Reporting\Contract\ReportWorkbookWriter;
use App\Application\Reporting\Contract\SnapshotSerialiser;
use App\Application\Search\Contract\SearchReadModel;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\ActorContextBinder;
use App\Application\Shared\Contract\ApplicationInfo;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Contract\HealthProbe;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Application\Shared\Contract\OwnerDirectory;
use App\Application\Shared\Contract\WorkLedger;
use App\Application\Shortcuts\Contract\ShortcutStore;
use App\Application\SurfaceView\Contract\SurfaceViewRepository;
use App\Application\System\Query\GetSystemHealth;
use App\Application\Tags\Contract\TagStore;
use App\Application\Workspace\Contract\AssignedWorkRepository;
use App\Application\Workspace\Contract\MemberDirectory;
use App\Application\Workspace\Contract\MemberReadModel;
use App\Application\Workspace\Contract\MembershipRepository;
use App\Application\Workspace\Contract\RoleReadModel;
use App\Domain\Client\Repository\ClientRepository;
use App\Domain\Integration\Repository\ExternalAccountRepository;
use App\Domain\Integration\Repository\IntegrationBindingRepository;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use App\Domain\Integration\WriteOperation;
use App\Domain\Project\Repository\ProjectRepository;
use App\Infrastructure\Access\ActorVisibilityResolver;
use App\Infrastructure\Access\EloquentOwnerDirectory;
use App\Infrastructure\Access\PermissionAuthorizationGuard;
use App\Infrastructure\Access\RequestActorContext;
use App\Infrastructure\Access\WorkspaceAccessSynchroniser;
use App\Infrastructure\Advertising\Sandbox\SandboxFixtures;
use App\Infrastructure\Advertising\SyncAdapterRegistry;
use App\Infrastructure\Agents\EloquentAgentIdentity;
use App\Infrastructure\Agents\EloquentAgentReadModel;
use App\Infrastructure\Agents\EloquentAgentRunLog;
use App\Infrastructure\Agents\EloquentFindingLog;
use App\Infrastructure\Audit\DatabaseAuditRecorder;
use App\Infrastructure\Commerce\CommerceSyncAdapterRegistry;
use App\Infrastructure\Commerce\Sandbox\CommerceFixtures;
use App\Infrastructure\Commerce\StoreOrderMapperRegistry;
use App\Infrastructure\Commerce\Webhook\StoreWebhookVerifiers;
use App\Infrastructure\Creative\EloquentCreativeSpecRegistry;
use App\Infrastructure\Export\EloquentExportJobStore;
use App\Infrastructure\Export\EloquentScheduleStore;
use App\Infrastructure\Export\LocalExportFileStorage;
use App\Infrastructure\Identity\EloquentIdentityReadModel;
use App\Infrastructure\Identity\SessionAuthenticator;
use App\Infrastructure\Integration\LaravelCredentialCipher;
use App\Infrastructure\Integration\ProviderAdapterRegistry;
use App\Infrastructure\Integration\Writer\CampaignWriterRegistry;
use App\Infrastructure\Intelligence\AnthropicLanguageModel;
use App\Infrastructure\Intelligence\ConfiguredModelRouter;
use App\Infrastructure\Intelligence\EloquentModelCredentialStore;
use App\Infrastructure\Intelligence\NoLanguageModel;
use App\Infrastructure\Leads\Adapter\HubSpotCrmReadAdapter;
use App\Infrastructure\Measurement\Adapter\GoogleTagManagerReadAdapter;
use App\Infrastructure\Measurement\MeasurementSyncAdapterRegistry;
use App\Infrastructure\Measurement\Sandbox\MeasurementFixtures;
use App\Infrastructure\Notifications\EloquentNotificationStore;
use App\Infrastructure\Notifications\PermissionAwareRecipientResolver;
use App\Infrastructure\Notifications\ReverbNotificationBroadcaster;
use App\Infrastructure\Operations\BroadcastBoardSignaller;
use App\Infrastructure\Operations\ChannelApprovalLinkDelivery;
use App\Infrastructure\Operations\EloquentApprovalLinkRepository;
use App\Infrastructure\Operations\LocalAttachmentStore;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentActivityReadModel;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentActorMemberships;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentAnalyticsReadModel;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentAttributionReadModel;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentAuditReadModel;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentBenchmarkReadModel;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentBudgetControlReadModel;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentBudgetSpendReadModel;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentCampaignControlReadModel;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentCampaignHierarchyReadModel;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentClientIndustryDirectory;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentClientReadModel;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentClientVisibility;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentConnectionDirectory;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentIntegrationReadModel;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentJourneyReadModel;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentLeadReadModel;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentMemberReadModel;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentObservedEventReadModel;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentOperationsReadModel;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentPortfolioReadModel;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentProjectReadModel;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentReadinessFacts;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentRoleReadModel;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentSearchReadModel;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentStoreAnalyticsReadModel;
use App\Infrastructure\Persistence\Eloquent\ReadModel\EloquentSyncCandidateSource;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentAdvertisingStore;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentApprovalRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentAssignedWorkRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentAutomationRuleRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentBudgetRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentCampaignAssignmentRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentCampaignDraftRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentClientOffboarding;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentClientRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentClientRequestRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentCommerceStore;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentCreativeRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentDashboardRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentDigestStore;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentEventDefinitionRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentExternalAccountRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentIntegrationBindingRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentLeadRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentMeasurementSourceRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentMemberDirectory;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentMembershipRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentObservedEventWriter;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentOperatingRuleRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentPortalRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentProjectMemberRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentProjectRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentProposalHistory;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentProviderConnectionRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentReportRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentRuleExecutionLog;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentSavedViewRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentShortcutStore;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentStoreDirectory;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentStoreLocator;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentSurfaceViewRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentSyncJournal;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentTagStore;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentTaskRepository;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentToolCallLog;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentWebhookEventJournal;
use App\Infrastructure\Persistence\Eloquent\Repository\EloquentWebhookEventReader;
use App\Infrastructure\Presentation\PptxWriter;
use App\Infrastructure\Reporting\EloquentReportShareStore;
use App\Infrastructure\Reporting\XlsxWriter;
use App\Infrastructure\Support\SystemClock;
use App\Infrastructure\Support\Uuid7Generator;
use App\Infrastructure\System\CacheHealthProbe;
use App\Infrastructure\System\DatabaseHealthProbe;
use App\Infrastructure\System\DatabaseWorkLedger;
use App\Infrastructure\System\FailedWorkHealthProbe;
use App\Infrastructure\System\LaravelApplicationInfo;
use App\Infrastructure\System\QueueHealthProbe;
use App\Interface\Export\ReportExportRenderer;
use App\Interface\Http\Resource\Reporting\ReportResource;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory as HttpFactory;
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
    /**
     * The modules that contribute to the attention queue, in the order they are consulted.
     *
     * A list here rather than a registry inside the Application layer, because this is the one place
     * allowed to know which modules exist. Adding a seventh is adding a line to this array; nothing in
     * the queue itself knows the set.
     */
    private const ATTENTION_SOURCES = [
        CampaignAttentionSource::class,
        AutomationAttentionSource::class,
        AgentAttentionSource::class,
        BudgetAttentionSource::class,
        TrackingAttentionSource::class,
        ApprovalAttentionSource::class,
        RequestAttentionSource::class,
        TaskAttentionSource::class,
    ];

    /** Probes composing the system health report, in display order. */
    private const HEALTH_PROBES = [
        DatabaseHealthProbe::class,
        CacheHealthProbe::class,
        QueueHealthProbe::class,
        /*
         * Last, and deliberately its own line in the report rather than folded into the queue's. An empty queue
         * and an empty queue because the work was abandoned are the same depth, and only one of them is fine.
         */
        FailedWorkHealthProbe::class,
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
        WorkLedger::class => DatabaseWorkLedger::class,
        ReportWorkbookWriter::class => XlsxWriter::class,
        BoardSignaller::class => BroadcastBoardSignaller::class,
        /*
         * One CRM, and the binding names it. A second would need a registry like the advertising adapters have;
         * one does not, and a registry with a single entry is a layer that explains nothing.
         */
        CrmReadAdapter::class => HubSpotCrmReadAdapter::class,
        AuthorizationGuard::class => PermissionAuthorizationGuard::class,
        IdentityReadModel::class => EloquentIdentityReadModel::class,
        Authenticator::class => SessionAuthenticator::class,
        ClientRepository::class => EloquentClientRepository::class,
        ClientReadModel::class => EloquentClientReadModel::class,
        ProjectRepository::class => EloquentProjectRepository::class,
        ProjectReadModel::class => EloquentProjectReadModel::class,
        ProjectMemberRepository::class => EloquentProjectMemberRepository::class,
        MemberReadModel::class => EloquentMemberReadModel::class,
        RoleReadModel::class => EloquentRoleReadModel::class,
        MembershipRepository::class => EloquentMembershipRepository::class,
        /*
         * Creating people, as opposed to re-roling them: a different lifetime, a different table, and the only
         * place in the product that generates a password.
         */
        MemberDirectory::class => EloquentMemberDirectory::class,
        /* The four assignment columns, so removing somebody does not leave orphans in any of them. */
        AssignedWorkRepository::class => EloquentAssignedWorkRepository::class,
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
        CommerceSyncAdapterRegistryContract::class => CommerceSyncAdapterRegistry::class,
        WebhookEventJournal::class => EloquentWebhookEventJournal::class,
        WebhookEventReader::class => EloquentWebhookEventReader::class,
        StoreLocator::class => EloquentStoreLocator::class,
        /*
         * The stores a workspace reads, and the one way a new one is written. Until this existed a `stores` row
         * could only come from a seeder, so a connected platform could never produce a figure.
         */
        StoreDirectory::class => EloquentStoreDirectory::class,
        /* Which project owns a campaign, and the review queue for the ones nobody has placed. */
        CampaignAssignmentRepository::class => EloquentCampaignAssignmentRepository::class,
        CampaignControlReadModel::class => EloquentCampaignControlReadModel::class,
        SearchReadModel::class => EloquentSearchReadModel::class,
        ShortcutStore::class => EloquentShortcutStore::class,
        TagStore::class => EloquentTagStore::class,
        ClientOffboarding::class => EloquentClientOffboarding::class,
        DigestStore::class => EloquentDigestStore::class,
        ConnectionDirectory::class => EloquentConnectionDirectory::class,
        PortfolioReadModel::class => EloquentPortfolioReadModel::class,
        OperationsReadModel::class => EloquentOperationsReadModel::class,
        ClientIndustryDirectory::class => EloquentClientIndustryDirectory::class,
        ActorMemberships::class => EloquentActorMemberships::class,
        ActivityReadModel::class => EloquentActivityReadModel::class,
        ExportJobStore::class => EloquentExportJobStore::class,
        SurfaceViewRepository::class => EloquentSurfaceViewRepository::class,
        AutomationRuleRepository::class => EloquentAutomationRuleRepository::class,
        OperatingRuleRepository::class => EloquentOperatingRuleRepository::class,
        ProposalHistory::class => EloquentProposalHistory::class,
        AgentActors::class => EloquentAgentIdentity::class,
        CampaignHierarchyReadModel::class => EloquentCampaignHierarchyReadModel::class,
        AgentRunLog::class => EloquentAgentRunLog::class,
        FindingLog::class => EloquentFindingLog::class,
        RuleExecutionLog::class => EloquentRuleExecutionLog::class,
        ApprovalLinkRepository::class => EloquentApprovalLinkRepository::class,
        ModelCredentialStore::class => EloquentModelCredentialStore::class,
        CampaignDraftRepository::class => EloquentCampaignDraftRepository::class,
        AgentReadModel::class => EloquentAgentReadModel::class,
        ToolCallLog::class => EloquentToolCallLog::class,
        ExportFileStorage::class => LocalExportFileStorage::class,
        ScheduleStore::class => EloquentScheduleStore::class,
        ExportRenderer::class => ReportExportRenderer::class,
        ReadinessFacts::class => EloquentReadinessFacts::class,
        BudgetControlReadModel::class => EloquentBudgetControlReadModel::class,
        SyncCandidateSource::class => EloquentSyncCandidateSource::class,
        StoreAnalyticsReadModel::class => EloquentStoreAnalyticsReadModel::class,
        AttributionReadModel::class => EloquentAttributionReadModel::class,
        CreativeSpecRegistry::class => EloquentCreativeSpecRegistry::class,
        CreativeRepository::class => EloquentCreativeRepository::class,

        // Operations (Phase 20)
        TaskRepository::class => EloquentTaskRepository::class,
        ApprovalRepository::class => EloquentApprovalRepository::class,
        ClientRequestRepository::class => EloquentClientRequestRepository::class,

        // Reporting (Phase 21)
        ReportRepository::class => EloquentReportRepository::class,
        ReportShareStore::class => EloquentReportShareStore::class,
        /*
         * The resource formats a live render and stores a snapshot, so a frozen report and a live one cannot render
         * differently in the same interface.
         */
        SnapshotSerialiser::class => ReportResource::class,

        // Client portal (Phase 22)
        ClientVisibility::class => EloquentClientVisibility::class,
        PortalRepository::class => EloquentPortalRepository::class,

        /*
         * The deck writer (Phase 24). PPTX only: PDF is printed from the preview, where the Arabic shaping is the
         * browser's own — see docs/PRESENTATIONS.md.
         */
        DeckWriter::class => PptxWriter::class,

        /*
         * The provider write path (Phase 24). Fails closed: the registry hands anything undocumented to a writer
         * that refuses — see `CampaignWriterRegistry`.
         */
        ProviderWriterRegistry::class => CampaignWriterRegistry::class,
        NotificationStore::class => EloquentNotificationStore::class,
        RecipientResolver::class => PermissionAwareRecipientResolver::class,
        NotificationBroadcaster::class => ReverbNotificationBroadcaster::class,

        // Leads (Phase 15)
        LeadRepository::class => EloquentLeadRepository::class,
        /*
         * An access question rather than a module's, which is why it lives in `Infrastructure\Access` beside
         * the visibility resolver whose rule it mirrors. One directory for three boards: tasks, client requests
         * and leads.
         */
        OwnerDirectory::class => EloquentOwnerDirectory::class,
        LeadReadModel::class => EloquentLeadReadModel::class,

        // Journeys (Phase 16)
        JourneyReadModel::class => EloquentJourneyReadModel::class,
        StoreOrderMapperRegistryContract::class => StoreOrderMapperRegistry::class,
        WebhookSignatureVerifier::class => StoreWebhookVerifiers::class,
        EventDefinitionRepository::class => EloquentEventDefinitionRepository::class,
        ObservedEventReadModel::class => EloquentObservedEventReadModel::class,
    ];

    public function register(): void
    {
        foreach (self::BINDINGS as $abstract => $concrete) {
            $this->app->bind($abstract, $concrete);
        }

        /* One-time approval links: keyed by the application key, sent to the SPA's own origin, delivered honestly. */
        $this->app->bind(ApprovalLinkDelivery::class, static fn (): ApprovalLinkDelivery => new ChannelApprovalLinkDelivery(
            logInDevelopment: app()->environment('local', 'testing'),
            whatsAppToken: is_string(config('services.whatsapp.token')) ? config('services.whatsapp.token') : null,
            mailer: is_string(config('mail.default')) ? config('mail.default') : null,
        ));
        $this->app->when([IssueApprovalLink::class, UseApprovalLink::class])->needs('$appKey')->give(static fn (): string => (string) config('app.key'));
        $this->app->when(IssueApprovalLink::class)->needs('$baseUrl')->give(static fn (): string => (string) config('app.frontend_url'));

        $this->registerActorContext();

        // Sanctum's SPA guard is stateful; the authenticator needs that
        // contract rather than the generic Guard.
        $this->app->bind(StatefulGuard::class, static fn (): StatefulGuard => Auth::guard('web'));

        foreach (self::HEALTH_PROBES as $probe) {
            $this->app->tag($probe, HealthProbe::class);
        }

        foreach (self::ATTENTION_SOURCES as $source) {
            $this->app->tag($source, AttentionSource::class);
        }

        /*
         * The tools, behind one gateway. Adding a tool is adding a line here; nothing in the gateway knows the set.
         * The caller holder is shared so a proposal records who really made it.
         */
        $this->app->singleton(ToolCaller::class);
        $this->app->bind(ToolGateway::class, static fn ($app): ToolGateway => new ToolGateway(
            [
                $app->make(ListCampaignsTool::class),
                $app->make(GetCampaignTool::class),
                $app->make(GetBudgetStatusTool::class),
                $app->make(GetAttentionItemsTool::class),
                $app->make(GetOperatingRulesTool::class),
                $app->make(CreateTaskTool::class),
                new ProposeStatusChangeTool($app->make(RequestStatusChangeUseCase::class), $app->make(StatusEvidence::class), $app->make(ToolCaller::class), WriteOperation::PauseCampaign),
                new ProposeStatusChangeTool($app->make(RequestStatusChangeUseCase::class), $app->make(StatusEvidence::class), $app->make(ToolCaller::class), WriteOperation::ResumeCampaign),
                $app->make(ProposeBudgetChangeTool::class),
                $app->make(ListAdSignalsTool::class),
                $app->make(ListClientsTool::class),
                $app->make(GetLeadQualityTool::class),
            ],
            $app->make(ActorContext::class),
            $app->make(ToolCallLog::class),
            $app->make(Clock::class),
            $app->make(ToolCaller::class),
        ));

        $this->app->bind(GetAttentionQueue::class, static fn ($app): GetAttentionQueue => new GetAttentionQueue(
            $app->tagged(AttentionSource::class),
            $app->make(ActorContext::class),
            $app->make(Clock::class),
            $app->make(ActorMemberships::class),
        ));

        /*
         * Attachments, on whichever disk is configured. The local private disk in development; a bucket in
         * production, which is BLOCKED_EXTERNAL — no cloud account is attached to this repository.
         */
        $this->app->singleton(AttachmentStore::class, fn ($app): AttachmentStore => new LocalAttachmentStore(
            $app->make(FilesystemFactory::class),
            $app->make(IdentifierGenerator::class),
            (string) config('digitalsmart.attachment_disk', 'local'),
        ));

        $this->registerAssistant();

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

        /*
         * Commerce fixtures shift their dates the same way, so a sandbox store looks like it has been
         * trading this month rather than whenever the fixture was written.
         */
        $this->app->singleton(CommerceFixtures::class, fn ($app): CommerceFixtures => new CommerceFixtures(
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
    /**
     * The assistant's analysers, and its language model.
     *
     * **The analysers are tagged and ordered here**, and the order is the order a reader sees. Measurement first,
     * because a conversion figure that cannot be trusted outranks a conversion figure that moved; then what
     * stopped, then what it cost, then where the money went, and the period's own shape last — the one finding
     * that is always present, so that an empty list cannot be mistaken for a clean bill of health.
     *
     * **The model is bound by whether a key exists.** With none — which is every environment in this repository,
     * because a model key is a production secret it cannot hold — `NoLanguageModel` is bound and the assistant
     * composes its own prose. The decision is made once, here, rather than by each caller checking a config value.
     */
    private function registerAssistant(): void
    {
        foreach ([
            TrackingBlindSpotAnalyser::class,
            DeliveryStoppedAnalyser::class,
            EfficiencyDriftAnalyser::class,
            SpendConcentrationAnalyser::class,
            PeriodShapeAnalyser::class,
        ] as $analyser) {
            $this->app->tag($analyser, Analyser::class);
        }

        $this->app->bind(Analyse::class, fn ($app): Analyse => new Analyse(
            $app->tagged(Analyser::class),
            $app->make(AnalysisAssembler::class),
            $app->make(Narrator::class),
            $app->make(FigureScanner::class),
            $app->make(AuthorizationGuard::class),
        ));

        $this->app->bind(ModelRouter::class, static fn ($app): ModelRouter => new ConfiguredModelRouter(
            $app->make(HttpFactory::class),
            $app->make(ModelCredentialStore::class),
            (array) config('digitalsmart.intelligence', []),
        ));

        $this->app->singleton(LanguageModel::class, function ($app): LanguageModel {
            /** @var array<string, mixed> $settings */
            $settings = (array) config('digitalsmart.intelligence', []);
            $key = is_string($settings['api_key'] ?? null) ? trim($settings['api_key']) : '';

            if ($key === '') {
                return new NoLanguageModel;
            }

            return new AnthropicLanguageModel(
                $app->make(HttpFactory::class),
                $key,
                is_string($settings['model'] ?? null) ? $settings['model'] : 'claude-sonnet-5-5',
                is_int($settings['max_tokens'] ?? null) ? $settings['max_tokens'] : 1024,
                is_int($settings['timeout'] ?? null) ? $settings['timeout'] : 20,
                is_string($settings['base_url'] ?? null) ? $settings['base_url'] : 'https://api.anthropic.com',
            );
        });
    }

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
