<?php

namespace App\Http\Controllers\Portal;

use App\Auth\PortalContact;
use App\Domains\DnsRecord;
use App\Domains\DomainContact;
use App\Domains\DomainManager;
use App\Domains\DomainOrders;
use App\Domains\DomainRenewals;
use App\Domains\RegistrarError;
use App\Enums\DomainStatus;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Domain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

/** The client's domains: renewals, nameservers, DNS, the registrant, ID protection and the transfer lock. */
class DomainController extends Controller
{
    public function __construct(private DomainManager $manager) {}

    public function index(Request $request): View
    {
        return view('portal.domains.index', [
            'domains' => $request->user('client')->domains()->orderByRaw('status in (?, ?) desc', [DomainStatus::Active->value, DomainStatus::Expired->value])->orderBy('name')->get(),
        ]);
    }

    public function show(Request $request, int $domain, DomainRenewals $renewals): View
    {
        $domain = $this->find($request, $domain);
        $hasDns = $domain->isManageable() && $this->manager->hasDns($domain);
        $records = [];
        $dnsError = null;

        if ($hasDns) {
            try {
                $records = $this->manager->dnsRecords($domain);
            } catch (RegistrarError $e) {
                $dnsError = $e->getMessage();
            }
        }

        $currency = $request->user('client')->currency;

        return view('portal.domains.show', [
            'domain' => $domain,
            'registrant' => $domain->registrant(),
            'hasDns' => $hasDns,
            'records' => old('records') ? array_map(fn ($row) => DnsRecord::fromArray((array) $row), (array) old('records')) : $records,
            'dnsError' => $dnsError,
            'openRenewal' => $renewals->openRenewal($domain),
            'renewalPaid' => DomainRenewals::renewalPaid($domain),
            'renewTerms' => $domain->tld?->termsFor($currency, 'renew') ?? collect(),
            'protectionPrice' => $domain->tld ? DomainOrders::protectionAmount($domain->tld, $currency, $domain->years) : 0,
            'canOrder' => PortalContact::allows('orders'),
        ]);
    }

    public function nameservers(Request $request, int $domain): RedirectResponse
    {
        $domain = $this->manageable($request, $domain);
        $data = $request->validate(['nameservers' => ['required', 'array', 'max:'.DomainManager::MAX_NAMESERVERS], 'nameservers.*' => ['nullable', 'string', 'max:253']]);

        return $this->attempt(fn () => $this->manager->setNameservers($domain, $data['nameservers']), __('Nameservers saved. Changes can take a few hours to reach everyone.'), 'nameservers');
    }

    public function dns(Request $request, int $domain): RedirectResponse
    {
        $domain = $this->manageable($request, $domain);
        abort_unless($this->manager->hasDns($domain), 404);
        $data = $request->validate([
            'records' => ['array', 'max:100'],
            'records.*.host' => ['nullable', 'string', 'max:253', 'regex:/^(@|\*|(\*\.)?[a-z0-9_]([a-z0-9_-]*[a-z0-9_])?(\.[a-z0-9_]([a-z0-9_-]*[a-z0-9_])?)*)$/i'],
            'records.*.type' => ['nullable', Rule::in(DnsRecord::TYPES)],
            'records.*.value' => ['nullable', 'string', 'max:1000'],
            'records.*.priority' => ['nullable', 'integer', 'between:0,65535'],
        ], ['records.*.host.regex' => __('A host is @ for the domain itself, or a name such as www.')]);

        // Rows left empty are dropped, so clients can clear one by emptying its value.
        $records = collect($data['records'] ?? [])
            ->filter(fn ($row) => filled($row['type'] ?? null) && filled($row['value'] ?? null))
            ->map(fn ($row) => DnsRecord::fromArray($row))
            ->values();
        $bad = $records->first(fn (DnsRecord $record) => $this->badValue($record));
        if ($bad) {
            return back()->withInput()->withErrors(['dns' => __(':value isn\'t a valid :type record value.', ['value' => $bad->value, 'type' => $bad->type])]);
        }

        return $this->attempt(fn () => $this->manager->setDnsRecords($domain, $records->all()), __('DNS records saved. Changes can take a while to reach everyone.'), 'dns');
    }

