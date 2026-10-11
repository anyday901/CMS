<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\CustomField;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function edit(Request $request): View
    {
        return view('portal.account', [
            'client' => $request->user('client'),
            'fields' => CustomField::forClients()->where('admin_only', false)->get(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $client = $request->user('client');
        $fields = CustomField::forClients()->where('admin_only', false)->get();

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('clients')->ignore($client)],
            'phone' => ['nullable', 'string', 'max:50'],
            'address1' => ['nullable', 'string', 'max:255'],
            'address2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'size:2'],
        ] + CustomField::rules($fields), [], CustomField::attributeNames($fields));

        $client->update([
            ...$data,
            'custom_fields' => CustomField::merge($fields, (array) $request->input('custom_fields', []), $client->custom_fields),
        ]);

        Activity::record('Updated their contact details', $client, ['changed' => array_keys($client->getChanges())]);

        return back()->with('status', 'Your details are saved.');
    }

    public function password(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:client'],
            'password' => ['required', 'confirmed', Password::min(10)],
        ]);

        $request->user('client')->update(['password' => $data['password']]);
        Activity::record('Changed their portal password', $request->user('client'));

        return back()->with('status', 'Your password is changed.');
    }
}
