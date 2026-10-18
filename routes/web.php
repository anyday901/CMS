<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Help;
use App\Http\Controllers\InboundEmailController;
use App\Http\Controllers\MarketingUnsubscribeController;
use App\Http\Controllers\Portal;
use App\Http\Controllers\Store;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\EnsureClientCanLogIn;
use App\Http\Middleware\RequireStaffTwoFactor;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/portal');

Route::post('webhooks/paypal', [WebhookController::class, 'paypal'])->name('webhooks.paypal');
Route::post('webhooks/square', [WebhookController::class, 'square'])->name('webhooks.square');
Route::post('webhooks/email', InboundEmailController::class)->middleware('throttle:60,1')->name('webhooks.email');

// The unsubscribe link for marketing emails, signed for each client.
Route::middleware(['signed', 'throttle:20,1'])->controller(MarketingUnsubscribeController::class)->group(function () {
    Route::get('unsubscribe/{client}', 'show')->name('marketing.unsubscribe');
    Route::post('unsubscribe/{client}', 'store');
});

// The public store. Guests can browse and fill a cart; checkout signs them up.
Route::middleware(EnsureClientCanLogIn::class)->group(function () {
    Route::get('store', [Store\CatalogController::class, 'index'])->name('store.index');
    Route::get('store/order/{product}', [Store\CatalogController::class, 'product'])->name('store.product');
    Route::get('store/{group}', [Store\CatalogController::class, 'group'])->name('store.group');
    Route::get('cart', [Store\CartController::class, 'index'])->name('cart.index');
    Route::middleware('throttle:30,1')->group(function () {
        Route::post('store/order/{product}', [Store\CartController::class, 'store'])->name('cart.store');
        Route::post('cart/addons', [Store\CartController::class, 'addon'])->middleware(['auth:client', 'contact.can:orders'])->name('cart.addon');
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
    Route::post('help/language', Help\LanguageController::class)->name('help.language');

    // Tickets from people without an account, when guest tickets are on.
    Route::get('support/contact', [Help\GuestTicketController::class, 'create'])->name('tickets.guest.create');
    Route::post('support/contact', [Help\GuestTicketController::class, 'store'])->middleware('throttle:5,1')->name('tickets.guest.store');
    Route::get('support/suggest', [Portal\TicketController::class, 'suggest'])->middleware('throttle:60,1')->name('tickets.guest.suggest');
    Route::prefix('support/tickets/{ticket}/{key}')->whereNumber('ticket')->name('tickets.guest.')->controller(Help\GuestTicketController::class)->group(function () {
        Route::get('/', 'show')->middleware('throttle:60,1')->name('show');
        Route::post('reply', 'reply')->middleware('throttle:20,1')->name('reply');
        Route::post('close', 'close')->middleware('throttle:10,1')->name('close');
        Route::post('feedback', 'feedback')->middleware('throttle:10,1')->name('feedback');
        Route::get('files/{attachment}', 'attachment')->middleware('throttle:30,1')->name('attachment');
    });
});

Route::prefix('portal')->name('portal.')->group(function () {
    Route::middleware('guest:client')->group(function () {
        Route::get('login', [Portal\AuthController::class, 'create'])->name('login');
        Route::post('login', [Portal\AuthController::class, 'store'])->middleware('throttle:10,1');
        Route::get('two-factor', [Portal\TwoFactorChallengeController::class, 'create'])->name('two-factor.challenge');
        Route::post('two-factor', [Portal\TwoFactorChallengeController::class, 'store'])->middleware('throttle:10,1');
        Route::get('forgot-password', [Portal\PasswordController::class, 'request'])->name('password.request');
        Route::post('forgot-password', [Portal\PasswordController::class, 'email'])->middleware('throttle:5,1')->name('password.email');
        Route::get('reset-password/{token}', [Portal\PasswordController::class, 'edit'])->name('password.reset');
        Route::post('reset-password', [Portal\PasswordController::class, 'update'])->middleware('throttle:10,1')->name('password.update');
    });

    // The link in the verification email works signed in or not.
    Route::get('verify-email/{client}/{hash}', [Portal\EmailVerificationController::class, 'verify'])->middleware(['signed', 'throttle:10,1'])->name('verification.verify');

    Route::middleware(['auth:client', EnsureClientCanLogIn::class])->group(function () {
        Route::post('logout', [Portal\AuthController::class, 'destroy'])->name('logout');
        Route::post('back-to-admin', [Admin\ImpersonationController::class, 'destroy'])->name('impersonation.stop');
        Route::get('/', Portal\DashboardController::class)->name('dashboard');
        Route::get('accounts', [Portal\AccountSwitchController::class, 'index'])->name('accounts');
        Route::post('accounts/{contact}', [Portal\AccountSwitchController::class, 'store'])->middleware('throttle:30,1')->name('accounts.switch');

        // Everyone signed in: the account holder and contacts manage their own login here.
        Route::prefix('security')->name('security.')->controller(Portal\SecurityController::class)->group(function () {
            Route::get('/', 'show')->name('show');
            Route::put('password', 'password')->middleware('throttle:5,1')->name('password');
            Route::post('two-factor', 'startTwoFactor')->name('two-factor.start');
            Route::post('two-factor/confirm', 'confirmTwoFactor')->middleware('throttle:10,1')->name('two-factor.confirm');
            Route::delete('two-factor/setup', 'cancelTwoFactorSetup')->name('two-factor.cancel');
            Route::delete('two-factor', 'disableTwoFactor')->middleware('throttle:5,1')->name('two-factor.disable');
            Route::post('two-factor/recovery-codes', 'recoveryCodes')->middleware('throttle:5,1')->name('two-factor.recovery-codes');
        });

        // Contacts only see what their login allows; the account holder sees everything.
        Route::middleware('contact.can:billing')->group(function () {
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
        });
        Route::middleware('contact.can:services')->group(function () {
            Route::get('services', [Portal\ServiceController::class, 'index'])->name('services.index');
            Route::get('services/{service}', [Portal\ServiceController::class, 'show'])->name('services.show');
            Route::post('services/{service}/control-panel', [Portal\ServiceAccountController::class, 'controlPanel'])->middleware('throttle:10,1')->name('services.control-panel');
            Route::put('services/{service}/password', [Portal\ServiceAccountController::class, 'password'])->middleware('throttle:5,1')->name('services.password');
            Route::post('services/{service}/reinstall', [Portal\ServiceAccountController::class, 'reinstall'])->middleware('throttle:3,1')->name('services.reinstall');
            Route::post('services/{service}/license/reissue', [Portal\ServiceAccountController::class, 'reissue'])->middleware('throttle:10,1')->name('services.license.reissue');
        });
        Route::middleware('contact.can:orders')->group(function () {
            Route::get('services/{service}/upgrade', [Portal\UpgradeController::class, 'index'])->name('services.upgrade');
            Route::get('services/{service}/upgrade/{product}', [Portal\UpgradeController::class, 'show'])->name('services.upgrade.show');
            Route::post('services/{service}/upgrade/{product}', [Portal\UpgradeController::class, 'store'])->middleware('throttle:10,1')->name('services.upgrade.store');
            Route::post('services/{service}/cancel', [Portal\CancellationController::class, 'store'])->middleware('throttle:10,1')->name('services.cancel');
            Route::delete('services/{service}/cancel', [Portal\CancellationController::class, 'destroy'])->name('services.cancel.destroy');
        });
        Route::middleware('contact.can:support')->group(function () {
            Route::get('support', [Portal\TicketController::class, 'index'])->name('tickets.index');
            Route::get('support/new', [Portal\TicketController::class, 'create'])->name('tickets.create');
            Route::get('support/suggest', [Portal\TicketController::class, 'suggest'])->middleware('throttle:60,1')->name('tickets.suggest');
            Route::middleware('throttle:20,1')->group(function () {
                Route::post('support', [Portal\TicketController::class, 'store'])->name('tickets.store');
                Route::post('support/{ticket}/reply', [Portal\TicketController::class, 'reply'])->name('tickets.reply');
            });
            Route::get('support/{ticket}', [Portal\TicketController::class, 'show'])->name('tickets.show');
            Route::post('support/{ticket}/close', [Portal\TicketController::class, 'close'])->name('tickets.close');
            Route::post('support/{ticket}/feedback', [Portal\TicketController::class, 'feedback'])->middleware('throttle:10,1')->name('tickets.feedback');
            Route::get('support/{ticket}/files/{attachment}', [Portal\TicketController::class, 'attachment'])->name('tickets.attachment');
        });

        // The account holder only.
        Route::middleware('contact.can:owner')->group(function () {
            Route::get('account', [Portal\AccountController::class, 'edit'])->name('account.edit');
            Route::put('account', [Portal\AccountController::class, 'update'])->name('account.update');
            Route::put('account/password', [Portal\AccountController::class, 'password'])->name('account.password');
            Route::put('account/marketing', [Portal\AccountController::class, 'marketing'])->name('account.marketing');
            Route::get('account/export', [Portal\AccountController::class, 'export'])->middleware('throttle:5,1')->name('account.export');
            Route::post('account/verify-email', [Portal\EmailVerificationController::class, 'resend'])->middleware('throttle:3,10')->name('verification.send');
            Route::resource('account/contacts', Portal\ContactController::class)->except(['index', 'show'])->parameters(['contacts' => 'contact'])->names('contacts');
            Route::post('account/contacts/{contact}/invite', [Portal\ContactController::class, 'resendInvite'])->middleware('throttle:5,1')->name('contacts.invite');
        });
    });
});

Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest:web')->group(function () {
        Route::get('login', [Admin\AuthController::class, 'create'])->name('login');
        Route::post('login', [Admin\AuthController::class, 'store'])->middleware('throttle:10,1');
        Route::get('two-factor', [Admin\AuthController::class, 'challenge'])->name('two-factor.challenge');
        Route::post('two-factor', [Admin\AuthController::class, 'verify'])->middleware('throttle:10,1');
    });

    Route::middleware(['auth:web', RequireStaffTwoFactor::class])->group(function () {
        $service = 'services/{service}';

        Route::post('logout', [Admin\AuthController::class, 'destroy'])->name('logout');
        Route::get('/', Admin\DashboardController::class)->name('dashboard');

        // Creating and editing come first so "create" isn't read as a client id.
        Route::middleware('can:manage-clients')->group(function () use ($service) {
            Route::get('clients/create', [Admin\ClientController::class, 'create'])->name('clients.create');
            Route::get('clients/export', [Admin\ClientController::class, 'export'])->name('clients.export');
            Route::post('clients', [Admin\ClientController::class, 'store'])->name('clients.store');
            Route::get('clients/{client}/edit', [Admin\ClientController::class, 'edit'])->name('clients.edit');
            Route::put('clients/{client}', [Admin\ClientController::class, 'update'])->name('clients.update');
            Route::post('emails/{email}/resend', [Admin\EmailLogController::class, 'resend'])->name('emails.resend');
            Route::get('clients/{client}/contacts/create', [Admin\ClientContactController::class, 'create'])->name('contacts.create');
            Route::post('clients/{client}/contacts', [Admin\ClientContactController::class, 'store'])->name('contacts.store');
            Route::resource('contacts', Admin\ClientContactController::class)->only(['edit', 'update', 'destroy']);
            Route::post('clients/{client}/login-as', [Admin\ImpersonationController::class, 'store'])->name('clients.login-as');
            Route::post('clients/{client}/password-link', [Admin\ClientController::class, 'sendPasswordLink'])->name('clients.password-link');
            Route::post('contacts/{contact}/invite', [Admin\ClientContactController::class, 'resendInvite'])->name('contacts.invite');
            Route::delete('contacts/{contact}/two-factor', [Admin\ClientPrivacyController::class, 'resetContactTwoFactor'])->name('contacts.two-factor.reset');
            Route::post('clients/{client}/notes', [Admin\ClientNoteController::class, 'store'])->name('client-notes.store');
            Route::resource('client-notes', Admin\ClientNoteController::class)->only(['update', 'destroy'])->parameters(['client-notes' => 'note']);
            Route::get('clients/{client}/merge', [Admin\ClientMergeController::class, 'show'])->name('clients.merge');
            Route::post('clients/{client}/merge', [Admin\ClientMergeController::class, 'store'])->name('clients.merge.store');
            Route::name('clients.')->controller(Admin\ClientPrivacyController::class)->group(function () {
                Route::get('clients/{client}/export', 'export')->name('export');
                Route::post('clients/{client}/anonymise', 'anonymise')->name('anonymise');
                Route::post('clients/{client}/verify-email', 'verifyEmail')->name('verify-email');
                Route::post('clients/{client}/verification-link', 'sendVerification')->name('verification-link');
                Route::delete('clients/{client}/two-factor', 'resetTwoFactor')->name('two-factor.reset');
            });
            Route::get('clients/{client}/services/create', [Admin\ServiceController::class, 'create'])->name('services.create');
            Route::post('clients/{client}/services', [Admin\ServiceController::class, 'store'])->name('services.store');
            Route::put('tasks/{task}', [Admin\TaskController::class, 'update'])->name('tasks.update');
            Route::put($service, [Admin\ServiceController::class, 'update'])->name('services.update');
            Route::get("{$service}/move", [Admin\ServiceMoveController::class, 'show'])->name('services.move');
            Route::post("{$service}/move", [Admin\ServiceMoveController::class, 'store'])->name('services.move.store');
            Route::post("{$service}/cancellations", [Admin\CancellationController::class, 'store'])->name('cancellations.store');
            Route::delete('cancellations/{cancellation}', [Admin\CancellationController::class, 'destroy'])->name('cancellations.destroy');
            Route::post('services/{service}/provision', [Admin\ServiceController::class, 'provision'])->name('services.provision');
            Route::put("{$service}/details", [Admin\ServiceController::class, 'details'])->name('services.details');
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

        // Every staff role can look, and keep a to-do list.
        Route::get('search', Admin\SearchController::class)->name('search');
        Route::resource('todos', Admin\TodoController::class)->except(['show', 'create']);
        Route::put('todos/{todo}/status', [Admin\TodoController::class, 'status'])->name('todos.status');
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
            Route::get('tickets/feedback', Admin\TicketFeedbackController::class)->name('tickets.feedback');
            Route::get('tickets/inbound', Admin\InboundEmailController::class)->name('tickets.inbound');
            Route::get('tickets/{ticket}', [Admin\TicketController::class, 'show'])->name('tickets.show');
            Route::put('tickets/{ticket}', [Admin\TicketController::class, 'update'])->name('tickets.update');
            Route::post('tickets/{ticket}/reply', [Admin\TicketController::class, 'reply'])->name('tickets.reply');
            Route::post('tickets/{ticket}/client', [Admin\TicketController::class, 'linkClient'])->name('tickets.client');
            Route::post('tickets/{ticket}/merge', [Admin\TicketController::class, 'merge'])->name('tickets.merge');
            Route::post('tickets/{ticket}/split', [Admin\TicketController::class, 'split'])->name('tickets.split');
            Route::get('tickets/{ticket}/files/{attachment}', [Admin\TicketController::class, 'attachment'])->name('tickets.attachment');

            // Knowledge base, announcements, network status and downloads.
            Route::get('knowledgebase', [Admin\KbCategoryController::class, 'index'])->name('kb.index');
            Route::resource('kb-categories', Admin\KbCategoryController::class)->only(['store', 'edit', 'update', 'destroy']);
            Route::resource('kb-articles', Admin\KbArticleController::class)->except(['index', 'show']);
            Route::resource('announcements', Admin\AnnouncementController::class)->except('show');
            Route::prefix('translations/{type}/{id}/{locale}')->whereIn('type', ['kb-articles', 'kb-categories', 'announcements'])->whereNumber('id')->name('translations.')->controller(Admin\TranslationController::class)->group(function () {
                Route::put('/', 'update')->name('update');
                Route::delete('/', 'destroy')->name('destroy');
            });
            Route::resource('network-issues', Admin\NetworkIssueController::class)->except('show');
            Route::post('network-issues/{network_issue}/resolve', [Admin\NetworkIssueController::class, 'resolve'])->name('network-issues.resolve');
            Route::resource('downloads', Admin\DownloadController::class)->except('show');
            Route::resource('download-categories', Admin\DownloadCategoryController::class)->only(['store', 'edit', 'update', 'destroy']);
        });

        Route::middleware('can:manage-settings')->group(function () {
            Route::resource('custom-fields', Admin\CustomFieldController::class)->except(['show', 'create']);
            Route::resource('client-groups', Admin\ClientGroupController::class)->except(['show', 'create']);
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
            Route::get('settings/automation', [Admin\AutomationController::class, 'edit'])->name('automation.edit');
            Route::put('settings/automation', [Admin\AutomationController::class, 'update'])->name('automation.update');
            Route::get('settings/cron', Admin\CronController::class)->name('cron.show');
        });
    });
});

