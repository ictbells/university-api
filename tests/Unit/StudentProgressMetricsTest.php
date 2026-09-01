<?php

namespace Tests\Unit;

use App\Support\StudentProgressMetrics;
use PHPUnit\Framework\TestCase;

class StudentProgressMetricsTest extends TestCase
{
    public function test_standing_uses_2013_cgpa_floor_and_outstanding_units(): void
    {
        $this->assertSame('GS', StudentProgressMetrics::standing(1.5, 0, '2020'));
        $this->assertSame('GS', StudentProgressMetrics::standing(1.5, 11, '2013/2014'));
        $this->assertSame('NGS', StudentProgressMetrics::standing(1.49, 0, '2020'));
        $this->assertSame('NGS', StudentProgressMetrics::standing(5.0, 12, '2020'));
    }

    public function test_standing_uses_lower_cgpa_floor_for_2012_and_before(): void
    {
        $this->assertSame('GS', StudentProgressMetrics::standing(1.0, 0, '2012'));
        $this->assertSame('NGS', StudentProgressMetrics::standing(0.99, 0, '2011/2012'));
        $this->assertSame('NGS', StudentProgressMetrics::standing(4.0, 12, '2010'));
    }

    public function test_unknown_entry_year_uses_2013_rule(): void
    {
        $this->assertSame('NGS', StudentProgressMetrics::standing(1.2, 0, null));
        $this->assertSame('GS', StudentProgressMetrics::standing(1.5, 0, '—'));
        $this->assertSame('—', StudentProgressMetrics::standing(null, 0, '2020'));
    }

    public function test_outstanding_display_is_count_over_units_with_codes(): void
    {
        $this->assertSame('—', StudentProgressMetrics::formatOutstanding(0, 0, []));
        $this->assertSame('6/16 (PHY102, ARC307)', StudentProgressMetrics::formatOutstanding(6, 16, ['PHY102', 'ARC307']));
    }
}
