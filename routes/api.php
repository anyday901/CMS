<?php

use App\Http\Controllers\Api\LicenseCheckController;
use App\Http\Controllers\Api\V1;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\EnsureApiKeyCan;
use Illuminate\Support\Facades\Route;

// Licensed software checks its key here (see docs/licensing.md).
Route::post('licenses/check', LicenseCheckController::class)->middleware('throttle:60,1')->name('api.licenses.check');

// The REST API (see docs/api.md). Each key is limited to the abilities staff gave it.
Route::prefix('v1')->name('api.v1.')->middleware(AuthenticateApiKey::class)->group(function () {
    Route::get('me', V1\MeController::class)->name('me');

    Route::middleware(EnsureApiKeyCan::using('clients:read'))->group(function () {
        Route::get('clients', [V1\ClientController::class, 'index'])->name('clients.index');
        Route::get('clients/{client}', [V1\ClientController::class, 'show'])->name('clients.show');
    });
    Route::middleware(EnsureApiKeyCan::using('clients:write'))->group(function () {
        Route::post('clients', [V1\ClientController::class, 'store'])->name('clients.store');
        Route::match(['put', 'patch'], 'clients/{client}', [V1\ClientController::class, 'update'])->name('clients.update');
    });

    Route::middleware(EnsureApiKeyCan::using('services:read'))->group(function () {
        Route::get('services', [V1\ServiceController::class, 'index'])->name('services.index');
        Route::get('services/{service}', [V1\ServiceController::class, 'show'])->name('services.show');
    });
    Route::post('services/{service}/{action}', [V1\ServiceController::class, 'action'])
        ->whereIn('action', ['suspend', 'unsuspend', 'terminate'])
        ->middleware(EnsureApiKeyCan::using('services:write'))->name('services.action');

    Route::middleware(EnsureApiKeyCan::using('invoices:read'))->group(function () {
        Route::get('invoices', [V1\InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('invoices/{invoice}', [V1\InvoiceController::class, 'show'])->name('invoices.show');
    });
    Route::post('invoices/{invoice}/payments', [V1\InvoiceController::class, 'pay'])
        ->middleware(EnsureApiKeyCan::using('invoices:write'))->name('invoices.payments.store');

    Route::middleware(EnsureApiKeyCan::using('orders:read'))->group(function () {
        Route::get('orders', [V1\OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/{order}', [V1\OrderController::class, 'show'])->name('orders.show');
    });

    Route::middleware(EnsureApiKeyCan::using('products:read'))->group(function () {
        Route::get('products', [V1\ProductController::class, 'index'])->name('products.index');
        Route::get('products/{product}', [V1\ProductController::class, 'show'])->name('products.show');
    });

    Route::middleware(EnsureApiKeyCan::using('tickets:read'))->group(function () {
        Route::get('tickets', [V1\TicketController::class, 'index'])->name('tickets.index');
        Route::get('tickets/{ticket}', [V1\TicketController::class, 'show'])->name('tickets.show');
    });
    Route::middleware(EnsureApiKeyCan::using('tickets:write'))->group(function () {
        Route::post('tickets', [V1\TicketController::class, 'store'])->name('tickets.store');
        Route::post('tickets/{ticket}/replies', [V1\TicketController::class, 'reply'])->name('tickets.replies.store');
    });
});
