<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Poland\Laravel\Http\Controllers\DashboardController;
use Poland\Laravel\Http\Controllers\InboxController;
use Poland\Laravel\Http\Controllers\PlatformController;
use Poland\Laravel\Http\Controllers\ProductController;
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
        Route::post('/profil', [DashboardController::class, 'storeProfile'])->name('profile.store');

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

        // Stage B — the review inbox. Nothing posts without a person's tap.
        Route::get('/skrzynka', [InboxController::class, 'index'])->name('inbox');
        Route::get('/skrzynka/{document}', [InboxController::class, 'show'])
            ->where('document', '\d+')->name('inbox.show');
        Route::post('/skrzynka/{document}/zatwierdz', [InboxController::class, 'approve'])
            ->where('document', '\d+')->name('inbox.approve');
        Route::post('/skrzynka/{document}/odrzuc', [InboxController::class, 'reject'])
            ->where('document', '\d+')->name('inbox.reject');
        Route::post('/skrzynka/{document}/pozycja/{line}/produkt', [InboxController::class, 'mapLine'])
            ->where(['document' => '\d+', 'line' => '\d+'])->name('inbox.map');
        Route::post('/skrzynka/{document}/duplikat', [InboxController::class, 'resolveDuplicate'])
            ->where('document', '\d+')->name('inbox.duplicate');
        Route::post('/skrzynka/rejestr/{period}/zastap', [InboxController::class, 'supersedeSummary'])
            ->where('period', '\d{4}-\d{2}')->name('inbox.supersede');

        // Stage B — products and the optional inventory.
        Route::get('/produkty', [ProductController::class, 'index'])->name('products');
        Route::post('/produkty', [ProductController::class, 'store'])->name('products.store');
        Route::post('/produkty/{product}/sledzenie', [ProductController::class, 'tracking'])
            ->where('product', '\d+')->name('products.tracking');
        Route::post('/produkty/{product}/inwentaryzacja', [ProductController::class, 'count'])
            ->where('product', '\d+')->name('products.count');

        // Stage B — platform settlements (Glovo).
        Route::get('/platformy', [PlatformController::class, 'index'])->name('platforms');
        Route::post('/platformy', [PlatformController::class, 'store'])->name('platforms.store');
    });
