<?php

namespace App\Http\Controllers;

use App\System\Installer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The web installer. Its routes run without sessions or cookies (there may
 * be no database for them yet), so each step re-renders the page itself
 * rather than redirecting with flashed errors.
 */
class InstallController extends Controller
{
    public function __construct(private Installer $installer)
    {
        abort_unless(Installer::enabled(), 404);
    }

    public function show(): Response
    {
        return $this->page();
    }

    public function database(Request $request): Response
    {
        $this->open();

        $validator = Validator::make($request->all(), [
            'driver' => ['required', Rule::in(array_keys(Installer::DRIVERS))],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'database' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_.\/-]+$/'],
            'username' => ['nullable', 'required_unless:driver,sqlite', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
        ], ['database.regex' => 'Use letters, digits, dots, dashes and underscores in the database name.']);

        if ($validator->fails()) {
            return $this->page($validator->errors(), $request->except('password'), step: 'database');
        }

        try {
            $this->installer->configureDatabase($validator->validated(), $request->root());
        } catch (RuntimeException $e) {
            return $this->page(new MessageBag(['database' => $e->getMessage()]), $request->except('password'), step: 'database');
        }

        return $this->page(status: 'Connected, and the tables are set up. Now create your staff account.');
    }

    public function finish(Request $request): Response
    {
        $this->open();
        abort_unless($this->installer->databaseReady(), 409, 'Set up the database first.');

        $validator = Validator::make($request->all(), [
            'company' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        if ($validator->fails()) {
            return $this->page($validator->errors(), $request->except(['password', 'password_confirmation']), step: 'account');
        }

        $this->installer->finish(...$validator->safe()->only(['company', 'name', 'email', 'password']));

        return response()->view('install.done', ['login' => url('/admin/login')]);
    }

    private function open(): void
    {
        abort_if($this->installer->installed(), 404);
    }

    /** @param  string|null  $step  the step whose form had errors, shown again even if an earlier check now fails */
    private function page(?MessageBag $errors = null, array $old = [], ?string $status = null, ?string $step = null): Response
    {
        $this->open();

        return response()->view('install.show', [
            'step' => $step ?? $this->installer->step(),
            'requirements' => $this->installer->requirements(),
            'drivers' => Installer::DRIVERS,
            'installErrors' => $errors ?? new MessageBag,
            'old' => $old,
            'status' => $status,
        ], $errors && $errors->isNotEmpty() ? 422 : 200);
    }
}
