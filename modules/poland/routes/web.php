<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Poland\Laravel\Http\Controllers\DashboardController;
use Poland\Laravel\Http\Controllers\KsefController;
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

        // Settings → Poland → KSeF. Every state change is a POST behind CSRF.
        Route::prefix('ksef')->name('ksef.')->group(function (): void {
            Route::get('/', [KsefController::class, 'status'])->name('status');
            Route::post('/test', [KsefController::class, 'test'])->name('test');
            Route::post('/synchronizuj', [KsefController::class, 'sync'])->name('sync');
            Route::get('/konfiguracja/{step?}', [KsefController::class, 'wizard'])->where('step', '[1-5]')->name('wizard');
            Route::post('/konfiguracja/{step}', [KsefController::class, 'wizardStore'])->where('step', '[1-5]')->name('wizard.store');

            Route::get('/faktury', [KsefController::class, 'submissions'])->name('submissions');
            Route::post('/faktury/przygotuj', [KsefController::class, 'prepare'])->name('submissions.prepare');
            Route::post('/nabywcy/{customerId}', [KsefController::class, 'customerIdentifier'])->where('customerId', '\d+')->name('customer_identifier');
            Route::get('/faktury/{submission}', [KsefController::class, 'show'])->where('submission', '\d+')->name('submissions.show');
            Route::post('/faktury/{submission}/zatwierdz', [KsefController::class, 'ready'])->where('submission', '\d+')->name('submissions.ready');
            Route::post('/faktury/{submission}/wyslij', [KsefController::class, 'send'])->where('submission', '\d+')->name('submissions.send');
            Route::post('/faktury/{submission}/status', [KsefController::class, 'poll'])->where('submission', '\d+')->name('submissions.poll');
            Route::post('/faktury/{submission}/upo', [KsefController::class, 'upo'])->where('submission', '\d+')->name('submissions.upo');
            Route::post('/faktury/{submission}/rozstrzygnij', [KsefController::class, 'resolve'])->where('submission', '\d+')->name('submissions.resolve');
            Route::post('/faktury/{submission}/anuluj', [KsefController::class, 'cancel'])->where('submission', '\d+')->name('submissions.cancel');
            Route::get('/faktury/{submission}/xml', [KsefController::class, 'xml'])->where('submission', '\d+')->name('submissions.xml');
            Route::get('/faktury/{submission}/upo.xml', [KsefController::class, 'upoXml'])->where('submission', '\d+')->name('submissions.upo_xml');
        });
    });
