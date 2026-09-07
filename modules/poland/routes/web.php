<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Poland\Laravel\Http\Controllers\DashboardController;

if (! config('poland.routes.enabled', true)) {
    return;
}

Route::middleware((array) config('poland.routes.middleware', ['web', 'auth']))
    ->prefix((string) config('poland.routes.prefix', 'poland'))
    ->name('poland.')
    ->group(function (): void {
        Route::get('/', [DashboardController::class, 'show'])->name('dashboard');
        Route::post('/sprzedaz', [DashboardController::class, 'storeSales'])->name('sales.store');
    });
