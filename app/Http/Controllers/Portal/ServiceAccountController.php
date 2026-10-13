<?php

namespace App\Http\Controllers\Portal;

use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Service;
use App\Provisioning\ModuleError;
use App\Provisioning\ModuleRegistry;
use App\Provisioning\Provisioner;
use App\Provisioning\SingleSignOn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/**
 * What clients do to the account behind a service from the portal: log in
 * to its control panel, change its password, and reinstall it.
 */
class ServiceAccountController extends Controller
{
    public function __construct(private ModuleRegistry $modules) {}

    /** What the client can do to the account now, for the service page. */
    public static function abilities(Service $service, ModuleRegistry $modules): array
    {
        $module = $modules->find($service->product->module);
        $ready = $service->status === ServiceStatus::Active && $service->provisioned_at !== null && $service->server_id !== null;
        $idle = $service->provisioning_status !== Provisioner::PENDING;

        return [
            'canLogIn' => $ready && $module instanceof SingleSignOn,
            'canChangePassword' => $ready && Provisioner::canChangePassword($module, $service),
            'canReinstall' => $ready && $idle && $module !== null && Provisioner::supports($module, 'reinstall'),
        ];
    }

    /** Opens the control panel, logged in, in place of the portal. */
    public function controlPanel(Request $request, int $service): RedirectResponse
    {
        $service = $this->service($request, $service);
        abort_unless(self::abilities($service, $this->modules)['canLogIn'], 403);

        try {
            $url = $this->modules->find($service->product->module)->loginUrl($service, (string) $request->ip());
        } catch (ModuleError $e) {
            report($e);

            return back()->withErrors(['account' => 'The control panel couldn\'t log you in just now. Use the address and password below, or try again in a few minutes.']);
        }

        Activity::record("Logged in to the control panel of {$service->description()}", $service);

        return redirect()->away($url);
    }

    /**
     * Sets a new password on the account. It waits in new_password until the
     * server has taken it, so the old one keeps working if that fails.
     */
    public function password(Request $request, int $service, Provisioner $provisioner): RedirectResponse
    {
        $service = $this->service($request, $service);
        abort_unless(self::abilities($service, $this->modules)['canChangePassword'], 403);

        $data = $request->validate([
            'password' => ['required', 'string', 'max:64', 'confirmed', 'regex:/^\S+$/', Password::min(10)->letters()->mixedCase()->numbers()],
        ], ['password.regex' => 'The password can\'t contain spaces.']);

        $service->forceFill(['new_password' => $data['password']])->save();
        Activity::record("Asked for a new password on {$service->description()}", $service);
        $provisioner->queue($service, 'change_password');

        return back()->with('status', 'Your new password is being set. Your old one keeps working until it is.');
    }

    /** Wipes the account and sets it up again from the product's template. */
    public function reinstall(Request $request, int $service, Provisioner $provisioner): RedirectResponse
    {
        $service = $this->service($request, $service);
        abort_unless(self::abilities($service, $this->modules)['canReinstall'], 403);
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Tick the box to confirm everything on it will be erased.']);

        Activity::record("Asked to reinstall {$service->description()}", $service);
        $provisioner->queue($service, 'reinstall');

        return back()->with('status', 'Reinstalling. It can take a few minutes; this page shows when it\'s done.');
    }

    private function service(Request $request, int $id): Service
    {
        return $request->user('client')->services()->with(['product', 'server', 'client'])->findOrFail($id);
    }
}
