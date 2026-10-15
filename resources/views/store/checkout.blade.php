<x-portal-layout title="Checkout" :store="true">
    <h1 class="mb-6 text-2xl font-semibold">Checkout</h1>
    @include('store._summary', ['removable' => false])

    <form method="POST" action="{{ route('checkout.store') }}" class="mt-8 max-w-2xl space-y-4 rounded-lg border border-ink-200 bg-white p-6 text-sm">
        @csrf
        @auth('client')
            <p>Ordering as <strong>{{ auth('client')->user()->fullName() }}</strong> ({{ auth('client')->user()->email }}). The invoice goes to your account, where you can pay it.</p>
        @else
            <div class="flex flex-wrap items-baseline gap-3">
                <h2 class="text-lg font-semibold">Create your account</h2>
                <a href="{{ route('checkout.login') }}" class="ml-auto text-brand-600">Already a client? Log in</a>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block">First name
                    <input name="first_name" value="{{ old('first_name') }}" required autocomplete="given-name" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                </label>
                <label class="block">Last name
                    <input name="last_name" value="{{ old('last_name') }}" required autocomplete="family-name" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                </label>
                <label class="block">Email
                    <input type="email" name="email" value="{{ old('email') }}" required autocomplete="email" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                </label>
                <label class="block">Phone (optional)
                    <input name="phone" value="{{ old('phone') }}" autocomplete="tel" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                </label>
                <label class="block sm:col-span-2">Company (optional)
                    <input name="company" value="{{ old('company') }}" autocomplete="organization" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                </label>
                <label class="block sm:col-span-2">Address
                    <input name="address1" value="{{ old('address1') }}" required autocomplete="address-line1" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                </label>
                <label class="block sm:col-span-2">Address line 2 (optional)
                    <input name="address2" value="{{ old('address2') }}" autocomplete="address-line2" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                </label>
                <label class="block">City
                    <input name="city" value="{{ old('city') }}" required autocomplete="address-level2" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                </label>
                <label class="block">State or region (optional)
                    <input name="state" value="{{ old('state') }}" autocomplete="address-level1" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                </label>
                <label class="block">Postcode
                    <input name="postcode" value="{{ old('postcode') }}" required autocomplete="postal-code" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                </label>
                <label class="block">Country (2-letter code)
                    <input name="country" value="{{ old('country') }}" required maxlength="2" placeholder="US" autocomplete="country" class="mt-1 w-full rounded-md px-3 py-2 uppercase ring-1 ring-ink-300">
                </label>
                @include('custom-fields._inputs', ['fields' => $fields, 'values' => [], 'wide' => 'sm:col-span-2'])
                <label class="block">Password (at least 10 characters)
                    <input type="password" name="password" required minlength="10" autocomplete="new-password" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                </label>
                <label class="block">Confirm password
                    <input type="password" name="password_confirmation" required autocomplete="new-password" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                </label>
            </div>
            <label class="mt-4 flex items-start gap-2 text-sm"><input type="checkbox" name="marketing_emails" value="1" class="mt-1" @checked(old('marketing_emails'))>
                <span>Email me news and special offers. You can unsubscribe at any time.</span></label>
            <x-captcha form="register" />
        @endauth
        <button class="rounded-full bg-brand-600 px-5 py-2 font-medium text-white hover:bg-brand-500">Place order</button>
    </form>
</x-portal-layout>
