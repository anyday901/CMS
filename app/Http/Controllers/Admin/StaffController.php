<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StaffRole;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StaffController extends Controller
{
    private const INDEX = 'admin.staff.index';

    public function index(): View
    {
        return view('admin.staff.index', ['staff' => User::orderBy('name')->get()]);
    }

    public function create(): View
    {
        return view('admin.staff.form', ['user' => new User]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = User::create($this->validated($request));
        Activity::record("Added staff member {$user->name} ({$user->role->label()})", $user);

        return redirect()->route(self::INDEX)->with('status', 'Staff member added.');
    }

    public function edit(User $user): View
    {
        return view('admin.staff.form', compact('user'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validated($request, $user);

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        DB::transaction(function () use ($request, $user, $data) {
            $role = StaffRole::from($data['role']);

            if ($role !== StaffRole::Admin && $user->role === StaffRole::Admin) {
                if ($user->is($request->user())) {
                    throw ValidationException::withMessages(['role' => 'You cannot remove your own admin role.']);
                }
                $this->ensureAnotherAdmin($user);
            }

            $user->update($data);
        });

        Activity::record("Updated staff member {$user->name}", $user, ['role' => $user->role->value]);

        return redirect()->route(self::INDEX)->with('status', 'Staff member updated.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->withErrors(['staff' => 'You cannot delete your own account.']);
        }

        DB::transaction(function () use ($user) {
            if ($user->role === StaffRole::Admin) {
                $this->ensureAnotherAdmin($user);
            }
            $user->delete();
        });

        Activity::record("Removed staff member {$user->name}", null, ['email' => $user->email]);

        return redirect()->route(self::INDEX)->with('status', 'Staff member removed.');
    }

    private function ensureAnotherAdmin(User $user): void
    {
        $others = User::where('role', StaffRole::Admin->value)->whereKeyNot($user->getKey())->lockForUpdate()->count();

        if ($others === 0) {
            throw ValidationException::withMessages(['role' => 'There must always be at least one admin.']);
        }
    }

    private function validated(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'role' => ['required', Rule::enum(StaffRole::class)],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:12', 'confirmed'],
        ]);
    }
}
