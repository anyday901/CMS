<?php

namespace Tests\Unit;

use App\Auth\Totp;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    /** "12345678901234567890" in base32, the RFC 6238 test secret. */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function test_codes_match_the_rfc_6238_test_vectors(): void
    {
        // The RFC lists eight digits; authenticator apps show the last six.
        foreach ([59 => '287082', 1111111109 => '081804', 1234567890 => '005924', 2000000000 => '279037'] as $time => $code) {
            $this->assertSame($code, Totp::code(self::RFC_SECRET, Totp::step($time)));
        }
    }

    public function test_verify_allows_one_step_of_drift_and_no_reuse(): void
    {
        $time = 1234567890;
        $step = Totp::step($time);

        $this->assertSame($step, Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step), null, $time));
        $this->assertSame($step - 1, Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step - 1), null, $time));
        $this->assertNull(Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step - 2), null, $time));

        // A code already used, or an older one, doesn't work again.
        $this->assertNull(Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step), $step, $time));
        $this->assertNull(Totp::verify(self::RFC_SECRET, 'abcdef', null, $time));
    }

    public function test_secrets_and_links_for_authenticator_apps(): void
    {
        $secret = Totp::secret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertNotSame($secret, Totp::secret());

        $uri = Totp::uri($secret, 'jane@example.com', 'Harbor Hosting');
        $this->assertStringStartsWith('otpauth://totp/Harbor%20Hosting:jane%40example.com?secret='.$secret.'&issuer=Harbor%20Hosting', $uri);
        $this->assertStringStartsWith('<svg', Totp::qrSvg($uri));
        $this->assertSame(39, strlen(Totp::readable($secret)));
    }
}
