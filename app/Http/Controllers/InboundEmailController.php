<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Tickets\InboundMail;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Takes a raw email (the full MIME message as the request body) from a mail
 * service that forwards to a URL. Off unless TICKETS_INBOUND_SECRET is set.
 */
class InboundEmailController extends Controller
{
    public function __invoke(Request $request, InboundMail $inbound): Response
    {
        $secret = (string) config('tickets.inbound_secret');
        $given = (string) ($request->header('X-Inbound-Secret') ?? $request->query('key', ''));

        abort_if($secret === '', 404);
        abort_unless(hash_equals($secret, $given), 403);

        $raw = $request->getContent();
        abort_if($raw === '', 422, 'Send the raw email as the request body.');

        $department = $request->filled('department') ? Department::active()->findOrFail($request->integer('department')) : null;
        [$outcome, $reason] = $inbound->handle($raw, $department);

        // Ignored mail is still accepted, so the sender's server doesn't keep retrying.
        return response("{$outcome}: {$reason}", 200, ['Content-Type' => 'text/plain']);
    }
}
