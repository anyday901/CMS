<?php

namespace App\Http\Middleware;

use App\Models\IpBan;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Turns away banned IP addresses. Payment and email webhooks still get through. */
class BlockBannedIps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('webhooks/*') && IpBan::covers($request->ip())) {
            abort(403, 'Access from your network has been blocked. If you think this is a mistake, please contact us another way.');
        }

        return $next($request);
    }
}
