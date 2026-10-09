<?php

namespace Tests\Unit;

use App\Services\Auth\Totp;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    // The RFC 6238 test secret, "12345678901234567890", base32-encoded.
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function test_it_matches_the_rfc_6238_test_vectors(): void
    {
        // RFC 6238 appendix B lists 8-digit values; ours are their last six digits.
        $this->assertSame('287082', Totp::code(self::SECRET, Totp::step(59)));
        $this->assertSame('081804', Totp::code(self::SECRET, Totp::step(1111111109)));
        $this->assertSame('005924', Totp::code(self::SECRET, Totp::step(1234567890)));
        $this->assertSame('279037', Totp::code(self::SECRET, Totp::step(2000000000)));
    }

    public function test_base32_round_trips(): void
    {
        $this->assertSame('12345678901234567890', Totp::base32Decode(self::SECRET));
        $raw = random_bytes(20);
        $this->assertSame($raw, Totp::base32Decode(Totp::base32Encode($raw)));
    }

    public function test_a_generated_secret_is_160_bits_of_base32(): void
    {
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', Totp::generateSecret());
        $this->assertNotSame(Totp::generateSecret(), Totp::generateSecret());
    }

    public function test_the_current_and_adjacent_steps_verify_but_further_ones_do_not(): void
    {
        $t = 1_000_000;
        $step = Totp::step($t);

        $this->assertSame($step, Totp::verify(self::SECRET, Totp::code(self::SECRET, $step), $t));
        $this->assertSame($step - 1, Totp::verify(self::SECRET, Totp::code(self::SECRET, $step - 1), $t));
        $this->assertSame($step + 1, Totp::verify(self::SECRET, Totp::code(self::SECRET, $step + 1), $t));
        $this->assertNull(Totp::verify(self::SECRET, Totp::code(self::SECRET, $step + 2), $t));
        $this->assertNull(Totp::verify(self::SECRET, Totp::code(self::SECRET, $step - 2), $t));
    }

    public function test_a_used_step_and_anything_older_cannot_be_replayed(): void
    {
        $t = 1_000_000;
        $step = Totp::step($t);
        $code = Totp::code(self::SECRET, $step);

        $this->assertNull(Totp::verify(self::SECRET, $code, $t, $step));
        $this->assertNull(Totp::verify(self::SECRET, Totp::code(self::SECRET, $step - 1), $t, $step));
        $this->assertSame($step + 1, Totp::verify(self::SECRET, Totp::code(self::SECRET, $step + 1), $t, $step));
    }

    public function test_malformed_codes_are_refused(): void
    {
        foreach (['', '12345', '1234567', 'abcdef', '12 34 5x'] as $bad) {
            $this->assertNull(Totp::verify(self::SECRET, $bad, 59));
        }

        $this->assertNotNull(Totp::verify(self::SECRET, '287 082', 59));
    }

    public function test_the_provisioning_uri_carries_what_an_authenticator_needs(): void
    {
        $uri = Totp::uri('Offline Platform', 'ops@example.test', self::SECRET);

        $this->assertStringStartsWith('otpauth://totp/Offline%20Platform%3Aops%40example.test?', $uri);
        $this->assertStringContainsString('secret=' . self::SECRET, $uri);
        $this->assertStringContainsString('issuer=Offline%20Platform', $uri);
    }
}
