<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ApiKey;
use App\Models\IpBan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Keys for the REST API (see docs/api.md). The full key is shown once,
 * right after it's made; after that only its prefix is known.
 */
class ApiKeyController extends Controller
{
    public function index(): View
    {
        return view('admin.api-keys.index', [
            'keys' => ApiKey::with('user')->orderByRaw('revoked_at is not null')->latest('id')->get(),
            'key' => new ApiKey(['abilities' => []]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        [$key, $plain] = ApiKey::generate($this->validated($request, new: true) + ['user_id' => $request->user()->id]);
        Activity::record("Created API key {$key->name}", $key, ['abilities' => $key->abilities]);

        return redirect()->route('admin.api-keys.index')
            ->with('status', 'API key created. Copy it now: it won\'t be shown again.')
            ->with('new_key', ['id' => $key->id, 'key' => $plain]);
    }

    public function edit(ApiKey $apiKey): View
    {
        return view('admin.api-keys.edit', ['key' => $apiKey]);
    }

    /** The key itself and its expiry stay the same; make a new key to change those. */
    public function update(Request $request, ApiKey $apiKey): RedirectResponse
    {
        $apiKey->update($this->validated($request, new: false));
        Activity::record("Updated API key {$apiKey->name}", $apiKey, ['abilities' => $apiKey->abilities]);

        return redirect()->route('admin.api-keys.index')->with('status', 'API key updated.');
    }

    /** It stops working straight away, and stays in the list so its history makes sense. */
    public function revoke(ApiKey $apiKey): RedirectResponse
    {
        if (! $apiKey->isRevoked()) {
            $apiKey->forceFill(['revoked_at' => now()])->save();
            Activity::record("Revoked API key {$apiKey->name}", $apiKey);
        }

        return redirect()->route('admin.api-keys.index')->with('status', "{$apiKey->name} is revoked. Requests with it are refused.");
    }

    public function destroy(ApiKey $apiKey): RedirectResponse
    {
        $apiKey->delete();
        Activity::record("Deleted API key {$apiKey->name}");

        return redirect()->route('admin.api-keys.index')->with('status', 'API key deleted.');
    }

    /**
     * The name, abilities and IP allowlist (one address or CIDR range per
     * line or comma), and for a new key the last day it works.
     */
    private function validated(Request $request, bool $new): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['required', 'array'],
            'abilities.*' => [Rule::in(array_keys(ApiKey::ABILITIES))],
            'allowed_ips' => ['nullable', 'string', 'max:5000'],
            'expires_on' => ['nullable', 'date', 'after:today'],
        ], ['abilities.required' => 'Tick at least one thing the key can do.', 'expires_on.after' => 'The expiry date has to be in the future.']);

        $ips = collect(preg_split('/[\s,]+/', (string) ($data['allowed_ips'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))->unique()->values();
        $bad = $ips->reject(fn (string $ip) => IpBan::validRange($ip));
        if ($bad->isNotEmpty()) {
            throw ValidationException::withMessages(['allowed_ips' => "{$bad->first()} isn't an IP address or a range such as 203.0.113.0/24."]);
        }

        $values = [
            'name' => $data['name'],
            // Kept in the same order as the form, so the list reads the same way.
            'abilities' => array_values(array_intersect(array_keys(ApiKey::ABILITIES), $data['abilities'])),
            'allowed_ips' => $ips->isEmpty() ? null : $ips->all(),
        ];
        if ($new) {
            // It works until the end of its last day.
            $values['expires_at'] = filled($data['expires_on'] ?? null) ? Carbon::parse($data['expires_on'])->endOfDay() : null;
        }

        return $values;
    }
}