// Kept outside the admin group above so that group stays readable; same login and two-factor checks.
Route::prefix('admin/reports')->name('admin.reports.')->middleware(['auth:web', RequireStaffTwoFactor::class, 'can:view-reports'])->controller(Admin\ReportController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('{report}', 'show')->name('show');
    Route::get('{report}/export', 'export')->name('export');
});

// Every staff member's own login.
Route::prefix('admin/account')->name('admin.account.')->middleware(['auth:web', RequireStaffTwoFactor::class])
    ->controller(Admin\AccountController::class)->group(function () {
        Route::get('/', 'show')->name('show');
        Route::put('password', 'password')->middleware('throttle:5,1')->name('password');
        Route::post('two-factor', 'startTwoFactor')->name('two-factor.start');
        Route::post('two-factor/confirm', 'confirmTwoFactor')->middleware('throttle:10,1')->name('two-factor.confirm');
        Route::delete('two-factor/setup', 'cancelTwoFactorSetup')->name('two-factor.cancel');
        Route::delete('two-factor', 'disableTwoFactor')->middleware('throttle:5,1')->name('two-factor.disable');
        Route::post('two-factor/recovery-codes', 'recoveryCodes')->middleware('throttle:5,1')->name('two-factor.recovery-codes');
    });

// Managing staff logins.
Route::prefix('admin')->name('admin.')->middleware(['auth:web', RequireStaffTwoFactor::class, 'can:manage-staff'])->group(function () {
    Route::resource('staff', Admin\StaffController::class)->except('show')->parameters(['staff' => 'user']);
    Route::delete('staff/{user}/two-factor', [Admin\StaffController::class, 'resetTwoFactor'])->name('staff.two-factor.reset');
});

