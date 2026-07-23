<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    public function test_strips_formatting(): void
    {
        $this->assertSame('01712345678', PhoneNumber::normalize('017 1234-5678'));
        $this->assertSame('01712345678', PhoneNumber::normalize('(017) 1234 5678'));
    }

    public function test_international_variants_collapse_to_local_form(): void
    {
        $this->assertSame('01712345678', PhoneNumber::normalize('+880 1712-345678'));
        $this->assertSame('01712345678', PhoneNumber::normalize('8801712345678'));
        $this->assertSame('01712345678', PhoneNumber::normalize('00880 1712 345678'));
        $this->assertSame('01712345678', PhoneNumber::normalize('01712345678'));
    }

    public function test_empty_and_null_return_null(): void
    {
        $this->assertNull(PhoneNumber::normalize(null));
        $this->assertNull(PhoneNumber::normalize('  '));
        $this->assertNull(PhoneNumber::normalize('abc'));
    }
}
