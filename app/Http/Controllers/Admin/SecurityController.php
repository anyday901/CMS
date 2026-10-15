<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\IpBan;
use App\Models\LoginAttempt;
use App\Security\Captcha;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\IpUtils;

/** Login security settings, CAPTCHA, IP bans and the login history. */
class SecurityController extends Controller
{
    private const OUTCOMES = [LoginAttempt::SUCCESS, LoginAttempt::FAILED, LoginAttempt::LOCKED, LoginAttempt::TWO_FACTOR_FAILED];

    public function show(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'area' => ['nullable', Rule::in([LoginAttempt::PORTAL, LoginAttempt::ADMIN])],
            'outcome' => ['nullable', Rule::in(self::OUTCOMES)],
            'client' => ['nullable', 'integer'],
        ]);

        $logins = LoginAttempt::with('account')
            ->when($filters['q'] ?? null, fn ($query, string $q) => $query->where(fn ($query) => $query
                ->where('email', 'like', '%'.addcslashes(strtolower($q), '%_\\').'%')
                ->orWhere('ip_address', $q)))
            ->when($filters['area'] ?? null, fn ($query, string $area) => $query->where('area', $area))
            ->when($filters['outcome'] ?? null, fn ($query, string $outcome) => $query->where('outcome', $outcome))
            ->when($filters['client'] ?? null, fn ($query, int $client) => $query->where('client_id', $client))
            ->latest('created_at')->latest('id')
            ->paginate(50)->withQueryString();

        return view('admin.security.show', [
            'logins' => $logins,
            'filters' => $filters,
            'outcomes' => collect(self::OUTCOMES)->mapWithKeys(fn (string $outcome) => [$outcome => (new LoginAttempt(['outcome' => $outcome]))->outcomeLabel()])->all(),
            'bans' => IpBan::with('user')->orderByRaw('expires_at is null desc')->orderByDesc('expires_at')->latest('id')->get(),
            'settings' => config('security'),
            'hasSecret' => filled(config('security.captcha.secret_key')),
            'myIp' => $request->ip(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'lockout_attempts' => ['required', 'integer', 'min:1', 'max:100'],
            'lockout_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'ban_after_failures' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'ban_hours' => ['required', 'integer', 'min:1', 'max:8760'],
            'login_history_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'staff_two_factor' => ['boolean'],
            'captcha_provider' => ['nullable', Rule::in(array_keys(Captcha::PROVIDERS))],
            'captcha_site_key' => ['nullable', 'string', 'max:255'],
            'captcha_secret_key' => ['nullable', 'string', 'max:255'],
            'captcha_forms' => ['nullable', 'array'],
            'captcha_forms.*' => [Rule::in(array_keys(Captcha::FORMS))],
        ]);

        $provider = $data['captcha_provider'] ?? null;
        // A blank secret keeps the saved one, but only for the provider it belongs to.
        $secret = $data['captcha_secret_key'] ?? null;
        if (blank($secret) && $provider !== null && $provider === config('security.captcha.provider')) {
            $secret = config('security.captcha.secret_key');
        }

        if ($provider !== null && (blank($data['captcha_site_key'] ?? null) || blank($secret))) {
            throw ValidationException::withMessages(['captcha_site_key' => 'Enter the site key and secret key from '.Captcha::PROVIDERS[$provider]['name'].', or turn the CAPTCHA off.']);
        }

        if ($request->boolean('staff_two_factor') && ! $request->user()->hasTwoFactor()) {
            throw ValidationException::withMessages(['staff_two_factor' => 'Turn on two-factor authentication for your own login first, under My account, so you aren\'t locked out.']);
        }

        Settings::save([
            'security.lockout_attempts' => (int) $data['lockout_attempts'],
            'security.lockout_minutes' => (int) $data['lockout_minutes'],
            'security.ban_after_failures' => filled($data['ban_after_failures'] ?? null) ? (int) $data['ban_after_failures'] : null,
            'security.ban_hours' => (int) $data['ban_hours'],
            'security.login_history_days' => (int) $data['login_history_days'],
            'security.staff_two_factor' => $request->boolean('staff_two_factor'),
            'security.captcha.provider' => $provider,
            'security.captcha.site_key' => $data['captcha_site_key'] ?? null,
            'security.captcha.secret_key' => $secret,
            'security.captcha.forms' => array_values($data['captcha_forms'] ?? []),
        ]);

        Activity::record('Updated security settings', null, collect(config('security'))->except('captcha')->all() + [
            'captcha' => ['provider' => $provider, 'forms' => config('security.captcha.forms')],
        ]);

        return redirect()->route('admin.security.show')->with('status', 'Security settings saved.');
    }

    public function ban(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ip_address' => ['required', 'string', 'max:64'],
            'reason' => ['nullable', 'string', 'max:255'],
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);
        $range = trim($data['ip_address']);

        if (! IpBan::validRange($range)) {
            throw ValidationException::withMessages(['ip_address' => 'Enter an IP address, or a range such as 203.0.113.0/24.']);
        }

        if (IpUtils::checkIp((string) $request->ip(), $range)) {
            throw ValidationException::withMessages(['ip_address' => 'That would ban your own address ('.$request->ip().').']);
        }

        $ban = IpBan::updateOrCreate(['ip_address' => $range], [
            'reason' => $data['reason'] ?? null,
            'expires_at' => filled($data['days'] ?? null) ? now()->addDays((int) $data['days']) : null,
            'user_id' => $request->user()->id,
        ]);
        Activity::record("Banned {$range}".($ban->expires_at ? ' until '.$ban->expires_at->format('M j, Y') : ''), $ban);

        return redirect()->to(route('admin.security.show').'#bans')->with('status', "{$range} is banned.");
    }

    public function unban(IpBan $ban): RedirectResponse
    {
        $ban->delete();
        Activity::record("Lifted the ban on {$ban->ip_address}");

        return redirect()->to(route('admin.security.show').'#bans')->with('status', "{$ban->ip_address} can use the site again.");
    }
}
