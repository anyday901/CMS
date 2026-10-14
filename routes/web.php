<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Help;
use App\Http\Controllers\InboundEmailController;
use App\Http\Controllers\Portal;
use App\Http\Controllers\Store;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\EnsureClientCanLogIn;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/portal');

Route::post('webhooks/paypal', [WebhookController::class, 'paypal'])->name('webhooks.paypal');
Route::post('webhooks/square', [WebhookController::class, 'square'])->name('webhooks.square');
Route::post('webhooks/email', InboundEmailController::class)->middleware('throttle:60,1')->name('webhooks.email');

// The public store. Guests can browse and fill a cart; checkout signs them up.
Route::middleware(EnsureClientCanLogIn::class)->group(function () {
    Route::get('store', [Store\CatalogController::class, 'index'])->name('store.index');
    Route::get('store/order/{product}', [Store\CatalogController::class, 'product'])->name('store.product');
    Route::get('store/{group}', [Store\CatalogController::class, 'group'])->name('store.group');
    Route::get('cart', [Store\CartController::class, 'index'])->name('cart.index');
    Route::middleware('throttle:30,1')->group(function () {
        Route::post('store/order/{product}', [Store\CartController::class, 'store'])->name('cart.store');
        Route::post('cart/addons', [Store\CartController::class, 'addon'])->middleware('auth:client')->name('cart.addon');
        Route::delete('cart/lines/{line}', [Store\CartController::class, 'destroy'])->name('cart.destroy');
        Route::post('cart/promotion', [Store\CartController::class, 'promotion'])->name('cart.promotion');
        Route::delete('cart/promotion', [Store\CartController::class, 'removePromotion'])->name('cart.promotion.destroy');
    });
    Route::get('checkout', [Store\CheckoutController::class, 'show'])->name('checkout.show');
    Route::post('checkout', [Store\CheckoutController::class, 'store'])->middleware('throttle:10,1')->name('checkout.store');
    Route::get('checkout/login', [Store\CheckoutController::class, 'login'])->middleware('guest:client')->name('checkout.login');
});

