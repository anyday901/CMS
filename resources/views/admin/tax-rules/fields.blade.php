@php use App\Support\Money; @endphp
<label class="block">Name
    <input name="name" value="{{ old('name', $rule->name) }}" required placeholder="Sales tax" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-gray-300">
</label>
<label class="block">Rate (%)
    <input name="rate" value="{{ old('rate', $rule->exists ? Money::toInput($rule->rate) : '') }}" required placeholder="8.25" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-gray-300">
</label>
<label class="block">Country (2-letter code, blank for all)
    <input name="country" value="{{ old('country', $rule->country) }}" maxlength="2" placeholder="US" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-gray-300">
</label>
<label class="block">State (blank for all; needs a country)
    <input name="state" value="{{ old('state', $rule->state) }}" placeholder="TX" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-gray-300">
</label>
