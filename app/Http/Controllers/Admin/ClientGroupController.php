<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ClientGroup;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Groups of clients, such as resellers or VIPs, with an optional discount and
 * billing settings of their own. Clients are put in one on their edit page.
 */
class ClientGroupController extends Controller
{
    public function index(): View
    {
        return view('admin.client-groups.index', [
            'groups' => ClientGroup::withCount('clients')->orderBy('name')->get(),
            'group' => new ClientGroup,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $group = ClientGroup::create($this->validated($request, new ClientGroup));
        Activity::record("Added client group {$group->name}", $group);

        return redirect()->route('admin.client-groups.index')->with('status', 'Group added.');
    }

    public function edit(ClientGroup $clientGroup): View
    {
        return view('admin.client-groups.edit', ['group' => $clientGroup]);
    }

    public function update(Request $request, ClientGroup $clientGroup): RedirectResponse
    {
        $clientGroup->update($this->validated($request, $clientGroup));
        Activity::record("Updated client group {$clientGroup->name}", $clientGroup);

        return redirect()->route('admin.client-groups.index')->with('status', 'Group updated.');
    }

    /** Its clients stay, without a group. */
    public function destroy(ClientGroup $clientGroup): RedirectResponse
    {
        $clientGroup->delete();
        Activity::record("Deleted client group {$clientGroup->name}");

        return redirect()->route('admin.client-groups.index')->with('status', 'Group deleted. Its clients are no longer in a group.');
    }

    private function validated(Request $request, ClientGroup $group): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('client_groups')->ignore($group)],
            'colour' => ['required', 'regex:/^#[0-9a-f]{6}$/i'],
            'description' => ['nullable', 'string', 'max:255'],
            'discount_percent' => ['nullable', Money::rule()],
            'credit_limit' => ['nullable', Money::rule()],
        ], [], ['discount_percent' => 'discount']);

        // Hundredths of a percent, as promotion codes keep it: "10" is 1000.
        $discount = filled($data['discount_percent'] ?? null) ? Money::parse($data['discount_percent']) : 0;
        if ($discount > 10000) {
            throw ValidationException::withMessages(['discount_percent' => 'The discount can\'t be more than 100%.']);
        }

        return [
            ...$data,
            'discount_percent' => $discount,
            'discount_renewals' => $request->boolean('discount_renewals'),
            'exempt_late_fees' => $request->boolean('exempt_late_fees'),
            'exempt_suspension' => $request->boolean('exempt_suspension'),
            'separate_invoices' => $request->boolean('separate_invoices'),
            'credit_limit' => filled($data['credit_limit'] ?? null) ? Money::parse($data['credit_limit']) : null,
        ];
    }
}
