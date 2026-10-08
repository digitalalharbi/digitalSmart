<?php

declare(strict_types=1);

use App\Interface\Http\Controller\Analytics\AnalyticsController;
use App\Interface\Http\Controller\Analytics\MetricCatalogueController;
use App\Interface\Http\Controller\Attribution\AttributionController;
use App\Interface\Http\Controller\Audit\AuditLogController;
use App\Interface\Http\Controller\Budget\BudgetController;
use App\Interface\Http\Controller\Budget\ForecastController;
use App\Interface\Http\Controller\Budget\PacingController;
use App\Interface\Http\Controller\Client\ClientController;
use App\Interface\Http\Controller\Commerce\StoreAnalyticsController;
use App\Interface\Http\Controller\Commerce\StoreWebhookController;
use App\Interface\Http\Controller\Creative\CreativeController;
use App\Interface\Http\Controller\Dashboard\DashboardController;
use App\Interface\Http\Controller\Dashboard\SavedViewController;
use App\Interface\Http\Controller\Identity\SessionController;
use App\Interface\Http\Controller\Identity\WorkspaceSwitchController;
use App\Interface\Http\Controller\Integration\ExternalAccountController;
use App\Interface\Http\Controller\Integration\ProjectBindingController;
use App\Interface\Http\Controller\Integration\ProviderController;
use App\Interface\Http\Controller\Leads\LeadController;
use App\Interface\Http\Controller\Measurement\MeasurementController;
use App\Interface\Http\Controller\Notifications\NotificationController;
use App\Interface\Http\Controller\Operations\ApprovalController;
use App\Interface\Http\Controller\Operations\ClientRequestController;
use App\Interface\Http\Controller\Operations\TaskController;
use App\Interface\Http\Controller\Portal\ClientPortalController;
use App\Interface\Http\Controller\Portal\PortalConfigurationController;
use App\Interface\Http\Controller\Project\ProjectController;
use App\Interface\Http\Controller\Project\ProjectMemberController;
use App\Interface\Http\Controller\Reporting\ReportController;
use App\Interface\Http\Controller\Reporting\SharedReportController;
use App\Interface\Http\Controller\System\HealthController;
use App\Interface\Http\Controller\Workspace\MemberController;
use App\Interface\Http\Middleware\BindActorContext;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
|
| Mounted under /api. digitalSmart is API-only: there is no web UI here.
| Routes stay declarative — one line per endpoint, pointing at a thin
| controller in App\Interface\Http. Permissions are enforced inside the use
| cases, not by route middleware, so they cannot be bypassed by a new caller.
|
*/

Route::get('health', HealthController::class)
    ->middleware('throttle:health')
    ->name('system.health');

// ---------------------------------------------------------------- Guests ---
/*
 * Store webhooks.
 *
 * Unauthenticated by necessity: a provider has no session and cannot be given one. Every delivery is
 * verified by signature inside the use case, against the secret of the workspace whose app sent it —
 * and two workspaces can legitimately have registered the same merchant, so the signature is what
 * decides whose order this is.
 *
 * Throttled, because the endpoint is public and a provider having a bad hour must not be able to fill
 * the queue. Rate-limited deliveries are redelivered by the provider, which is the correct outcome for
 * a 429 — unlike a failed signature, which is answered 200 and recorded rather than retried forever.
 */
Route::post('webhooks/stores/{provider}', StoreWebhookController::class)
    ->middleware('throttle:webhooks')
    ->name('webhooks.stores');

/*
 * Signing in, with no `guest` middleware — deliberately.
 *
 * Laravel's `guest` middleware answers an already-authenticated request with a **302 to a web
 * route**, which is wrong twice over for an API. An API client is owed a status and a body it can
 * act on, and the redirect's target is absolute against `APP_URL`: for a single-page app served
 * from a different origin that redirect is cross-origin, the browser blocks it outright, and the
 * only thing the application can honestly report is that the server could not be reached. That
 * failure cost two end-to-end investigations and was twice attributed to something else.
 *
 * Signing in while already signed in is also a legitimate act — somebody switching accounts — and
 * it still requires the second account's password. The authenticator regenerates the session on
 * every successful attempt, so there is no fixation to guard against here.
 */