// Keys for the REST API (see docs/api.md).
Route::prefix('admin/settings/api-keys')->name('admin.api-keys.')->middleware(['auth:web', RequireStaffTwoFactor::class, 'can:manage-settings'])
    ->controller(Admin\ApiKeyController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::get('{apiKey}/edit', 'edit')->name('edit');
        Route::put('{apiKey}', 'update')->name('update');
        Route::post('{apiKey}/revoke', 'revoke')->name('revoke');
        Route::delete('{apiKey}', 'destroy')->name('destroy');
    });

// Mass mail, for staff who manage clients.
Route::prefix('admin/mass-mail')->name('admin.mass-mail.')->middleware(['auth:web', RequireStaffTwoFactor::class, 'can:manage-clients'])->group(function () {
    Route::get('/', [Admin\MassMailController::class, 'index'])->name('index');
    Route::get('create', [Admin\MassMailController::class, 'create'])->name('create');
    Route::post('preview', [Admin\MassMailController::class, 'preview'])->name('preview');
    Route::post('/', [Admin\MassMailController::class, 'store'])->middleware('throttle:10,1')->name('store');
    Route::get('{massMail}', [Admin\MassMailController::class, 'show'])->name('show');
});

// Add-ons, webhooks, chat and SMS, under Settings > Integrations.
Route::prefix('admin/settings')->name('admin.')->middleware(['auth:web', RequireStaffTwoFactor::class, 'can:manage-settings'])->group(function () {
    Route::get('addons', [Admin\AddonController::class, 'index'])->name('addons.index');
    Route::prefix('addons/{key}')->name('addons.')->controller(Admin\AddonController::class)->group(function () {
        Route::get('/', 'edit')->name('edit');
        Route::put('/', 'update')->name('update');
        Route::delete('/', 'destroy')->name('destroy');
        foreach (['install', 'enable', 'disable', 'upgrade'] as $action) {
            Route::post($action, $action)->name($action);
        }
    });
    Route::resource('webhooks', Admin\WebhookController::class)->except(['show', 'create']);
    Route::post('webhook-deliveries/{delivery}/redeliver', [Admin\WebhookController::class, 'redeliver'])->name('webhook-deliveries.redeliver');
    Route::post('webhooks/{webhook}/test', [Admin\WebhookController::class, 'test'])->name('webhooks.test');
    Route::post('webhooks/{webhook}/secret', [Admin\WebhookController::class, 'secret'])->name('webhooks.secret');
    Route::resource('chat', Admin\NotificationChannelController::class)->except(['show', 'create'])->names('chat-channels')->parameters(['chat' => 'chatChannel']);
    Route::post('chat/{chatChannel}/test', [Admin\NotificationChannelController::class, 'test'])->name('chat-channels.test');
    Route::controller(Admin\SmsSettingsController::class)->group(function () {
        Route::get('sms', 'edit')->name('sms.edit');
        Route::put('sms', 'update')->name('sms.update');
        Route::post('sms/test', 'test')->middleware('throttle:5,1')->name('sms.test');
    });
    Route::prefix('security')->name('security.')->controller(Admin\SecurityController::class)->group(function () {
        Route::get('/', 'show')->name('show');
        Route::put('/', 'update')->name('update');
        Route::post('bans', 'ban')->name('bans.store');
        Route::delete('bans/{ban}', 'unban')->name('bans.destroy');
    });
    Route::prefix('whmcs-import')->name('whmcs-import.')->controller(Admin\WhmcsImportController::class)->group(function () {
        Route::get('/', 'show')->name('show');
        Route::post('connect', 'connect')->name('connect');
        Route::post('dry-run', 'dryRun')->name('dry-run');
        Route::post('import', 'import')->name('import');
        Route::post('emails', 'emails')->name('emails');
        Route::post('logins', 'logins')->name('logins');
        Route::get('report/{run}', 'report')->whereIn('run', ['dry_run', 'import', 'emails', 'logins'])->name('report');
        Route::delete('/', 'reset')->name('reset');
    });
});