// The public help pages: knowledge base, announcements, network status and downloads.
Route::middleware(EnsureClientCanLogIn::class)->group(function () {
    Route::get('knowledgebase', [Help\KnowledgebaseController::class, 'index'])->name('kb.index');
    Route::get('knowledgebase/category/{category}', [Help\KnowledgebaseController::class, 'category'])->name('kb.category');
    Route::get('knowledgebase/{article}', [Help\KnowledgebaseController::class, 'article'])->whereNumber('article')->name('kb.article');
    Route::post('knowledgebase/{article}/vote', [Help\KnowledgebaseController::class, 'vote'])->middleware('throttle:20,1')->name('kb.vote');
    Route::get('announcements', [Help\AnnouncementController::class, 'index'])->name('announcements.index');
    Route::get('announcements/rss', [Help\AnnouncementController::class, 'rss'])->name('announcements.rss');
    Route::get('announcements/{announcement}', [Help\AnnouncementController::class, 'show'])->whereNumber('announcement')->name('announcements.show');
    Route::get('network-status', Help\NetworkStatusController::class)->name('network.index');
    Route::get('downloads', [Help\DownloadController::class, 'index'])->name('downloads.index');
    Route::get('downloads/{download}', [Help\DownloadController::class, 'file'])->middleware('throttle:30,1')->name('downloads.file');
});

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
        Route::post('back-to-admin', [Admin\ImpersonationController::class, 'destroy'])->name('impersonation.stop');
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
        Route::get('services/{service}/upgrade', [Portal\UpgradeController::class, 'index'])->name('services.upgrade');
        Route::get('services/{service}/upgrade/{product}', [Portal\UpgradeController::class, 'show'])->name('services.upgrade.show');
        Route::post('services/{service}/upgrade/{product}', [Portal\UpgradeController::class, 'store'])->middleware('throttle:10,1')->name('services.upgrade.store');
        Route::post('services/{service}/cancel', [Portal\CancellationController::class, 'store'])->middleware('throttle:10,1')->name('services.cancel');
        Route::delete('services/{service}/cancel', [Portal\CancellationController::class, 'destroy'])->name('services.cancel.destroy');
        Route::post('services/{service}/control-panel', [Portal\ServiceAccountController::class, 'controlPanel'])->middleware('throttle:10,1')->name('services.control-panel');
        Route::put('services/{service}/password', [Portal\ServiceAccountController::class, 'password'])->middleware('throttle:5,1')->name('services.password');
        Route::post('services/{service}/reinstall', [Portal\ServiceAccountController::class, 'reinstall'])->middleware('throttle:3,1')->name('services.reinstall');
        Route::post('services/{service}/license/reissue', [Portal\ServiceAccountController::class, 'reissue'])->middleware('throttle:10,1')->name('services.license.reissue');
        Route::get('support', [Portal\TicketController::class, 'index'])->name('tickets.index');
        Route::get('support/new', [Portal\TicketController::class, 'create'])->name('tickets.create');
        Route::middleware('throttle:20,1')->group(function () {
            Route::post('support', [Portal\TicketController::class, 'store'])->name('tickets.store');
            Route::post('support/{ticket}/reply', [Portal\TicketController::class, 'reply'])->name('tickets.reply');
        });
        Route::get('support/{ticket}', [Portal\TicketController::class, 'show'])->name('tickets.show');
        Route::post('support/{ticket}/close', [Portal\TicketController::class, 'close'])->name('tickets.close');
        Route::get('support/{ticket}/files/{attachment}', [Portal\TicketController::class, 'attachment'])->name('tickets.attachment');
        Route::get('account', [Portal\AccountController::class, 'edit'])->name('account.edit');
        Route::put('account', [Portal\AccountController::class, 'update'])->name('account.update');
        Route::put('account/password', [Portal\AccountController::class, 'password'])->name('account.password');
        Route::resource('account/contacts', Portal\ContactController::class)->except(['index', 'show'])->parameters(['contacts' => 'contact'])->names('contacts');
    });
});

Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest:web')->group(function () {
        Route::get('login', [Admin\AuthController::class, 'create'])->name('login');
        Route::post('login', [Admin\AuthController::class, 'store'])->middleware('throttle:10,1');
    });

    Route::middleware('auth:web')->group(function () {
        $service = 'services/{service}';

        Route::post('logout', [Admin\AuthController::class, 'destroy'])->name('logout');
        Route::get('/', Admin\DashboardController::class)->name('dashboard');

        // Creating and editing come first so "create" isn't read as a client id.
        Route::middleware('can:manage-clients')->group(function () use ($service) {
            Route::get('clients/create', [Admin\ClientController::class, 'create'])->name('clients.create');
            Route::post('clients', [Admin\ClientController::class, 'store'])->name('clients.store');
            Route::get('clients/{client}/edit', [Admin\ClientController::class, 'edit'])->name('clients.edit');
            Route::put('clients/{client}', [Admin\ClientController::class, 'update'])->name('clients.update');
            Route::post('emails/{email}/resend', [Admin\EmailLogController::class, 'resend'])->name('emails.resend');
            Route::get('clients/{client}/contacts/create', [Admin\ClientContactController::class, 'create'])->name('contacts.create');
            Route::post('clients/{client}/contacts', [Admin\ClientContactController::class, 'store'])->name('contacts.store');
            Route::resource('contacts', Admin\ClientContactController::class)->only(['edit', 'update', 'destroy']);
            Route::post('clients/{client}/login-as', [Admin\ImpersonationController::class, 'store'])->name('clients.login-as');
            Route::post('clients/{client}/password-link', [Admin\ClientController::class, 'sendPasswordLink'])->name('clients.password-link');
            Route::get('clients/{client}/services/create', [Admin\ServiceController::class, 'create'])->name('services.create');
            Route::post('clients/{client}/services', [Admin\ServiceController::class, 'store'])->name('services.store');
            Route::put('tasks/{task}', [Admin\TaskController::class, 'update'])->name('tasks.update');
            Route::put($service, [Admin\ServiceController::class, 'update'])->name('services.update');
            Route::get("{$service}/move", [Admin\ServiceMoveController::class, 'show'])->name('services.move');
            Route::post("{$service}/move", [Admin\ServiceMoveController::class, 'store'])->name('services.move.store');
            Route::post("{$service}/cancellations", [Admin\CancellationController::class, 'store'])->name('cancellations.store');
            Route::delete('cancellations/{cancellation}', [Admin\CancellationController::class, 'destroy'])->name('cancellations.destroy');
            Route::post('services/{service}/provision', [Admin\ServiceController::class, 'provision'])->name('services.provision');
            Route::put("{$service}/server", [Admin\ServiceController::class, 'server'])->name('services.server');
            Route::post("{$service}/control-panel", [Admin\ServiceController::class, 'controlPanel'])->name('services.control-panel');
            Route::post("{$service}/account-moves", [Admin\AccountMoveController::class, 'store'])->name('account-moves.store');
            Route::post('account-moves/{move}/finish', [Admin\AccountMoveController::class, 'finish'])->name('account-moves.finish');
            Route::delete('account-moves/{move}', [Admin\AccountMoveController::class, 'destroy'])->name('account-moves.destroy');
            Route::post("{$service}/license/reissue", [Admin\ServiceController::class, 'reissueLicense'])->name('services.license.reissue');
            Route::post('services/{service}/{action}', [Admin\ServiceController::class, 'action'])
                ->whereIn('action', ['suspend', 'unsuspend', 'terminate'])
                ->name('services.action');
        });

        Route::middleware('can:manage-billing')->group(function () {
            Route::get('clients/{client}/invoices/create', [Admin\InvoiceController::class, 'create'])->name('invoices.create');
            Route::post('clients/{client}/invoices', [Admin\InvoiceController::class, 'store'])->name('invoices.store');
            Route::resource('invoices', Admin\InvoiceController::class)->only(['edit', 'update']);
            Route::post('clients/{client}/credit', [Admin\CreditController::class, 'store'])->name('clients.credit');
            Route::post('invoices/{invoice}/publish', [Admin\InvoiceController::class, 'publish'])->name('invoices.publish');
            Route::post('invoices/{invoice}/payments', [Admin\InvoiceController::class, 'pay'])->name('invoices.pay');
            Route::post('invoices/{invoice}/cancel', [Admin\InvoiceController::class, 'cancel'])->name('invoices.cancel');
            Route::post('invoices/{invoice}/credit', [Admin\InvoiceController::class, 'applyCredit'])->name('invoices.credit');
            Route::post('transactions/{transaction}/refund', [Admin\InvoiceController::class, 'refund'])->name('transactions.refund');
            Route::post('orders/{order}/accept', [Admin\OrderController::class, 'accept'])->name('orders.accept');
            Route::post('orders/{order}/cancel', [Admin\OrderController::class, 'cancel'])->name('orders.cancel');
            Route::post('orders/{order}/fraud', [Admin\OrderController::class, 'fraud'])->name('orders.fraud');
        });

        // Every staff role can look.
        Route::get('clients', [Admin\ClientController::class, 'index'])->name('clients.index');
        Route::get('clients/{client}', [Admin\ClientController::class, 'show'])->name('clients.show');
        Route::get($service, [Admin\ServiceController::class, 'show'])->name('services.show');
        Route::get('tasks', [Admin\TaskController::class, 'index'])->name('tasks.index');
        Route::get('tasks/{task}', [Admin\TaskController::class, 'show'])->name('tasks.show');
        Route::resource('invoices', Admin\InvoiceController::class)->only(['index', 'show']);
        Route::resource('orders', Admin\OrderController::class)->only(['index', 'show']);
        Route::get('invoices/{invoice}/pdf', [Admin\InvoiceController::class, 'pdf'])->name('invoices.pdf');

        Route::middleware('can:manage-tickets')->group(function () {
            Route::resource('canned-replies', Admin\CannedReplyController::class)->except(['show', 'create']);
            Route::get('tickets', [Admin\TicketController::class, 'index'])->name('tickets.index');
            Route::get('tickets/{ticket}', [Admin\TicketController::class, 'show'])->name('tickets.show');
            Route::put('tickets/{ticket}', [Admin\TicketController::class, 'update'])->name('tickets.update');
            Route::post('tickets/{ticket}/reply', [Admin\TicketController::class, 'reply'])->name('tickets.reply');
            Route::get('tickets/{ticket}/files/{attachment}', [Admin\TicketController::class, 'attachment'])->name('tickets.attachment');

            // Knowledge base, announcements, network status and downloads.
            Route::get('knowledgebase', [Admin\KbCategoryController::class, 'index'])->name('kb.index');
            Route::resource('kb-categories', Admin\KbCategoryController::class)->only(['store', 'edit', 'update', 'destroy']);
            Route::resource('kb-articles', Admin\KbArticleController::class)->except(['index', 'show']);
            Route::resource('announcements', Admin\AnnouncementController::class)->except('show');
            Route::resource('network-issues', Admin\NetworkIssueController::class)->except('show');
            Route::post('network-issues/{network_issue}/resolve', [Admin\NetworkIssueController::class, 'resolve'])->name('network-issues.resolve');
            Route::resource('downloads', Admin\DownloadController::class)->except('show');
            Route::resource('download-categories', Admin\DownloadCategoryController::class)->only(['store', 'edit', 'update', 'destroy']);
        });

        Route::middleware('can:manage-settings')->group(function () {
            Route::resource('custom-fields', Admin\CustomFieldController::class)->except(['show', 'create']);
            Route::resource('departments', Admin\DepartmentController::class)->only(['index', 'store', 'edit', 'update']);
            Route::resource('escalation-rules', Admin\EscalationRuleController::class)->except('show');
            Route::get('email-templates', [Admin\EmailTemplateController::class, 'index'])->name('email-templates.index');
            Route::get('email-templates/{key}', [Admin\EmailTemplateController::class, 'edit'])->name('email-templates.edit');
            Route::put('email-templates/{key}', [Admin\EmailTemplateController::class, 'update'])->name('email-templates.update');
            Route::get('emails', [Admin\EmailLogController::class, 'index'])->name('emails.index');
            Route::get('emails/{email}', [Admin\EmailLogController::class, 'show'])->name('emails.show');
            Route::resource('products', Admin\ProductController::class)->except(['show', 'destroy']);
            Route::resource('product-groups', Admin\ProductGroupController::class)->except(['show', 'create']);
            Route::resource('promotions', Admin\PromotionController::class)->except(['show', 'create']);
            Route::get('products/{product}/options', [Admin\ProductOptionController::class, 'index'])->name('product-options.index');
            Route::post('products/{product}/options', [Admin\ProductOptionController::class, 'store'])->name('product-options.store');
            Route::resource('product-options', Admin\ProductOptionController::class)->only(['edit', 'update', 'destroy']);
            Route::resource('servers', Admin\ServerController::class)->except('show');
            Route::post('servers/{server}/test', [Admin\ServerController::class, 'test'])->name('servers.test');
            Route::post('servers/{server}/check', [Admin\ServerController::class, 'check'])->name('servers.check');
            Route::resource('server-groups', Admin\ServerGroupController::class)->only(['create', 'store', 'edit', 'update', 'destroy']);
            Route::resource('tax-rules', Admin\TaxRuleController::class)->except(['show', 'create']);
            Route::get('activity', Admin\ActivityController::class)->name('activity.index');
            Route::get('settings', [Admin\SettingsController::class, 'edit'])->name('settings.edit');
            Route::put('settings', [Admin\SettingsController::class, 'update'])->name('settings.update');
            Route::prefix('settings/whmcs-import')->name('whmcs-import.')->controller(Admin\WhmcsImportController::class)->group(function () {
                Route::get('/', 'show')->name('show');
                Route::post('connect', 'connect')->name('connect');
                Route::post('dry-run', 'dryRun')->name('dry-run');
                Route::post('import', 'import')->name('import');
                Route::get('report/{run}', 'report')->whereIn('run', ['dry_run', 'import'])->name('report');
                Route::delete('/', 'reset')->name('reset');
            });
        });

        Route::middleware('can:manage-staff')->group(function () {
            Route::resource('staff', Admin\StaffController::class)->except('show')->parameters(['staff' => 'user']);
        });
    });
});
