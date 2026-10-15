<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ManagesTwoFactor;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\LoginAttempt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/** A staff member's own login: password, two-factor authentication and recent logins. */
class AccountController extends Controller
{
    use ManagesTwoFactor;

    public function show(Request $request): View
    {
        $user = $request->user();

        return view('admin.account', [
            'user' => $user,
            'logins' => LoginAttempt::where('account_type', $user->getMorphClass())->where('account_id', $user->id)
                ->latest('created_at')->latest('id')->limit(20)->get(),
            'setupSecret' => $user->hasTwoFactor() ? null : $request->session()->get('two_factor_setup'),
        ]);
    }

    public function password(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        $request->user()->update(['password' => $data['password']]);
        Activity::record('Changed their staff password', $request->user());

        return back()->with('status', 'Your password is changed.');
    }

    protected function twoFactorAccount(): Model
    {
        return Auth::guard('web')->user();
    }

    protected function twoFactorPage(): string
    {
        return route('admin.account.show');
    }
}
