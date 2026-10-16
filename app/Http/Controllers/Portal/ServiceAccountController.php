<?php

namespace App\Http\Controllers\Portal;

use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\License;
use App\Models\Service;
use App\Provisioning\ControlPanelLogin;
use App\Provisioning\ModuleError;
use App\Provisioning\ModuleRegistry;
use App\Provisioning\Modules\Licensing;
use App\Provisioning\Provisioner;
use App\Provisioning\SingleSignOn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/**
 * What clients do to the account behind a service from the portal: log in
 * to its control panel, change its password, reinstall it, and reissue its
 * software license.
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
            'canReissue' => $module instanceof Licensing && $service->license?->status === License::ACTIVE && Licensing::clientsReissue($service),
        ];
    }

    /** Opens the control panel, logged in, in place of the portal. */
    public function controlPanel(Request $request, int $service): RedirectResponse
    {
        $service = $this->service($request, $service);
        abort_unless(self::abilities($service, $this->modules)['canLogIn'], 403);

        try {
            $url = app(ControlPanelLogin::class)->url($service, $this->modules->find($service->product->module), $request->ip());
        } catch (ModuleError $e) {
            report($e);

            return back()->withErrors(['account' => __("The control panel couldn't log you in just now. Use the address and password below, or try again in a few minutes.")]);
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
        ], ['password.regex' => __("The password can't contain spaces.")]);

        $service->forceFill(['new_password' => $data['password']])->save();
        Activity::record("Asked for a new password on {$service->description()}", $service);
        $provisioner->queue($service, 'change_password');

        return back()->with('status', __('Your new password is being set. Your old one keeps working until it is.'));
    }

    /** Wipes the account and sets it up again from the product's template. */
    public function reinstall(Request $request, int $service, Provisioner $provisioner): RedirectResponse
    {
        $service = $this->service($request, $service);
        abort_unless(self::abilities($service, $this->modules)['canReinstall'], 403);
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => __('Tick the box to confirm everything on it will be erased.')]);

        Activity::record("Asked to reinstall {$service->description()}", $service);
        $provisioner->queue($service, 'reinstall');

        return back()->with('status', __("Reinstalling. It can take a few minutes; this page shows when it's done."));
    }

    /** Frees the license from where it was installed, so it can be installed somewhere else. */
    public function reissue(Request $request, int $service): RedirectResponse
    {
        $service = $this->service($request, $service);
        abort_unless(self::abilities($service, $this->modules)['canReissue'], 403);

        $service->license->reissue();
        Activity::record("Reissued the license of {$service->description()}", $service);

        return back()->with('status', __('License reissued. It locks to the next place it is installed.'));
    }

    private function service(Request $request, int $id): Service
    {
        return $request->user('client')->services()->with(['product', 'server', 'client'])->findOrFail($id);
    }
}
