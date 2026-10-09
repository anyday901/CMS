<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Portal;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/portal');

Route::prefix('portal')->name('portal.')->group(function () {
    Route::middleware('guest:client')->group(function () {
        Route::get('login', [Portal\AuthController::class, 'create'])->name('login');
        Route::post('login', [Portal\AuthController::class, 'store'])->middleware('throttle:10,1');
        Route::get('forgot-password', [Portal\PasswordController::class, 'request'])->name('password.request');
        Route::post('forgot-password', [Portal\PasswordController::class, 'email'])->middleware('throttle:5,1')->name('password.email');
        Route::get('reset-password/{token}', [Portal\PasswordController::class, 'edit'])->name('password.reset');
        Route::post('reset-password', [Portal\PasswordController::class, 'update'])->middleware('throttle:10,1')->name('password.update');
    });

    Route::middleware('auth:client')->group(function () {
        Route::post('logout', [Portal\AuthController::class, 'destroy'])->name('logout');
        Route::get('/', Portal\DashboardController::class)->name('dashboard');
        Route::get('invoices', [Portal\InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('invoices/{invoice}', [Portal\InvoiceController::class, 'show'])->name('invoices.show');
        Route::get('services', [Portal\ServiceController::class, 'index'])->name('services.index');
        Route::get('services/{service}', [Portal\ServiceController::class, 'show'])->name('services.show');
        Route::get('account', [Portal\AccountController::class, 'edit'])->name('account.edit');
        Route::put('account', [Portal\AccountController::class, 'update'])->name('account.update');
        Route::put('account/password', [Portal\AccountController::class, 'password'])->name('account.password');
    });
});

Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest:web')->group(function () {
        Route::get('login', [Admin\AuthController::class, 'create'])->name('login');
        Route::post('login', [Admin\AuthController::class, 'store'])->middleware('throttle:10,1');
    });

    Route::middleware('auth:web')->group(function () {
        Route::post('logout', [Admin\AuthController::class, 'destroy'])->name('logout');
        Route::get('/', Admin\DashboardController::class)->name('dashboard');

        Route::resource('clients', Admin\ClientController::class)->except('destroy');
        Route::post('clients/{client}/password-link', [Admin\ClientController::class, 'sendPasswordLink'])->name('clients.password-link');
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
