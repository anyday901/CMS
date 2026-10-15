<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ClientStatus;
use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Jobs\SendMassMail;
use App\Mail\EmailTemplates;
use App\Mail\MassMailAudience;
use App\Mail\MassMailer;
use App\Models\Activity;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\EmailLog;
use App\Models\MassMail;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Mass mail: one email to the clients a set of filters picks. Staff write
 * it, preview who it reaches and how it reads, then send it.
 */
class MassMailController extends Controller
{
    public function index(): View
    {
        return view('admin.mass-mail.index', [
            'mailings' => MassMail::with('user')->withCount([
                'emails as sent_count' => fn ($q) => $q->where('status', EmailLog::SENT),
                'emails as failed_count' => fn ($q) => $q->where('status', EmailLog::FAILED),
            ])->latest('id')->paginate(25),
        ]);
    }

    public function create(Request $request): View
    {
        $copy = $request->integer('copy') ? MassMail::find($request->integer('copy')) : null;

        return $this->form(new MassMail($copy?->only(['subject', 'body', 'marketing', 'filters']) ?? [
            'marketing' => true,
            'filters' => ['statuses' => [ClientStatus::Active->value]],
        ]));
    }

    /** Shows how many clients it reaches and how it reads for the first of them. */
    public function preview(Request $request, MassMailer $mailer): View
    {
        $mail = new MassMail($this->validated($request));
        $recipients = MassMailAudience::query($mail->filters, $mail->marketing);
        $sample = (clone $recipients)->orderBy('id')->first();

        return $this->form($mail, [
            'count' => $recipients->count(),
            'sample' => $sample ? ['client' => $sample, ...$mailer->render($mail, $sample)] : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        if (MassMailAudience::query($data['filters'], $data['marketing'])->doesntExist()) {
            return back()->withInput()->withErrors(['filters' => 'No clients match these filters.']);
        }

        $mail = MassMail::create($data + ['user_id' => $request->user()->id, 'status' => MassMail::QUEUED]);
        SendMassMail::dispatch($mail->id);
        Activity::record(($mail->marketing ? 'Sent marketing email' : 'Sent mass email')." \"{$mail->subject}\"", $mail);

        return redirect()->route('admin.mass-mail.show', $mail)->with('status', 'Sending. Emails go out from the queue over the next few minutes.');
    }

    public function show(MassMail $massMail): View
    {
        $counts = $massMail->emails()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('admin.mass-mail.show', [
            'mail' => $massMail->load('user'),
            'counts' => $counts,
            'emails' => $massMail->emails()->with('client')->latest('id')->paginate(50),
            'html' => EmailTemplates::html($massMail->body),
        ]);
    }

    /** @param  array<string, mixed>|null  $preview */
    private function form(MassMail $mail, ?array $preview = null): View
    {
        return view('admin.mass-mail.create', [
            'mail' => $mail,
            'preview' => $preview,
            'groups' => ClientGroup::orderBy('name')->get(),
            'products' => Product::orderBy('name')->get(['id', 'name']),
            'countries' => Client::query()->whereNotNull('country')->distinct()->orderBy('country')->pluck('country'),
            'currencies' => Client::query()->distinct()->orderBy('currency')->pluck('currency'),
            'clientStatuses' => ClientStatus::cases(),
            'serviceStatuses' => ServiceStatus::cases(),
        ]);
    }

    /** @return array{subject: string, body: string, marketing: bool, filters: array<string, mixed>} */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:250'],
            'body' => ['required', 'string', 'max:50000'],
            'marketing' => ['required', 'boolean'],
            ...collect(MassMailAudience::rules())->mapWithKeys(fn ($rules, $key) => ["filters.{$key}" => $rules])->all(),
        ], ['filters.statuses.required' => 'Pick at least one client status.'], ['filters.statuses' => 'client status']);

        $filters = array_filter((array) ($data['filters'] ?? []), fn ($value) => $value !== null && $value !== [] && $value !== '');

        return [
            'subject' => $data['subject'],
            'body' => $data['body'],
            'marketing' => (bool) $data['marketing'],
            'filters' => $filters,
        ];
    }
}
