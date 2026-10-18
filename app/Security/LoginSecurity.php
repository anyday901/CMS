<?php

namespace App\Security;

use App\Models\Activity;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\IpBan;
use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Login history, lockouts and automatic IP bans. Too many failed logins
 * from one IP address to one email lock that address out of it for a while;
 * too many from one address to any emails ban the address.
 */
class LoginSecurity
{
    /** Checks a password, moving the stored hash to the current algorithm and cost when it matches. */
    public static function passwordMatches(Model $account, string $password): bool
    {
        $hash = (string) $account->getAuthPassword();

        if ($hash === '' || ! Hash::check($password, $hash)) {
            return false;
        }

        if (Hash::needsRehash($hash)) {
            $account->forceFill(['password' => $password])->save();
        }

        return true;
    }

    /**
     * @param  Model|null  $account  whose login the email is, for the history
     *
     * @throws ValidationException while this address is locked out of this email
     */
    public function ensureNotLockedOut(string $area, string $email, Request $request, ?Model $account = null): void
    {
        $minutes = $this->lockedOutFor($area, $email, $request->ip());

        if ($minutes === null) {
            return;
        }

        $this->record($area, $email, $account, $request, LoginAttempt::LOCKED);

        throw ValidationException::withMessages([
            'email' => trans_choice('Too many failed logins. Try again in :count minute, or reset your password.|Too many failed logins. Try again in :count minutes, or reset your password.', $minutes),
        ]);
    }

    /** Minutes until the lockout ends, or null when there isn't one. */
    public function lockedOutFor(string $area, string $email, ?string $ip): ?int
    {
        $attempts = max(1, (int) config('security.lockout_attempts'));
        $window = max(1, (int) config('security.lockout_minutes'));
        $email = self::normalize($email);

        $recent = LoginAttempt::where('area', $area)->where('email', $email)->where('ip_address', $ip)
            ->where('created_at', '>=', now()->subMinutes($window))
            ->orderBy('created_at')->orderBy('id')
            ->get(['outcome', 'created_at']);

        // A successful login starts the count again.
        $lastSuccess = $recent->keys()->filter(fn (int $i) => $recent[$i]->outcome === LoginAttempt::SUCCESS)->last();
        $failures = $recent->slice($lastSuccess === null ? 0 : $lastSuccess + 1)
            ->filter(fn (LoginAttempt $attempt) => in_array($attempt->outcome, LoginAttempt::FAILURES, true))
            ->values();

        if ($failures->count() < $attempts) {
            return null;
        }

        // Locked until enough failures have aged out of the window.
        $unlocksAt = $failures[$failures->count() - $attempts]->created_at->copy()->addMinutes($window);

        return max(1, (int) ceil(now()->diffInSeconds($unlocksAt, true) / 60));
    }

    public function failed(string $area, string $email, ?Model $account, Request $request, string $outcome = LoginAttempt::FAILED): void
    {
        $this->record($area, $email, $account, $request, $outcome);
        $this->banIfNeeded($request->ip());
    }

    public function succeeded(string $area, Model $account, Request $request): void
    {
        $this->record($area, (string) $account->getAttribute('email'), $account, $request, LoginAttempt::SUCCESS);
    }

    private function record(string $area, string $email, ?Model $account, Request $request, string $outcome): void
    {
        LoginAttempt::create([
            'area' => $area,
            'email' => Str::limit(self::normalize($email), 250, ''),
            'account_type' => $account?->getMorphClass(),
            'account_id' => $account?->getKey(),
            'client_id' => match (true) {
                $account instanceof Client => $account->id,
                $account instanceof ClientContact => $account->client_id,
                default => null,
            },
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'outcome' => $outcome,
            'created_at' => now(),
        ]);
    }

    /** Bans the address for a while once it has failed too many logins in the last hour. */
    private function banIfNeeded(?string $ip): void
    {
        $limit = (int) config('security.ban_after_failures');

        if ($ip === null || $limit < 1 || IpBan::covers($ip)) {
            return;
        }

        $failures = LoginAttempt::where('ip_address', $ip)->whereIn('outcome', LoginAttempt::FAILURES)
            ->where('created_at', '>=', now()->subHour())->count();

        if ($failures < $limit) {
            return;
        }

        $hours = max(1, (int) config('security.ban_hours'));
        $ban = IpBan::updateOrCreate(['ip_address' => $ip], [
            'reason' => "{$failures} failed logins in an hour",
            'expires_at' => now()->addHours($hours),
            'user_id' => null,
        ]);
        Activity::record("Banned {$ip} for {$hours} ".Str::plural('hour', $hours).' after too many failed logins', $ban);
    }

    public static function normalize(string $email): string
    {
        return strtolower(trim($email));
    }

    /** The staff member or portal account the attempt was for, for links. */
    public static function accountUrl(LoginAttempt $attempt): ?string
    {
        return match (true) {
            $attempt->account instanceof User => route('admin.staff.edit', $attempt->account),
            $attempt->client_id !== null => route('admin.clients.show', $attempt->client_id),
            default => null,
        };
    }
}
