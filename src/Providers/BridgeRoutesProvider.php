<?php

namespace Hexalabs\BillingBridge\Providers;

use Hexalabs\BillingBridge\Http\Controllers\EggController;
use Hexalabs\BillingBridge\Http\Controllers\LifecycleController;
use Hexalabs\BillingBridge\Http\Controllers\NodeController;
use Hexalabs\BillingBridge\Http\Controllers\ProvisionController;
use Hexalabs\BillingBridge\Http\Controllers\UserController;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Route;

class BridgeRoutesProvider extends RouteServiceProvider
{
    public function boot(): void
    {
        $this->routes(function () {
            Route::prefix('api/application/billing')
                ->middleware(['auth:sanctum', 'throttle:120,1'])
                ->withoutMiddleware(['web', 'auth', 'verify-csrf-token', 'App\Http\Middleware\VerifyCsrfToken'])
                ->group(function () {
                    Route::post('/users', [UserController::class, 'store'])
                        ->name('billing-bridge.users.store');
                    Route::get('/users/external/{externalId}', [UserController::class, 'showByExternalId'])
                        ->name('billing-bridge.users.external');
                    Route::patch('/users/{user}', [UserController::class, 'update'])
                        ->name('billing-bridge.users.update');
                    Route::delete('/users/{user}', [UserController::class, 'destroy'])
                        ->name('billing-bridge.users.destroy');

                    Route::post('/servers', ProvisionController::class)
                        ->name('billing-bridge.servers.store');
                    Route::get('/servers/{server}', [LifecycleController::class, 'show'])
                        ->name('billing-bridge.servers.show');
                    Route::post('/servers/{server}/suspend', [LifecycleController::class, 'suspend'])
                        ->name('billing-bridge.servers.suspend');
                    Route::post('/servers/{server}/unsuspend', [LifecycleController::class, 'unsuspend'])
                        ->name('billing-bridge.servers.unsuspend');
                    Route::patch('/servers/{server}/plan', [LifecycleController::class, 'applyPlan'])
                        ->name('billing-bridge.servers.plan');
                    Route::delete('/servers/{server}', [LifecycleController::class, 'destroy'])
                        ->name('billing-bridge.servers.destroy');

                    Route::get('/eggs', EggController::class)
                        ->name('billing-bridge.eggs.index');

                    Route::get('/nodes', NodeController::class)
                        ->name('billing-bridge.nodes.index');
                });
        });
    }
}
