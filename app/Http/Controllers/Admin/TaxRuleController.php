<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TaxRule;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TaxRuleController extends Controller
{
    public function index(): View
    {
        $rules = TaxRule::orderBy('country')->orderBy('state')->orderBy('name')->get();

        return view('admin.tax-rules.index', ['rules' => $rules, 'rule' => new TaxRule]);
    }

    public function store(Request $request): RedirectResponse
    {
        TaxRule::create($this->validated($request));

        return redirect()->route('admin.tax-rules.index')->with('status', 'Tax rule added.');
    }

    public function edit(TaxRule $taxRule): View
    {
        return view('admin.tax-rules.edit', ['rule' => $taxRule]);
    }

    public function update(Request $request, TaxRule $taxRule): RedirectResponse
    {
        $taxRule->update($this->validated($request));

        return redirect()->route('admin.tax-rules.index')->with('status', 'Tax rule updated.');
    }

    public function destroy(TaxRule $taxRule): RedirectResponse
    {
        $taxRule->delete();

        return redirect()->route('admin.tax-rules.index')->with('status', 'Tax rule deleted.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'rate' => ['required', Money::rule()],
            // A state code alone is ambiguous across countries.
            'country' => ['nullable', 'required_with:state', 'string', 'size:2'],
            'state' => ['nullable', 'string', 'max:255'],
        ]);

        // Money::parse reads "8.25" as 825, which is the hundredths of a percent we store.
        $rate = Money::parse($data['rate']);

        if ($rate > 10000) {
            throw ValidationException::withMessages(['rate' => 'The rate cannot be over 100%.']);
        }

        return [
            'name' => $data['name'],
            'rate' => $rate,
            'country' => filled($data['country'] ?? null) ? strtoupper($data['country']) : null,
            'state' => filled($data['state'] ?? null) ? trim($data['state']) : null,
        ];
    }
}
