<?php

namespace App\Http\Middleware;

use App\Support\Language;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shows client-facing pages in the visitor's language (see Language::current()).
 * The admin area stays in the app's own language.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('admin', 'admin/*')) {
            app()->setLocale(Language::current());
        }

        return $next($request);
    }
}
