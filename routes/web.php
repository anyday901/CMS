<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Portal;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\EnsureClientCanLogIn;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/portal');

Route::post('webhooks/paypal', [WebhookController::class, 'paypal'])->name('webhooks.paypal');

Route::prefix('portal')->name('portal.')->group(function () {
    Route::middleware('guest:client')->group(function () {
        Route::get('login', [Portal\AuthController::class, 'create'])->name('login');
        Route::post('login', [Portal\AuthController::class, 'store'])->middleware('throttle:10,1');
        Route::get('forgot-password', [Portal\PasswordController::class, 'request'])->name('password.request');
        Route::post('forgot-password', [Portal\PasswordController::class, 'email'])->middleware('throttle:5,1')->name('password.email');
        Route::get('reset-password/{token}', [Portal\PasswordController::class, 'edit'])->name('password.reset');
        Route::post('reset-password', [Portal\PasswordController::class, 'update'])->middleware('throttle:10,1')->name('password.update');
    });

    Route::middleware(['auth:client', EnsureClientCanLogIn::class])->group(function () {
        Route::post('logout', [Portal\AuthController::class, 'destroy'])->name('logout');
        Route::get('/', Portal\DashboardController::class)->name('dashboard');
        Route::get('invoices', [Portal\InvoiceController::class, 'index'])->name('invoices.index');
        Route::prefix('invoices/{invoice}')->group(function () {
            Route::get('/', [Portal\InvoiceController::class, 'show'])->name('invoices.show');
            Route::post('credit', [Portal\InvoiceController::class, 'applyCredit'])->name('invoices.credit');
            Route::get('pdf', [Portal\InvoiceController::class, 'pdf'])->name('invoices.pdf');
            Route::middleware('throttle:20,1')->group(function () {
                Route::post('paypal/order', [Portal\PaymentController::class, 'paypalOrder'])->name('pay.paypal.order');
                Route::post('paypal/capture', [Portal\PaymentController::class, 'paypalCapture'])->name('pay.paypal.capture');
                Route::post('cashapp', [Portal\PaymentController::class, 'cashApp'])->name('pay.cashapp');
            });
        });
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

        // Creating and editing come first so "create" isn't read as a client id.
        Route::middleware('can:manage-clients')->group(function () {
            Route::get('clients/create', [Admin\ClientController::class, 'create'])->name('clients.create');
            Route::post('clients', [Admin\ClientController::class, 'store'])->name('clients.store');
            Route::get('clients/{client}/edit', [Admin\ClientController::class, 'edit'])->name('clients.edit');
            Route::put('clients/{client}', [Admin\ClientController::class, 'update'])->name('clients.update');
            Route::post('clients/{client}/password-link', [Admin\ClientController::class, 'sendPasswordLink'])->name('clients.password-link');
            Route::get('clients/{client}/services/create', [Admin\ServiceController::class, 'create'])->name('services.create');
            Route::post('clients/{client}/services', [Admin\ServiceController::class, 'store'])->name('services.store');
            Route::post('services/{service}/{action}', [Admin\ServiceController::class, 'action'])
                ->whereIn('action', ['suspend', 'unsuspend', 'terminate'])
                ->name('services.action');
        });

        Route::middleware('can:manage-billing')->group(function () {
            Route::get('clients/{client}/invoices/create', [Admin\InvoiceController::class, 'create'])->name('invoices.create');
            Route::post('clients/{client}/invoices', [Admin\InvoiceController::class, 'store'])->name('invoices.store');
            Route::resource('invoices', Admin\InvoiceController::class)->only(['edit', 'update']);
            Route::post('invoices/{invoice}/publish', [Admin\InvoiceController::class, 'publish'])->name('invoices.publish');
            Route::post('invoices/{invoice}/payments', [Admin\InvoiceController::class, 'pay'])->name('invoices.pay');
            Route::post('invoices/{invoice}/cancel', [Admin\InvoiceController::class, 'cancel'])->name('invoices.cancel');
            Route::post('invoices/{invoice}/credit', [Admin\InvoiceController::class, 'applyCredit'])->name('invoices.credit');
            Route::post('transactions/{transaction}/refund', [Admin\InvoiceController::class, 'refund'])->name('transactions.refund');
        });

        // Every staff role can look.
        Route::get('clients', [Admin\ClientController::class, 'index'])->name('clients.index');
        Route::get('clients/{client}', [Admin\ClientController::class, 'show'])->name('clients.show');
        Route::get('services/{service}', [Admin\ServiceController::class, 'show'])->name('services.show');
        Route::resource('invoices', Admin\InvoiceController::class)->only(['index', 'show']);
        Route::get('invoices/{invoice}/pdf', [Admin\InvoiceController::class, 'pdf'])->name('invoices.pdf');

        Route::middleware('can:manage-settings')->group(function () {
            Route::resource('products', Admin\ProductController::class)->except(['show', 'destroy']);
            Route::resource('tax-rules', Admin\TaxRuleController::class)->except(['show', 'create']);
            Route::get('activity', Admin\ActivityController::class)->name('activity.index');
        });

        Route::middleware('can:manage-staff')->group(function () {
            Route::resource('staff', Admin\StaffController::class)->except('show')->parameters(['staff' => 'user']);
        });
    });
});
