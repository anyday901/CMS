<?php

use App\Http\Controllers\Admin;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('login', [Admin\AuthController::class, 'create'])->name('login');
        Route::post('login', [Admin\AuthController::class, 'store'])->middleware('throttle:10,1');
    });

    Route::middleware('auth')->group(function () {
        Route::post('logout', [Admin\AuthController::class, 'destroy'])->name('logout');
        Route::get('/', Admin\DashboardController::class)->name('dashboard');

        Route::resource('clients', Admin\ClientController::class)->except('destroy');
        Route::resource('products', Admin\ProductController::class)->except(['show', 'destroy']);

        Route::get('clients/{client}/services/create', [Admin\ServiceController::class, 'create'])->name('services.create');
        Route::post('clients/{client}/services', [Admin\ServiceController::class, 'store'])->name('services.store');
        Route::get('services/{service}', [Admin\ServiceController::class, 'show'])->name('services.show');
        Route::post('services/{service}/{action}', [Admin\ServiceController::class, 'action'])
            ->whereIn('action', ['suspend', 'unsuspend', 'terminate'])
            ->name('services.action');

        Route::get('invoices', [Admin\InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('invoices/{invoice}', [Admin\InvoiceController::class, 'show'])->name('invoices.show');
        Route::post('invoices/{invoice}/payments', [Admin\InvoiceController::class, 'pay'])->name('invoices.pay');
        Route::post('invoices/{invoice}/cancel', [Admin\InvoiceController::class, 'cancel'])->name('invoices.cancel');
    });
});
