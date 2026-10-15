<?php

namespace App\Http\Middleware;

use App\Addons\AddonManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** An add-on's pages answer 404 unless it is installed and enabled. */
class EnsureAddonEnabled
{
    public function __construct(private AddonManager $addons) {}

    public function handle(Request $request, Closure $next, string $key): Response
    {
        abort_unless($this->addons->enabled($key), 404);

        return $next($request);
    }
}
