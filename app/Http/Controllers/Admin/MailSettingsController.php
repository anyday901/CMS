<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Support\Demo;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/** Settings > Mail: how the app sends email, and who it comes from. */
class MailSettingsController extends Controller
{
    /** Ways of sending, by config/mail.php mailer name. */
    public const MAILERS = [
        'smtp' => 'SMTP server',
        'sendmail' => 'Sendmail on this server',
        'log' => 'Don\'t send; write emails to the log (for trying things out)',
    ];

    public const SECURITY = [
        'smtp' => 'STARTTLS when the server offers it (usually port 587 or 25)',
        'smtps' => 'TLS from the start (usually port 465)',
    ];

    public function edit(): View
    {
        return view('admin.mail.edit', [
            'mailers' => self::MAILERS,
            'security' => self::SECURITY,
            'mailer' => config('mail.default'),
            'scheme' => config('mail.mailers.smtp.scheme') ?: 'smtp',
            'host' => config('mail.mailers.smtp.host'),
            'port' => config('mail.mailers.smtp.port'),
            'username' => config('mail.mailers.smtp.username'),
            'hasPassword' => filled(config('mail.mailers.smtp.password')),
            'fromAddress' => config('mail.from.address'),
            'fromName' => config('mail.from.name'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        Demo::refuse();

        $data = $request->validate([
            'mailer' => ['required', Rule::in(array_keys(self::MAILERS))],
            'scheme' => ['nullable', Rule::in(array_keys(self::SECURITY))],
            'host' => ['nullable', 'required_if:mailer,smtp', 'string', 'max:255'],
            'port' => ['nullable', 'required_if:mailer,smtp', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:500'],
            'forget_password' => ['boolean'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:255'],
        ], [], ['from_address' => 'from address', 'from_name' => 'from name']);

        $values = [
            'mail.default' => $data['mailer'],
            'mail.mailers.smtp.scheme' => $data['scheme'] ?? 'smtp',
            'mail.mailers.smtp.host' => $data['host'] ?? null,
            'mail.mailers.smtp.port' => isset($data['port']) ? (int) $data['port'] : null,
            'mail.mailers.smtp.username' => $data['username'] ?? null,
            'mail.from.address' => $data['from_address'],
            'mail.from.name' => $data['from_name'],
        ];

        // A blank password field keeps the saved one.
        if ($request->boolean('forget_password') || filled($data['password'] ?? null)) {
            $values['mail.mailers.smtp.password'] = $request->boolean('forget_password') ? null : $data['password'];
        }

        Settings::save($values);
        Mail::forgetMailers();
        // Queue workers read settings when they start, so they restart to send with the new ones.
        Artisan::call('queue:restart');
        Activity::record('Changed the mail settings', null, ['mailer' => $data['mailer'], 'host' => $data['host'] ?? null, 'from' => $data['from_address']]);

        return redirect()->route('admin.mail.edit')->with('status', 'Mail settings saved.');
    }

    /** Sends a test email straight away, so a wrong setting shows its error here. */
    public function test(Request $request): RedirectResponse
    {
        $data = $request->validate(['to' => ['required', 'email', 'max:255']], [], ['to' => 'address']);

        try {
            Mail::raw('This is a test email from '.config('app.name').'. If you can read it, your mail settings work.', function ($message) use ($data) {
                $message->to($data['to'])->subject('Test email from '.config('app.name'));
            });
        } catch (Throwable $e) {
            return back()->withInput()->withErrors(['to' => 'The test email wasn\'t sent: '.Str::limit($e->getMessage(), 300)]);
        }

        return back()->with('status', config('mail.default') === 'log'
            ? "Test email to {$data['to']} written to the log. Choose SMTP or Sendmail to really send it."
            : "Test email sent to {$data['to']}.");
    }
}
