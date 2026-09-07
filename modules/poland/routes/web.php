<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Poland\Laravel\Http\Controllers\DashboardController;
use Poland\Laravel\Http\Controllers\ReportController;

if (! config('poland.routes.enabled', true)) {
    return;
}

Route::middleware((array) config('poland.routes.middleware', ['web', 'auth']))
    ->prefix((string) config('poland.routes.prefix', 'poland'))
    ->name('poland.')
    ->group(function (): void {
        Route::get('/', [DashboardController::class, 'show'])->name('dashboard');
        Route::post('/sprzedaz', [DashboardController::class, 'storeSales'])->name('sales.store');
        Route::post('/koszty', [DashboardController::class, 'storeCosts'])->name('costs.store');

        Route::get('/raport/{period}', [ReportController::class, 'show'])
            ->where('period', '\d{4}-\d{2}')->name('report');
        Route::post('/raport/{period}/generuj', [ReportController::class, 'generate'])
            ->where('period', '\d{4}-\d{2}')->name('report.generate');
        Route::post('/raport/{period}/platnosc', [ReportController::class, 'markPaid'])
            ->where('period', '\d{4}-\d{2}')->name('report.paid');
        Route::post('/raport/{period}/zamknij', [ReportController::class, 'close'])
            ->where('period', '\d{4}-\d{2}')->name('report.close');
        Route::post('/raport/{period}/otworz', [ReportController::class, 'reopen'])
            ->where('period', '\d{4}-\d{2}')->name('report.reopen');
        Route::get('/raport/{period}/historia', [ReportController::class, 'history'])
            ->where('period', '\d{4}-\d{2}')->name('report.history');
    });
