<?php

namespace Tests\Unit;

use App\Services\Codes\CodeGenerator;
use PHPUnit\Framework\TestCase;

class CodeGeneratorTest extends TestCase
{
    public function test_generates_the_requested_number_of_digits_after_the_prefix(): void
    {
        $code = (new CodeGenerator(12))->generate('4821');

        $this->assertMatchesRegularExpression('/^4821\d{12}$/', $code);
    }

    public function test_codes_are_not_repeated(): void
    {
        $generator = new CodeGenerator(12);
        $codes = array_map(fn () => $generator->generate(), range(1, 200));

        $this->assertCount(200, array_unique($codes));
    }

    public function test_normalize_strips_the_hash_terminator_and_separators(): void
    {
        $this->assertSame('123456', (new CodeGenerator(6))->normalize('12 34-56#'));
    }
}
