<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Client;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * The unsubscribe link for marketing emails: a signed link per client, so
 * it works without logging in. Opening it asks to confirm; mail apps'
 * one-click unsubscribe posts to it directly.
 */
class MarketingUnsubscribeController extends Controller
{
    /** A link that unsubscribes the client from marketing emails, without logging in. */
    public static function url(Client $client): string
    {
        return URL::signedRoute('marketing.unsubscribe', ['client' => $client->id]);
    }

    public function show(Client $client): View
    {
        return view('marketing.unsubscribe', ['client' => $client, 'done' => ! $client->marketing_emails]);
    }

    public function store(Client $client): View
    {
        if ($client->setMarketingConsent(false)) {
            $client->save();
            Activity::record('Unsubscribed from marketing emails with the link in an email', $client);
        }

        return view('marketing.unsubscribe', ['client' => $client, 'done' => true]);
    }
}