// Affiliates: referral links, the portal's affiliate page, and the admin area for affiliates, payouts and settings.
Route::middleware(EnsureClientCanLogIn::class)->controller(Store\AffiliateLinkController::class)->group(function () {
    Route::get('r/{code}', 'visit')->middleware('throttle:60,1')->name('affiliates.visit');
    Route::get('aff.php', 'whmcs')->middleware('throttle:60,1')->name('affiliates.whmcs');
});
Route::prefix('portal/affiliates')->name('portal.affiliates.')->middleware(['auth:client', EnsureClientCanLogIn::class, 'contact.can:owner'])
    ->controller(Portal\AffiliateController::class)->group(function () {
        Route::get('/', 'show')->name('show');
        Route::post('/', 'store')->middleware('throttle:10,1')->name('store');
        Route::post('payouts', 'payout')->middleware('throttle:10,1')->name('payouts.store');
    });
Route::prefix('admin')->name('admin.')->middleware(['auth:web', RequireStaffTwoFactor::class])->group(function () {
    Route::middleware('can:manage-settings')->controller(Admin\AffiliateSettingsController::class)->group(function () {
        Route::get('affiliates/settings', 'edit')->name('affiliates.settings');
        Route::put('affiliates/settings', 'update')->name('affiliates.settings.update');
    });
    Route::middleware('can:manage-billing')->group(function () {
        Route::get('affiliates', [Admin\AffiliateController::class, 'index'])->name('affiliates.index');
        Route::post('affiliates', [Admin\AffiliateController::class, 'store'])->name('affiliates.store');
        Route::get('affiliates/payouts', [Admin\AffiliatePayoutController::class, 'index'])->name('affiliates.payouts');
        Route::post('affiliates/payouts/{payout}/pay', [Admin\AffiliatePayoutController::class, 'pay'])->name('affiliates.payouts.pay');
        Route::post('affiliates/payouts/{payout}/reject', [Admin\AffiliatePayoutController::class, 'reject'])->name('affiliates.payouts.reject');
        Route::post('affiliates/commissions/{commission}/approve', [Admin\AffiliateCommissionController::class, 'approve'])->name('affiliates.commissions.approve');
        Route::post('affiliates/commissions/{commission}/void', [Admin\AffiliateCommissionController::class, 'void'])->name('affiliates.commissions.void');
        Route::put('clients/{client}/referrer', [Admin\AffiliateController::class, 'referrer'])->name('affiliates.referrer');
        Route::get('affiliates/{affiliate}', [Admin\AffiliateController::class, 'show'])->whereNumber('affiliate')->name('affiliates.show');
        Route::put('affiliates/{affiliate}', [Admin\AffiliateController::class, 'update'])->whereNumber('affiliate')->name('affiliates.update');
    });
});

