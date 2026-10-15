<?php

use App\Http\Middleware\BlockBannedIps;
use App\Http\Middleware\EnsureContactCan;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: ['webhooks/*']);
        $middleware->web(append: [BlockBannedIps::class]);
        $middleware->alias(['contact.can' => EnsureContactCan::class]);
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
