<?php

namespace App\Http\Controllers\Portal;

use App\Billing\VatNumbers;
use App\Http\Controllers\Controller;
use App\Integrations\Sms\Sms;
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
    public function edit(Request $request, Sms $sms): View
    {
        return view('portal.account', [
            'client' => $request->user('client'),
            'fields' => CustomField::forClients()->where('admin_only', false)->get(),
            'offersTexts' => $sms->configured() && config('sms.client_events') !== [],
        ]);
    }

    public function update(Request $request, EmailVerification $verification, VatNumbers $vatNumbers): RedirectResponse
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
            'tax_id' => ['nullable', 'string', 'max:100'],
        ] + CustomField::rules($fields), [], CustomField::attributeNames($fields));

        $client->fill([
            ...$data,
            ...$vatNumbers->attributes($data['tax_id'] ?? null, $client),
            'custom_fields' => CustomField::merge($fields, (array) $request->input('custom_fields', []), $client->custom_fields),
        ]);
        $emailChanged = $client->isDirty('email');
        if ($emailChanged) {
            $client->email_verified_at = null;
        }
        $client->save();

        Activity::record('Updated their contact details', $client, ['changed' => array_keys(Arr::except($client->getChanges(), ['email_verified_at', 'tax_id_verified_at']))]);

        if ($emailChanged) {
            $verification->send($client);

            return back()->with('status', __("Your details are saved. We've sent a link to :email to confirm it's yours.", ['email' => $client->email]));
        }

        return back()->with('status', __('Your details are saved.'));
    }

    /** The account holder downloads everything kept about their account. */
    public function export(Request $request, ClientDataExport $export): Response
    {
        $client = $request->user('client');
        Activity::record('Downloaded their personal data', $client);

        return $export->download($client);
    }

    /** The account holder says yes or no to news and offers by email. */
    public function marketing(Request $request): RedirectResponse
    {
        $client = $request->user('client');

        if ($client->setMarketingConsent($request->boolean('marketing_emails'))) {
            $client->save();
            Activity::record($client->marketing_emails ? 'Agreed to marketing emails' : 'Opted out of marketing emails', $client);
        }

        // Only offered when the business sends texts.
        if ($request->has('sms_notifications') && $client->sms_notifications !== $request->boolean('sms_notifications')) {
            $client->forceFill(['sms_notifications' => $request->boolean('sms_notifications')])->save();
            Activity::record($client->sms_notifications ? 'Agreed to account texts' : 'Turned off account texts', $client);
        }

        return back()->with('status', $client->marketing_emails ? __("Thanks, we'll email you news and offers.") : __("You won't get news and offers by email."));
    }

    public function password(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:client'],
            'password' => ['required', 'confirmed', Password::min(10)],
        ]);

        $request->user('client')->update(['password' => $data['password']]);
        Activity::record('Changed their portal password', $request->user('client'));

        return back()->with('status', __('Your password is changed.'));
    }
}
