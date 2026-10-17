<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendLoggedEmail;
use App\Mail\EmailTemplates;
use App\Models\Activity;
use App\Models\EmailLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmailLogController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $status = $request->query('status');

        $emails = EmailLog::query()
            ->with('client')
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('to', 'like', "%{$search}%")
                ->orWhere('subject', 'like', "%{$search}%")))
            ->when(in_array($status, [EmailLog::QUEUED, EmailLog::SENT, EmailLog::FAILED], true), fn ($query) => $query->where('status', $status))
            ->latest()
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.emails.index', compact('emails', 'search', 'status'));
    }

    public function show(EmailLog $email): View
    {
        $email->load('client');

        return view('admin.emails.show', ['email' => $email, 'html' => EmailTemplates::html($email->body)]);
    }

    /** Sends a failed email again. Emails with a private link can't be resent; send a new link instead. */
    public function resend(EmailLog $email): RedirectResponse
    {
        abort_if(EmailTemplates::definitions()[$email->template]['sensitive'] ?? false, 422, 'This email contained a private link. Send a new one instead.');
        abort_if($email->status === EmailLog::SENT, 422, 'This email was already sent.');

        $email->update(['status' => EmailLog::QUEUED, 'error' => null]);
        SendLoggedEmail::dispatch($email->id);
        Activity::record("Resent email \"{$email->subject}\" to {$email->to}", $email->client);

        return back()->with('status', 'Email queued again.');
    }
}
