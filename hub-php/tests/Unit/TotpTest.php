<?php

namespace Tests\Unit;

use App\Auth\Totp;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    /** RFC 6238 appendix B, the SHA-1 rows. */
    public function test_codes_match_the_rfc_test_vectors(): void
    {
        $key = '12345678901234567890';
        $this->assertSame('94287082', Totp::code($key, Totp::step(59), 8));
        $this->assertSame('07081804', Totp::code($key, Totp::step(1111111109), 8));
        $this->assertSame('89005924', Totp::code($key, Totp::step(1234567890), 8));
        $this->assertSame('65353130', Totp::code($key, Totp::step(20000000000), 8));
    }

    public function test_base32_round_trips(): void
    {
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', Totp::base32('12345678901234567890'));
        $this->assertSame('12345678901234567890', Totp::decode('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'));
        $secret = Totp::secret();
        $this->assertSame(32, strlen($secret));
        $this->assertSame($secret, Totp::base32(Totp::decode($secret)));
    }

    public function test_match_accepts_one_step_either_side_and_refuses_a_used_step(): void
    {
        $secret = Totp::base32('12345678901234567890');
        $key = Totp::decode($secret);
        $now = 1234567890;
        $step = Totp::step($now);

        $this->assertSame($step, Totp::match($secret, Totp::code($key, $step), $now, null));
        $this->assertSame($step - 1, Totp::match($secret, Totp::code($key, $step - 1), $now, null));
        $this->assertSame($step + 1, Totp::match($secret, Totp::code($key, $step + 1), $now, null));
        $this->assertNull(Totp::match($secret, Totp::code($key, $step - 2), $now, null));
        $this->assertNull(Totp::match($secret, Totp::code($key, $step), $now, $step));
        $this->assertNull(Totp::match($secret, '12345', $now, null));
    }

    public function test_uri_names_the_issuer_and_account(): void
    {
        $this->assertSame(
            'otpauth://totp/Coilbox%20Hub%3ATom?secret=ABC&issuer=Coilbox%20Hub&algorithm=SHA1&digits=6&period=30',
            Totp::uri('ABC', 'Tom', 'Coilbox Hub'),
        );
    }
}
