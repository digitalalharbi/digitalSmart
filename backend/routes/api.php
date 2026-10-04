<?php

declare(strict_types=1);

use App\Interface\Http\Controller\Client\ClientController;
use App\Interface\Http\Controller\Identity\SessionController;
use App\Interface\Http\Controller\Identity\WorkspaceSwitchController;
use App\Interface\Http\Controller\System\HealthController;
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
});