// Domains: the store's search, price list and WHOIS lookup; the client's domains in the portal; the admin area's domains, TLDs and settings.
Route::middleware(EnsureClientCanLogIn::class)->prefix('domains')->name('domains.')->controller(Store\DomainController::class)->group(function () {
    Route::get('/', 'index')->middleware('throttle:30,1')->name('index');
    Route::get('whois', 'whois')->middleware('throttle:20,1')->name('whois');
    Route::post('cart', 'add')->middleware('throttle:30,1')->name('cart');
});
Route::prefix('portal/domains')->name('portal.domains.')->middleware(['auth:client', EnsureClientCanLogIn::class])
    ->controller(Portal\DomainController::class)->group(function () {
        Route::middleware('contact.can:services')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('{domain}', 'show')->whereNumber('domain')->name('show');
            Route::middleware('throttle:20,1')->group(function () {
                Route::put('{domain}/nameservers', 'nameservers')->name('nameservers');
                Route::put('{domain}/dns', 'dns')->name('dns');
                Route::put('{domain}/contact', 'contact')->name('contact');
                Route::put('{domain}/id-protection', 'idProtection')->name('id-protection');
                Route::put('{domain}/lock', 'lock')->name('lock');
            });
            Route::post('{domain}/epp-code', 'eppCode')->middleware('throttle:5,1')->name('epp-code');
        });
        Route::middleware('contact.can:orders')->group(function () {
            Route::put('{domain}/auto-renew', 'autoRenew')->name('auto-renew');
            Route::post('{domain}/renew', 'renew')->middleware('throttle:10,1')->name('renew');
        });
    });
