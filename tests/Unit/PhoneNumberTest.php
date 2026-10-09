<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    public function test_different_written_forms_of_one_number_match(): void
    {
        $this->assertTrue(PhoneNumber::same('+2348012345678', '08012345678'));
        $this->assertTrue(PhoneNumber::same('2348012345678', '+234 801 234 5678'));
    }

    public function test_a_number_from_another_country_never_matches_one_that_only_ends_the_same(): void
    {
        $this->assertFalse(PhoneNumber::same('+2348012345678', '+18012345678'));
        $this->assertFalse(PhoneNumber::same('+2348012345678', '+447012345678'));
        $this->assertSame('2348012345678', PhoneNumber::normalize('0801 234 5678'));
        $this->assertSame('18012345678', PhoneNumber::normalize('+1 801 234 5678'));
    }

    public function test_different_numbers_and_empty_values_do_not_match(): void
    {
        $this->assertFalse(PhoneNumber::same('+2348012345678', '+2348012345679'));
        $this->assertFalse(PhoneNumber::same(null, null));
        $this->assertFalse(PhoneNumber::same('', '08012345678'));
    }
}
