<?php

use App\Http\Middleware\BlockBannedIps;
use App\Http\Middleware\EnsureAddonEnabled;
use App\Http\Middleware\EnsureContactCan;
use App\Http\Middleware\RecallPortalContact;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Mail apps' one-click unsubscribe posts without a session; the link is signed instead.
        $middleware->validateCsrfTokens(except: ['webhooks/*', 'unsubscribe/*']);
        $middleware->web(append: [BlockBannedIps::class, RecallPortalContact::class]);
        $middleware->alias(['contact.can' => EnsureContactCan::class]);
        // A remembered contact is signed back in before routes check who is signed in.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: RecallPortalContact::class);
        // A disabled or uninstalled add-on answers 404 before its routes look up records in tables that may not exist.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureAddonEnabled::class);
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('admin*')
            ? route('admin.login')
            : route('portal.login'));
        $middleware->redirectUsersTo(fn (Request $request) => match (true) {
            $request->is('checkout*') => route('checkout.show'),
            $request->is('admin*') => route('admin.dashboard'),
            default => route('portal.dashboard'),
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
