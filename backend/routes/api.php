<?php

declare(strict_types=1);

use App\Interface\Http\Controller\Analytics\AnalyticsController;
use App\Interface\Http\Controller\Analytics\MetricCatalogueController;
use App\Interface\Http\Controller\Audit\AuditLogController;
use App\Interface\Http\Controller\Budget\BudgetController;
use App\Interface\Http\Controller\Budget\ForecastController;
use App\Interface\Http\Controller\Budget\PacingController;
use App\Interface\Http\Controller\Client\ClientController;
use App\Interface\Http\Controller\Commerce\StoreWebhookController;
use App\Interface\Http\Controller\Dashboard\DashboardController;
use App\Interface\Http\Controller\Dashboard\SavedViewController;
use App\Interface\Http\Controller\Identity\SessionController;
use App\Interface\Http\Controller\Identity\WorkspaceSwitchController;
use App\Interface\Http\Controller\Integration\ExternalAccountController;
use App\Interface\Http\Controller\Integration\ProjectBindingController;
use App\Interface\Http\Controller\Integration\ProviderController;
use App\Interface\Http\Controller\Measurement\MeasurementController;
use App\Interface\Http\Controller\Project\ProjectController;
use App\Interface\Http\Controller\Project\ProjectMemberController;
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

Route::middleware('guest')->group(function (): void {
    Route::post('auth/login', [SessionController::class, 'store'])
        ->middleware('throttle:auth')
        ->name('auth.login');
});

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
    Route::get('measurement/containers', [MeasurementController::class, 'containers'])
        ->name('measurement.containers');
    Route::get('measurement/sources/{source}/readiness', [MeasurementController::class, 'readiness'])
        ->whereUuid('source')->name('measurement.sources.readiness');

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
