<?php

namespace App\Security;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * A CAPTCHA on public forms, from Cloudflare Turnstile, hCaptcha or Google
 * reCAPTCHA (the v2 checkbox). Staff pick the provider, its keys and the
 * forms on the Security page.
 */
class Captcha
{
    public const PROVIDERS = [
        'turnstile' => [
            'name' => 'Cloudflare Turnstile',
            'script' => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
            'class' => 'cf-turnstile',
            'field' => 'cf-turnstile-response',
            'verify' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        ],
        'hcaptcha' => [
            'name' => 'hCaptcha',
            'script' => 'https://js.hcaptcha.com/1/api.js',
            'class' => 'h-captcha',
            'field' => 'h-captcha-response',
            'verify' => 'https://api.hcaptcha.com/siteverify',
        ],
        'recaptcha' => [
            'name' => 'Google reCAPTCHA v2',
            'script' => 'https://www.google.com/recaptcha/api.js',
            'class' => 'g-recaptcha',
            'field' => 'g-recaptcha-response',
            'verify' => 'https://www.google.com/recaptcha/api/siteverify',
        ],
    ];

    public const FORMS = [
        'login' => 'Portal login',
        'register' => 'Sign-up at checkout',
        'password' => 'Forgot password',
        'contact' => 'Contact form (tickets without an account)',
        'staff_login' => 'Staff login',
    ];

    /** The provider's details, when one is set up with both keys. */
    public static function provider(): ?array
    {
        $provider = self::PROVIDERS[(string) config('security.captcha.provider')] ?? null;

        return $provider && filled(config('security.captcha.site_key')) && filled(config('security.captcha.secret_key')) ? $provider : null;
    }

    public static function enabledFor(string $form): bool
    {
        return self::provider() !== null && in_array($form, (array) config('security.captcha.forms'), true);
    }

    /** @throws ValidationException when the form has a CAPTCHA and it wasn't solved */
    public static function check(Request $request, string $form): void
    {
        if (! self::enabledFor($form)) {
            return;
        }

        $provider = self::provider();
        $answer = (string) $request->input($provider['field']);

        if ($answer === '' || ! self::passes($provider, $answer, $request->ip())) {
            throw ValidationException::withMessages(['captcha' => __("Please complete the check that you're not a robot.")]);
        }
    }

    private static function passes(array $provider, string $answer, ?string $ip): bool
    {
        try {
            $response = Http::asForm()->timeout(10)->post($provider['verify'], array_filter([
                'secret' => config('security.captcha.secret_key'),
                'response' => $answer,
                'remoteip' => $ip,
            ]));
        } catch (ConnectionException $e) {
            // Don't lock everyone out when the provider is down.
            Log::warning("CAPTCHA check couldn't reach {$provider['name']}: {$e->getMessage()}");

            return true;
        }

        return $response->successful() && $response->json('success') === true;
    }
}
