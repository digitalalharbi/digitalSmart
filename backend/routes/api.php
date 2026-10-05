<?php

declare(strict_types=1);

use App\Interface\Http\Controller\Audit\AuditLogController;
use App\Interface\Http\Controller\Client\ClientController;
use App\Interface\Http\Controller\Identity\SessionController;
use App\Interface\Http\Controller\Identity\WorkspaceSwitchController;
use App\Interface\Http\Controller\Integration\ExternalAccountController;
use App\Interface\Http\Controller\Integration\ProjectBindingController;
use App\Interface\Http\Controller\Integration\ProviderController;
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
