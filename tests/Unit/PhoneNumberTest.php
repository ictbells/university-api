<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use Tests\TestCase;

class PhoneNumberTest extends TestCase
{
    public function test_it_accepts_nigerian_local_and_international_forms(): void
    {
        $this->assertSame('+2348031234567', PhoneNumber::normalize('0803 123 4567'));
        $this->assertSame('+2348031234567', PhoneNumber::normalize('08031234567'));
        $this->assertSame('+2348031234567', PhoneNumber::normalize('2348031234567'));
        $this->assertSame('+2348031234567', PhoneNumber::normalize('+234 803 123 4567'));
        $this->assertSame('+2348031234567', PhoneNumber::normalize('+23408031234567'));
        $this->assertSame('+2348031234567', PhoneNumber::normalize('23408031234567'));
    }

    public function test_it_accepts_international_e164_numbers(): void
    {
        $this->assertSame('+12025550100', PhoneNumber::normalize('+1 202 555 0100'));
        $this->assertSame('+447911123456', PhoneNumber::normalize('+44 7911 123456'));
        $this->assertSame('+447911123456', PhoneNumber::normalize('00447911123456'));
    }

    public function test_it_rejects_invalid_numbers(): void
    {
        $this->assertNull(PhoneNumber::normalize('12345'));
        $this->assertNull(PhoneNumber::normalize('0803'));
        $this->assertNull(PhoneNumber::normalize('+23480'));
        $this->assertNull(PhoneNumber::normalize('not-a-number'));
        $this->assertNull(PhoneNumber::normalize('+0123456789'));
        $this->assertNull(PhoneNumber::normalize('2025550100'));
    }
}
