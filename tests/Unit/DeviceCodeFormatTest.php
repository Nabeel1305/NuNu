<?php

namespace Tests\Unit;

use App\Services\Offline\DeviceCodeFormat as F;
use PHPUnit\Framework\TestCase;

class DeviceCodeFormatTest extends TestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef';
    private const TS = 1791600000;

    /** Pinned: every client library must produce exactly these digits (clients/js/index.test.js has the same vectors). */
    public function test_known_vectors(): void
    {
        $this->assertSame('1482019370004200250000700112344868933947',
            F::build(self::KEY, '48201937', 42, 250000, '70011234', self::TS, 'NGN', '2000000001', '058', '3000000001', '011'));
        $this->assertSame('1000000070000100000100000000090281068932',
            F::build(self::KEY, '00000007', 1, 100, '00000009', self::TS, 'NGN', '2000000001', '058', '3000000001', '011'));
    }

    public function test_the_canonical_text_is_exactly_this(): void
    {
        $this->assertSame('nunu-d1|48201937|42|250000|70011234|5972000|NGN|2000000001|058|3000000001|011',
            F::canonical('48201937', 42, 250000, '70011234', F::window(self::TS), 'ngn', '2000000001', '058', '3000000001', '011'));
    }

    public function test_a_code_parses_back_into_its_fields(): void
    {
        $f = F::parse('1482019370004200250000700112344868933947');

        $this->assertSame(['version' => '1', 'device' => '48201937', 'counter' => 42, 'amount' => 250000, 'merchant' => '70011234', 'mac' => '4868933947'], $f);
    }

    public function test_malformed_digits_do_not_parse(): void
    {
        foreach (['', '123', str_repeat('1', 39), str_repeat('1', 41), '2' . str_repeat('0', 39), '1' . str_repeat('a', 39)] as $bad) {
            $this->assertNull(F::parse($bad), $bad);
        }
    }

    public function test_the_window_changes_every_five_minutes(): void
    {
        $this->assertSame(F::window(1000), F::window(1199));
        $this->assertNotSame(F::window(1199), F::window(1200));
    }

    public function test_every_signed_field_changes_the_mac(): void
    {
        $base = F::build(self::KEY, '48201937', 42, 250000, '70011234', self::TS, 'NGN', '2000000001', '058', '3000000001', '011');
        $variants = [
            F::build(self::KEY, '48201938', 42, 250000, '70011234', self::TS, 'NGN', '2000000001', '058', '3000000001', '011'),
            F::build(self::KEY, '48201937', 43, 250000, '70011234', self::TS, 'NGN', '2000000001', '058', '3000000001', '011'),
            F::build(self::KEY, '48201937', 42, 250001, '70011234', self::TS, 'NGN', '2000000001', '058', '3000000001', '011'),
            F::build(self::KEY, '48201937', 42, 250000, '70011235', self::TS, 'NGN', '2000000001', '058', '3000000001', '011'),
            F::build(self::KEY, '48201937', 42, 250000, '70011234', self::TS + 300, 'NGN', '2000000001', '058', '3000000001', '011'),
            F::build(self::KEY, '48201937', 42, 250000, '70011234', self::TS, 'USD', '2000000001', '058', '3000000001', '011'),
            F::build(self::KEY, '48201937', 42, 250000, '70011234', self::TS, 'NGN', '2000000002', '058', '3000000001', '011'),
            F::build(self::KEY, '48201937', 42, 250000, '70011234', self::TS, 'NGN', '2000000001', '044', '3000000001', '011'),
            F::build(self::KEY, '48201937', 42, 250000, '70011234', self::TS, 'NGN', '2000000001', '058', '3000000002', '011'),
            F::build(self::KEY, '48201937', 42, 250000, '70011234', self::TS, 'NGN', '2000000001', '058', '3000000001', '044'),
            F::build('fedcba9876543210fedcba9876543210', '48201937', 42, 250000, '70011234', self::TS, 'NGN', '2000000001', '058', '3000000001', '011'),
        ];

        foreach ($variants as $i => $v) {
            $this->assertNotSame(substr($base, 30), substr($v, 30), "variant {$i} produced the same MAC");
        }
    }
}
