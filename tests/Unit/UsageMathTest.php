<?php

namespace Tests\Unit;

use App\Support\UsageMath;
use PHPUnit\Framework\TestCase;

class UsageMathTest extends TestCase
{
    public function test_norm_rejects_junk_and_formats_floats_without_noise(): void
    {
        $this->assertSame('0.300000', UsageMath::norm(0.1 + 0.2));
        $this->assertSame('12.500000', UsageMath::norm('12.5'));
        $this->assertSame('7.000000', UsageMath::norm(7));
        $this->expectException(\InvalidArgumentException::class);
        UsageMath::norm('1e3');
    }

    public function test_major_micro_round_trip(): void
    {
        $this->assertSame(200000, UsageMath::majorToMicro('0.002', 'USD'));   // $0.002 = 0.2 cent
        $this->assertSame(8000000, UsageMath::majorToMicro('0.08', 'USD'));
        $this->assertSame(1, UsageMath::majorToMicro('0.00000001', 'USD'));   // finest representable price
        $this->assertSame(0, UsageMath::majorToMicro('0.000000001', 'USD'));  // beyond precision is truncated
        $this->assertSame(1000000, UsageMath::majorToMicro('1', 'JPY'));      // zero-decimal currency: 1 yen = 1 minor unit
        $this->assertSame('0.002', UsageMath::microToMajor(200000, 'USD', 2));
        $this->assertSame('12.50', UsageMath::microToMajor(1250000000, 'USD', 2));
    }

    public function test_rounding_happens_once_half_up(): void
    {
        $this->assertSame(0, UsageMath::toMinor(UsageMath::lineMicro('1.000000', 499999)));
        $this->assertSame(1, UsageMath::toMinor(UsageMath::lineMicro('1.000000', 500000)));
        $this->assertSame(1, UsageMath::toMinor(UsageMath::lineMicro('3.000000', 166667)));
        // 1,000,000 calls at 0.0001 cent each = 100 cents, exact with no float drift
        $this->assertSame(100, UsageMath::toMinor(UsageMath::lineMicro('1000000.000000', 100)));
    }

    public function test_billable_never_negative(): void
    {
        $this->assertSame('0.000000', UsageMath::billable('5.000000', '10.000000'));
        $this->assertSame('2.500000', UsageMath::billable('12.500000', '10.000000'));
    }
}
