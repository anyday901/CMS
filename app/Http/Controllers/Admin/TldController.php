<?php

namespace App\Http\Controllers\Admin;

use App\Domains\RegistrarRegistry;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ProductPrice;
use App\Models\Tld;
use App\Models\TldPrice;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/** Domain extensions for sale, each with its registrar and a price grid per term and currency. */
class TldController extends Controller
{
    /** Terms offered on the price grid. */
    public const TERMS = [1, 2, 3, 5, 10];

    private const INDEX = 'admin.tlds.index';

    public function __construct(private RegistrarRegistry $registrars) {}

    public function index(): View
    {
        return view('admin.tlds.index', [
            'tlds' => Tld::with('prices')->withCount('domains')->orderBy('sort_order')->orderBy('extension')->get(),
            'registrars' => $this->registrars->all(),
            'currency' => config('billing.currency'),
        ]);
    }

    public function create(): View
    {
        return $this->form(new Tld(['active' => true, 'epp_required' => true]));
    }

    public function store(Request $request): RedirectResponse
    {
        $tld = DB::transaction(function () use ($request) {
            $tld = Tld::create($this->validated($request));
            $this->savePrices($tld, $request);

            return $tld;
        });
        Activity::record("Added TLD {$tld->label()}", $tld);

        return redirect()->route(self::INDEX)->with('status', "{$tld->label()} added.");
    }

    public function edit(Tld $tld): View
    {
        return $this->form($tld->load('prices'));
    }

    public function update(Request $request, Tld $tld): RedirectResponse
    {
        DB::transaction(function () use ($request, $tld) {
            $tld->update($this->validated($request, $tld));
            $this->savePrices($tld, $request);
        });
        Activity::record("Updated TLD {$tld->label()}", $tld);

        return redirect()->route(self::INDEX)->with('status', "{$tld->label()} saved.");
    }

    public function destroy(Tld $tld): RedirectResponse
    {
        if ($tld->domains()->exists()) {
            return back()->withErrors(['tld' => "Domains use {$tld->label()}. Untick Active to stop selling it instead."]);
        }
        $tld->delete();
        Activity::record("Deleted TLD {$tld->label()}");

        return redirect()->route(self::INDEX)->with('status', "{$tld->label()} deleted.");
    }

    private function form(Tld $tld): View
    {
        $currencies = collect([config('billing.currency')])
            ->merge(TldPrice::distinct()->pluck('currency'))
            ->merge(ProductPrice::distinct()->pluck('currency'))
            ->unique()->values();

        return view('admin.tlds.form', [
            'tld' => $tld,
            'registrars' => $this->registrars->all(),
            'currencies' => $currencies,
            'terms' => self::TERMS,
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Tld $tld = null): array
    {
        $request->merge(['extension' => strtolower(ltrim(trim((string) $request->input('extension')), '.'))]);
        $data = $request->validate([
            'extension' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9-]+(\.[a-z0-9-]+)*$/', Rule::unique('tlds', 'extension')->ignore($tld?->id)],
            'registrar' => ['nullable', Rule::in($this->registrars->all()->keys()->all())],
            'whois_server' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9.-]+$/i'],
            'whois_available' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ], ['extension.regex' => 'Write the extension without the dot, such as com or co.uk.']);

        return $data + [
            'active' => $request->boolean('active'),
            'epp_required' => $request->boolean('epp_required'),
            'id_protection' => $request->boolean('id_protection'),
            'dns_management' => $request->boolean('dns_management'),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    /**
     * Saves the price grid: prices[currency][years][register|transfer|renew|id_protection].
     * A term with no register, transfer or renew price is removed.
     */
    private function savePrices(Tld $tld, Request $request): void
    {
        $grid = (array) $request->input('prices', []);
        $kept = [];

        foreach ($grid as $currency => $terms) {
            if (! preg_match('/^[A-Z]{3}$/', (string) $currency)) {
                continue;
            }
            foreach ((array) $terms as $years => $row) {
                $years = (int) $years;
                if (! in_array($years, self::TERMS, true)) {
                    continue;
                }
                $amounts = [];
                foreach ([...TldPrice::TYPES, 'id_protection'] as $type) {
                    $value = trim((string) ($row[$type] ?? ''));
                    try {
                        $amounts[$type] = $value === '' ? null : Money::parse($value);
                    } catch (InvalidArgumentException) {
                        throw ValidationException::withMessages(["prices.{$currency}.{$years}.{$type}" => "{$value} isn't an amount ({$currency}, {$years} year(s), {$type})."]);
                    }
                }
                if ($amounts['register'] === null && $amounts['transfer'] === null && $amounts['renew'] === null) {
                    continue;
                }
                $price = $tld->prices()->updateOrCreate(['currency' => $currency, 'years' => $years], [...$amounts, 'id_protection' => $amounts['id_protection'] ?? 0]);
                $kept[] = $price->id;
            }
        }

        $tld->prices()->whereNotIn('id', $kept)->delete();
    }
}
