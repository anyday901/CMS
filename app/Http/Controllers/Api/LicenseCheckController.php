<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Licensing\LicenseChecker;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Where licensed software checks its key (see docs/licensing.md). The
 * answer is JSON, signed with the product's signing secret in the
 * X-License-Signature header: an HMAC-SHA256 of the body, in hex.
 */
class LicenseCheckController extends Controller
{
    public const SIGNATURE = 'X-License-Signature';

    public function __invoke(Request $request, LicenseChecker $checker): Response
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:100'],
            'domain' => ['nullable', 'string', 'max:255'],
            'directory' => ['nullable', 'string', 'max:1000'],
            'check_token' => ['nullable', 'string', 'max:100'],
        ]);

        [$license, $answer] = $checker->check($data['key'], $data['domain'] ?? null, $data['directory'] ?? null, (string) $request->ip());
        // The software's own token comes back in the signed answer, so an old answer can't be replayed to it.
        $body = (string) json_encode([...$answer, 'checked_at' => now()->toIso8601String(), 'check_token' => $data['check_token'] ?? null], JSON_UNESCAPED_SLASHES);
        $secret = $license?->service->product->module_config['secret'] ?? null;

        return response($body, 200, array_filter([
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store',
            self::SIGNATURE => filled($secret) ? hash_hmac('sha256', $body, $secret) : null,
        ]));
    }
}