    public function contact(Request $request, int $domain): RedirectResponse
    {
        $domain = $this->manageable($request, $domain);
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'company' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'address1' => ['required', 'string', 'max:255'],
            'address2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postcode' => ['required', 'string', 'max:20'],
            'country' => ['required', 'string', 'size:2'],
        ]);

        return $this->attempt(fn () => $this->manager->setContact($domain, DomainContact::fromArray($data)), __('Contact details saved.'), 'contact');
    }

    public function idProtection(Request $request, int $domain): RedirectResponse
    {
        $domain = $this->manageable($request, $domain);
        $on = $request->boolean('id_protection');

        return $this->attempt(fn () => $this->manager->setIdProtection($domain, $on), $on ? __('ID protection is on.') : __('ID protection is off.'), 'id_protection');
    }

    public function lock(Request $request, int $domain): RedirectResponse
    {
        $domain = $this->manageable($request, $domain);
        $locked = $request->boolean('locked');

        return $this->attempt(fn () => $this->manager->setLock($domain, $locked), $locked ? __('The domain is locked against transfers.') : __('The domain is unlocked. Lock it again once your transfer is done.'), 'lock');
    }

    public function eppCode(Request $request, int $domain): RedirectResponse
    {
        $domain = $this->manageable($request, $domain);

        try {
            $code = $this->manager->eppCode($domain);
        } catch (RegistrarError $e) {
            return back()->withErrors(['epp' => $e->getMessage()]);
        }

        return $code === null
            ? back()->with('status', __('The EPP code has been emailed to the domain\'s registrant.'))
            : back()->with('epp_code', $code);
    }

    public function autoRenew(Request $request, int $domain): RedirectResponse
    {
        $domain = $this->find($request, $domain);
        abort_unless(in_array($domain->status, DomainStatus::live(), true), 404);
        $on = $request->boolean('auto_renew');
        $domain->update(['auto_renew' => $on]);
        Activity::record(($on ? 'Turned on' : 'Turned off')." auto-renew for {$domain->name}", $domain);

        return back()->with('status', $on ? __("Auto-renew is on. We'll send the renewal invoice before :name expires.", ['name' => $domain->name]) : __("Auto-renew is off. :name won't renew unless you renew it here.", ['name' => $domain->name]));
    }

    public function renew(Request $request, int $domain, DomainRenewals $renewals): RedirectResponse
    {
        $domain = $this->find($request, $domain);
        $data = $request->validate(['years' => ['required', 'integer', 'between:1,10']]);

        try {
            $invoice = $renewals->renewNow($domain, (int) $data['years']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['renew' => $e->getMessage()]);
        }

        return PortalContact::allows('billing')
            ? redirect()->route('portal.invoices.show', $invoice)->with('status', __('Pay this invoice to renew :name.', ['name' => $domain->name]))
            : back()->with('status', __('The renewal invoice for :name is ready for the account holder to pay.', ['name' => $domain->name]));
    }

    private function find(Request $request, int $id): Domain
    {
        return $request->user('client')->domains()->with('tld.prices')->findOrFail($id);
    }

    private function manageable(Request $request, int $id): Domain
    {
        $domain = $this->find($request, $id);
        abort_unless($domain->isManageable(), 404);

        return $domain;
    }

    /** Runs a change at the registrar, showing its refusal under $errorKey. */
    private function attempt(callable $change, string $done, string $errorKey): RedirectResponse
    {
        try {
            $change();
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors([$errorKey => $e->getMessage()]);
        } catch (RegistrarError $e) {
            Log::warning('Registrar refused a portal change', ['error' => $e->getMessage()]);

            return back()->withInput()->withErrors([$errorKey => __("The registrar didn't accept the change: :reason", ['reason' => $e->getMessage()])]);
        }

        return back()->with('status', $done);
    }

    private function badValue(DnsRecord $record): bool
    {
        return match ($record->type) {
            'A' => filter_var($record->value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false,
            'AAAA' => filter_var($record->value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false,
            'CNAME', 'MX' => ! preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+\.?$/i', $record->value),
            default => false,
        };
    }
}