Route::post('auth/login', [SessionController::class, 'store'])
    ->middleware('throttle:auth')
    ->name('auth.login');

/*
 * Reading a report through a shared link, with no account.
 *
 * The only unauthenticated read in this product. The token is the whole of the authorisation and it names exactly
 * one report: there is no other parameter, so there is nothing a visitor can change to reach a different document.
 *
 * Throttled on its own bucket, and tighter than the authenticated API's. That limit is per user; this has no user,
 * so it is per address — and a bare token in a URL is the one thing here worth brute forcing. The response is always
 * 200 with a state, because a 404 on a link a client was sent reads as a broken site and they ask for it again
 * instead of learning that it lapsed.
 */
Route::get('shared-reports/{token}', SharedReportController::class)
    ->middleware('throttle:shared-reports')
    ->where('token', '[A-Fa-f0-9]{96}')
    ->name('shared_reports.show');

// --------------------------------------------------------- Authenticated ---
Route::middleware(['auth:sanctum', BindActorContext::class])->group(function (): void {
    Route::get('auth/me', [SessionController::class, 'show'])->name('auth.me');
    Route::post('auth/logout', [SessionController::class, 'destroy'])->name('auth.logout');

    Route::post('workspaces/{workspace}/activate', WorkspaceSwitchController::class)
        ->whereUuid('workspace')
        ->name('workspaces.activate');

    Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
    Route::post('clients', [ClientController::class, 'store'])->name('clients.store');
    Route::get('clients/{client}', [ClientController::class, 'show'])
        ->whereUuid('client')->name('clients.show');
    Route::put('clients/{client}', [ClientController::class, 'update'])
        ->whereUuid('client')->name('clients.update');
    Route::patch('clients/{client}/status', [ClientController::class, 'changeStatus'])
        ->whereUuid('client')->name('clients.status');
    Route::delete('clients/{client}', [ClientController::class, 'destroy'])
        ->whereUuid('client')->name('clients.destroy');

    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('projects/{project}', [ProjectController::class, 'show'])
        ->whereUuid('project')->name('projects.show');
    Route::put('projects/{project}', [ProjectController::class, 'update'])
        ->whereUuid('project')->name('projects.update');
    Route::patch('projects/{project}/status', [ProjectController::class, 'changeStatus'])
        ->whereUuid('project')->name('projects.status');
    Route::delete('projects/{project}', [ProjectController::class, 'destroy'])
        ->whereUuid('project')->name('projects.destroy');

    Route::get('projects/{project}/members', [ProjectMemberController::class, 'index'])
        ->whereUuid('project')->name('projects.members.index');
    Route::post('projects/{project}/members', [ProjectMemberController::class, 'store'])
        ->whereUuid('project')->name('projects.members.store');
    Route::delete('projects/{project}/members/{user}', [ProjectMemberController::class, 'destroy'])
        ->whereUuid('project')->whereUuid('user')->name('projects.members.destroy');

    Route::get('members', [MemberController::class, 'index'])->name('members.index');
    Route::patch('members/{user}/role', [MemberController::class, 'changeRole'])
        ->whereUuid('user')->name('members.role');
    Route::patch('members/{user}/status', [MemberController::class, 'changeStatus'])
        ->whereUuid('user')->name('members.status');
    Route::delete('members/{user}', [MemberController::class, 'destroy'])
        ->whereUuid('user')->name('members.destroy');

    Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit.index');

    // ---------------------------------------------------------------- Analytics ---
    /*
     * One endpoint for scorecard, chart and table. They have to agree, and the
     * only way to guarantee that is to compute them from a single query.
     */
    Route::get('analytics', AnalyticsController::class)->name('analytics.query');
    // What can be asked for, and what each metric means. Served rather than
    // duplicated in the client, so formatting cannot diverge between screens.
    Route::get('analytics/catalogue', MetricCatalogueController::class)->name('analytics.catalogue');

    /*
     * Saved views: a stored analytics query. The preset is stored rather than the
     * dates it resolved to, so "last 28 days" keeps meaning that.
     */
    Route::get('analytics/views', [SavedViewController::class, 'index'])->name('views.index');
    Route::post('analytics/views', [SavedViewController::class, 'store'])->name('views.store');
    Route::put('analytics/views/{view}', [SavedViewController::class, 'update'])
        ->whereUuid('view')->name('views.update');
    Route::delete('analytics/views/{view}', [SavedViewController::class, 'destroy'])
        ->whereUuid('view')->name('views.destroy');
    Route::post('analytics/views/{view}/opened', [SavedViewController::class, 'touch'])
        ->whereUuid('view')->name('views.opened');

    /*
     * Dashboards. A dashboard and its whole widget set are saved together, because
     * the canvas a reader sees is the whole truth about it — a widget they removed
     * has to go, and diffing grid positions is a worse way to get there.
     */
    Route::get('dashboards', [DashboardController::class, 'index'])->name('dashboards.index');
    Route::post('dashboards', [DashboardController::class, 'store'])->name('dashboards.store');
    Route::get('dashboards/{dashboard}', [DashboardController::class, 'show'])
        ->whereUuid('dashboard')->name('dashboards.show');
    Route::put('dashboards/{dashboard}', [DashboardController::class, 'update'])
        ->whereUuid('dashboard')->name('dashboards.update');
    Route::delete('dashboards/{dashboard}', [DashboardController::class, 'destroy'])
        ->whereUuid('dashboard')->name('dashboards.destroy');
    Route::post('dashboards/{dashboard}/duplicate', [DashboardController::class, 'duplicate'])
        ->whereUuid('dashboard')->name('dashboards.duplicate');

    /*
     * ---------------------------------------------------------------- Budgets ---
     *
     * The governance hierarchy: an approved client budget, split across platforms,
     * campaigns and ad groups, compared with the spend the sync records. Approval is
     * its own endpoint rather than a status field, because it is an act with its own
     * permission and its own audit entry.
     */
    Route::get('budgets', [BudgetController::class, 'index'])->name('budgets.index');
    Route::post('budgets', [BudgetController::class, 'store'])->name('budgets.store');
    Route::get('budgets/alerts', [PacingController::class, 'alerts'])->name('budgets.alerts');
    Route::get('budgets/{budget}', [BudgetController::class, 'show'])
        ->whereUuid('budget')->name('budgets.show');
    Route::put('budgets/{budget}', [BudgetController::class, 'update'])
        ->whereUuid('budget')->name('budgets.update');
    Route::delete('budgets/{budget}', [BudgetController::class, 'destroy'])
        ->whereUuid('budget')->name('budgets.destroy');
    Route::post('budgets/{budget}/approve', [BudgetController::class, 'approve'])
        ->whereUuid('budget')->name('budgets.approve');
    Route::post('budgets/{budget}/archive', [BudgetController::class, 'archive'])
        ->whereUuid('budget')->name('budgets.archive');
    Route::put('budgets/{budget}/allocations', [BudgetController::class, 'allocations'])
        ->whereUuid('budget')->name('budgets.allocations');
    Route::get('budgets/{budget}/pacing', [PacingController::class, 'show'])
        ->whereUuid('budget')->name('budgets.pacing');
    /*
     * The calculator, in both directions. A POST despite being a read: the request
     * carries a provider list, a scope and a target that do not belong in a URL, and
     * nothing is stored, so a reader can try as many numbers as they like.
     */
    Route::post('budgets/forecast', ForecastController::class)->name('budgets.forecast');

    /*
     * ------------------------------------------------------------ Measurement ---
     *
     * What measures a client's work, what counts as success there, and whether it is
     * working. The conversion registry is the hinge: nothing in this product infers
     * meaning from an event name, so a raw name counts towards a conversion only because
     * somebody mapped it here.
     */
    Route::get('measurement/sources', [MeasurementController::class, 'sources'])
        ->name('measurement.sources.index');
    Route::post('measurement/sources', [MeasurementController::class, 'storeSource'])
        ->name('measurement.sources.store');
    Route::put('measurement/sources/{source}', [MeasurementController::class, 'updateSource'])
        ->whereUuid('source')->name('measurement.sources.update');
    Route::delete('measurement/sources/{source}', [MeasurementController::class, 'destroySource'])
        ->whereUuid('source')->name('measurement.sources.destroy');

    Route::get('measurement/conversions', [MeasurementController::class, 'definitions'])
        ->name('measurement.conversions.index');
    Route::post('measurement/conversions', [MeasurementController::class, 'storeDefinition'])
        ->name('measurement.conversions.store');
    Route::put('measurement/conversions/{definition}', [MeasurementController::class, 'updateDefinition'])
        ->whereUuid('definition')->name('measurement.conversions.update');
    Route::delete('measurement/conversions/{definition}', [MeasurementController::class, 'destroyDefinition'])
        ->whereUuid('definition')->name('measurement.conversions.destroy');

    Route::get('measurement/health', [MeasurementController::class, 'health'])
        ->name('measurement.health');

    /*
     * Discovery, suggestion and sync. All three reach a provider, so all three need
     * `tracking.manage` rather than `tracking.view` — reading a workspace's whole analytics
     * estate is a setup action, not a reporting one.
     */
    Route::get('measurement/providers/{provider}/properties', [MeasurementController::class, 'properties'])
        ->name('measurement.properties');
    Route::get('measurement/sources/{source}/suggestions', [MeasurementController::class, 'suggestions'])
        ->whereUuid('source')->name('measurement.sources.suggestions');
    Route::post('measurement/sources/{source}/sync', [MeasurementController::class, 'sync'])
        ->whereUuid('source')->name('measurement.sources.sync');

    /*
     * Tag containers. Readiness is its own endpoint rather than part of `measurement/health`,
     * because health is derived from our own tables and always answers while this needs a live
     * call to Tag Manager — and a provider outage must not empty the tracking page of facts it
     * already had.
     */
    /*
     * Leads. A lead is a person who did something; counted taps and form starts are signals and live in the
     * measurement layer. The Lead Center shows both, side by side, and no ratio between them.
     */
    Route::get('leads', [LeadController::class, 'index'])->name('leads.index');
    Route::post('leads', [LeadController::class, 'store'])->name('leads.store');
    Route::post('leads/{lead}/stage', [LeadController::class, 'stage'])
        ->whereUuid('lead')->name('leads.stage');
    Route::get('leads/centre', [LeadController::class, 'centre'])->name('leads.centre');

    /*
     * Attribution. The catalogue first, so a client never hard-codes the model vocabulary and so the
     * models this product cannot compute can be offered, explained and refused rather than quietly
     * missing.
     */
    Route::get('attribution/models', [AttributionController::class, 'models'])
        ->name('attribution.models');
    Route::get('attribution/comparison', [AttributionController::class, 'comparison'])
        ->name('attribution.comparison');
    Route::get('attribution', [AttributionController::class, 'show'])->name('attribution.show');

    /*
     * Creative operations. The vocabulary first, so a board never carries its own copy of the legal moves —
     * a front end that did would offer a drop target the server refuses.
     */
    Route::get('creative/vocabulary', [CreativeController::class, 'vocabulary'])->name('creative.vocabulary');
    Route::get('creative/specs', [CreativeController::class, 'specs'])->name('creative.specs');
    Route::get('creative/board', [CreativeController::class, 'board'])->name('creative.board');
    Route::post('creative', [CreativeController::class, 'store'])->name('creative.store');
    Route::get('creative/{creative}', [CreativeController::class, 'show'])
        ->whereUuid('creative')->name('creative.show');
    Route::post('creative/{creative}/state', [CreativeController::class, 'move'])
        ->whereUuid('creative')->name('creative.move');
    Route::post('creative/{creative}/versions', [CreativeController::class, 'addVersion'])
        ->whereUuid('creative')->name('creative.versions.store');
    Route::post('creative/{creative}/comments', [CreativeController::class, 'comment'])
        ->whereUuid('creative')->name('creative.comments.store');
    /*
     * The decision is on a **version**, and the route says so. A path under the request would invite an
     * endpoint that approves "the request", which is the one thing an approval trail must not record.
     */
    Route::post('creative-versions/{version}/decision', [CreativeController::class, 'decide'])
        ->whereUuid('version')->name('creative.versions.decide');
    // ------------------------------------------------------------- Operations ---
    /*
     * The board's own vocabulary: columns, legal moves, the priority scale and the due buckets. Fetched
     * rather than hard-coded, so an interface cannot offer a move the server refuses.
     */
    Route::get('tasks/vocabulary', [TaskController::class, 'vocabulary'])->name('tasks.vocabulary');
    Route::get('tasks/board', [TaskController::class, 'board'])->name('tasks.board');
    Route::post('tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::get('tasks/{task}', [TaskController::class, 'show'])
        ->whereUuid('task')->name('tasks.show');
    Route::patch('tasks/{task}', [TaskController::class, 'update'])
        ->whereUuid('task')->name('tasks.update');
    Route::post('tasks/{task}/state', [TaskController::class, 'move'])
        ->whereUuid('task')->name('tasks.move');
    /*
     * Its own route rather than a field on the patch, matching its own use case and its own audit action.
     * "Who was asked to do this" is the entry people go looking for, and a generic update buries it.
     */
    Route::post('tasks/{task}/assignee', [TaskController::class, 'assign'])
        ->whereUuid('task')->name('tasks.assign');

    /*
     * Client requests. The same endpoints serve the agency and a client: the read model decides what each sees
     * from whether the caller holds `client_requests.view`, so there is no second API for a client to drift
     * against. A client's own message has its audience forced to client-visible.
     */
    Route::get('client-requests/vocabulary', [ClientRequestController::class, 'vocabulary'])
        ->name('client_requests.vocabulary');
    Route::get('client-requests/queue', [ClientRequestController::class, 'queue'])
        ->name('client_requests.queue');
    Route::post('client-requests', [ClientRequestController::class, 'store'])
        ->name('client_requests.store');
    Route::get('client-requests/{clientRequest}', [ClientRequestController::class, 'show'])
        ->whereUuid('clientRequest')->name('client_requests.show');
    Route::post('client-requests/{clientRequest}/state', [ClientRequestController::class, 'triage'])
        ->whereUuid('clientRequest')->name('client_requests.triage');
    /*
     * Its own route, because a promised date is the thing a client quotes back. "You said the fifteenth" has to
     * be answerable from the record rather than from somebody's memory of a call.
     */
    Route::post('client-requests/{clientRequest}/promise', [ClientRequestController::class, 'promise'])
        ->whereUuid('clientRequest')->name('client_requests.promise');
    Route::post('client-requests/{clientRequest}/messages', [ClientRequestController::class, 'answer'])
        ->whereUuid('clientRequest')->name('client_requests.answer');
    Route::post('client-requests/{clientRequest}/owner', [ClientRequestController::class, 'assign'])
        ->whereUuid('clientRequest')->name('client_requests.assign');
    /*
     * Raising work for a request **links** a task to it rather than converting it. The request keeps its own
     * state, its promised date and the half of the conversation a client can see; an intake queue whose rows
     * vanish into a task board is one where nobody can tell a client what happened to what they asked for.
     */
    Route::post('client-requests/{clientRequest}/tasks', [ClientRequestController::class, 'raiseTask'])
        ->whereUuid('clientRequest')->name('client_requests.raise_task');

    /*
     * Notifications. No permission on these routes: every row is scoped to the reader's own user id, and the
     * permission that decided whether they could be told was checked when the notification was written.
     */
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/vocabulary', [NotificationController::class, 'vocabulary'])
        ->name('notifications.vocabulary');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])
        ->name('notifications.read_all');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])
        ->whereUuid('notification')->name('notifications.read');

    /*
     * Approvals. The vocabulary publishes, per kind, whether approving a request makes the change — which today
     * is never: this product does not write to the ad platforms, so an approval authorises a person and the
     * queue counts the change until somebody records having made it.
     */
    Route::get('approvals/vocabulary', [ApprovalController::class, 'vocabulary'])
        ->name('approvals.vocabulary');
    Route::get('approvals/queue', [ApprovalController::class, 'queue'])->name('approvals.queue');
    Route::post('approvals', [ApprovalController::class, 'store'])->name('approvals.store');
    Route::get('approvals/{approval}', [ApprovalController::class, 'show'])
        ->whereUuid('approval')->name('approvals.show');
    Route::patch('approvals/{approval}', [ApprovalController::class, 'amend'])
        ->whereUuid('approval')->name('approvals.amend');
    /*
     * A decision must send the fingerprint its sender's screen displayed. A mismatch answers 409 with the
     * proposal as it stands, because a request that was correct when it was read is not a validation failure.
     */
    Route::post('approvals/{approval}/decision', [ApprovalController::class, 'decide'])
        ->whereUuid('approval')->name('approvals.decide');
    Route::post('approvals/{approval}/withdrawal', [ApprovalController::class, 'withdraw'])
        ->whereUuid('approval')->name('approvals.withdraw');
    /*
     * Its own route, because approval is not execution. This records a person saying they made the change, with
     * no claim that the product verified it.
     */
    Route::post('approvals/{approval}/enactment', [ApprovalController::class, 'enact'])
        ->whereUuid('approval')->name('approvals.enact');

    /*
     * One person's history. Enquiries and orders only: the marketing touches before them need event-level data
     * this product does not have, and the response says so rather than implying a complete picture.
     */
    Route::get('leads/{lead}/journey', [LeadController::class, 'journey'])
        ->whereUuid('lead')->name('leads.journey');

    /*
     * Store analytics. `revenue_source` is required on every request, because a Salla total and a GA4
     * total measure different things and there is no total across them that means anything.
     */
    Route::get('commerce/analytics', StoreAnalyticsController::class)
        ->name('commerce.analytics');

    Route::get('measurement/containers', [MeasurementController::class, 'containers'])
        ->name('measurement.containers');
    Route::get('measurement/sources/{source}/readiness', [MeasurementController::class, 'readiness'])
        ->whereUuid('source')->name('measurement.sources.readiness');

    // -------------------------------------------------------------- Reporting ---
    Route::get('reports/vocabulary', [ReportController::class, 'vocabulary'])
        ->name('reports.vocabulary');
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::post('reports', [ReportController::class, 'store'])->name('reports.store');
    /*
     * The rendered report and its definition are separate reads. Rendering costs one analytics aggregation per
     * section, and an editor that paid for it on every keystroke would be the slowest screen in the product.
     */
    Route::get('reports/{report}', [ReportController::class, 'show'])
        ->whereUuid('report')->name('reports.show');
    Route::get('reports/{report}/definition', [ReportController::class, 'definition'])
        ->whereUuid('report')->name('reports.definition');
    Route::put('reports/{report}', [ReportController::class, 'update'])
        ->whereUuid('report')->name('reports.update');
    Route::delete('reports/{report}', [ReportController::class, 'destroy'])
        ->whereUuid('report')->name('reports.destroy');
    /* The caveats travel in the file — see `ReportCsv`. */
    Route::get('reports/{report}/csv', [ReportController::class, 'csv'])
        ->whereUuid('report')->name('reports.csv');
    /*
     * A snapshot is stored output, never recomputed. It is the only way this product can answer "the report said
     * 1.2 million" three months later.
     */
    Route::get('reports/{report}/snapshots', [ReportController::class, 'snapshots'])
        ->whereUuid('report')->name('reports.snapshots');
    Route::post('reports/{report}/snapshots', [ReportController::class, 'snapshot'])
        ->whereUuid('report')->name('reports.snapshot');
    Route::get('reports/{report}/shares', [ReportController::class, 'shares'])
        ->whereUuid('report')->name('reports.shares');
    Route::post('reports/{report}/shares', [ReportController::class, 'share'])
        ->whereUuid('report')->name('reports.share');
    Route::delete('report-shares/{share}', [ReportController::class, 'revokeShare'])
        ->whereUuid('share')->name('reports.shares.revoke');

    // --------------------------------------------------------- Client portal ---
    /*
     * The agency's control over what each client can read. Every write here changes what somebody outside the
     * workspace can see, which is why each one is a separate route with its own audit entry rather than one
     * `PUT /portals/{client}` carrying a state, a reason and a module list together.
     */
    Route::get('portals/vocabulary', [PortalConfigurationController::class, 'vocabulary'])
        ->name('portals.vocabulary');
    Route::get('portals', [PortalConfigurationController::class, 'index'])->name('portals.index');
    Route::get('portals/{client}', [PortalConfigurationController::class, 'show'])
        ->whereUuid('client')->name('portals.show');
    Route::post('portals/{client}/enable', [PortalConfigurationController::class, 'enable'])
        ->whereUuid('client')->name('portals.enable');
    Route::post('portals/{client}/suspend', [PortalConfigurationController::class, 'suspend'])
        ->whereUuid('client')->name('portals.suspend');
    Route::post('portals/{client}/resume', [PortalConfigurationController::class, 'resume'])
        ->whereUuid('client')->name('portals.resume');
    Route::put('portals/{client}/modules', [PortalConfigurationController::class, 'modules'])
        ->whereUuid('client')->name('portals.modules');

    /*
     * The client's own side. Authorization is the portal's module grants, not a role: a module that is not exposed
     * answers 404, because telling a client a surface about them exists and is withheld says something about the
     * agency's intentions rather than about the product.
     *
     * Agency staff holding `portal.view` read the same routes as a preview, through the same code, and every
     * response says `is_preview` so a preview cannot be mistaken for their own view of the product.
     */
    Route::get('portal', [ClientPortalController::class, 'index'])->name('portal.index');
    Route::get('portal/{client}/performance', [ClientPortalController::class, 'performance'])
        ->whereUuid('client')->name('portal.performance');
    Route::get('portal/{client}/revenue', [ClientPortalController::class, 'revenue'])
        ->whereUuid('client')->name('portal.revenue');
    Route::get('portal/{client}/reports', [ClientPortalController::class, 'reports'])
        ->whereUuid('client')->name('portal.reports');
    Route::get('portal/{client}/reports/{report}', [ClientPortalController::class, 'report'])
        ->whereUuid('client')->whereUuid('report')->name('portal.report');
    Route::get('portal/{client}/tracking', [ClientPortalController::class, 'tracking'])
        ->whereUuid('client')->name('portal.tracking');

    // ------------------------------------------------------------ Integrations ---
    Route::get('integrations/providers', [ProviderController::class, 'index'])
        ->name('integrations.providers.index');
    Route::put('integrations/providers/{provider}', [ProviderController::class, 'configure'])
        ->name('integrations.providers.configure');
    Route::post('integrations/providers/{provider}/discover', [ProviderController::class, 'discover'])
        ->name('integrations.providers.discover');
    Route::get('integrations/providers/{provider}/health', [ProviderController::class, 'health'])
        ->name('integrations.providers.health');
    Route::delete('integrations/providers/{provider}', [ProviderController::class, 'destroy'])
        ->name('integrations.providers.destroy');

    Route::get('integrations/accounts', [ExternalAccountController::class, 'index'])
        ->name('integrations.accounts.index');

    /*
     * Bindings live under the project, because that is what they are about:
     * which external accounts this one project may read.
     */
    Route::get('projects/{project}/integrations', [ProjectBindingController::class, 'index'])
        ->whereUuid('project')->name('projects.integrations.index');
    Route::post('projects/{project}/integrations', [ProjectBindingController::class, 'store'])
        ->whereUuid('project')->name('projects.integrations.store');
    Route::patch('projects/{project}/integrations/{account}', [ProjectBindingController::class, 'update'])
        ->whereUuid('project')->whereUuid('account')->name('projects.integrations.update');
    Route::delete('projects/{project}/integrations/{account}', [ProjectBindingController::class, 'destroy'])
        ->whereUuid('project')->whereUuid('account')->name('projects.integrations.destroy');
});