Route::prefix('admin')->name('admin.')->middleware(['auth:web', RequireStaffTwoFactor::class])->group(function () {
    Route::get('domains', [Admin\DomainController::class, 'index'])->name('domains.index');
    Route::get('domains/{domain}', [Admin\DomainController::class, 'show'])->whereNumber('domain')->name('domains.show');
    Route::middleware('can:manage-clients')->controller(Admin\DomainController::class)->group(function () {
        Route::get('clients/{client}/domains/create', 'create')->name('domains.create');
        Route::post('clients/{client}/domains', 'store')->name('domains.store');
        Route::put('domains/{domain}', 'update')->name('domains.update');
        Route::put('domains/{domain}/nameservers', 'nameservers')->name('domains.nameservers');
        Route::post('domains/{domain}/epp-code', 'eppCode')->name('domains.epp-code');
        Route::post('domains/{domain}/{action}', 'action')->whereIn('action', ['register', 'transfer', 'renew', 'sync'])->name('domains.action');
    });
    Route::middleware('can:manage-settings')->group(function () {
        Route::resource('tlds', Admin\TldController::class)->except('show');
        Route::controller(Admin\DomainSettingsController::class)->group(function () {
            Route::get('settings/domains', 'edit')->name('domain-settings.edit');
            Route::put('settings/domains', 'update')->name('domain-settings.update');
            Route::post('settings/domains/{registrar}/test', 'test')->name('domain-settings.test');
        });
    });
});

require __DIR__.'/platform.php';
