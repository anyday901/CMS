<?php

namespace App\Http\Controllers\Admin;

use App\Domains\DomainManager;
use App\Domains\DomainName;
use App\Domains\DomainOrders;
use App\Domains\DomainRenewals;
use App\Domains\DomainSync;
use App\Domains\RegistrarActions;
use App\Domains\RegistrarError;
use App\Domains\RegistrarRegistry;
use App\Enums\DomainStatus;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Tld;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/** Staff view of every domain: details, registrar actions and the sync. */
class DomainController extends Controller
{
    private const CLIENT = 'client';

    public function __construct(private RegistrarRegistry $registrars) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $status = DomainStatus::tryFrom((string) $request->query('status'));
        $expiring = $request->boolean('expiring');

        $domains = Domain::query()
            ->with(self::CLIENT)
            ->when($status, fn (Builder $query) => $query->where('status', $status))
            ->when($expiring, fn (Builder $query) => $query->where('status', DomainStatus::Active)->whereDate('expiry_date', '<=', today()->addDays(30)))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', '%'.(DomainName::normalise($search) ?? $search).'%')
                ->orWhereHas(self::CLIENT, fn (Builder $clients) => $clients
                    ->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('company', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%"))))
            ->orderBy($expiring ? 'expiry_date' : 'name')
            ->paginate(50)
            ->withQueryString();

        return view('admin.domains.index', [
            'domains' => $domains,
            'search' => $search,
            'status' => $status,
            'expiring' => $expiring,
            'registrars' => $this->registrars->all(),
            'failed' => Domain::where('registrar_status', RegistrarActions::FAILED)->count(),
        ]);
    }

    public function show(Domain $domain, DomainRenewals $renewals): View
    {
        $domain->load(['client', 'tld.prices', 'order', 'invoiceItems.invoice']);

        return view('admin.domains.show', [
            'domain' => $domain,
            'registrar' => $this->registrars->find($domain->registrar),
            'registrars' => $this->registrars->all(),
            'configured' => $this->registrars->configured($domain->registrar),
            'openRenewal' => $renewals->openRenewal($domain),
            'activity' => Activity::where('subject_type', $domain->getMorphClass())->where('subject_id', $domain->id)->latest('id')->limit(20)->get(),
        ]);
    }

    /** Adds a domain the client already has with a registrar, such as one moved over from another system, without an invoice. */
    public function create(Client $client): View
    {
        return view('admin.domains.create', ['client' => $client, 'tlds' => Tld::orderBy('extension')->get(), 'registrars' => $this->registrars->all()]);
    }

    public function store(Request $request, Client $client): RedirectResponse
    {
        $data = $this->validated($request);
        $name = DomainName::normalise($data['name']) ?? throw ValidationException::withMessages(['name' => 'That isn\'t a domain name.']);
        if (DomainOrders::isTaken($name)) {
            throw ValidationException::withMessages(['name' => "{$name} is already here."]);
        }
        $tld = DomainName::parse($name, Tld::all())?->tld;

        $domain = Domain::create([
            'client_id' => $client->id,
            'tld_id' => $tld?->id,
            'name' => $name,
            'registrar' => $data['registrar'] ?? $tld?->registrar,
            'type' => Domain::REGISTER,
            'status' => $data['status'],
            'years' => (int) $data['years'],
            'recurring_amount' => isset($data['recurring_amount']) ? Money::parse($data['recurring_amount']) : ($tld ? (int) DomainOrders::renewalAmount($tld->load('prices'), $client->currency, (int) $data['years']) : 0),
            'registration_date' => $data['registration_date'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            'next_due_date' => $data['expiry_date'] ?? null,
            'auto_renew' => $request->boolean('auto_renew'),
            'nameservers' => DomainOrders::defaultNameservers() ?: null,
        ]);
        Activity::record("Added domain {$domain->name}", $domain);

        return redirect()->route('admin.domains.show', $domain)->with('status', 'Domain added. Sync it to fetch its expiry date and nameservers from the registrar.');
    }

    public function update(Request $request, Domain $domain): RedirectResponse
    {
        $data = $this->validated($request, $domain);
        $domain->update([
            'registrar' => $data['registrar'] ?? null,
            'status' => $data['status'],
            'years' => (int) $data['years'],
            'recurring_amount' => Money::parse((string) ($data['recurring_amount'] ?? '0')),
            'registration_date' => $data['registration_date'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            'next_due_date' => $data['next_due_date'] ?? null,
            'auto_renew' => $request->boolean('auto_renew'),
        ]);
        Activity::record("Updated domain {$domain->name}", $domain, $domain->getChanges());

        return back()->with('status', 'Domain saved.');
    }

    /** Runs register, transfer or renew at the registrar now, or syncs from it. */
    public function action(Request $request, Domain $domain, string $action, RegistrarActions $actions, DomainSync $sync): RedirectResponse
    {
        if ($action === 'sync') {
            $error = $sync->sync($domain->load(['client', 'tld']));

            return $error === null ? back()->with('status', "Synced {$domain->name} with its registrar.") : back()->withErrors(['registrar' => "Sync failed: {$error}"]);
        }

        $years = (int) ($request->validate(['years' => ['nullable', 'integer', 'between:1,10']])['years'] ?? 0) ?: $domain->years;
        $actions->queue($domain, $action, $years);
        $domain->refresh();

        return $domain->registrar_status === RegistrarActions::FAILED
            ? back()->withErrors(['registrar' => ucfirst($action)." failed: {$domain->registrar_error}"])
            : back()->with('status', ucfirst($action).' sent to the registrar.');
    }

    public function nameservers(Request $request, Domain $domain, DomainManager $manager): RedirectResponse
    {
        $data = $request->validate(['nameservers' => ['required', 'array', 'max:'.DomainManager::MAX_NAMESERVERS], 'nameservers.*' => ['nullable', 'string', 'max:253']]);

        try {
            $manager->setNameservers($domain, $data['nameservers']);
        } catch (InvalidArgumentException|RegistrarError $e) {
            return back()->withErrors(['nameservers' => $e->getMessage()]);
        }

        return back()->with('status', 'Nameservers saved.');
    }

    public function eppCode(Domain $domain, DomainManager $manager): RedirectResponse
    {
        try {
            $code = $manager->eppCode($domain);
        } catch (RegistrarError $e) {
            return back()->withErrors(['registrar' => $e->getMessage()]);
        }

        return $code === null ? back()->with('status', 'The registrar emailed the EPP code to the registrant.') : back()->with('epp_code', $code);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Domain $domain = null): array
    {
        return $request->validate([
            'name' => $domain ? ['prohibited'] : ['required', 'string', 'max:253'],
            'registrar' => ['nullable', Rule::in($this->registrars->all()->keys()->all())],
            'status' => ['required', Rule::enum(DomainStatus::class)],
            'years' => ['required', 'integer', 'between:1,10'],
            'recurring_amount' => [$domain ? 'required' : 'nullable', Money::rule()],
            'registration_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'next_due_date' => ['nullable', 'date'],
        ], [], ['recurring_amount' => 'renewal price']);
    }
}
