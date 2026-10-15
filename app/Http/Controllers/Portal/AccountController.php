<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ClientContact;
use App\Models\CustomField;
use App\Privacy\ClientDataExport;
use App\Security\EmailVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class AccountController extends Controller
{
    public function edit(Request $request): View
    {
        return view('portal.account', [
            'client' => $request->user('client'),
            'fields' => CustomField::forClients()->where('admin_only', false)->get(),
        ]);
    }

    public function update(Request $request, EmailVerification $verification): RedirectResponse
    {
        $client = $request->user('client');
        $fields = CustomField::forClients()->where('admin_only', false)->get();

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('clients')->ignore($client), ClientContact::loginEmailRule()],
            'phone' => ['nullable', 'string', 'max:50'],
            'address1' => ['nullable', 'string', 'max:255'],
            'address2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'size:2'],
        ] + CustomField::rules($fields), [], CustomField::attributeNames($fields));

        $client->fill([
            ...$data,
            'custom_fields' => CustomField::merge($fields, (array) $request->input('custom_fields', []), $client->custom_fields),
        ]);
        $emailChanged = $client->isDirty('email');
        if ($emailChanged) {
            $client->email_verified_at = null;
        }
        $client->save();

        Activity::record('Updated their contact details', $client, ['changed' => array_keys(Arr::except($client->getChanges(), 'email_verified_at'))]);

        if ($emailChanged) {
            $verification->send($client);

            return back()->with('status', "Your details are saved. We've sent a link to {$client->email} to confirm it's yours.");
        }

        return back()->with('status', 'Your details are saved.');
    }

    /** The account holder downloads everything kept about their account. */
    public function export(Request $request, ClientDataExport $export): Response
    {
        $client = $request->user('client');
        Activity::record('Downloaded their personal data', $client);

        return $export->download($client);
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
