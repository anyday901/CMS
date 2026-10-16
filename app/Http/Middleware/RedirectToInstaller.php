<?php

namespace App\Http\Middleware;

use App\System\Installer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends every page to the web installer until setup is done. It runs before
 * the session starts, since a new install may have no database yet.
 */
class RedirectToInstaller
{
    public function __construct(private Installer $installer) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (Installer::enabled() && ! $request->is('install', 'install/*', 'up', 'health', 'theme-assets/*') && ! $this->installer->installed()) {
            return redirect('/install');
        }

        return $next($request);
    }
}
