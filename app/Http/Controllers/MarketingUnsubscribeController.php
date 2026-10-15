<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The unsubscribe link for marketing emails: a signed link per client, so
 * it works without logging in. Opening it asks to confirm; mail apps'
 * one-click unsubscribe posts to it directly.
 */
class MarketingUnsubscribeController extends Controller
{
    public function show(Client $client): View
    {
        return view('marketing.unsubscribe', ['client' => $client, 'done' => ! $client->marketing_emails]);
    }

    public function store(Request $request, Client $client): View
    {
        if ($client->setMarketingConsent(false)) {
            $client->save();
            Activity::record('Unsubscribed from marketing emails with the link in an email', $client);
        }

        return view('marketing.unsubscribe', ['client' => $client, 'done' => true]);
    }
}
